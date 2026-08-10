<?php

use App\Support\Board;
use App\Support\GitHub;
use App\Support\Workspaces;
use Illuminate\Support\Facades\Config;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $assigned = [];

    /** @var array<int, array<string, mixed>> */
    public array $other = [];

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

    public function mount(): void
    {
        $this->load();
    }

    #[On('reload')]
    public function load(): void
    {
        $this->played = [];

        try {
            $board = app(Board::class);
            $github = app(GitHub::class);

            $this->assigned = $board->unblocked($github->issues('assignee:@me is:issue is:open'));

            $query = Config::get('orchestrator.other_issues');
            $other = is_string($query) && $query !== '' ? $board->unblocked($github->issues($query)) : [];

            // The second list is free-form, so it can overlap with what's already mine.
            $mine = array_column($this->assigned, 'id');
            $this->other = array_values(array_filter($other, fn (array $issue): bool => ! in_array($issue['id'], $mine, true)));

            $this->claim = array_fill_keys([...$mine, ...array_column($this->other, 'id')], true);
            $this->error = null;

            // An issue that already has a workspace is shown as played: no ▶, no checkbox.
            $workspaces = Workspaces::byRef();

            foreach ([...$this->assigned, ...$this->other] as $issue) {
                if ($workspace = $workspaces[$issue['url']] ?? null) {
                    $this->played[$issue['id']] = ['status' => $workspace['label']];
                }
            }
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->assigned = $this->other = [];
        }
    }

    public function play(string $id): void
    {
        $issue = collect([...$this->assigned, ...$this->other])->firstWhere('id', $id);

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

        unset($this->played[$id], $this->claim[$id]);
    }
};
?>

@placeholder
    <div class="contents">
        <x-section title="Assigned to me" empty="Loading…" />
        <x-section title="Other issues" empty="Loading…" />
    </div>
@endplaceholder

{{-- `contents` so both sections are grid items of the page, not of this component. --}}
<div class="contents">
    @foreach ([['Assigned to me', $assigned], ['Other issues', $other]] as [$title, $issues])
        <x-section :title="$title" :count="count($issues)" :empty="$error">
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
