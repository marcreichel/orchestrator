<?php

use App\Support\Workspaces;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

/** Isolated so this list loads in its own request — see the issues component. */
new #[Isolate] class extends Component
{
    private const CACHE = 'orchestrator.workspaces';

    /** @var array<int, array<string, mixed>> */
    public array $workspaces = [];

    public ?string $error = null;

    /** Cached list first, live one via `wire:init` — see the issues component. */
    public function mount(): void
    {
        $this->fill(Cache::get(self::CACHE) ?? ['error' => 'Loading…']);
    }

    #[On('reload')]
    public function load(): void
    {
        try {
            $this->workspaces = Workspaces::all();
            $this->error = null;

            // An empty refresh still renders (a list really can be empty), but it never
            // becomes the list the next page paints, or one bad answer keeps showing up
            // long after it.
            if ($this->workspaces !== []) {
                Cache::forever(self::CACHE, $this->only('workspaces'));
            }
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
            $this->workspaces = [];
        }
    }

    public function open(string $id): void
    {
        $this->act($id, fn () => Workspaces::open($id));
    }

    public function showChanges(string $id): void
    {
        $this->act($id, fn () => Workspaces::showChanges($id));
    }

    public function showPorts(string $id): void
    {
        $this->act($id, function () use ($id): void {
            foreach ($this->workspaces as $index => $workspace) {
                if ($workspace['id'] === $id) {
                    $this->workspaces[$index]['ports'] = Workspaces::ports($id);
                }
            }
        });
    }

    public function remove(string $id): void
    {
        // A failed removal keeps its row, so the error has somewhere to show.
        if ($this->act($id, fn () => Workspaces::remove($id))) {
            $this->workspaces = array_values(array_filter($this->workspaces, fn (array $workspace): bool => $workspace['id'] !== $id));
        }
    }

    /**
     * A failed action shows on its own row — the lane's `$error` is only ever seen while
     * the list is empty.
     */
    private function act(string $id, \Closure $action): bool
    {
        try {
            $action();
            $error = null;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        foreach ($this->workspaces as $index => $workspace) {
            if (($workspace['id'] ?? null) === $id) {
                $this->workspaces[$index]['error'] = $error;
            }
        }

        return $error === null;
    }

    /**
     * A freshly played issue or pull request already carries its workspace row, so it
     * is listed without refetching.
     *
     * @param  array<string, mixed>  $workspace
     */
    #[On('workspace-created')]
    public function prepend(array $workspace): void
    {
        array_unshift($this->workspaces, $workspace);
    }
};
?>

{{-- Every 5s, not like the 300s inbox lanes: it's a local CLI call, and the agents' working
     state is the thing to watch. `.visible` stops it in a background tab. Full width under
     the two inbox lanes until there's room for a third column. --}}
<div wire:init="load" wire:poll.5s.visible="load" class="lg:col-span-2 xl:col-span-1">
    {{-- Puts the working agents in the tab title, to check on them from another tab. Keyed on
         the count, so Livewire swaps the element and x-init runs again when it changes. --}}
    @php($working = count(array_filter($workspaces, fn (array $workspace): bool => ($workspace['agent'] ?? null) === 'working')))
    <span wire:key="title-{{ $working }}" x-data x-init="document.title = @js(config('app.name').($working ? " · {$working} working" : ''))"></span>

    <x-lane title="Workspaces" :count="count($workspaces)">
        <x-section :count="count($workspaces)" :error="$error" empty="No workspaces running. Play an issue to start one.">
            @foreach ($workspaces as $workspace)
                {{-- Keyed on the workspace, not on its position: prepend() puts a freshly played
                     one on the front, which shifts every index below it and makes Livewire
                     re-patch the whole list. Falls back to the branch for rows cached before
                     the id was carried. --}}
                <li wire:key="{{ $workspace['id'] ?? $workspace['branch'] }}"
                    wire:loading.class="opacity-40" wire:target="remove('{{ $workspace['id'] ?? '' }}')"
                    class="row flex gap-3 py-2.5">
                    {{-- The signal strip: the agent's state, readable down the lane at a glance. --}}
                    <x-dot bar :state="match ($workspace['agent'] ?? null) { 'working' => 'working', 'active' => 'active', default => null }" />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-3">
                            <span class="min-w-0 flex-1 font-mono text-[13px]/5 [overflow-wrap:anywhere]">{{ $workspace['branch'] }}</span>

                            {{-- Rows cached before the id was carried have nothing to act on. --}}
                            @isset($workspace['id'])
                            <span class="-my-1 flex">
                                <button type="button" wire:click="open('{{ $workspace['id'] }}')" class="icon-btn" title="Open" aria-label="Open in Orca"><x-icon name="open" /></button>
                                <button type="button" wire:click="showChanges('{{ $workspace['id'] }}')" class="icon-btn" title="Diff" aria-label="Show the diff"><x-icon name="diff" /></button>
                                <button type="button" wire:click="showPorts('{{ $workspace['id'] }}')" class="icon-btn" title="Ports" aria-label="List listening ports"><x-icon name="ports" /></button>
                                <button type="button" wire:click="remove('{{ $workspace['id'] }}')" wire:confirm="Remove this workspace? The branch stays."
                                        class="icon-btn hover:bg-rose/15 hover:text-rose" title="Remove" aria-label="Remove workspace"><x-icon name="remove" /></button>
                            </span>
                            @endisset
                        </div>

                        <div class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted">
                            <span>{{ $workspace['agent'] ?? 'inactive' }} · {{ $workspace['status'] }}</span>

                            @if ($workspace['age'])
                                {{-- Rendered server-side, so it stays put until the next refresh. --}}
                                <span title="{{ $workspace['createdAt'] }}">{{ $workspace['age'] }}</span>
                            @endif
                        </div>

                        @if ($workspace['ref'])
                            <a href="{{ $workspace['ref']['url'] }}" target="_blank" title="{{ $workspace['ref']['title'] }}"
                               class="mt-0.5 block truncate text-xs text-muted hover:text-paper hover:underline">#{{ $workspace['ref']['number'] }} {{ $workspace['ref']['title'] }}</a>
                        @endif

                        @isset($workspace['ports'])
                            <div class="mt-1.5 flex flex-wrap gap-1.5 text-xs text-muted">
                                @forelse ($workspace['ports'] as $port)
                                    <a href="{{ $port['url'] }}" target="_blank" class="rounded bg-chip px-1.5 font-mono text-paper hover:bg-chip-hover">:{{ $port['port'] }}</a>
                                @empty
                                    No ports listening
                                @endforelse
                            </div>
                        @endisset

                        @if ($workspace['error'] ?? null)
                            <p role="alert" class="mt-1 text-xs text-rose">✗ {{ $workspace['error'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </x-section>
    </x-lane>
</div>
