<?php

use Livewire\Livewire;
use Polyscope\Laravel\Facades\Polyscope;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);

    // Every load looks for existing workspaces; byDefault so a test can say otherwise.
    Polyscope::shouldReceive('workspaces')->andReturn([])->byDefault();
});

it('lists pull requests waiting on my review with their CI state', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)], checks: ['PR_7' => 'FAILURE']);

    Livewire::test('prs')
        ->assertSet('error', null)
        ->assertSee('Pull request 7')
        ->assertSee('@octocat')
        ->assertSee('failure')
        ->assertSeeHtml('bg-rose');
});

it('starts a review workspace for the pull request', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    Polyscope::shouldReceive('createWorkspace')
        ->once()
        ->withArgs(fn (array $data): bool => $data === [
            'repository_id' => 'repo-1',
            'pull_request_url' => 'https://github.com/a/b/pull/7',
        ])
        ->andReturn(workspace(['id' => 'ws-7', 'branch' => 'review/7', 'status' => 'active']));

    // The prompt follows as a message, once the pull request is checked out.
    Polyscope::shouldReceive('client->sendWorkspaceMessage')->once()->with('ws-7', '/my-pr-review 7');

    Livewire::test('prs')
        ->call('review', 7)
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ review/7 · active')
        // The row stays put — the review still has to be written.
        ->assertSee('Pull request 7');
});

it('shows the existing review workspace instead of a play button', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    Polyscope::shouldReceive('workspaces')->andReturn([
        workspace(['branch' => 'review/7', 'pr_number' => 7, 'pr_url' => 'https://github.com/a/b/pull/7']),
    ]);

    Livewire::test('prs')
        ->assertSee('✓ review/7 · active')
        ->assertDontSeeHtml('wire:click="review(7)"');
});

it('reports a workspace failure on the row', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    Polyscope::shouldReceive('createWorkspace')->andThrow(new RuntimeException('Server offline'));

    Livewire::test('prs')
        ->call('review', 7)
        ->assertSee('✗ Server offline')
        ->assertNotDispatched('workspace-created');
});
