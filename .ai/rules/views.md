---
paths:
  - 'resources/views/**'
---

# Views

## Durations in wire: and x-on: modifiers only parse `ms`
Both Livewire's `wire:poll` and Alpine's `x-on:...debounce`/`.throttle` parse the duration by splitting on `ms`. Anything else — `10s`, `.5m` — matches nothing and silently falls back to the default (2s for wire:poll, 250ms for Alpine), so the directive looks right and behaves nothing like it reads.

Always write these in milliseconds, or in `s` only for `wire:poll`, which does accept that one: `wire:poll.300s`, `x-on:click.throttle.10000ms`.

This has already bitten twice — see the poll interval on the list components and the Refresh button in dashboard.blade.php.
