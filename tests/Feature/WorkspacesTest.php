<?php

use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);
    config()->set('app.timezone', 'UTC');
});

it('lists only the workspaces of mapped repositories, newest first', function () {
    fakeOrca([
        worktree(['branch' => 'refs/heads/older', 'createdAt' => 1_000]),
        worktree(['repoId' => 'somebody-elses', 'branch' => 'refs/heads/not-mine']),
        worktree(['branch' => 'refs/heads/main', 'isMainWorktree' => true]),
        worktree(['branch' => 'refs/heads/archived', 'isArchived' => true]),
        worktree(['branch' => 'refs/heads/newer', 'createdAt' => 2_000]),
    ]);

    loaded('workspaces')
        ->assertCount('workspaces', 2)
        ->assertSet('workspaces.0.branch', 'newer')
        ->assertSet('workspaces.1.branch', 'older')
        ->assertDontSee('not-mine')
        ->assertDontSee('archived');

    assertOrcaCalled('worktree list', 1);
});

it('shows the linked issue when Orca returns one', function () {
    fakeOrca([linkedWorktree(42, 'https://github.com/a/b/issues/42')]);

    loaded('workspaces')->assertSee('#42');
});

// Orca stamps createdAt in epoch milliseconds, so the clock must come out right in the
// app's timezone without any zone parsing on our side.
it('renders createdAt in the application timezone', function () {
    fakeOrca([worktree()]);

    loaded('workspaces')
        ->assertSee('Sat, Aug 8, 2026 11:18 AM')
        // Rendering again must survive the round-trip: a Carbon nested in an array
        // property comes back from Livewire as a string.
        ->call('$refresh')
        ->assertSee('Sat, Aug 8, 2026 11:18 AM');
});

it('lists a freshly played workspace without refetching', function () {
    fakeOrca();

    loaded('workspaces')
        ->assertSee('Nothing here.')
        ->dispatch('workspace-created', workspace: [
            'id' => 'ws-9',
            'branch' => 'feature/9',
            'status' => 'in-progress',
            'age' => null,
            'createdAt' => null,
            'ref' => null,
        ])
        ->assertCount('workspaces', 1)
        ->assertSee('feature/9');
});

// An empty refresh renders — but it must not become the list the next page paints from cache.
it('keeps the cached list when a refresh comes back empty', function () {
    $cached = ['workspaces' => [[
        'id' => 'ws-cached',
        'branch' => 'cached-branch',
        'status' => 'in-progress',
        'age' => null,
        'createdAt' => null,
        'ref' => null,
    ]]];

    Cache::forever('orchestrator.workspaces', $cached);

    fakeOrca();

    loaded('workspaces')
        ->assertCount('workspaces', 0)
        ->assertSee('Nothing here.');

    expect(Cache::get('orchestrator.workspaces'))->toBe($cached);
});

it('shows the failure instead of the list', function () {
    fakeOrcaFailing('Orca is not running');

    loaded('workspaces')->assertSee('Orca is not running');
});
