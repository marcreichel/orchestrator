---
paths:
  - 'resources/views/components/**'
---

# Components

## Slots inside Livewire components are never empty
`$slot->isEmpty()` is unreliable for Blade components rendered inside a Livewire component: Livewire injects `<!--[if BLOCK]>` markers around every conditional and loop, so a slot with zero items still has content. Decide emptiness from the data instead — pass a count (see x-section).

## Livewire list components: refresh mechanics
`wire:poll` only parses `ms` and `s` modifiers. `.5m` matches neither and silently falls back to Livewire's 2s default, so five minutes must be written `wire:poll.300s`.

The lists must carry `#[Isolate]`. Without it Livewire pools the same-tick commits of all three components into one request, so every list only ever arrives as fast as the slowest of GitHub and Polyscope.

Header spinners use an untargeted `wire:loading`: the Refresh button reloads by dispatching an event, which commits as `__dispatch`, so `wire:target="load"` never matches it.

`mount()` only reads the cache (`orchestrator.issues|prs|workspaces`) so the page renders instantly; `wire:init="load"` does the live fetch and writes the cache back on success. Keep the components undeferred — `defer` would cost a round-trip before the cached lists appear.
