{{-- One list inside a lane. Untitled when it's the lane's only list. --}}
@props(['title' => null, 'count' => null, 'empty' => null, 'truncated' => false, 'collapsible' => false])

{{-- Folds through a native <details>. wire:ignore.self, or every poll would re-render it
     without `open` and snap a list the user opened shut again. --}}
<{{ $collapsible ? 'details' : 'div' }} @if ($collapsible) wire:ignore.self class="group" @endif>
    @if ($title)
        {{-- Sticky under the page header and the lane heading — top-25 is their two heights. --}}
        <{{ $collapsible ? 'summary' : 'h3' }} @class([
            'material sticky top-25 z-[4] mt-4 flex items-center gap-1.5 py-1.5 text-[11px] font-medium tracking-[.08em] text-muted uppercase',
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
             an "empty" slot non-empty. --}}
        @if (! $count)
            <li class="py-3 text-sm text-muted">{{ $empty ?? 'Nothing here.' }}</li>
        @else
            {{ $slot }}
        @endif
    </ul>
</{{ $collapsible ? 'details' : 'div' }}>
