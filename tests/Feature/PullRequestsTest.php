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

// Only a working agent breathes — a lane of pulsing CI dots would drown it out.
it('shows a pending check as a steady dot', function () {
    fakeGitHub(pullRequests: [pullRequestItem(7)], checks: ['PR_7' => 'PENDING']);

    loaded('prs')
        ->assertSee('pending')
        ->assertSeeHtml('bg-amber')
        ->assertDontSeeHtml('animate-pulse');
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

// "#123 fix: …" next to the PR's own number reads as two ids, so the issue moves aside.
it('moves the issue a title leads with to the meta line', function () {
    fakeGitHub(pullRequests: [[...pullRequestItem(7), 'title' => '#31387 fix(contracts): Resolve ICT']]);

    loaded('prs')
        ->assertSee('fix(contracts): Resolve ICT')
        ->assertDontSeeHtml('>#31387 fix')
        ->assertSeeHtml('href="https://github.com/a/b/issues/31387"')
        ->assertSee('for #31387');
});

// Only my reviews inside the window count — a PR merely touched since doesn't.
it('counts the pull requests I reviewed in the last seven days', function () {
    fakeGitHub(
        reviewed: [pullRequestItem(1), pullRequestItem(2)],
        reviews: ['PR_1' => [now()->subDays(2)->toIso8601String()], 'PR_2' => [now()->subDays(9)->toIso8601String()]],
    );

    loaded('prs')
        ->assertSet('reviewedThisWeek', 1)
        ->assertSee('1 review done in the last 7 days');
});

it('keeps the last review count when the lookup fails', function () {
    fakeGitHub(reviewed: [pullRequestItem(1)], reviews: ['PR_1' => [now()->toIso8601String()]]);

    $component = loaded('prs')->assertSet('reviewedThisWeek', 1);

    Http::fake(['api.github.com/search/issues*' => Http::sequence()->push(['total_count' => 0, 'items' => []])->push([], 500)]);

    $component->call('load')
        ->assertSet('error', null)
        ->assertSet('reviewedThisWeek', 1);
});
