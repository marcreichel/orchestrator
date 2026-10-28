{{-- One list inside a lane. Untitled when it's the lane's only list. --}}
@props(['title' => null, 'count' => null, 'empty' => null, 'error' => null, 'truncated' => false, 'collapsible' => false])

{{-- Folds through a native <details>. wire:ignore.self, or every poll would re-render it
     without `open` and snap a list the user opened shut again. --}}
<{{ $collapsible ? 'details' : 'div' }} @if ($collapsible) wire:ignore.self class="group" @endif>
    @if ($title)
        {{-- Sticky under the lane heading, which is 2.5rem tall and pinned at --lane-top.
             Bled to the panel's edges, so rows don't show through beside it. --}}
        <{{ $collapsible ? 'summary' : 'h3' }} @class([
            'material sticky top-[calc(var(--lane-top,3.75rem)+2.5rem)] z-[4] -mx-4 mt-4 flex items-center gap-1.5 px-4 py-1.5 text-xs font-medium text-muted',
            'cursor-pointer list-none hover:text-paper [&::-webkit-details-marker]:hidden' => $collapsible,
        ])>
            @if ($collapsible)
                <x-icon name="chevron" class="size-3 transition-transform group-open:rotate-90 motion-reduce:transition-none" />
            @endif

            {{ $title }}
            @unless (is_null($count))
                <span @if ($truncated) title="GitHub returned only the first page of matches — there are more than this." @endif
                      class="font-mono tabular-nums">{{ $count }}{{ $truncated ? '+' : '' }}</span>
            @endunless
        </{{ $collapsible ? 'summary' : 'h3' }}>
    @endif

    <ul class="divide-y divide-line">
        {{-- Counted from the data, not from the slot: Livewire's conditional markers make
             an "empty" slot non-empty. A cold cache passes 'Loading…' as the error — it gets
             placeholder rows instead, so it doesn't read as an empty list. An error behind a
             list that still has rows is left unsaid: the last known rows are worth more. --}}
        @if (! $count && $error === 'Loading…')
            @foreach (['w-3/4', 'w-1/2', 'w-2/3'] as $width)
                <li aria-hidden="true" class="flex gap-3 py-3.5">
                    <span class="h-3 w-10 rounded bg-chip"></span>
                    <span class="h-3 {{ $width }} rounded bg-chip"></span>
                </li>
            @endforeach
            <li class="sr-only">Loading…</li>
        @elseif (! $count && $error)
            <li class="flex items-start gap-2 py-3 text-sm text-muted">
                <x-icon name="alert" class="mt-0.5 size-4 shrink-0 text-rose" />
                <span class="[overflow-wrap:anywhere]">{{ $error }}</span>
            </li>
        @elseif (! $count)
            <li class="flex flex-col items-center gap-3 py-8 text-center text-sm text-muted">
                <span class="grid size-9 place-items-center rounded-lg border border-line bg-chip">
                    <x-icon name="inbox" />
                </span>
                <span class="max-w-64 text-balance">{{ $empty ?? 'Nothing here.' }}</span>
            </li>
        @else
            {{ $slot }}
        @endif
    </ul>
</{{ $collapsible ? 'details' : 'div' }}>
