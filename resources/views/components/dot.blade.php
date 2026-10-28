{{-- Only these states are known; anything else stays grey and reads fine, since it's
     spelled out next to the dot. Pending breathes, so live work stands out from idle.
     `bar` stretches it into the signal strip down the side of a workspace row. --}}
@props(['state' => null, 'bar' => false])

<span @class([
    'shrink-0 rounded-full',
    $bar ? 'w-[3px] self-stretch' : 'size-2',
    match ($state) {
        'active', 'success' => 'bg-mint',
        'pending' => 'bg-amber motion-safe:animate-pulse',
        'failure' => 'bg-rose',
        default => 'bg-idle',
    },
])></span>
