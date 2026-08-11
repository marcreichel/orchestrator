{{-- Heading + list, kept together as one grid item. --}}
@props(['title', 'count' => null, 'empty' => null, 'spinner' => false])

<section>
    <h2 class="mt-8 mb-2 flex items-center gap-1.5 text-xs tracking-[.08em] text-muted uppercase">
        {{ $title }}@unless (is_null($count)) ({{ $count }})@endunless

        {{-- Untargeted: the Refresh button reloads by dispatching an event, which commits as
             `__dispatch` and so never matches wire:target="load". Only for sections inside a
             Livewire component — elsewhere nothing ever hides it again. --}}
        @if ($spinner)
            <span wire:loading class="size-3 shrink-0 animate-spin rounded-full border border-line border-t-muted"></span>
        @endif
    </h2>

    <ul>
        {{-- Counted from the data, not from the slot: Livewire's conditional markers make
             an "empty" slot non-empty. --}}
        @if (! $count)
            <li class="border-t border-line py-2 text-muted">{{ $empty ?? 'Nothing here.' }}</li>
        @else
            {{ $slot }}
        @endif
    </ul>
</section>
