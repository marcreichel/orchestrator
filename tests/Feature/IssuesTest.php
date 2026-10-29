<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);
    config()->set('orchestrator.ignore', []);
    config()->set('orchestrator.other_issues', 'is:open is:issue no:assignee');
    config()->set('orchestrator.unassigned_bugs', 'is:open is:issue no:assignee type:Bug');

    // Every load looks for existing workspaces; a test can fake something else.
    fakeOrca();
});

it('lists assigned issues without the ignored and in-flight ones', function () {
    config()->set('orchestrator.ignore', [3]);

    fakeGitHub(
        assigned: [issueItem(1), issueItem(2), issueItem(3)],
        statuses: ['I_2' => 'In Progress'],
    );

    loaded('issues')
        ->assertSet('error', null)
        ->assertCount('assigned', 1)
        ->assertSee('Issue 1')
        ->assertDontSee('Issue 2')
        ->assertDontSee('Issue 3');
});

it('drops issues from the second list that are already assigned to me', function () {
    fakeGitHub(assigned: [issueItem(1)], other: [issueItem(1), issueItem(2)]);

    loaded('issues')
        ->assertCount('assigned', 1)
        ->assertCount('other', 1)
        ->assertSee('Issue 2');
});

// Only my own issues start open. wire:ignore.self keeps a list the user opened from
// snapping shut on the next poll.
it('folds the extra lists away', function () {
    fakeGitHub(assigned: [issueItem(1)], bugs: [issueItem(4)]);

    $html = loaded('issues')->html();

    $fold = strpos($html, 'wire:ignore.self');

    // Assigned to me comes before the first fold, Unassigned bugs after it.
    expect($fold)->not->toBeFalse()
        ->and(strpos($html, 'Assigned to me'))->toBeLessThan($fold)
        ->and(strpos($html, 'Unassigned bugs'))->toBeGreaterThan($fold);
});

it('lists unassigned bugs in their own section', function () {
    fakeGitHub(assigned: [issueItem(1)], bugs: [issueItem(4)]);

    loaded('issues')
        ->assertCount('bugs', 1)
        ->assertSee('Unassigned bugs')
        ->assertSee('Issue 4');

    // The three lists share one board lookup — a per-list unblocked() call would be three.
    expect(Http::recorded(fn (Request $request): bool => str_contains(graphqlDocument($request), 'projectItems')))
        ->toHaveCount(1);
});

it('drops bugs the second list already shows', function () {
    fakeGitHub(other: [issueItem(2)], bugs: [issueItem(2), issueItem(4)]);

    loaded('issues')
        ->assertCount('other', 1)
        ->assertCount('bugs', 1)
        ->assertSet('bugs.0.number', 4);
});

it('creates the workspace before claiming the issue', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    fakeOrca(created: worktree(['branch' => 'refs/heads/feature/1']));

    loaded('issues')
        ->call('play', 'I_1')
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ feature/1 · in-progress')
        ->assertSet('played.I_1.drop', true);

    // One call creates the worktree with the issue attached and starts the agent on the prompt.
    Process::assertRan(fn ($process): bool => $process->command === [
        'orca', 'worktree', 'create',
        '--repo', 'id:repo-1',
        '--name', 'issue-1',
        '--no-parent',
        '--reference', 'https://github.com/a/b/issues/1',
        '--agent', 'claude',
        '--prompt', '/implement-issue 1',
        '--json',
    ]);

    // The claim assigns me and moves the issue to the "In Progress" option id.
    Http::assertSent(fn (Request $request): bool => str_contains(graphqlDocument($request), 'updateProjectV2ItemFieldValue')
        && data_get($request->data(), 'variables.option') === 'o4'
        && data_get($request->data(), 'variables.item') === 'ITEM');
});

