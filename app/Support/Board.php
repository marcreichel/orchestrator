<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class Board
{
    public function __construct(private readonly GitHub $github) {}

    /**
     * The project id, the Status field with its ordered options, and my own node id.
     * Board columns change maybe twice a year, so this is cached — `php artisan
     * cache:clear` picks up a rename.
     *
     * @return array{project: string, field: string, options: array<int, string>, started: string, viewer: string}
     */
    public function meta(): array
    {
        /** @var array{project: string, field: string, options: array<int, string>, started: string, viewer: string} */
        return Cache::remember('orchestrator.board', now()->addDay(), function (): array {
            $name = Config::string('orchestrator.board.name');

            $data = $this->github->graphql(<<<'GRAPHQL'
                query ($org: String!, $board: String!) {
                  viewer { id }
                  organization(login: $org) {
                    projectsV2(first: 20, query: $board) {
                      nodes {
                        id title
                        field(name: "Status") {
                          ... on ProjectV2SingleSelectField { id options { id name } }
                        }
                      }
                    }
                  }
                }
                GRAPHQL, ['org' => Config::string('orchestrator.board.org'), 'board' => $name]);

            $board = collect((array) data_get($data, 'organization.projectsV2.nodes', []))
                ->firstWhere('title', $name);

            if ($board === null) {
                throw new RuntimeException("No \"{$name}\" project found — does the token have the read:project scope?");
            }

            $options = (array) data_get($board, 'field.options', []);
            $names = array_map(fn (mixed $option): string => (string) data_get($option, 'name'), $options);

            $startedName = Config::string('orchestrator.board.started');
            $started = collect($options)->firstWhere('name', $startedName);

            if ($started === null) {
                throw new RuntimeException("Board has no \"{$startedName}\" status — got ".implode(', ', $names));
            }

            return [
                'project' => (string) data_get($board, 'id'),
                'field' => (string) data_get($board, 'field.id'),
                'options' => array_values($names),
                'started' => (string) data_get($started, 'id'),
                'viewer' => (string) data_get($data, 'viewer.id'),
            ];
        });
    }

    /**
     * Drop the issues sitting in a blocked column. Not being on the board at all
     * never blocks.
     *
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    public function unblocked(array $issues): array
    {
        if ($issues === []) {
            return [];
        }

        $blocked = self::blockedStatuses($this->meta()['options'], Config::string('orchestrator.board.cutoff'));
        $statuses = $this->github->boardStatuses(
            array_map(fn (array $issue): string => (string) $issue['id'], $issues),
            Config::string('orchestrator.board.name'),
        );

        return array_values(array_filter(
            $issues,
            fn (array $issue): bool => ! in_array($statuses[(string) $issue['id']] ?? null, $blocked, true),
        ));
    }

    /**
     * Board columns are ordered, so "from `cutoff` onwards" is just a slice — a new
     * column added past the cutoff is excluded automatically.
     *
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    public static function blockedStatuses(array $options, string $cutoff): array
    {
        $index = array_search($cutoff, $options, true);

        if ($index === false) {
            throw new RuntimeException("Board has no \"{$cutoff}\" status — got ".implode(', ', $options));
        }

        return array_slice($options, $index);
    }

    /** Make me the only assignee and move the issue to the "started" column. */
    public function claim(string $issueId): void
    {
        $meta = $this->meta();

        // assigneeIds replaces the whole list, so this assigns me and drops everyone else.
        // addProjectV2ItemById returns the existing item if the issue is already on the board.
        $data = $this->github->graphql(<<<'GRAPHQL'
            mutation ($issue: ID!, $me: ID!, $project: ID!) {
              updateIssue(input: { id: $issue, assigneeIds: [$me] }) { clientMutationId }
              added: addProjectV2ItemById(input: { projectId: $project, contentId: $issue }) {
                item { id }
              }
            }
            GRAPHQL, ['issue' => $issueId, 'me' => $meta['viewer'], 'project' => $meta['project']]);

        $this->github->graphql(<<<'GRAPHQL'
            mutation ($project: ID!, $item: ID!, $field: ID!, $option: String!) {
              updateProjectV2ItemFieldValue(input: {
                projectId: $project, itemId: $item, fieldId: $field
                value: { singleSelectOptionId: $option }
              }) { clientMutationId }
            }
            GRAPHQL, [
            'project' => $meta['project'],
            'item' => (string) data_get($data, 'added.item.id'),
            'field' => $meta['field'],
            'option' => $meta['started'],
        ]);
    }
}
