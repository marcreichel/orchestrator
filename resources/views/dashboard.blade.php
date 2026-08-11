<x-layout>
    <x-slot:actions>
        <button type="button" x-data x-on:click="Livewire.dispatch('reload')"
                class="cursor-pointer rounded-md bg-chip px-2.5 py-1 hover:bg-chip-hover">Refresh</button>
    </x-slot:actions>

    {{-- minmax(0,…) — plain 1fr won't shrink below its content and pushes the page wide. --}}
    <div class="items-start gap-x-10 lg:grid lg:grid-cols-[repeat(2,minmax(0,1fr))]">
        {{-- Not deferred: mounting only reads the cache, so the last known lists render
             with the page. Each still fetches in its own `wire:init` request, so a dead
             GitHub or Polyscope only takes out its own list. --}}
        <livewire:issues />
        <livewire:prs />
        <livewire:workspaces />
    </div>
</x-layout>
