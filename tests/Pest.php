<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    // The configured commands run scripts on this machine; a test opts back in with a faked one.
    ->beforeEach(fn () => config(['orchestrator.before_create' => []]))
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A list component with its background refresh done. Mounting only reads the cache —
 * the live fetch is what `wire:init` triggers in the browser, which the test harness
 * doesn't do on its own.
 */
function loaded(string $name): Testable
{
    return Livewire::test($name)->call('load');
}

/**
 * Fake the two GitHub endpoints the app talks to. Every GraphQL document posts to the
 * same URL, so they're told apart by a fragment only that document contains.
 *
 * @param  array<int, array<string, mixed>>  $assigned
 * @param  array<int, array<string, mixed>>  $other
 * @param  array<int, array<string, mixed>>  $bugs
 * @param  array<int, array<string, mixed>>  $pullRequests
 * @param  array<string, string>  $statuses  Issue node id => board column it sits in.
 * @param  array<string, string>  $checks  Pull request node id => CI rollup state.
 * @param  array<int, array<string, mixed>>  $mine  My own open pull requests.
 * @param  array<string, array<string, mixed>>  $threads  Pull request node id => mergeable state and thread resolutions.
 * @param  array<int, array<string, mixed>>  $search  What the header's issue search matches.
 * @param  array<int, array<string, mixed>>  $byNumber  The issues a `#123` lookup can fetch, matched on repo and number.
 * @param  int|null  $totalCount  What GitHub says it matched, when that is more than the
 *                                page it hands back. Defaults to the page's own size, so
 *                                a search reads as complete unless a test says otherwise.
 */
function fakeGitHub(
    array $assigned = [],
    array $other = [],
    array $bugs = [],
    array $pullRequests = [],
    array $statuses = [],
    array $checks = [],
    ?string $claimError = null,
    ?int $totalCount = null,
    array $reviewed = [],
    array $reviews = [],
    array $mine = [],
    array $threads = [],
    array $search = [],
    array $byNumber = [],
): void {
    Http::fake([
        // The bug search is told apart by `type:Bug` — `no:assignee` won't do, the
        // second list's own fixture query uses it too.
        'api.github.com/search/issues*' => function (Request $request) use ($assigned, $other, $bugs, $pullRequests, $reviewed, $mine, $search, $totalCount) {
            $url = $request->url();

            $items = match (true) {
                str_contains($url, 'reviewed-by') => $reviewed,
                str_contains($url, 'author%3A%40me') => $mine,
                str_contains($url, 'is%3Apr') => $pullRequests,
                // Only the lists exclude blocked issues; the header search filters nothing.
                ! str_contains($url, 'is%3Ablocked') => $search,
                str_contains($url, 'assignee%3A%40me') => $assigned,
                str_contains($url, 'type%3ABug') => $bugs,
                default => $other,
            };

            return Http::response(['total_count' => $totalCount ?? count($items), 'items' => $items]);
        },

        'api.github.com/repos/*/issues/*' => function (Request $request) use ($byNumber) {
            foreach ($byNumber as $item) {
                if (str_ends_with($request->url(), Str::after($item['repository_url'], 'api.github.com')."/issues/{$item['number']}")) {
                    return Http::response($item);
                }
            }

            return Http::response(['message' => 'Not Found'], 404);
        },

        'api.github.com/graphql' => function (Request $request) use ($statuses, $checks, $reviews, $threads, $claimError) {
            $query = (string) $request['query'];

            return match (true) {
                str_contains($query, 'projectsV2(') => Http::response(['data' => [
                    'viewer' => ['id' => 'VIEWER'],
                    'organization' => ['projectsV2' => ['nodes' => [[
                        'id' => 'PROJECT',
                        'title' => config('orchestrator.board.name'),
                        'field' => ['id' => 'FIELD', 'options' => [
                            ['id' => 'o1', 'name' => 'Open'],
                            ['id' => 'o2', 'name' => 'Ready'],
                            ['id' => 'o3', 'name' => 'On Hold'],
                            ['id' => 'o4', 'name' => 'In Progress'],
                            ['id' => 'o5', 'name' => 'Done'],
                        ]],
                    ]]]],
                ]]),

                str_contains($query, 'projectItems') => Http::response(['data' => ['nodes' => collect($statuses)
                    ->map(fn (string $status, string $id): array => [
                        'id' => $id,
                        'projectItems' => ['nodes' => [[
                            'project' => ['title' => config('orchestrator.board.name')],
                            'fieldValueByName' => ['name' => $status],
                        ]]],
                    ])
                    ->values()
                    ->all()]]),

                // $reviews: PR node id => submittedAt of my review, as the viewer "me".
                str_contains($query, 'submittedAt') => Http::response(['data' => [
                    'viewer' => ['login' => 'me'],
                    'nodes' => collect($reviews)
                        ->map(fn (array $submitted, string $id): array => [
                            'id' => $id,
                            'reviews' => ['nodes' => array_map(fn (string $at): array => [
                                'submittedAt' => $at,
                                'author' => ['login' => 'me'],
                            ], $submitted)],
                        ])
                        ->values()
                        ->all(),
                ]]),

                // $threads: PR node id => ['mergeable' => …, 'resolved' => [bool, …]].
                str_contains($query, 'reviewThreads') => Http::response(['data' => ['nodes' => collect($threads)
                    ->map(fn (array $thread, string $id): array => [
                        'id' => $id,
                        'headRefName' => 'feature/'.$id,
                        'mergeable' => $thread['mergeable'] ?? 'MERGEABLE',
                        'reviewThreads' => ['nodes' => array_map(fn (bool $resolved): array => ['isResolved' => $resolved], $thread['resolved'] ?? [])],
                    ])
                    ->values()
                    ->all()]]),

                str_contains($query, 'statusCheckRollup') => Http::response(['data' => ['nodes' => collect($checks)
                    ->map(fn (string $state, string $id): array => [
                        'id' => $id,
                        'commits' => ['nodes' => [['commit' => ['statusCheckRollup' => ['state' => $state]]]]],
                    ])
                    ->values()
                    ->all()]]),

                str_contains($query, 'updateIssue') => $claimError === null
                    ? Http::response(['data' => ['added' => ['item' => ['id' => 'ITEM']]]])
                    : Http::response(['errors' => [['message' => $claimError]]]),

                default => Http::response(['data' => []]),
            };
        },
    ]);
}

