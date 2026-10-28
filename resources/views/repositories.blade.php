{{-- Where the ids for `orchestrator.repos` come from. --}}
<x-layout>
    <x-lane title="Orca repositories" :count="count($repositories)" class="panel max-w-4xl">
        <x-section :count="count($repositories)" :empty="$error ?? 'Orca has no repositories yet.'">
            @foreach ($repositories as $repository)
                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5">
                    <span class="min-w-1/2 flex-1 font-medium [overflow-wrap:anywhere]">{{ $repository['name'] }}</span>

                    @if ($repository['path'])
                        <span class="min-w-0 flex-1 truncate text-right font-mono text-xs text-muted" title="{{ $repository['path'] }}">{{ $repository['path'] }}</span>
                    @endif

                    <code class="rounded bg-chip px-1.5 py-0.5 font-mono text-xs select-all">{{ $repository['id'] }}</code>
                </li>
            @endforeach
        </x-section>
    </x-lane>
</x-layout>