it('keeps the workspace visible when the claim fails', function () {
    fakeGitHub(assigned: [issueItem(1)], claimError: 'Resource not accessible by personal access token');

    fakeOrca();

    loaded('issues')
        ->call('play', 'I_1')
        ->assertSee('✓ feature/1 · in-progress')
        ->assertSee('✗ Resource not accessible by personal access token')
        // No self-dismissal: the issue is still unassigned, so the row stays.
        ->assertSet('played.I_1.drop', false);
});

it('leaves the issue untouched when the workspace cannot be created', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    fakeOrcaFailing('Server offline');

    loaded('issues')
        ->call('play', 'I_1')
        ->assertSee('✗ Server offline')
        ->assertNotDispatched('workspace-created');

    Http::assertNotSent(fn (Request $request): bool => str_contains(graphqlDocument($request), 'updateIssue'));
});

it('shows the existing workspace instead of a play button', function () {
    fakeGitHub(assigned: [issueItem(1)], other: [issueItem(2)]);

    fakeOrca([linkedWorktree(1, 'https://github.com/a/b/issues/1')]);

    loaded('issues')
        ->assertSee('✓ feature/1 · in-progress')
        ->assertDontSeeHtml('wire:click="play(\'I_1\'')
        // The unplayed issue keeps its ▶.
        ->assertSeeHtml('wire:click="play(\'I_2\', ! $event.altKey)"');
});

it('keeps the issues playable when the workspace lookup fails', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    fakeOrcaFailing('Orca is not running');

    loaded('issues')
        ->assertSet('error', null)
        ->assertSee('Issue 1')
        ->assertSeeHtml('wire:click="play(\'I_1\', ! $event.altKey)"');
});

// A dead Orca can't tell a played issue from an unplayed one, so a refresh during
// the outage must not cache a run with every ✓ dropped — the next page load paints it.
it('keeps the played marks when the workspace lookup fails', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    fakeOrca([linkedWorktree(1, 'https://github.com/a/b/issues/1')]);

    loaded('issues')->assertSee('✓ feature/1 · in-progress');

    // Second refresh, same issue, Orca now down.
    Cache::forget('orchestrator.workspaces.all');
    fakeOrcaFailing('Orca is not running');

    loaded('issues')->assertSee('✓ feature/1 · in-progress');

    expect(Cache::get('orchestrator.issues')['played'])->toBe(['I_1' => ['status' => '✓ feature/1 · in-progress']]);
});

// ⌥-click plays without claiming: the workspace starts, the issue stays where it is,
// and so does its row.
it('plays without claiming on option-click', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    fakeOrca();

    loaded('issues')
        ->call('play', 'I_1', false)
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ feature/1 · in-progress')
        ->assertSet('played.I_1.drop', false);

    Http::assertNotSent(fn (Request $request): bool => str_contains(graphqlDocument($request), 'updateProjectV2ItemFieldValue'));
});

// Only the first page of a search is ever fetched, so a capped list has to say so
// instead of reading as the whole picture.
it('marks a list that GitHub had more matches for', function () {
    fakeGitHub(assigned: [issueItem(1)], totalCount: 214);

    loaded('issues')
        ->assertSet('truncated.assigned', true)
        ->assertSee('1+');
});

it('leaves a complete list unmarked', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    loaded('issues')
        ->assertSet('truncated.assigned', false)
        ->assertDontSee('1+');
});

it('drops the row once the issue is in flight', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    loaded('issues')
        ->call('dismiss', 'I_1')
        ->assertCount('assigned', 0)
        ->assertSee('Nothing is assigned to you.');
});

// `nodes(ids:)` is capped at 100, and three lists of up to 100 issues each blow past it.
it('looks the board up in chunks of a hundred issues', function () {
    fakeGitHub(assigned: array_map(issueItem(...), range(1, 101)));

    loaded('issues')->assertCount('assigned', 101);

    expect(Http::recorded(fn (Request $request): bool => str_contains(graphqlDocument($request), 'projectItems')))
        ->toHaveCount(2);
});

