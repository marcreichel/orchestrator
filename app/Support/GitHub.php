<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GitHub
{
    /** Not actionable yet — excluded from every issue list. */
    private const EXCLUDED_LABELS = ['WIP', 'Screening', 'blocked'];

    /** GitHub's cap on `nodes(ids:)`. */
    private const NODES_PER_QUERY = 100;

    /** GitHub's rollup states, collapsed to the three things a dot can mean. */
    private const CHECKS = [
        'SUCCESS' => 'success',
        'PENDING' => 'pending',
        'EXPECTED' => 'pending',
        'FAILURE' => 'failure',
        'ERROR' => 'failure',
    ];

    /**
     * @param  array<int, string>  $repoNames
     * @param  array<int, string>  $ignore  Bare numbers (any repo) or `owner/repo#number`.
     */
    public function __construct(
        private readonly string $token,
        private readonly array $repoNames,
        private readonly array $ignore,
    ) {}

    /**
     * Issues matching each query across the mapped repos, minus the ignored ones, keyed
     * the way the queries came in. Takes them all at once because the lists don't depend
     * on each other — run one at a time their latencies just add up.
     *
     * @param  array<string, string>  $queries
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function issues(array $queries): array
    {
        return array_map(
            fn (array $items): array => $this->issueRows($items),
            $this->searchMany(array_map(
                fn (string $query): string => self::issueQuery($this->repoNames, $query),
                $queries,
            )),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function issueRows(array $items): array
    {
        $issues = array_map(fn (array $item): array => [
            'id' => (string) $item['node_id'],
            'number' => (int) $item['number'],
            'title' => (string) $item['title'],
            'url' => (string) $item['html_url'],
            // repository_url looks like https://api.github.com/repos/owner/name
            'repo' => Str::after((string) $item['repository_url'], '/repos/'),
            'labels' => array_map(fn (mixed $label): array => [
                'name' => (string) data_get($label, 'name'),
                'color' => (string) data_get($label, 'color'),
            ], (array) $item['labels']),
        ], $items);

        return array_values(array_filter($issues, fn (array $issue): bool => ! self::isIgnored(
            $this->ignore,
            (string) $issue['repo'],
            (int) $issue['number'],
        )));
    }

    /**
     * Pull requests waiting on my review, each with the CI rollup of its head commit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullRequests(): array
    {
        $items = $this->searchItems(self::prQuery($this->repoNames));
        $checks = $this->checkStates(array_map(fn (array $item): string => (string) $item['node_id'], $items));

        return array_map(fn (array $item): array => [
            'number' => (int) $item['number'],
            'title' => (string) $item['title'],
            'url' => (string) $item['html_url'],
            'repo' => Str::after((string) $item['repository_url'], '/repos/'),
            'author' => (string) data_get($item, 'user.login'),
            'check' => $checks[(string) $item['node_id']] ?? null,
        ], $items);
    }

    /**
     * Board status per issue node id. A missing entry means the issue isn't on the
     * board at all, which never blocks.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string|null>
     */
    public function boardStatuses(array $ids, string $board): array
    {
        if ($ids === []) {
            return [];
        }

        $statuses = [];

        // `nodes(ids:)` takes at most 100 ids and the issue lists together can hand over
        // more, so the lookup goes out in chunks.
        foreach (array_chunk($ids, self::NODES_PER_QUERY) as $chunk) {
            $data = $this->graphql(<<<'GRAPHQL'
                query ($ids: [ID!]!) {
                  nodes(ids: $ids) {
                    ... on Issue {
                      id
                      projectItems(first: 20) {
                        nodes {
                          project { title }
                          fieldValueByName(name: "Status") {
                            ... on ProjectV2ItemFieldSingleSelectValue { name }
                          }
                        }
                      }
                    }
                  }
                }
                GRAPHQL, ['ids' => $chunk]);

            foreach ((array) data_get($data, 'nodes', []) as $node) {
                $item = collect((array) data_get($node, 'projectItems.nodes', []))
                    ->firstWhere('project.title', $board);
                $status = data_get($item, 'fieldValueByName.name');
                $statuses[(string) data_get($node, 'id')] = is_string($status) ? $status : null;
            }
        }

        return $statuses;
    }

    /**
     * Rollup state per pull request node id. A missing entry means no CI ran on the
     * head commit.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string|null>
     */
    public function checkStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $data = $this->graphql(<<<'GRAPHQL'
            query ($ids: [ID!]!) {
              nodes(ids: $ids) {
                ... on PullRequest {
                  id
                  commits(last: 1) { nodes { commit { statusCheckRollup { state } } } }
                }
              }
            }
            GRAPHQL, ['ids' => $ids]);

        $states = [];

        foreach ((array) data_get($data, 'nodes', []) as $node) {
            $state = data_get($node, 'commits.nodes.0.commit.statusCheckRollup.state');
            $states[(string) data_get($node, 'id')] = is_string($state) ? (self::CHECKS[$state] ?? null) : null;
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $body = (array) $this->client()->post('graphql', [
            'query' => $query,
            'variables' => $variables,
        ])->throw()->json();

        // GraphQL answers 200 with an `errors` array, so failures have to be raised here.
        $errors = (array) data_get($body, 'errors.*.message');

        if ($errors !== []) {
            throw new RuntimeException(implode('; ', array_map('strval', $errors)));
        }

        return (array) data_get($body, 'data', []);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchItems(string $query): array
    {
        return self::items($this->client()->get('search/issues', self::searchParams($query)));
    }

    /**
     * The same search run for every query at once, keyed the way they came in.
     *
     * @param  array<string, string>  $queries
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function searchMany(array $queries): array
    {
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $key): mixed => $this->configure($pool->as($key))
                ->get('search/issues', self::searchParams($queries[$key])),
            array_combine(array_keys($queries), array_keys($queries)),
        ));

        return array_map(function (mixed $response): array {
            // A pool hands back the connection failure instead of throwing it.
            if ($response instanceof Throwable) {
                throw $response;
            }

            return self::items($response);
        }, $responses);
    }

    /**
     * @return array<string, mixed>
     */
    private static function searchParams(string $query): array
    {
        return [
            'q' => $query,
            'sort' => 'updated',
            'order' => 'desc',
            'per_page' => 100,
            // A parenthesised `repo:a OR repo:b` only parses with advanced search on.
            'advanced_search' => 'true',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function items(mixed $response): array
    {
        $items = $response instanceof Response ? $response->throw()->json('items') : null;

        /** @var array<int, array<string, mixed>> */
        return is_array($items) ? $items : [];
    }

    /**
     * Scope an issue search to the mapped repos, minus what's never actionable.
     *
     * @param  array<int, string>  $repoNames
     */
    public static function issueQuery(array $repoNames, string $query): string
    {
        $excluded = implode(' ', array_map(fn (string $label): string => "-label:{$label}", self::EXCLUDED_LABELS));

        return trim(self::scope($repoNames)." {$excluded} -type:Epic {$query}");
    }

    /**
     * Pull requests waiting on my review. No label or epic exclusions: if someone
     * asked for my review I want to see it, and `-is:draft` is already the "not ready
     * yet" filter for pull requests. `is:open` is load-bearing — without it the
     * request survives the merge and every pull request I was ever asked to review
     * comes back.
     *
     * @param  array<int, string>  $repoNames
     */
    public static function prQuery(array $repoNames): string
    {
        return self::scope($repoNames).' is:pr is:open review-requested:@me -is:draft';
    }

    /**
     * `$ignore` entries are either a bare number (any repo) or `owner/repo#number`.
     *
     * @param  array<int, string>  $ignore
     */
    public static function isIgnored(array $ignore, string $repo, int $number): bool
    {
        return in_array((string) $number, $ignore, true) || in_array("{$repo}#{$number}", $ignore, true);
    }

    /**
     * Multiple bare `repo:` qualifiers are ANDed by GitHub (always 0 hits), so they
     * must be ORed inside parens — which only parses with `advanced_search=true`.
     *
     * @param  array<int, string>  $repoNames
     */
    private static function scope(array $repoNames): string
    {
        return '('.implode(' OR ', array_map(fn (string $repo): string => "repo:{$repo}", $repoNames)).')';
    }

    private function client(): PendingRequest
    {
        return $this->configure(Http::createPendingRequest());
    }

    /** Shared by the plain client and by the requests a pool hands out. */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->baseUrl('https://api.github.com')
            ->withToken($this->token)
            ->acceptJson();
    }
}
