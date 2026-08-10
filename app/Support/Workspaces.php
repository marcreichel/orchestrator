<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Polyscope\Laravel\Facades\Polyscope;
use Polyscope\Laravel\Resources\Workspace;
use RuntimeException;
use Throwable;

class Workspaces
{
    /**
     * Workspaces belonging to the mapped repos, newest first (the API's own order).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $repoIds = array_values(Config::array('orchestrator.repos'));

        /** @var array<int, Workspace> $workspaces */
        $workspaces = Polyscope::workspaces();

        return array_values(array_map(
            fn (Workspace $workspace): array => self::row($workspace),
            array_filter($workspaces, fn (Workspace $workspace): bool => in_array($workspace->repoId, $repoIds, true)),
        ));
    }

    /**
     * Existing workspaces keyed by the URL of the issue or pull request they were started
     * for, newest per URL, so a list row can tell it has already been played.
     *
     * A broken lookup returns no matches instead of throwing: the issue and pull request
     * lists must survive a dead Polyscope, which surfaces the failure on its own section.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function byRef(): array
    {
        try {
            $workspaces = self::all();
        } catch (Throwable) {
            return [];
        }

        $keyed = [];

        foreach ($workspaces as $workspace) {
            if ($url = $workspace['ref']['url'] ?? null) {
                $keyed[$url] ??= $workspace;
            }
        }

        return $keyed;
    }

    /** @return array<string, mixed> */
    public static function forIssue(string $repo, int $number, string $url): array
    {
        return self::start(
            $repo,
            ['issue_url' => $url],
            sprintf(Config::string('orchestrator.prompts.issue'), $number),
        );
    }

    /** @return array<string, mixed> */
    public static function forPullRequest(string $repo, int $number, string $url): array
    {
        return self::start(
            $repo,
            ['pull_request_url' => $url],
            sprintf(Config::string('orchestrator.prompts.pull_request'), $number),
        );
    }

    /**
     * The prompt goes out as a message rather than as the create call's `prompt`, so the
     * workspace has the issue or pull request checked out before it runs.
     *
     * @param  array<string, string>  $link  `issue_url` or `pull_request_url`.
     * @return array<string, mixed>
     */
    private static function start(string $repo, array $link, string $prompt): array
    {
        $workspace = Polyscope::createWorkspace(['repository_id' => self::repositoryId($repo)] + $link);

        // Via client(): the facade only declares a subset of the SDK's methods.
        Polyscope::client()->sendWorkspaceMessage((string) $workspace->id, $prompt);

        return self::row($workspace);
    }

    /**
     * The list endpoint returns thin workspaces — `repository`, `agent` and `stats`
     * come back null there, so a row can only use what a *listed* workspace also has.
     *
     * @return array<string, mixed>
     */
    private static function row(Workspace $workspace): array
    {
        // Polyscope timestamps are UTC but carry no zone ("2026-08-08 11:18:15"), which
        // Carbon would otherwise read in the application's timezone. Formatted here
        // rather than in the view: Livewire doesn't rehydrate a Carbon nested in an
        // array property, so it would come back as a string on the next round-trip.
        $createdAt = $workspace->createdAt !== null
            ? Carbon::parse($workspace->createdAt, 'UTC')->timezone(Config::string('app.timezone'))
            : null;

        return [
            'branch' => $workspace->branch,
            'status' => $workspace->status,
            // What a played row shows, whether the workspace was just created or already existed.
            'label' => "✓ {$workspace->branch} · {$workspace->status}",
            'previewUrl' => $workspace->previewUrl,
            'age' => $createdAt?->diffForHumans(short: true),
            'createdAt' => $createdAt?->toDayDateTimeString(),
            'ref' => match (true) {
                $workspace->issueNumber !== null => [
                    'number' => $workspace->issueNumber,
                    'title' => $workspace->issueTitle,
                    'url' => $workspace->issueUrl,
                ],
                $workspace->prNumber !== null => [
                    'number' => $workspace->prNumber,
                    'title' => $workspace->prTitle,
                    'url' => $workspace->prUrl,
                ],
                default => null,
            },
        ];
    }

    private static function repositoryId(string $repo): string
    {
        $id = Config::array('orchestrator.repos')[$repo] ?? null;

        if (! is_string($id)) {
            throw new RuntimeException("No Polyscope repository mapped for {$repo}");
        }

        return $id;
    }
}
