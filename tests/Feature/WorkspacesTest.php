<?php

use Livewire\Livewire;
use Polyscope\Laravel\Facades\Polyscope;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);
    config()->set('app.timezone', 'UTC');
});

it('lists only the workspaces of mapped repositories', function () {
    Polyscope::shouldReceive('workspaces')->once()->andReturn([
        workspace(['branch' => 'feature/1', 'preview_url' => 'https://preview.test/1']),
        workspace(['repo_id' => 'somebody-elses', 'branch' => 'not-mine']),
    ]);

    Livewire::test('workspaces')
        ->assertCount('workspaces', 1)
        ->assertSee('feature/1')
        ->assertSeeHtml('https://preview.test/1')
        ->assertDontSee('not-mine');
});

it('shows the linked issue when Polyscope returns one', function () {
    Polyscope::shouldReceive('workspaces')->andReturn([
        workspace([
            'issue_number' => 42,
            'issue_title' => 'Fix the thing',
            'issue_url' => 'https://github.com/a/b/issues/42',
        ]),
    ]);

    Livewire::test('workspaces')->assertSee('#42 Fix the thing');
});

// Polyscope timestamps are UTC but carry no zone, so a wrong reading would shift the
// clock by the local offset instead of rendering 11:18 back.
it('reads the zoneless created_at as UTC', function () {
    Polyscope::shouldReceive('workspaces')->andReturn([workspace(['created_at' => '2026-08-08 11:18:15'])]);

    Livewire::test('workspaces')
        ->assertSee('Sat, Aug 8, 2026 11:18 AM')
        // Rendering again must survive the round-trip: a Carbon nested in an array
        // property comes back from Livewire as a string.
        ->call('$refresh')
        ->assertSee('Sat, Aug 8, 2026 11:18 AM');
});

it('lists a freshly played workspace without refetching', function () {
    Polyscope::shouldReceive('workspaces')->once()->andReturn([]);

    Livewire::test('workspaces')
        ->assertSee('Nothing here.')
        ->dispatch('workspace-created', workspace: [
            'branch' => 'feature/9',
            'status' => 'active',
            'previewUrl' => null,
            'age' => null,
            'createdAt' => null,
            'ref' => null,
        ])
        ->assertCount('workspaces', 1)
        ->assertSee('feature/9');
});

it('shows the failure instead of the list', function () {
    Polyscope::shouldReceive('workspaces')->andThrow(new RuntimeException('Missing API token'));

    Livewire::test('workspaces')->assertSee('Missing API token');
});
