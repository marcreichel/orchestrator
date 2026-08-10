{{-- Heading + list, kept together as one grid item. --}}
@props(['title', 'count' => null, 'empty' => null])

<section>
    <h2 class="mt-8 mb-2 text-xs tracking-[.08em] text-muted uppercase">
        {{ $title }}@unless (is_null($count)) ({{ $count }})@endunless
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
