<?php

use Polyscope\Laravel\Facades\Polyscope;
use Polyscope\Laravel\Resources\Repository;

it('lists every repository with its id', function () {
    Polyscope::shouldReceive('repositories')->once()->andReturn([
        new Repository(['id' => 'f64cd685', 'name' => 'core-ng', 'path' => '/Users/me/core-ng']),
    ]);

    $this->get('/repositories')
        ->assertOk()
        ->assertSee('core-ng')
        ->assertSee('f64cd685');
});

it('shows the failure instead of an error page when Polyscope is down', function () {
    Polyscope::shouldReceive('repositories')->andThrow(new RuntimeException('no token'));

    $this->get('/repositories')
        ->assertOk()
        ->assertSee('no token');
});
