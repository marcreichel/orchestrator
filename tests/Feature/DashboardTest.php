<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('renders the three sections', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('issues')
        ->assertSeeLivewire('prs')
        ->assertSeeLivewire('workspaces');
});

// Without the memo Livewire bundles all three loads into one request, so a slow list
// holds up the others.
it('isolates every list into its own request', function () {
    $this->get('/')->assertSeeInOrder(array_fill(0, 3, '&quot;isolate&quot;:true'), escape: false);
});

// The point of the cache: the page itself talks to nothing, so it renders at once and
// `wire:init` fetches the live lists afterwards.
it('renders the cached lists without fetching', function () {
    Cache::forever('orchestrator.issues', [
        'assigned' => [['id' => 'I_1', 'number' => 1, 'title' => 'Cached issue', 'url' => '', 'labels' => []]],
        'other' => [],
        'claim' => [],
        'played' => [],
    ]);
    Cache::forever('orchestrator.workspaces', ['workspaces' => [[
        'branch' => 'cached-branch',
        'status' => 'active',
        'previewUrl' => null,
        'age' => null,
        'createdAt' => null,
        'ref' => null,
    ]]]);

    Http::preventStrayRequests();

    $this->get('/')
        ->assertSee('Cached issue')
        ->assertSee('cached-branch')
        // Nothing cached for the pull requests yet.
        ->assertSee('Loading…')
        ->assertSeeHtml('wire:init="load"')
        // Every five minutes. Not `.5m`: wire:poll parses only `ms` and `s`, so an
        // unparsed modifier would quietly poll at the 2s default.
        ->assertSeeHtml('wire:poll.300s="load"')
        // The header spinner runs while the background refresh is in flight.
        ->assertSeeHtml('wire:loading class="size-3 shrink-0 animate-spin');
});
