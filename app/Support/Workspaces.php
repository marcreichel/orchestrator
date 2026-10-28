<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
        // All three lists want this on the same refresh — the issue and pull request ones
        // to mark played rows, the workspace one to render them — and they load in
        // separate requests, so only a cache can collapse that into one fetch. Just long
        // enough to cover the fan-out; a failure isn't cached at all.
        //
        // Locked, or the cache never gets the chance: the three requests are isolated and
        // go out together, so on a cold cache all three miss it in the same instant and
        // each fetches the whole list. The lock lets the first through and the other two
        // wait for the entry it writes.
        /** @var array<int, array<string, mixed>> */
        return Cache::lock('orchestrator.workspaces.fetch', 30)->block(15, fn (): array => Cache::remember(
            'orchestrator.workspaces.all',
            now()->addSeconds(15),
            function (): array {
                $repoIds = array_values(Config::array('orchestrator.repos'));

                /** @var array<int, array<string, mixed>> $worktrees */
                $worktrees = self::orca(['worktree', 'ps', '--limit', '500'])['worktrees'] ?? [];

                // Orca lists the main checkout and archived worktrees too; neither is a
                // workspace this app started.
                $mine = array_filter($worktrees, fn (array $worktree): bool => in_array($worktree['repoId'], $repoIds, true)
                    && ! $worktree['isMainWorktree']
                    && ! $worktree['isArchived']);

                usort($mine, fn (array $a, array $b): int => ($b['createdAt'] ?? 0) <=> ($a['createdAt'] ?? 0));

                return array_map(fn (array $worktree): array => self::row($worktree), $mine);
            },
        ));
    }

    /**
     * Existing workspaces keyed by the URL of the issue or pull request they were started
     * for, newest per URL, so a list row can tell it has already been played.
     *
     * A broken lookup returns null instead of throwing: the issue and pull request lists
     * must survive a dead Orca, which surfaces the failure on its own section. Null
     * rather than no matches, so a caller can tell "nothing has been played" from "no
     * idea what has" — the second must not be cached over the first.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public static function byRef(): ?array
    {
        try {
            $workspaces = self::all();
        } catch (Throwable) {
            return null;
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
            "issue-{$number}",
            $url,
            sprintf(Config::string('orchestrator.prompts.issue'), $number),
        );
    }

    /** @return array<string, mixed> */
    public static function forPullRequest(string $repo, int $number, string $url): array
    {
        return self::start(
            $repo,
            "pr-{$number}",
            $url,
            sprintf(Config::string('orchestrator.prompts.pull_request'), $number),
        );
    }

    /** Brings the workspace's first terminal to the front in Orca. */
    public static function open(string $id): void
    {
        /** @var array<int, array<string, mixed>> $terminals */
        $terminals = self::orca(['terminal', 'list', '--worktree', "id:{$id}"])['terminals'] ?? [];

        if ($terminals === []) {
            throw new RuntimeException('This workspace has no live terminal');
        }

        self::orca(['terminal', 'switch', '--terminal', $terminals[0]['handle']]);
        self::focusOrca();
    }

    /** Opens the workspace's changed files as diff tabs and brings Orca to them. */
    public static function showChanges(string $id): void
    {
        self::orca(['file', 'open-changed', '--mode', 'diff', '--focus', '--worktree', "id:{$id}"]);
        self::focusOrca();
    }

    /** Removes the worktree from Orca and git; the branch stays. */
    public static function remove(string $id): void
    {
        self::orca(['worktree', 'rm', '--worktree', "id:{$id}"], 120);
        Cache::forget('orchestrator.workspaces.all');
    }

    /**
     * The CLI switches tabs but never raises the window, so the app itself is activated.
     * macOS only; a failure here must not undo the switch that already happened.
     */
    private static function focusOrca(): void
    {
        Process::run(['open', '-a', 'Orca']);
    }

    /**
     * Every Orca repository with its id, for filling in `orchestrator.repos`.
     *
     * @return array<int, array{id: string, name: string, path: string|null}>
     */
    public static function repositories(): array
    {
        /** @var array<int, array<string, mixed>> $repos */
        $repos = self::orca(['repo', 'list'])['repos'] ?? [];

        return array_map(fn (array $repo): array => [
            'id' => $repo['id'],
            'name' => $repo['displayName'],
            'path' => $repo['path'] ?? null,
        ], $repos);
    }

    /**
     * One call: Orca creates the worktree with the issue or pull request attached, then
     * starts the agent on the prompt.
     *
     * @return array<string, mixed>
     */
    private static function start(string $repo, string $name, string $url, string $prompt): array
    {
        $result = self::orca([
            'worktree', 'create',
            '--repo', 'id:'.self::repositoryId($repo),
            '--name', $name,
            '--no-parent',
            '--reference', $url,
            '--agent', Config::string('orchestrator.agent'),
            '--prompt', $prompt,
        ], 120);

        /** @var array<string, mixed> $worktree */
        $worktree = $result['worktree'];

        return self::row($worktree);
    }

    /**
     * Runs the `orca` CLI and returns its `result`. Orca reports failures as `ok: false`
     * and as a non-zero exit, so either one raises.
     *
     * @param  array<int, string>  $arguments
     * @return array<string, mixed>
     */
    private static function orca(array $arguments, int $timeout = 30): array
    {
        $process = Process::timeout($timeout)->run(['orca', ...$arguments, '--json']);

        // The JSON is the last thing on stdout; a shell profile can print before it.
        $body = json_decode(Str::of($process->output())->after('{')->prepend('{')->toString(), true);

        if ($process->failed() || ! is_array($body) || ! ($body['ok'] ?? false)) {
            throw new RuntimeException($body['error']['message'] ?? (trim($process->errorOutput()) ?: 'Orca did not answer'));
        }

        return $body['result'];
    }

    /**
     * @param  array<string, mixed>  $worktree
     * @return array<string, mixed>
     */
    private static function row(array $worktree): array
    {
        // Formatted here rather than in the view: Livewire doesn't rehydrate a Carbon
        // nested in an array property, so it would come back as a string on the next
        // round-trip.
        $createdAt = isset($worktree['createdAt'])
            ? Carbon::createFromTimestampMs($worktree['createdAt'])->timezone(Config::string('app.timezone'))
            : null;

        $branch = Str::after($worktree['branch'], 'refs/heads/');
        $status = $worktree['workspaceStatus'];

        // Orca doesn't carry the title of what a worktree is linked to, only its number and URL.
        $linked = collect($worktree['linkedItems'] ?? [])->first(fn (array $item): bool => isset($item['url']));

        return [
            // What a row is keyed on — a branch can be shared, and the position shifts
            // every time a freshly played workspace is put on the front.
            'id' => $worktree['worktreeId'] ?? $worktree['id'],
            'branch' => $branch,
            'status' => $status,
            // What the agent in it is doing: working, active or inactive. A fresh create
            // answers without it, and one has just been started then.
            'agent' => $worktree['status'] ?? 'working',
            // What a played row shows, whether the workspace was just created or already existed.
            'label' => "✓ {$branch} · {$status}",
            'age' => $createdAt?->diffForHumans(short: true),
            'createdAt' => $createdAt?->toDayDateTimeString(),
            'ref' => $linked ? [
                'number' => $linked['number'] ?? null,
                'title' => null,
                'url' => $linked['url'],
            ] : null,
        ];
    }

    private static function repositoryId(string $repo): string
    {
        $id = Config::array('orchestrator.repos')[$repo] ?? null;

        if (! is_string($id)) {
            throw new RuntimeException("No Orca repository mapped for {$repo}");
        }

        return $id;
    }
}
