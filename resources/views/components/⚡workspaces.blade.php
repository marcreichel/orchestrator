<?php

use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Isolated so this list loads in its own request — see the issues component. */
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.workspaces';

    /** @var array<int, array<string, mixed>> */
    public array $workspaces = [];

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
            $this->workspaces = Workspaces::all();
            $this->error = null;

            // The SDK turns an unreadable 200 — empty body, truncated JSON, anything
            // without a `data` key — into an empty list rather than into a failure, so an
            // empty refresh is the one result that can't be told from a broken one. It
            // still renders (a list really can be empty), but it never becomes the list
            // the next page paints, or one bad answer keeps showing up long after it.
            if ($this->workspaces !== []) {
                Cache::forever(self::CACHE, $this->only('workspaces'));
            }
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->workspaces = [];
        }
    }

    /**
     * A freshly played issue or pull request already carries its workspace row, so it
     * is listed without refetching.
     *
     * @param  array<string, mixed>  $workspace
     */
    #[On('workspace-created')]
    public function prepend(array $workspace): void
    {
        array_unshift($this->workspaces, $workspace);
    }
};
?>

{{-- 300s, not 5m — see the issues component. --}}
<div wire:init="load" wire:poll.300s="load">
    <x-section title="Workspaces" :count="count($workspaces)" :empty="$error" spinner>
        @foreach ($workspaces as $workspace)
            <li wire:key="{{ $loop->index }}-{{ $workspace['branch'] }}" class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 border-t border-line py-2">
                {{-- previewUrl is nullable — an unlinked branch is plain text rather than a dead link.
                     min-w-1/2 so a long branch never collapses to one character per line next to
                     an even longer issue title. --}}
                @if ($workspace['previewUrl'])
                    <a href="{{ $workspace['previewUrl'] }}" target="_blank" class="min-w-1/2 flex-1 [overflow-wrap:anywhere] hover:underline">{{ $workspace['branch'] }}</a>
                @else
                    <span class="min-w-1/2 flex-1 [overflow-wrap:anywhere]">{{ $workspace['branch'] }}</span>
                @endif

                @if ($workspace['ref'])
                    {{-- Gets whatever is left and gives up first, since the branch identifies the
                         row. Zero-basis, or flex-wrap would break it onto its own line instead of
                         truncating it; right-aligned so a short one still sits next to the status. --}}
                    <a href="{{ $workspace['ref']['url'] }}" target="_blank" title="{{ $workspace['ref']['title'] }}"
                       class="min-w-0 flex-1 truncate text-right text-sm text-muted hover:underline">#{{ $workspace['ref']['number'] }} {{ $workspace['ref']['title'] }}</a>
                @endif

                <span class="inline-flex items-center gap-1.5 text-sm text-muted">
                    <x-dot :state="$workspace['status']" />{{ $workspace['status'] }}
                </span>

                @if ($workspace['age'])
                    {{-- Rendered server-side, so it stays put until the next refresh. --}}
                    <span class="text-sm text-muted" title="{{ $workspace['createdAt'] }}">{{ $workspace['age'] }}</span>
                @endif
            </li>
        @endforeach
    </x-section>
</div>
