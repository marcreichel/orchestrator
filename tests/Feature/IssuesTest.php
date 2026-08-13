<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Polyscope\Laravel\Facades\Polyscope;

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);
    config()->set('orchestrator.ignore', []);
    config()->set('orchestrator.other_issues', 'is:open is:issue no:assignee');
    config()->set('orchestrator.unassigned_bugs', 'is:open is:issue no:assignee type:Bug');

    // Every load looks for existing workspaces; byDefault so a test can say otherwise.
    Polyscope::shouldReceive('workspaces')->andReturn([])->byDefault();
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

    Polyscope::shouldReceive('createWorkspace')
        ->once()
        ->withArgs(fn (array $data): bool => $data === [
            'repository_id' => 'repo-1',
            'issue_url' => 'https://github.com/a/b/issues/1',
        ])
        ->andReturn(workspace(['id' => 'ws-1', 'branch' => 'feature/1', 'status' => 'active']));

    // The prompt follows as a message, once the issue is checked out.
    Polyscope::shouldReceive('client->sendWorkspaceMessage')->once()->with('ws-1', '/implement-issue 1');

    loaded('issues')
        ->call('play', 'I_1')
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ feature/1 · active')
        ->assertSet('played.I_1.drop', true);

    // The claim assigns me and moves the issue to the "In Progress" option id.
    Http::assertSent(fn (Request $request): bool => str_contains(graphqlDocument($request), 'updateProjectV2ItemFieldValue')
        && data_get($request->data(), 'variables.option') === 'o4'
        && data_get($request->data(), 'variables.item') === 'ITEM');
});

it('keeps the workspace visible when the claim fails', function () {
    fakeGitHub(assigned: [issueItem(1)], claimError: 'Resource not accessible by personal access token');

    Polyscope::shouldReceive('createWorkspace')->andReturn(workspace(['branch' => 'feature/1', 'status' => 'active']));
    Polyscope::shouldReceive('client->sendWorkspaceMessage');

    loaded('issues')
        ->call('play', 'I_1')
        ->assertSee('✓ feature/1 · active')
        ->assertSee('✗ Resource not accessible by personal access token')
        // No self-dismissal: the issue is still unassigned, so the row stays.
        ->assertSet('played.I_1.drop', false);
});

it('leaves the issue untouched when the workspace cannot be created', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    Polyscope::shouldReceive('createWorkspace')->andThrow(new RuntimeException('Server offline'));

    loaded('issues')
        ->call('play', 'I_1')
        ->assertSee('✗ Server offline')
        ->assertNotDispatched('workspace-created');

    Http::assertNotSent(fn (Request $request): bool => str_contains(graphqlDocument($request), 'updateIssue'));
});

it('shows the existing workspace instead of a play button', function () {
    fakeGitHub(assigned: [issueItem(1)], other: [issueItem(2)]);

    Polyscope::shouldReceive('workspaces')->andReturn([
        workspace(['branch' => 'feature/1', 'issue_number' => 1, 'issue_url' => 'https://github.com/a/b/issues/1']),
    ]);

    loaded('issues')
        ->assertSee('✓ feature/1 · active')
        ->assertDontSeeHtml('wire:click="play(\'I_1\')"')
        // The unplayed issue keeps its ▶.
        ->assertSeeHtml('wire:click="play(\'I_2\')"');
});

it('keeps the issues playable when the workspace lookup fails', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    Polyscope::shouldReceive('workspaces')->andThrow(new RuntimeException('Missing API token'));

    loaded('issues')
        ->assertSet('error', null)
        ->assertSee('Issue 1')
        ->assertSeeHtml('wire:click="play(\'I_1\')"');
});

it('drops the row once the issue is in flight', function () {
    fakeGitHub(assigned: [issueItem(1)]);

    loaded('issues')
        ->call('dismiss', 'I_1')
        ->assertCount('assigned', 0)
        ->assertSee('Nothing here.');
});

// `nodes(ids:)` is capped at 100, and three lists of up to 100 issues each blow past it.
it('looks the board up in chunks of a hundred issues', function () {
    fakeGitHub(assigned: array_map(issueItem(...), range(1, 101)));

    loaded('issues')->assertCount('assigned', 101);

    expect(Http::recorded(fn (Request $request): bool => str_contains(graphqlDocument($request), 'projectItems')))
        ->toHaveCount(2);
});

// Both lists mark their played rows with it, and the workspace list renders it — one
// fetch has to cover all three, or a refresh hits Polyscope three times over.
it('fetches the workspaces once for every list of a refresh', function () {
    fakeGitHub(assigned: [issueItem(1)], pullRequests: [pullRequestItem(5)]);

    Polyscope::shouldReceive('workspaces')->once()->andReturn([]);

    loaded('issues');
    loaded('prs');
    loaded('workspaces');
});

it('shows the failure instead of the list', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    loaded('issues')
        ->assertCount('assigned', 0)
        ->assertSee('401');
});
