<?php

use App\Support\Board;
use App\Support\GitHub;
use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

// Isolated, or Livewire bundles the three lists' loads into one request and they can
// only ever arrive together — as slow as the slowest of GitHub and Orca.
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.issues';

    /** @var array<int, array<string, mixed>> */
    public array $assigned = [];

    /** @var array<int, array<string, mixed>> */
    public array $other = [];

    /** @var array<int, array<string, mixed>> */
    public array $bugs = [];

    /**
     * Whether GitHub had more matches than the one page a search asks for, per list.
     *
     * @var array<string, bool>
     */
    public array $truncated = [];

    /**
     * What playing an issue did: `status` once the workspace exists, `error` if
     * anything failed, `drop` when the row should remove itself.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $played = [];

    public ?string $error = null;

    /**
     * The last successful lists, rendered with the page itself; `wire:init` then
     * fetches the live ones in the background. `$error` doubles as the empty text,
     * so a cold cache says what it is waiting for.
     */
    public function mount(): void
    {
        $this->fill(Cache::get(self::CACHE) ?? ['error' => 'Loading…']);
    }

    #[On('reload')]
    public function load(): void
    {
        try {
            $board = app(Board::class);

            // One round-trip for the three searches — a list whose query is unset is
            // never asked for and comes back empty.
            $query = function (string $key): ?string {
                $query = Config::get("orchestrator.{$key}");

                return is_string($query) && $query !== '' ? $query : null;
            };

            $missing = ['items' => [], 'truncated' => false];

            $found = app(GitHub::class)->issues(array_filter([
                'assigned' => 'assignee:@me is:issue is:open',
                'other' => $query('other_issues'),
                'bugs' => $query('unassigned_bugs'),
            ])) + ['assigned' => $missing, 'other' => $missing, 'bugs' => $missing];

            // The extra lists are free-form, so each can overlap with the ones before it.
            $assigned = $found['assigned']['items'];
            $other = self::reject($found['other']['items'], $assigned);
            $bugs = self::reject($found['bugs']['items'], [...$assigned, ...$other]);

            $this->truncated = array_map(fn (array $page): bool => $page['truncated'], $found);

            // One board lookup for all three lists, after the overlaps are gone — the
            // status of an issue that isn't going to be shown is nobody's business.
            $kept = array_column($board->unblocked([...$assigned, ...$other, ...$bugs]), 'id');
            $keep = fn (array $issues): array => array_values(array_filter(
                $issues,
                fn (array $issue): bool => in_array($issue['id'], $kept, true),
            ));

            $this->assigned = $keep($assigned);
            $this->other = $keep($other);
            $this->bugs = $keep($bugs);

            $ids = array_flip(array_column($this->issues(), 'id'));
            $this->error = null;

            // An issue that already has a workspace is shown as played: no ▶, no checkbox.
            // A dead Orca means "no idea", not "nothing has been played", so a failed
            // lookup keeps the ✓ marks the last good one left rather than caching a run
            // that has lost every one of them.
            $workspaces = Workspaces::byRef();
            $this->played = $workspaces === null ? array_intersect_key($this->played, $ids) : [];

            foreach ($this->issues() as $issue) {
                if ($workspace = $workspaces[$issue['url']] ?? null) {
                    $this->played[$issue['id']] = ['status' => $workspace['label']];
                }
            }

            Cache::forever(self::CACHE, $this->only('assigned', 'other', 'bugs', 'truncated', 'played'));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->assigned = $this->other = $this->bugs = [];
        }

        // Failed or not, the list is as fresh as it gets — the header's "Updated" time.
        $this->dispatch('refreshed');
    }

    /**
     * Start a workspace for the issue and, unless `$claim` is off (⌥-click), assign it
     * to me and move it to the started column.
     */
    public function play(string $id, bool $claim = true): void
    {
        $issue = collect($this->issues())->firstWhere('id', $id);

        if ($issue === null) {
            return;
        }

        try {
            $workspace = Workspaces::forIssue((string) $issue['repo'], (int) $issue['number'], (string) $issue['url']);
        } catch (Throwable $exception) {
            // No workspace, so the row keeps its ▶ and can be played again.
            $this->played[$id] = ['error' => $exception->getMessage()];

            return;
        }

        $this->dispatch('workspace-created', workspace: $workspace)->to('workspaces');

        // Claiming happens after the workspace, so a failed play leaves the issue untouched.
        $error = null;

        if ($claim) {
            try {
                app(Board::class)->claim($id);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $this->played[$id] = [
            'status' => $workspace['label'],
            'error' => $error,
            // A claimed issue is in flight now — the row drops itself once the ✓ is readable.
            'drop' => $claim && $error === null,
        ];
    }

    public function dismiss(string $id): void
    {
        $keep = fn (array $issue): bool => $issue['id'] !== $id;

        $this->assigned = array_values(array_filter($this->assigned, $keep));
        $this->other = array_values(array_filter($this->other, $keep));
        $this->bugs = array_values(array_filter($this->bugs, $keep));

        unset($this->played[$id]);
    }

    /**
     * Every listed issue, in section order — what play() and the workspace lookup
     * work on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function issues(): array
    {
        return [...$this->assigned, ...$this->other, ...$this->bugs];
    }

    /**
     * $issues minus everything an earlier list already shows.
     *
     * @param  array<int, array<string, mixed>>  $issues
     * @param  array<int, array<string, mixed>>  $seen
     * @return array<int, array<string, mixed>>
     */
    private static function reject(array $issues, array $seen): array
    {
        $ids = array_column($seen, 'id');

        return array_values(array_filter($issues, fn (array $issue): bool => ! in_array($issue['id'], $ids, true)));
    }
};
?>

{{-- 300s, not 5m: wire:poll only parses `ms` and `s`, so `.5m` silently polls every 2s. --}}
<div wire:init="load" wire:poll.300s="load">
    <x-lane title="Issues" :count="count($assigned) + count($other) + count($bugs)" :truncated="in_array(true, $truncated, true)" spinner>
        {{-- array_filter, so a list whose query is unset renders no section at all. --}}
        @foreach (array_filter([
            ['Assigned to me', $assigned, 'assigned', 'Nothing is assigned to you.'],
            config('orchestrator.other_issues') ? ['Other issues', $other, 'other', 'No other issues match.'] : null,
            config('orchestrator.unassigned_bugs') ? ['Unassigned bugs', $bugs, 'bugs', 'No unassigned bugs.'] : null,
        ]) as [$title, $issues, $key, $empty])
            {{-- Only my own issues start open; the extra lists fold away so they're reachable
                 without scrolling past all of them. --}}
            <x-section :title="$title" :count="count($issues)" :truncated="$truncated[$key] ?? false" :error="$error" :empty="$empty"
                       :collapsible="$key !== 'assigned'">
                @foreach ($issues as $issue)
                    @php($played = $played[$issue['id']] ?? [])
                    {{-- Fixed number column, so titles line up whatever the number's width. --}}
                    <li wire:key="{{ $issue['id'] }}" class="row group/row grid grid-cols-[3.25rem_minmax(0,1fr)_auto] items-baseline gap-x-3 py-2.5">
                        <span class="text-right font-mono text-xs text-muted tabular-nums">#{{ $issue['number'] }}</span>

                        <div class="min-w-0 text-pretty">
                            <a href="{{ $issue['url'] }}" target="_blank" class="[overflow-wrap:anywhere] hover:underline">{{ $issue['title'] }}</a>

                            @if ($issue['labels'] || $played)
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    {{-- Neutral chips with the label's colour as a dot, so the labels stay
                                         quieter than the titles whatever colour they happen to be.
                                         Fixed height: an emoji in the name would otherwise make its chip taller. --}}
                                    @foreach (array_slice($issue['labels'], 0, 3) as $label)
                                        <span class="inline-flex h-[18px] items-center gap-1 rounded-full bg-white/8 px-1.5 text-[11px] whitespace-nowrap text-muted">
                                            <span class="label-dot size-1.5 shrink-0 rounded-full" style="--label:#{{ $label['color'] }}"></span>{{ $label['name'] }}
                                        </span>
                                    @endforeach

                                    @if (count($issue['labels']) > 3)
                                        <span class="text-[11px] text-muted" title="{{ implode(', ', array_column(array_slice($issue['labels'], 3), 'name')) }}">
                                            +{{ count($issue['labels']) - 3 }}
                                        </span>
                                    @endif

                                    @isset($played['status'])
                                        <span class="font-mono text-xs text-mint">{{ $played['status'] }}</span>
                                    @endisset

                                    @if ($played['error'] ?? null)
                                        <span role="alert" class="text-xs text-rose">✗ {{ $played['error'] }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="-mt-[3px] flex items-center gap-1.5 self-start">
                            {{-- aria-label, not just title: a bare ▶ has no reliable accessible name.
                                 Muted until its row is hovered, so the lane's mint is left for
                                 state. ⌥-click passes claim=false; the target lists both calls,
                                 since a parameterised target only matches its exact arguments. --}}
                            @unless (isset($played['status']))
                                <button wire:click="play('{{ $issue['id'] }}', ! $event.altKey)" wire:loading.attr="disabled"
                                        wire:target="play('{{ $issue['id'] }}', true), play('{{ $issue['id'] }}', false)"
                                        aria-label="Play issue #{{ $issue['number'] }}"
                                        title="Play — assigns it to you and moves it to {{ config('orchestrator.board.started') }}. ⌥-click to play without claiming."
                                        class="icon-btn group-hover/row:text-mint hover:text-mint focus-visible:text-mint"><x-icon name="play" class="size-3.5" /></button>
                            @endunless

                            <span wire:loading wire:target="play('{{ $issue['id'] }}', true), play('{{ $issue['id'] }}', false)" role="status" aria-label="Starting a workspace"
                                  class="size-3 animate-spin rounded-full border border-line border-t-mint"></span>
                        </div>

                        {{-- Marks the row leaving so it collapses out, then drops it once the ✓ has
                             been readable for a beat. --}}
                        @if ($played['drop'] ?? false)
                            <span x-data x-init="setTimeout(() => { $el.closest('li').classList.add('leaving'); setTimeout(() => $wire.dismiss('{{ $issue['id'] }}'), 250) }, 1250)"></span>
                        @endif
                    </li>
                @endforeach
            </x-section>
        @endforeach
    </x-lane>
</div>