/** The GraphQL document a request carries, or '' for the REST searches. */
function graphqlDocument(Request $request): string
{
    return (string) data_get($request->data(), 'query');
}

/**
 * A GitHub issue search result, thinned down to the fields the app reads.
 *
 * @param  array<int, array{name: string, color: string}>  $labels
 * @return array<string, mixed>
 */
function issueItem(int $number, array $labels = [], string $repo = 'a/b'): array
{
    return [
        'node_id' => "I_{$number}",
        'number' => $number,
        'title' => "Issue {$number}",
        'html_url' => "https://github.com/{$repo}/issues/{$number}",
        'repository_url' => "https://api.github.com/repos/{$repo}",
        'labels' => $labels,
    ];
}

/**
 * A pull request search result.
 *
 * @return array<string, mixed>
 */
function pullRequestItem(int $number, string $author = 'octocat', string $repo = 'a/b'): array
{
    return [
        'node_id' => "PR_{$number}",
        'number' => $number,
        'title' => "Pull request {$number}",
        'html_url' => "https://github.com/{$repo}/pull/{$number}",
        'repository_url' => "https://api.github.com/repos/{$repo}",
        'user' => ['login' => $author],
    ];
}

/**
 * An Orca worktree as `orca worktree list --json` reports it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function worktree(array $attributes = []): array
{
    return $attributes + [
        'worktreeId' => 'repo-1::/orca/feature-1',
        'status' => 'inactive',
        'repoId' => 'repo-1',
        'branch' => 'refs/heads/feature/1',
        'isMainWorktree' => false,
        'isArchived' => false,
        'workspaceStatus' => 'in-progress',
        'createdAt' => Carbon::parse('2026-08-08 11:18:15', 'UTC')->getTimestampMs(),
        'linkedItems' => [],
    ];
}

/**
 * A worktree linked to a GitHub issue or pull request.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function linkedWorktree(int $number, string $url, array $attributes = []): array
{
    return worktree($attributes + [
        'linkedItems' => [['provider' => 'github', 'type' => 'issue', 'number' => $number, 'url' => $url]],
    ]);
}

/**
 * Fake the `orca` CLI: what the worktree and repo lists hold, and the worktree a
 * create call hands back. Call it again to replace what an earlier call faked.
 *
 * @param  array<int, array<string, mixed>>  $worktrees
 * @param  array<int, array<string, mixed>>  $repos
 * @param  array<string, mixed>|null  $created
 */
function fakeOrca(array $worktrees = [], array $repos = [], ?array $created = null, array $terminals = [['handle' => 'term_1']], array $ports = []): void
{
    $ok = fn (array $result) => Process::result(json_encode(['ok' => true, 'result' => $result]));

    Process::fake([
        // Array commands reach the fake shell-quoted: 'orca' 'worktree' 'list'.
        "*'worktree' 'ps'*" => $ok(['worktrees' => $worktrees]),
        "*'terminal' 'list'*" => $ok(['terminals' => $terminals]),
        "*'ports'*" => $ok(['ports' => $ports]),
        "*'terminal' 'switch'*" => $ok([]),
        "*'open' '-a' 'Orca'*" => Process::result(),
        "*'file' 'open-changed'*" => $ok([]),
        "*'worktree' 'rm'*" => $ok([]),
        "*'worktree' 'create'*" => $ok(['worktree' => $created ?? worktree()]),
        "*'repo' 'list'*" => $ok(['repos' => $repos]),
    ]);

    // An unfaked call would otherwise reach the real Orca.
    Process::preventStrayProcesses();
}

/** Every `orca` call fails with this message, the way a stopped Orca does. */
function fakeOrcaFailing(string $message): void
{
    $failure = Process::result(errorOutput: $message, exitCode: 1);

    // The same patterns fakeOrca() registers — an earlier, more specific one would win over a `*`.
    Process::fake(["*'worktree' 'ps'*" => $failure, "*'terminal' 'list'*" => $failure, "*'worktree' 'create'*" => $failure, "*'repo' 'list'*" => $failure]);
    Process::preventStrayProcesses();
}

/** Assert the CLI was called exactly this many times with this subcommand, e.g. `worktree ps`. */
function assertOrcaCalled(string $subcommand, int $times): void
{
    Process::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), $subcommand), $times);
}
