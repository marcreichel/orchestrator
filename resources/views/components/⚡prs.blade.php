<?php

use App\Support\GitHub;
use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Isolated so this list loads in its own request — see the issues component. */
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.prs';

    /** @var array<int, array<string, mixed>> */
    public array $pullRequests = [];

    /** Whether GitHub had more matches than the one page the search asks for. */
    public bool $truncated = false;

    /**
     * What starting a review did, per pull request number.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $reviewed = [];

    /** Pull requests I reviewed in the last seven days; null until a lookup has worked. */
    public ?int $reviewedThisWeek = null;

    public bool $reviewedTruncated = false;

    public ?string $error = null;

    /** Cached list first, live one via `wire:init` — see the issues component. */
    public function mount(): void
    {
        $this->fill(Cache::get(self::CACHE) ?? ['error' => 'Loading…']);
    }

    #[On('reload')]
    public function load(): void
    {
        try {
            ['items' => $this->pullRequests, 'truncated' => $this->truncated] = app(GitHub::class)->pullRequests();
            $this->error = null;

            // A pull request that already has a review workspace is shown as reviewed: no ▶.
            // A dead Orca means "no idea", not "nothing has been reviewed" — see the
            // issues component.
            $numbers = array_flip(array_column($this->pullRequests, 'number'));
            $workspaces = Workspaces::byRef();
            $this->reviewed = $workspaces === null ? array_intersect_key($this->reviewed, $numbers) : [];

            foreach ($this->pullRequests as $pullRequest) {
                if ($workspace = $workspaces[$pullRequest['url']] ?? null) {
                    $this->reviewed[$pullRequest['number']] = ['status' => $workspace['label']];
                }
            }

            // The stat is a footnote: if it fails the last number stays and the list is unaffected.
            try {
                ['count' => $this->reviewedThisWeek, 'truncated' => $this->reviewedTruncated] = app(GitHub::class)->reviewedThisWeek();
            } catch (Throwable) {
            }

            Cache::forever(self::CACHE, $this->only('pullRequests', 'truncated', 'reviewed', 'reviewedThisWeek', 'reviewedTruncated'));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->pullRequests = [];
        }

        $this->dispatch('refreshed');
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

{{-- 300s, not 5m — see the issues component. --}}
{{-- Flex column so the stat can sit at the foot of the panel even when the list is short. --}}
<div wire:init="load" wire:poll.300s="load" class="panel flex min-h-0 flex-1 flex-col pb-2 xl:overflow-y-auto xl:overscroll-contain xl:scroll-fade xl:[--lane-top:0px]">
    <x-lane class="flex-1" title="Review requested" :count="count($pullRequests)" :truncated="$truncated" spinner>
        <x-section :count="count($pullRequests)" :error="$error" empty="No reviews waiting for you.">
            @foreach ($pullRequests as $pullRequest)
                @php($reviewed = $reviewed[$pullRequest['number']] ?? [])
                {{-- Titles often lead with the issue they close ("#123 fix: …"). Next to the PR's
                     own number that reads as two ids, so the issue moves to the meta line. --}}
                @php(preg_match('/^#(\d+)\s+(.+)$/s', $pullRequest['title'], $leading))
                <li wire:key="{{ $pullRequest['number'] }}" class="row group/row grid grid-cols-[3.25rem_minmax(0,1fr)_auto] items-baseline gap-x-3 py-2.5">
                    <span class="text-right font-mono text-xs text-muted tabular-nums">#{{ $pullRequest['number'] }}</span>

                    <div class="min-w-0 text-pretty">
                        {{-- Three lines at most, full text on hover — fewer cut long scoped titles mid-word. --}}
                        <a href="{{ $pullRequest['url'] }}" target="_blank" title="{{ $pullRequest['title'] }}"
                           class="line-clamp-3 [overflow-wrap:anywhere] hover:underline">{{ $leading[2] ?? $pullRequest['title'] }}</a>

                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                            <span>{{ '@'.$pullRequest['author'] }}</span>

                            @if ($leading)
                                {{-- issues/N redirects to the pull request when N turns out to be one. --}}
                                <a href="https://github.com/{{ $pullRequest['repo'] }}/issues/{{ $leading[1] }}" target="_blank"
                                   class="hover:text-paper hover:underline">for #{{ $leading[1] }}</a>
                            @endif

                            @if ($pullRequest['check'])
                                {{-- Dot centres on its own label, not on the row — rows grow when titles wrap. --}}
                                <span class="inline-flex items-center gap-1.5">
                                    <x-dot :state="$pullRequest['check']" />{{ $pullRequest['check'] }}
                                </span>
                            @endif

                            @isset($reviewed['status'])
                                <span class="font-mono text-mint">{{ $reviewed['status'] }}</span>
                            @endisset

                            @if ($reviewed['error'] ?? null)
                                <span role="alert" class="text-rose">✗ {{ $reviewed['error'] }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="-mt-[3px] flex items-center gap-1.5 self-start">
                        {{-- aria-label, not just title: a bare icon has no reliable accessible name.
                             An eye, not ▶ — reviewing is a different job from playing an issue. --}}
                        @unless (isset($reviewed['status']))
                            <button wire:click="review({{ $pullRequest['number'] }})" wire:loading.attr="disabled" wire:target="review({{ $pullRequest['number'] }})" title="Review"
                                    aria-label="Review pull request #{{ $pullRequest['number'] }}"
                                    class="icon-btn group-hover/row:text-mint hover:text-mint focus-visible:text-mint"><x-icon name="review" /></button>
                        @endunless

                        <span wire:loading wire:target="review({{ $pullRequest['number'] }})" role="status" aria-label="Starting a workspace"
                              class="size-3 animate-spin rounded-full border border-line border-t-mint"></span>
                    </div>
                </li>
            @endforeach
        </x-section>
    </x-lane>

    @if ($reviewedThisWeek !== null)
        <p class="material sticky bottom-0 z-[5] -mx-4 mt-auto border-t border-line px-4 py-2 text-center text-xs text-muted tabular-nums">{{ $reviewedThisWeek }}{{ $reviewedTruncated ? '+' : '' }} {{ Str::plural('pull request', $reviewedThisWeek) }} reviewed in the last 7 days</p>
    @endif
</div>
