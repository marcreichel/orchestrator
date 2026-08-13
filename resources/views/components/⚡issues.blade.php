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
// only ever arrive together — as slow as the slowest of GitHub and Polyscope.
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.issues';

    /** @var array<int, array<string, mixed>> */
    public array $assigned = [];

    /** @var array<int, array<string, mixed>> */
    public array $other = [];

    /** @var array<int, array<string, mixed>> */
    public array $bugs = [];

    /** Claim checkbox state, per issue node id. */
    /** @var array<string, bool> */
    public array $claim = [];

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
        $this->played = [];

        try {
            $board = app(Board::class);

            // One round-trip for the three searches — a list whose query is unset is
            // never asked for and comes back empty.
            $query = function (string $key): ?string {
                $query = Config::get("orchestrator.{$key}");

                return is_string($query) && $query !== '' ? $query : null;
            };

            $found = app(GitHub::class)->issues(array_filter([
                'assigned' => 'assignee:@me is:issue is:open',
                'other' => $query('other_issues'),
                'bugs' => $query('unassigned_bugs'),
            ])) + ['assigned' => [], 'other' => [], 'bugs' => []];

            // The extra lists are free-form, so each can overlap with the ones before it.
            $assigned = $found['assigned'];
            $other = self::reject($found['other'], $assigned);
            $bugs = self::reject($found['bugs'], [...$assigned, ...$other]);

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

            $this->claim = array_fill_keys(array_column($this->issues(), 'id'), true);
            $this->error = null;

            // An issue that already has a workspace is shown as played: no ▶, no checkbox.
            $workspaces = Workspaces::byRef();

            foreach ($this->issues() as $issue) {
                if ($workspace = $workspaces[$issue['url']] ?? null) {
                    $this->played[$issue['id']] = ['status' => $workspace['label']];
                }
            }

            Cache::forever(self::CACHE, $this->only('assigned', 'other', 'bugs', 'claim', 'played'));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->assigned = $this->other = $this->bugs = [];
        }
    }

    public function play(string $id): void
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

        if ($this->claim[$id] ?? false) {
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
            'drop' => ($this->claim[$id] ?? false) && $error === null,
        ];
    }

    public function dismiss(string $id): void
    {
        $keep = fn (array $issue): bool => $issue['id'] !== $id;

        $this->assigned = array_values(array_filter($this->assigned, $keep));
        $this->other = array_values(array_filter($this->other, $keep));
        $this->bugs = array_values(array_filter($this->bugs, $keep));

        unset($this->played[$id], $this->claim[$id]);
    }

    /**
     * Every listed issue, in section order — what play(), the claim state and the
     * workspace lookup all work on.
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

{{-- `contents` so both sections are grid items of the page, not of this component.
     300s, not 5m: wire:poll only parses `ms` and `s`, so `.5m` silently polls every 2s. --}}
<div class="contents" wire:init="load" wire:poll.300s="load">
    {{-- array_filter, so a list whose query is unset renders no section at all. --}}
    @foreach (array_filter([
        ['Assigned to me', $assigned],
        config('orchestrator.other_issues') ? ['Other issues', $other] : null,
        config('orchestrator.unassigned_bugs') ? ['Unassigned bugs', $bugs] : null,
    ]) as [$title, $issues])
        <x-section :title="$title" :count="count($issues)" :empty="$error" spinner>
            @foreach ($issues as $issue)
                @php($played = $played[$issue['id']] ?? [])
                <li wire:key="{{ $issue['id'] }}" class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 border-t border-line py-2">
                    <span class="text-muted tabular-nums">#{{ $issue['number'] }}</span>

                    {{-- min-w-0 so long titles wrap instead of forcing the row wider than its column. --}}
                    <a href="{{ $issue['url'] }}" target="_blank" class="min-w-0 flex-1 [overflow-wrap:anywhere] hover:underline">{{ $issue['title'] }}</a>

                    {{-- GitHub label colours can be near-black, so tint the background instead of
                         colouring the text — readable whatever colour the label happens to be. --}}
                    @foreach (array_slice($issue['labels'], 0, 3) as $label)
                        <span class="rounded-full border px-1.5 py-px text-xs whitespace-nowrap"
                              style="background:#{{ $label['color'] }}33;border-color:#{{ $label['color'] }}88">{{ $label['name'] }}</span>
                    @endforeach

                    @if (count($issue['labels']) > 3)
                        <span class="text-xs text-muted" title="{{ implode(', ', array_column(array_slice($issue['labels'], 3), 'name')) }}">
                            +{{ count($issue['labels']) - 3 }}
                        </span>
                    @endif

                    @unless (isset($played['status']))
                        <input type="checkbox" wire:model="claim.{{ $issue['id'] }}" class="accent-mint"
                               title="Assign to me and move to {{ config('orchestrator.board.started') }}">
                        <button wire:click="play('{{ $issue['id'] }}')" wire:loading.attr="disabled"
                                class="cursor-pointer rounded-md bg-chip px-2.5 py-0.5 hover:bg-chip-hover">▶</button>
                    @endunless

                    <span wire:loading wire:target="play('{{ $issue['id'] }}')" class="text-sm text-mint">…</span>

                    @isset($played['status'])
                        <span class="text-sm text-mint">{{ $played['status'] }}</span>
                    @endisset

                    @if ($played['error'] ?? null)
                        <span class="text-sm text-rose">✗ {{ $played['error'] }}</span>
                    @endif

                    @if ($played['drop'] ?? false)
                        <span x-data x-init="setTimeout(() => $wire.dismiss('{{ $issue['id'] }}'), 1500)"></span>
                    @endif
                </li>
            @endforeach
        </x-section>
    @endforeach
</div>
