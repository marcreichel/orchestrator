{{-- Hand-drawn 16px strokes, inline so there's no icon set to install. Decorative: the
     button carrying it owns the accessible name. --}}
@props(['name'])

<svg {{ $attributes->merge(['class' => 'size-4']) }} viewBox="0 0 16 16" fill="none" stroke="currentColor"
     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch ($name)
        @case('play')
            <path d="M5.5 3.5v9l7-4.5z" fill="currentColor"/>
            @break
        @case('review')
            <path d="M1.5 8s2.5-4.5 6.5-4.5S14.5 8 14.5 8s-2.5 4.5-6.5 4.5S1.5 8 1.5 8z"/>
            <circle cx="8" cy="8" r="2"/>
            @break
        @case('open')
            <path d="M6.5 3.5h6v6M12.5 3.5l-8 8"/>
            @break
        @case('diff')
            <path d="M8 2.5v6M5 5.5h6M5 12.5h6"/>
            @break
        @case('ports')
            <path d="M6 2v3M10 2v3M4.5 5h7v2.5a3.5 3.5 0 0 1-7 0zM8 11v3"/>
            @break
        @case('remove')
            <path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.5h5.8l.6-8.5"/>
            @break
        @case('chevron')
            <path d="M6 3.5 10.5 8 6 12.5"/>
            @break
        @case('refresh')
            <path d="M13 8a5 5 0 1 1-1.46-3.54M13 2.5V5h-2.5"/>
            @break
    @endswitch
</svg>
