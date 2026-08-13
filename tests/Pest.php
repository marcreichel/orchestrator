<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Polyscope\Laravel\Resources\Workspace;
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
 */
function fakeGitHub(
    array $assigned = [],
    array $other = [],
    array $bugs = [],
    array $pullRequests = [],
    array $statuses = [],
    array $checks = [],
    ?string $claimError = null,
): void {
    Http::fake([
        // The bug search is told apart by `type:Bug` — `no:assignee` won't do, the
        // second list's own fixture query uses it too.
        'api.github.com/search/issues*' => function (Request $request) use ($assigned, $other, $bugs, $pullRequests) {
            $url = $request->url();

            return Http::response(['items' => match (true) {
                str_contains($url, 'is%3Apr') => $pullRequests,
                str_contains($url, 'assignee%3A%40me') => $assigned,
                str_contains($url, 'type%3ABug') => $bugs,
                default => $other,
            }]);
        },

        'api.github.com/graphql' => function (Request $request) use ($statuses, $checks, $claimError) {
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

/** @param  array<string, mixed>  $attributes */
function workspace(array $attributes = []): Workspace
{
    return new Workspace($attributes + [
        'id' => 'ws-1',
        'repo_id' => 'repo-1',
        'branch' => 'feature/1',
        'status' => 'active',
        'created_at' => '2026-08-08 11:18:15',
    ]);
}
