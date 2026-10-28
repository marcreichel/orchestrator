---
paths:
  - 'resources/views/components/**'
---

# Components

## Slots inside Livewire components are never empty
`$slot->isEmpty()` is unreliable for Blade components rendered inside a Livewire component: Livewire injects `<!--[if BLOCK]>` markers around every conditional and loop, so a slot with zero items still has content. Decide emptiness from the data instead — pass a count (see x-section).

## Livewire list components: refresh mechanics
`wire:poll` only parses `ms` and `s` modifiers. `.5m` matches neither and silently falls back to Livewire's 2s default, so five minutes must be written `wire:poll.300s`.

The lists must carry `#[Isolate]`. Without it Livewire pools the same-tick commits of all three components into one request, so every list only ever arrives as fast as the slowest of GitHub and Orca.

Header spinners use an untargeted `wire:loading`: the Refresh button reloads by dispatching an event, which commits as `__dispatch`, so `wire:target="load"` never matches it.

`mount()` only reads the cache (`orchestrator.issues|prs|workspaces`) so the page renders instantly; `wire:init="load"` does the live fetch and writes the cache back on success. Keep the components undeferred — `defer` would cost a round-trip before the cached lists appear.

## Keep wire:click on plain elements, not on Blade components
Attributes passed to a Blade component are escaped, so `wire:click="play('I_1')"` renders as `play(&#039;I_1&#039;)`. Livewire still works, but the `assertSeeHtml('wire:click="play(\'I_1\')"')` tests stop matching. That's why the row actions are plain `<button class="icon-btn">` with an `<x-icon>` inside, and not an icon-button component.

## Parameterised wire:target matches exact arguments only
`wire:target="play('I_1')"` only matches a call whose params JSON-equal `['I_1']`. When `wire:click` passes a runtime argument (e.g. `play('I_1', ! $event.altKey)` for ⌥-click), list every variant: `wire:target="play('I_1', true), play('I_1', false)"`. A bare `wire:target="play"` would light up every row's spinner.
