<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

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

    assertOrcaCalled('worktree ps', 1);
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

it('shows what the agent in a workspace is doing', function () {
    fakeOrca([worktree(['status' => 'working'])]);

    loaded('workspaces')->assertSee('working · in-progress');
});

it('brings the first terminal of a workspace to the front', function () {
    fakeOrca([worktree()]);

    loaded('workspaces')->call('open', 'repo-1::/orca/feature-1')->assertSet('error', null);

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'terminal switch --terminal term_1'));
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'open -a Orca'));
});

it('says so when a workspace has no live terminal to open', function () {
    fakeOrca([worktree()], terminals: []);

    loaded('workspaces')->call('open', 'repo-1::/orca/feature-1')->assertSet('error', 'This workspace has no live terminal');
});

it('opens the changed files of a workspace as diffs', function () {
    fakeOrca([worktree()]);

    loaded('workspaces')->call('showChanges', 'repo-1::/orca/feature-1');

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'open-changed --mode diff --focus --worktree id:repo-1::/orca/feature-1'));
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'open -a Orca'));
});

it('removes a workspace and drops its row', function () {
    fakeOrca([worktree()]);

    loaded('workspaces')
        ->assertCount('workspaces', 1)
        ->call('remove', 'repo-1::/orca/feature-1')
        ->assertCount('workspaces', 0);

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'worktree rm --worktree id:repo-1::/orca/feature-1'));
});

it('lists the ports a workspace is listening on, falling back to the host and port', function () {
    fakeOrca([worktree()], ports: [
        ['port' => 3000, 'connectHost' => '127.0.0.1', 'advertisedUrl' => 'http://localhost:3000'],
        ['port' => 8080, 'connectHost' => '127.0.0.1'],
    ]);

    loaded('workspaces')
        ->call('showPorts', 'repo-1::/orca/feature-1')
        ->assertSet('workspaces.0.ports', [
            ['port' => 3000, 'url' => 'http://localhost:3000'],
            ['port' => 8080, 'url' => 'http://127.0.0.1:8080'],
        ])
        ->assertSee(':8080');
});

it('says so when a workspace listens on no ports', function () {
    fakeOrca([worktree()]);

    loaded('workspaces')->call('showPorts', 'repo-1::/orca/feature-1')->assertSee('No ports listening');
});
