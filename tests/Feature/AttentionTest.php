<?php

beforeEach(function () {
    config()->set('orchestrator.repos', ['a/b' => 'repo-1']);

    fakeOrca();
});

it('lists my pull requests that have conflicts or unresolved threads', function () {
    fakeGitHub(
        mine: [pullRequestItem(1), pullRequestItem(2), pullRequestItem(3), pullRequestItem(4)],
        threads: [
            'PR_1' => ['mergeable' => 'CONFLICTING'],
            'PR_2' => ['resolved' => [true, false, false]],
            'PR_3' => ['resolved' => [true]],
            // Not computed yet — counts as clean until the next refresh.
            'PR_4' => ['mergeable' => 'UNKNOWN'],
        ],
        checks: ['PR_1' => 'FAILURE'],
    );

    loaded('attention')
        ->assertSet('error', null)
        ->assertCount('pullRequests', 2)
        ->assertSee('Pull request 1')
        ->assertSee('conflicts')
        ->assertSee('failure')
        ->assertSee('Pull request 2')
        ->assertSee('2 unresolved')
        ->assertDontSee('Pull request 3')
        ->assertDontSee('Pull request 4');
});

it('renders no panel when nothing needs attention', function () {
    fakeGitHub(mine: [pullRequestItem(3)], threads: ['PR_3' => ['resolved' => [true]]]);

    loaded('attention')->assertDontSee('Needs attention');
});

it('shows a failure instead of hiding the panel', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);

    loaded('attention')->assertSee('Needs attention');
});

it('starts a merge workspace for a conflicting pull request', function () {
    fakeGitHub(mine: [pullRequestItem(1)], threads: ['PR_1' => ['mergeable' => 'CONFLICTING', 'resolved' => [false]]]);
    fakeOrca(created: worktree(['branch' => 'refs/heads/fix-1']));

    loaded('attention')
        ->call('fix', 1)
        ->assertDispatchedTo('workspaces', 'workspace-created')
        ->assertSee('✓ fix-1 · in-progress');

    Process::assertRan(fn ($process): bool => in_array('/merge-upstream feature/PR_1', $process->command, true)
        && in_array('fix-1', $process->command, true));
});

it('asks to address the comments when there are no conflicts', function () {
    fakeGitHub(mine: [pullRequestItem(2)], threads: ['PR_2' => ['resolved' => [false]]]);
    fakeOrca(created: worktree());

    loaded('attention')->call('fix', 2);

    Process::assertRan(fn ($process): bool => in_array('Address the review comments in the current PR', $process->command, true));
});
