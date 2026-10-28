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

// Pages are a menu next to the app name, usage sits in the centre track and the page's
// own actions stay on the right.
it('lays the header out as menu, usage, actions', function () {
    $this->get('/')
        ->assertSeeInOrder(['grid-cols-[1fr_auto_1fr]', config('app.name'), '<nav', 'Repositories', '</nav>'], escape: false)
        ->assertSeeInOrder(['</nav>', 'wire:name="usage"', 'Refresh'], escape: false);
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
        // Nothing cached for the pull requests yet: placeholder rows, named for screen readers.
        ->assertSeeHtml('<li class="sr-only">Loading…</li>')
        ->assertSeeHtml('wire:init="load"')
        // Every five minutes. Not `.5m`: wire:poll parses only `ms` and `s`, so an
        // unparsed modifier would quietly poll at the 2s default.
        ->assertSeeHtml('wire:poll.300s="load"')
        // The header spinner runs while the background refresh is in flight, and announces
        // itself — an unlabelled one is silent to a screen reader.
        ->assertSeeHtml('wire:loading role="status" aria-label="Refreshing"');
});
