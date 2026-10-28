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
    private const CACHE = 'orchestrator.attention';

    /** @var array<int, array<string, mixed>> */
    public array $pullRequests = [];

    /** Whether GitHub had more matches than the one page the search asks for. */
    public bool $truncated = false;

    /**
     * What starting a fix did, per pull request number.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $fixing = [];

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
            ['items' => $this->pullRequests, 'truncated' => $this->truncated] = app(GitHub::class)->attentionPullRequests();
            $this->error = null;

            // An existing workspace on the pull request means a fix is already under way.
            // A dead Orca means "no idea" — see the issues component.
            $numbers = array_flip(array_column($this->pullRequests, 'number'));
            $workspaces = Workspaces::byRef();
            $this->fixing = $workspaces === null ? array_intersect_key($this->fixing, $numbers) : [];

            foreach ($this->pullRequests as $pullRequest) {
                if ($workspace = $workspaces[$pullRequest['url']] ?? null) {
                    $this->fixing[$pullRequest['number']] = ['status' => $workspace['label']];
                }
            }

            Cache::forever(self::CACHE, $this->only('pullRequests', 'truncated', 'fixing'));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->pullRequests = [];
        }

        $this->dispatch('refreshed');
    }

    public function fix(int $number): void
    {
        $pullRequest = collect($this->pullRequests)->firstWhere('number', $number);

        if ($pullRequest === null) {
            return;
        }

        try {
            $workspace = Workspaces::forPullRequestFix(
                (string) $pullRequest['repo'],
                $number,
                (string) $pullRequest['url'],
                (string) $pullRequest['branch'],
                (bool) $pullRequest['conflicts'],
            );
        } catch (Throwable $exception) {
            $this->fixing[$number] = ['error' => $exception->getMessage()];

            return;
        }

        $this->dispatch('workspace-created', workspace: $workspace)->to('workspaces');

        $this->fixing[$number] = ['status' => $workspace['label']];
    }
};
?>

{{-- Nothing to say most of the time, so the panel only exists while there is a row or a
     failure to show — not for the cold-cache placeholder. The wrapper stays for wire:init
     and the poll, and is `contents` so the panel is the flex item. --}}
<div wire:init="load" wire:poll.300s="load" class="contents">
    @if ($pullRequests || ($error && $error !== 'Loading…'))
        <x-lane class="panel max-h-[50%] shrink-0 overflow-y-auto pb-2 [--lane-top:0px]" title="Needs attention" :count="count($pullRequests)" :truncated="$truncated" spinner>
            <x-section :count="count($pullRequests)" :error="$error">
                @foreach ($pullRequests as $pullRequest)
                    @php($state = $fixing[$pullRequest['number']] ?? [])
                    <li wire:key="{{ $pullRequest['number'] }}" class="row group/row grid grid-cols-[3.25rem_minmax(0,1fr)_auto] items-baseline gap-x-3 py-2.5">
                        <span class="text-right font-mono text-xs text-muted tabular-nums">#{{ $pullRequest['number'] }}</span>

                        <div class="min-w-0 text-pretty">
                            <a href="{{ $pullRequest['url'] }}" target="_blank" title="{{ $pullRequest['title'] }}"
                               class="line-clamp-3 [overflow-wrap:anywhere] hover:underline">{{ $pullRequest['title'] }}</a>

                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                @if ($pullRequest['conflicts'])
                                    <span class="text-rose">conflicts</span>
                                @endif

                                @if ($pullRequest['unresolved'])
                                    <span class="text-amber">{{ $pullRequest['unresolved'] }} unresolved</span>
                                @endif

                                @if ($pullRequest['check'])
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-dot :state="$pullRequest['check']" />{{ $pullRequest['check'] }}
                                    </span>
                                @endif

                                @isset($state['status'])
                                    <span class="font-mono text-mint">{{ $state['status'] }}</span>
                                @endisset

                                @if ($state['error'] ?? null)
                                    <span role="alert" class="text-rose">✗ {{ $state['error'] }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="-mt-[3px] flex items-center gap-1.5 self-start">
                            @unless (isset($state['status']))
                                <button wire:click="fix({{ $pullRequest['number'] }})" wire:loading.attr="disabled" wire:target="fix({{ $pullRequest['number'] }})" title="Fix"
                                        aria-label="Fix pull request #{{ $pullRequest['number'] }}"
                                        class="icon-btn group-hover/row:text-mint hover:text-mint focus-visible:text-mint"><x-icon name="play" /></button>
                            @endunless

                            <span wire:loading wire:target="fix({{ $pullRequest['number'] }})" role="status" aria-label="Starting a workspace"
                                  class="size-3 animate-spin rounded-full border border-line border-t-mint"></span>
                        </div>
                    </li>
                @endforeach
            </x-section>
        </x-lane>
    @endif
</div>
