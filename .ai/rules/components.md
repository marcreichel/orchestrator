---
paths:
  - 'resources/views/components/**'
---

# Components

## Slots inside Livewire components are never empty
`$slot->isEmpty()` is unreliable for Blade components rendered inside a Livewire component: Livewire injects `<!--[if BLOCK]>` markers around every conditional and loop, so a slot with zero items still has content. Decide emptiness from the data instead — pass a count (see x-section).
