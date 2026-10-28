{{-- One column of the switchboard: a heading with the lane's total, then its lists. --}}
@props(['title', 'count' => null, 'truncated' => false, 'spinner' => false])

<section {{ $attributes }}>
    {{-- Sticky under the page header (top-15 is its height), so a long lane keeps its name.
         Fixed h-10 — the list headings below pin themselves at the sum of the two. --}}
    <header class="material sticky top-15 z-[5] flex h-10 items-center gap-2 border-t-2 border-line">
        <h2 class="text-[15px] font-semibold tracking-[-.01em]">{{ $title }}</h2>

        {{-- A search only ever asks for its first page, so a capped list gets a `+` and says
             so on hover rather than reading as the whole picture. --}}
        @unless (is_null($count))
            <span @if ($truncated) title="GitHub returned only the first page of matches — there are more than this." @endif
                  class="rounded bg-chip px-1.5 font-mono text-xs/5 text-muted tabular-nums">{{ $count }}{{ $truncated ? '+' : '' }}</span>
        @endunless

        {{-- Untargeted: the Refresh button reloads by dispatching an event, which commits as
             `__dispatch` and so never matches wire:target="load". Only for lanes inside a
             Livewire component — elsewhere nothing ever hides it again. --}}
        @if ($spinner)
            <span wire:loading role="status" aria-label="Refreshing"
                  class="ml-auto size-3 shrink-0 animate-spin rounded-full border border-line border-t-muted"></span>
        @endif
    </header>

    {{ $slot }}
</section>
