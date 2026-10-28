{{-- One list inside a lane. Untitled when it's the lane's only list. --}}
@props(['title' => null, 'count' => null, 'empty' => null, 'truncated' => false, 'collapsible' => false])

{{-- Folds through a native <details>. wire:ignore.self, or every poll would re-render it
     without `open` and snap a list the user opened shut again. --}}
<{{ $collapsible ? 'details' : 'div' }} @if ($collapsible) wire:ignore.self class="group" @endif>
    @if ($title)
        {{-- Sticky under the lane heading, which is 2.5rem tall and pinned at --lane-top. --}}
        <{{ $collapsible ? 'summary' : 'h3' }} @class([
            'material sticky top-[calc(var(--lane-top,3.75rem)+2.5rem)] z-[4] mt-4 flex items-center gap-1.5 py-1.5 text-[11px] font-medium tracking-[.08em] text-muted uppercase',
            'cursor-pointer list-none hover:text-paper [&::-webkit-details-marker]:hidden' => $collapsible,
        ])>
            @if ($collapsible)
                <x-icon name="chevron" class="size-3 transition-transform group-open:rotate-90 motion-reduce:transition-none" />
            @endif

            {{ $title }}
            @unless (is_null($count))
                <span @if ($truncated) title="GitHub returned only the first page of matches — there are more than this." @endif
                      class="font-mono tracking-normal tabular-nums">{{ $count }}{{ $truncated ? '+' : '' }}</span>
            @endunless
        </{{ $collapsible ? 'summary' : 'h3' }}>
    @endif

    <ul class="divide-y divide-line">
        {{-- Counted from the data, not from the slot: Livewire's conditional markers make
             an "empty" slot non-empty. A cold cache passes 'Loading…' as the empty text — it
             gets placeholder rows instead, so it doesn't read as an empty list. --}}
        @if (! $count && $empty === 'Loading…')
            @foreach (['w-3/4', 'w-1/2', 'w-2/3'] as $width)
                <li aria-hidden="true" class="flex gap-3 py-3.5">
                    <span class="h-3 w-10 rounded bg-chip"></span>
                    <span class="h-3 {{ $width }} rounded bg-chip"></span>
                </li>
            @endforeach
            <li class="sr-only">Loading…</li>
        @elseif (! $count)
            <li class="py-3 text-sm text-muted">{{ $empty ?? 'Nothing here.' }}</li>
        @else
            {{ $slot }}
        @endif
    </ul>
</{{ $collapsible ? 'details' : 'div' }}>
