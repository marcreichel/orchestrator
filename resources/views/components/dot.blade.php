{{-- Only these states are known; anything else stays grey and reads fine, since it's
     spelled out next to the dot. Only a working agent breathes — a pending CI check is
     steady amber, or a lane of them pulses and the motion stops meaning anything.
     `bar` stretches it into the signal strip down the side of a workspace row. --}}
@props(['state' => null, 'bar' => false])

<span @class([
    'shrink-0 rounded-full',
    $bar ? 'w-[3px] self-stretch' : 'size-2',
    match ($state) {
        'active', 'success' => 'bg-mint',
        'pending' => 'bg-amber',
        'working' => 'bg-amber motion-safe:animate-pulse',
        'failure' => 'bg-rose',
        default => 'bg-idle',
    },
])></span>
