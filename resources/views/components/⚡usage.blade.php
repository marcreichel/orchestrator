<?php

use App\Support\Claude;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Isolated so this loads in its own request — see the issues component. */
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.usage';

    /** @var array<string, array{percent: int, resetsAt: ?string}> */
    public array $usage = [];

    public ?string $error = null;

    /** Cached numbers first, live ones via `wire:init` — see the issues component. */
    public function mount(): void
    {
        $this->fill(Cache::get(self::CACHE) ?? []);
    }

    /**
     * A failure keeps the last numbers on screen and only marks them stale — an expired
     * token is routine (it lasts until Claude Code next runs), not worth blanking over.
     */
    #[On('reload')]
    public function load(): void
    {
        try {
            $this->usage = Claude::usage();
            $this->error = null;

            Cache::forever(self::CACHE, $this->only('usage'));
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }
    }

    /** The reset as a clock time for the tooltip; the countdown is the browser's. */
    public function resetTime(?string $resetsAt): string
    {
        return $resetsAt ? Carbon::parse($resetsAt)->timezone(config('app.timezone'))->format('D, M j g:i A') : 'unknown';
    }

    /** Muted until it's worth noticing. */
    public function tone(int $percent): string
    {
        return match (true) {
            $percent >= 90 => 'text-rose',
            $percent >= 75 => 'text-amber',
            default => 'text-muted',
        };
    }
};
?>

{{-- 300s, not 5m — see the issues component. No room on a phone, like "Updated …".
     The countdowns tick in the browser, or they'd sit up to a poll behind; a time that
     won't parse (an old cache entry) shows nothing until the next load. They only fit
     from lg, where the header has room to spare. --}}
@php($tooltip = collect($usage)->map(fn (array $window, string $label): string => "{$label} resets ".$this->resetTime($window['resetsAt']))->push($error ? "Stale: {$error}" : null)->filter()->implode("\n"))
<div wire:init="load" wire:poll.300s="load"
     x-data="{
         now: Date.now(),
         left(at) {
             let minutes = Math.floor(Math.max(0, Date.parse(at) - this.now) / 60000)
             if (isNaN(minutes)) return ''
             let days = Math.floor(minutes / 1440), hours = Math.floor(minutes % 1440 / 60)
             return days ? `${days}d ${hours}h` : hours ? `${hours}h ${minutes % 60}m` : `${minutes}m`
         },
     }"
     x-init="setInterval(() => now = Date.now(), 30000)"
     title="{{ $tooltip ?: 'Claude usage' }}"
     @class(['hidden items-center gap-3 text-xs tabular-nums sm:flex', 'opacity-50' => $error])>
    @forelse ($usage as $label => $window)
        {{-- The bar repeats the number, so screen readers skip it. Inline width: a class
             per percentage would never make it into the build. --}}
        <span class="flex items-center gap-1.5 text-muted">
            {{ $label }}
            <span aria-hidden="true" class="h-1 w-10 overflow-hidden rounded-full bg-white/10">
                <span class="block h-full rounded-full bg-current {{ $this->tone($window['percent']) }}" style="width: {{ min($window['percent'], 100) }}%"></span>
            </span>
            <span class="{{ $this->tone($window['percent']) }}">{{ $window['percent'] }}%</span>
            <span x-text="left(@js($window['resetsAt']))" class="hidden text-muted/60 lg:inline"></span>
        </span>
    @empty
        <span class="text-muted">—</span>
    @endforelse
</div>
