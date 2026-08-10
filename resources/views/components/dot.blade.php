{{-- Only these states are known; anything else stays grey and reads fine, since it's
     spelled out next to the dot. --}}
@props(['state' => null])

<span @class([
    'size-2 shrink-0 rounded-full',
    match ($state) {
        'active', 'success' => 'bg-mint',
        'pending' => 'bg-amber',
        'failure' => 'bg-rose',
        default => 'bg-idle',
    },
])></span>