// Both lists mark their played rows with it, and the workspace list renders it — one
// fetch has to cover all three, or a refresh hits Orca three times over.
it('fetches the workspaces once for every list of a refresh', function () {
    fakeGitHub(assigned: [issueItem(1)], pullRequests: [pullRequestItem(5)]);

    loaded('issues');
    loaded('prs');
    loaded('workspaces');

    assertOrcaCalled('worktree ps', 1);
});

it('shows the failure instead of the list', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    loaded('issues')
        ->assertCount('assigned', 0)
        ->assertSee('401')
        // A failed refresh still ends the header's "Updating…".
        ->assertDispatched('refreshed');
});

it('searches the open issues of the mapped repos without the lists\' filters', function () {
    fakeGitHub(assigned: [issueItem(1)], search: [issueItem(7)]);

    loaded('issues')
        ->set('search', 'label:bug flaky')
        ->assertCount('results', 1)
        ->assertSee('Results')
        ->assertSee('Issue 7')
        // The results stand in for the lists.
        ->assertDontSee('Issue 1')
        ->assertDontSee('Assigned to me');

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'q') === '(repo:a/b) is:issue is:open label:bug flaky');
});

it('brings the lists back once the search is cleared', function () {
    fakeGitHub(assigned: [issueItem(1)], search: [issueItem(7)]);

    loaded('issues')
        ->set('search', 'flaky')
        ->set('search', '')
        ->assertSet('results', [])
        ->assertSee('Issue 1')
        ->assertDontSee('Issue 7');
});

it('leaves a single character unsearched', function () {
    fakeGitHub(search: [issueItem(7)]);

    loaded('issues')->set('search', 'f')->assertSet('results', []);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'search/issues') && ! str_contains((string) data_get($request->data(), 'q'), 'is:blocked'));
});

it('looks a number up directly, closed or not', function () {
    fakeGitHub(byNumber: [issueItem(9) + ['state' => 'closed']]);

    loaded('issues')
        ->set('search', '#9')
        ->assertCount('results', 1)
        ->assertSee('Issue 9')
        ->assertSee('closed');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'repos/a/b/issues/9'));
});

it('drops a pull request from a number lookup', function () {
    fakeGitHub(byNumber: [issueItem(9) + ['pull_request' => ['url' => 'x']]]);

    loaded('issues')
        ->set('search', '9')
        ->assertSet('results', [])
        ->assertSee('No issues match.');
});

it('marks search results that already have a workspace', function () {
    fakeGitHub(search: [issueItem(7)]);

    fakeOrca([linkedWorktree(7, 'https://github.com/a/b/issues/7')]);

    loaded('issues')
        ->set('search', 'flaky')
        ->assertSee('✓ feature/1 · in-progress')
        ->assertDontSeeHtml('wire:click="play(\'I_7\'');
});

it('plays a search result and keeps its row', function () {
    fakeGitHub(search: [issueItem(7)]);

    fakeOrca(created: linkedWorktree(7, 'https://github.com/a/b/issues/7'));

    loaded('issues')
        ->set('search', 'flaky')
        ->call('play', 'I_7')
        ->assertSet('played.I_7.drop', true)
        ->assertSee('Issue 7')
        ->assertDontSeeHtml('$wire.dismiss(\'I_7\')');
});

it('shows a failed search without touching the lists', function () {
    Cache::forever('orchestrator.issues', ['assigned' => [['id' => 'I_1', 'number' => 1, 'title' => 'Issue 1', 'url' => 'u', 'repo' => 'a/b', 'labels' => []]]]);

    Http::fake(['api.github.com/search/issues*' => Http::response(['message' => 'Rate limited'], 403)]);

    Livewire::test('issues')
        ->set('search', 'flaky')
        ->assertSee('Rate limited')
        ->set('search', '')
        ->assertSet('error', null)
        ->assertSee('Issue 1');
});
