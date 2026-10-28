<x-layout>
    <x-slot:actions>
        {{-- A refresh spends four of GitHub's thirty search requests per minute, so
             repeated clicking can burn the quota the poll depends on. Throttled rather
             than disabled: the section spinners already say a refresh is running, and a
             counter driven by Livewire's message hooks stays stuck if one never lands.
             10000ms, not 10s — Alpine parses only `ms` here and silently falls back to
             250ms otherwise, exactly like wire:poll. --}}
        <button type="button" x-data x-on:click.throttle.10000ms="Livewire.dispatch('reload')"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-chip px-2.5 py-1 text-sm transition-[background-color,scale] duration-100 hover:bg-chip-hover active:scale-96">
            <x-icon name="refresh" class="size-3.5 text-muted" />Refresh
        </button>
    </x-slot:actions>

    {{-- Lanes follow the work: issues in, reviews waiting, workspaces running. Two columns
         with the workspaces underneath until there's room for three. minmax(0,…) — plain 1fr
         won't shrink below its content and pushes the page wide. --}}
    <div class="grid items-start gap-x-10 gap-y-8 lg:grid-cols-[repeat(2,minmax(0,1fr))] xl:grid-cols-[repeat(3,minmax(0,1fr))]">
        {{-- Not deferred: mounting only reads the cache, so the last known lists render
             with the page. Each still fetches in its own `wire:init` request, so a dead
             GitHub or Orca only takes out its own list. --}}
        <livewire:issues />
        <livewire:prs />
        <livewire:workspaces />
    </div>
</x-layout>
