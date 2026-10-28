<?php

it('lists every repository with its id', function () {
    fakeOrca(repos: [['id' => 'f64cd685', 'displayName' => 'core-ng', 'path' => '/Users/me/core-ng']]);

    $this->get('/repositories')
        ->assertOk()
        ->assertSee('core-ng')
        ->assertSee('f64cd685')
        ->assertSeeHtml('aria-current="page"')
        // No Livewire here, so nothing would ever hide a spinner again.
        ->assertDontSeeHtml('animate-spin');
});

it('shows the failure instead of an error page when Orca is down', function () {
    fakeOrcaFailing('no token');

    $this->get('/repositories')
        ->assertOk()
        ->assertSee('no token');
});
