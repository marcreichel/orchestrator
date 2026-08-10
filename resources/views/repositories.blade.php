{{-- Where the ids for `orchestrator.repos` come from. --}}
<x-layout>
    <x-section title="Polyscope repositories" :count="count($repositories)" :empty="$error">
        @foreach ($repositories as $repository)
            <li class="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 border-t border-line py-2">
                <span class="min-w-1/2 flex-1 [overflow-wrap:anywhere]">{{ $repository->name }}</span>

                @if ($repository->path)
                    <span class="min-w-0 flex-1 truncate text-right text-sm text-muted" title="{{ $repository->path }}">{{ $repository->path }}</span>
                @endif

                <code class="rounded bg-chip px-1.5 py-0.5 text-sm select-all">{{ $repository->id }}</code>
            </li>
        @endforeach
    </x-section>
</x-layout>
