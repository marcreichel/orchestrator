<x-layout>
    <x-slot:actions>
        {{-- A refresh spends four of GitHub's thirty search requests per minute, so
             repeated clicking can burn the quota the poll depends on. Throttled rather
             than disabled: the section spinners already say a refresh is running, and a
             counter driven by Livewire's message hooks stays stuck if one never lands.
             10000ms, not 10s — Alpine parses only `ms` here and silently falls back to
             250ms otherwise, exactly like wire:poll.
             The cached lists paint before anything is fetched, so their age is unknown until
             the first list reports back — "Updating…" until then, and again after a click. --}}
        <span x-data="{
                  at: null,
                  now: Date.now(),
                  get label() {
                      if (this.at === null) return 'Updating…'
                      let minutes = Math.floor((this.now - this.at) / 60000)
                      return minutes < 1 ? 'Updated just now' : 'Updated ' + new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(-minutes, 'minute')
                  },
              }"
              x-init="setInterval(() => now = Date.now(), 15000)"
              x-on:refreshed.window="at = now = Date.now()"
              class="contents">
            {{-- No room for it on a phone; the Refresh button's tooltip carries it there. --}}
            <span x-text="label" aria-live="polite" class="hidden text-xs text-muted tabular-nums sm:inline">Updating…</span>

            <button type="button" x-bind:title="label" x-on:click.throttle.10000ms="at = null; Livewire.dispatch('reload')"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 px-2.5 py-1 text-sm text-paper/80 transition-[background-color,color,scale] duration-100 hover:bg-chip-hover hover:text-paper active:scale-96">
                <x-icon name="refresh" class="size-3.5 text-muted" /><span class="sr-only sm:not-sr-only">Refresh</span>
            </button>
        </span>
    </x-slot:actions>

    {{-- Lanes follow the work: issues in, reviews waiting, workspaces running. Two columns
         with the workspaces underneath until there's room for three. minmax(0,…) — plain 1fr
         won't shrink below its content and pushes the page wide. Workspaces get the narrower
         track: a branch and four buttons need less than a title does.
         Side by side, each lane fills the viewport and scrolls on its own (7.75rem is the page
         header, its margin and the main's bottom padding), so they read as fixed panes and the
         long issue list doesn't drag the other two along.
         --lane-top re-pins the lane headings to the top of their own scroller. Each lane is a
         panel, whose padding keeps the row hover tint from tripping a sideways scroll. --}}
    <div class="grid items-start gap-4 *:panel *:pb-2 lg:grid-cols-[repeat(2,minmax(0,1fr))] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,.8fr)] xl:*:h-[calc(100dvh-7.75rem)] xl:*:overflow-x-hidden xl:*:overflow-y-auto xl:*:overscroll-contain xl:*:scroll-fade xl:*:[--lane-top:0px]">
        {{-- Not deferred: mounting only reads the cache, so the last known lists render
             with the page. Each still fetches in its own `wire:init` request, so a dead
             GitHub or Orca only takes out its own list. --}}
        <livewire:issues />
        <livewire:prs />
        <livewire:workspaces />
    </div>
</x-layout>
