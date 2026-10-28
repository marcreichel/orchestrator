<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=inter:400,500,600|jetbrains-mono:400,500&display=swap">

        @vite('resources/css/app.css')
    </head>
    <body class="bg-ink text-sm/normal text-paper">
        {{-- Pinned and translucent, so the lanes scroll under it. Fixed h-15, its hairline
             included: the lane and list headings pin themselves right below it (3.75rem, see
             --lane-top). Full width; only its contents keep to the page column. --}}
        <header class="material sticky top-0 z-10 h-15 border-b border-line">
            {{-- Equal outer tracks keep the centre slot in the true middle whatever sits either
                 side; plain 1fr won't shrink below its content, so a tight header pushes rather
                 than overlaps. --}}
            <div class="mx-auto grid h-full max-w-4xl grid-cols-[1fr_auto_1fr] items-center gap-2 px-4 sm:px-6 lg:max-w-[100rem]">
                <div class="flex items-center">
                    <h1 class="flex text-lg font-semibold tracking-[-.03em]">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5">
                            <svg width="26" height="26" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="5" height="14" rx="2.5"/><rect x="13.5" y="9" width="5" height="14" rx="2.5"/><rect x="21" y="13" width="5" height="14" rx="2.5"/></svg>
                            {{ config('app.name') }}
                        </a>
                    </h1>

                    {{-- Pages sit next to the name. --}}
                    <nav class="ml-4 flex items-center">
                        <a href="{{ route('repositories') }}" @if (request()->routeIs('repositories')) aria-current="page" @endif
                           class="rounded-md px-2.5 py-1 text-sm text-muted transition-colors hover:bg-chip-hover hover:text-paper aria-[current=page]:text-paper">Repositories</a>
                    </nav>
                </div>

                {{-- Page-specific slots; a named slot that wasn't passed is undefined. The
                     wrappers stay even when empty, or the actions would slide into the
                     centre track. --}}
                <div>{{ $center ?? '' }}</div>

                <div class="flex items-center justify-end gap-2">{{ $actions ?? '' }}</div>
            </div>
        </header>

        <main class="mx-auto mt-6 max-w-4xl px-4 pb-10 sm:px-6 lg:max-w-[100rem]">
            {{ $slot }}
        </main>
    </body>
</html>
