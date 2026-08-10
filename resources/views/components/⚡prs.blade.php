<?php

use App\Support\GitHub;
use App\Support\Workspaces;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $pullRequests = [];

    /**
     * What starting a review did, per pull request number.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $reviewed = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    #[On('reload')]
    public function load(): void
    {
        $this->reviewed = [];

        try {
            $this->pullRequests = app(GitHub::class)->pullRequests();
            $this->error = null;

            // A pull request that already has a review workspace is shown as reviewed: no ▶.
            $workspaces = Workspaces::byRef();

            foreach ($this->pullRequests as $pullRequest) {
                if ($workspace = $workspaces[$pullRequest['url']] ?? null) {
                    $this->reviewed[$pullRequest['number']] = ['status' => $workspace['label']];
                }
            }
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->pullRequests = [];
        }
    }

    public function review(int $number): void
    {
        $pullRequest = collect($this->pullRequests)->firstWhere('number', $number);

        if ($pullRequest === null) {
            return;
        }

        try {
            $workspace = Workspaces::forPullRequest((string) $pullRequest['repo'], $number, (string) $pullRequest['url']);
        } catch (Throwable $exception) {
            $this->reviewed[$number] = ['error' => $exception->getMessage()];

            return;
        }

        $this->dispatch('workspace-created', workspace: $workspace)->to('workspaces');

        // The row stays put — the review still has to be written.
        $this->reviewed[$number] = ['status' => $workspace['label']];
    }
};
?>

@placeholder
    <div>
        <x-section title="Review requested" empty="Loading…" />
    </div>
@endplaceholder

<div>
    <x-section title="Review requested" :count="count($pullRequests)" :empty="$error">
        @foreach ($pullRequests as $pullRequest)
            @php($reviewed = $reviewed[$pullRequest['number']] ?? [])
            <li wire:key="{{ $pullRequest['number'] }}" class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 border-t border-line py-2">
                <span class="text-muted tabular-nums">#{{ $pullRequest['number'] }}</span>

                {{-- Titles are long and the author/check chips matter more — one line, ellipsis, full text on hover. --}}
                <a href="{{ $pullRequest['url'] }}" target="_blank" title="{{ $pullRequest['title'] }}"
                   class="min-w-0 flex-1 truncate hover:underline">{{ $pullRequest['title'] }}</a>

                <span class="text-sm text-muted">{{ '@'.$pullRequest['author'] }}</span>

                @if ($pullRequest['check'])
                    {{-- Dot centres on its own label, not on the row — rows grow when titles wrap. --}}
                    <span class="inline-flex items-center gap-1.5 text-sm text-muted">
                        <x-dot :state="$pullRequest['check']" />{{ $pullRequest['check'] }}
                    </span>
                @endif

                @unless (isset($reviewed['status']))
                    <button wire:click="review({{ $pullRequest['number'] }})" wire:loading.attr="disabled" title="Review"
                            class="cursor-pointer rounded-md bg-chip px-2.5 py-0.5 hover:bg-chip-hover">▶</button>
                @endunless

                <span wire:loading wire:target="review({{ $pullRequest['number'] }})" class="text-sm text-mint">…</span>

                @isset($reviewed['status'])
                    <span class="text-sm text-mint">{{ $reviewed['status'] }}</span>
                @endisset

                @if ($reviewed['error'] ?? null)
                    <span class="text-sm text-rose">✗ {{ $reviewed['error'] }}</span>
                @endif
            </li>
        @endforeach
    </x-section>
</div>
