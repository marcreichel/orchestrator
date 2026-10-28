<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        {{-- Same mark as the header, inline so there's no icon file to serve. Thicker
             stroke: 1.5 vanishes at 16px. --}}
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%23f7f8f8' stroke-width='2' stroke-linecap='round'><path d='M6 10h4V5h3M6 10h7M6 10h4v5h3'/><circle cx='4' cy='10' r='2.2' fill='%23f7f8f8' stroke='none'/><circle cx='15' cy='5' r='1.8'/><circle cx='15' cy='10' r='1.8'/><circle cx='15' cy='15' r='1.8'/></svg>">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500&display=swap">

        @vite('resources/css/app.css')
    </head>
    <body class="bg-ink text-[15px]/normal text-paper">
        {{-- Pinned and translucent, so the lanes scroll under it. Fixed h-15, its hairline
             included: the lane and list headings pin themselves right below it (3.75rem, see
             --lane-top). Full width; only its contents keep to the page column. --}}
        <header class="material sticky top-0 z-10 h-15 border-b border-line">
            <div class="mx-auto flex h-full max-w-4xl items-center gap-2 px-6 lg:max-w-[100rem]">
                <h1 class="flex-1 text-lg font-medium tracking-[-.02em]">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5">
                        <svg width="26" height="26" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                             stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 10h4V5h3M6 10h7M6 10h4v5h3"/>
                            <circle cx="4" cy="10" r="2" fill="currentColor" stroke="none"/>
                            <circle cx="15" cy="5" r="1.6"/><circle cx="15" cy="10" r="1.6"/><circle cx="15" cy="15" r="1.6"/>
                        </svg>
                        {{ config('app.name') }}
                    </a>
                </h1>

                <a href="{{ route('repositories') }}"
                   class="rounded-md px-2.5 py-1 text-sm text-muted transition-colors hover:bg-chip-hover hover:text-paper">Repositories</a>

                {{-- Page-specific buttons; a named slot that wasn't passed is undefined. --}}
                {{ $actions ?? '' }}
            </div>
        </header>

        <main class="mx-auto mt-6 max-w-4xl px-6 pb-10 lg:max-w-[100rem]">
            {{ $slot }}
        </main>
    </body>
</html>
