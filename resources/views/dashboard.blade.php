<x-layout>
    <x-slot:actions>
        <button type="button" x-data x-on:click="Livewire.dispatch('reload')"
                class="cursor-pointer rounded-md bg-chip px-2.5 py-1 hover:bg-chip-hover">Refresh</button>
    </x-slot:actions>

    {{-- minmax(0,…) — plain 1fr won't shrink below its content and pushes the page wide. --}}
    <div class="items-start gap-x-10 lg:grid lg:grid-cols-[repeat(2,minmax(0,1fr))]">
        {{-- Deferred, so each section loads in its own request: a dead GitHub or
             Polyscope only takes out its own list. --}}
        <livewire:issues defer />
        <livewire:prs defer />
        <livewire:workspaces defer />
    </div>
</x-layout>
