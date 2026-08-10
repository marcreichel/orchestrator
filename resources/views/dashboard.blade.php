<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        {{-- Same mark as the header, inline so there's no icon file to serve. Thicker
             stroke: 1.5 vanishes at 16px. --}}
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%236ee7a8' stroke-width='2' stroke-linecap='round'><path d='M6 10h4V5h3M6 10h7M6 10h4v5h3'/><circle cx='4' cy='10' r='2.2' fill='%236ee7a8' stroke='none'/><circle cx='15' cy='5' r='1.8'/><circle cx='15' cy='10' r='1.8'/><circle cx='15' cy='15' r='1.8'/></svg>">

        @vite('resources/css/app.css')
    </head>
    <body class="mx-auto max-w-4xl bg-ink px-6 py-10 text-[15px]/normal text-paper lg:max-w-[100rem]">
        <header class="mb-8 flex items-baseline gap-4">
            <h1 class="flex flex-1 items-center gap-2.5 text-xl">
                <svg width="26" height="26" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" aria-hidden="true" class="text-mint">
                    <path d="M6 10h4V5h3M6 10h7M6 10h4v5h3"/>
                    <circle cx="4" cy="10" r="2" fill="currentColor" stroke="none"/>
                    <circle cx="15" cy="5" r="1.6"/><circle cx="15" cy="10" r="1.6"/><circle cx="15" cy="15" r="1.6"/>
                </svg>
                {{ config('app.name') }}
            </h1>

            <button type="button" x-data x-on:click="Livewire.dispatch('reload')"
                    class="cursor-pointer rounded-md bg-chip px-2.5 py-1 hover:bg-chip-hover">Refresh</button>
        </header>

        {{-- minmax(0,…) — plain 1fr won't shrink below its content and pushes the page wide. --}}
        <div class="items-start gap-x-10 lg:grid lg:grid-cols-[repeat(2,minmax(0,1fr))]">
            {{-- Deferred, so each section loads in its own request: a dead GitHub or
                 Polyscope only takes out its own list. --}}
            <livewire:issues defer />
            <livewire:prs defer />
            <livewire:workspaces defer />
        </div>
    </body>
</html>
