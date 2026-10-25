<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);

    // Every load looks for existing workspaces; a test can fake something else.
    fakeOrca();
});

it('lists pull requests waiting on my review with their CI state', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)], checks: ['PR_7' => 'FAILURE']);

    loaded('prs')
        ->assertSet('error', null)
        ->assertSee('Pull request 7')
        ->assertSee('@octocat')
        ->assertSee('failure')
        ->assertSeeHtml('bg-rose');
});

it('starts a review workspace for the pull request', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    fakeOrca(created: worktree(['branch' => 'refs/heads/review/7']));

    loaded('prs')
        ->call('review', 7)
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ review/7 · in-progress')
        // The row stays put — the review still has to be written.
        ->assertSee('Pull request 7');

    Process::assertRan(fn ($process): bool => $process->command === [
        'orca', 'worktree', 'create',
        '--repo', 'id:repo-1',
        '--name', 'pr-7',
        '--no-parent',
        '--reference', 'https://github.com/a/b/pull/7',
        '--agent', 'claude',
        '--prompt', '/my-pr-review 7',
        '--json',
    ]);
});

it('shows the existing review workspace instead of a play button', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    fakeOrca([linkedWorktree(7, 'https://github.com/a/b/pull/7', ['branch' => 'refs/heads/review/7'])]);

    loaded('prs')
        ->assertSee('✓ review/7 · in-progress')
        ->assertDontSeeHtml('wire:click="review(7)"');
});

it('reports a workspace failure on the row', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)]);

    fakeOrcaFailing('Server offline');

    loaded('prs')
        ->call('review', 7)
        ->assertSee('✗ Server offline')
        ->assertNotDispatched('workspace-created');
});

// `nodes(ids:)` is capped at 100, and a page of review requests can hold exactly that
// many — so the lookup must chunk rather than lean on the two numbers matching.
it('looks the check states up in chunks of a hundred pull requests', function () {
    fakeGitHub(pullRequests: array_map(pullRequestItem(...), range(1, 101)));

    loaded('prs')->assertCount('pullRequests', 101);

    expect(Http::recorded(fn (Request $request): bool => str_contains(graphqlDocument($request), 'statusCheckRollup')))
        ->toHaveCount(2);
});
