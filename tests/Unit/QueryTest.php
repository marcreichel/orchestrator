<?php

use App\Support\Board;
use App\Support\GitHub;

// Repos must be ORed — bare `repo:a repo:b` is an AND and matches nothing. WIP/Screening,
// blocked and epics are excluded for every caller, so all three issue lists get them.
it('scopes an issue search to the mapped repos', function () {
    expect(GitHub::issueQuery(['a/b', 'c/d'], 'assignee:@me is:issue is:open'))
        ->toBe('(repo:a/b OR repo:c/d) -label:WIP -label:Screening -label:blocked -type:Epic assignee:@me is:issue is:open')
        ->and(GitHub::issueQuery(['a/b'], ''))
        ->toBe('(repo:a/b) -label:WIP -label:Screening -label:blocked -type:Epic');
});

// Same OR-scoping, but reviews are shown whatever they're labelled. `is:open` must stay:
// a review request outlives the merge, so without it the list never shrinks.
it('asks for open pull requests waiting on my review', function () {
    expect(GitHub::prQuery(['a/b', 'c/d']))
        ->toBe('(repo:a/b OR repo:c/d) is:pr is:open review-requested:@me -is:draft')
        ->and(GitHub::prQuery(['a/b']))->toContain('is:open');
});

// JSON numbers and repo-qualified strings both match; neither over-matches.
it('ignores issues by number or by repo and number', function () {
    $ignore = ['42', 'c/d#7'];

    expect(GitHub::isIgnored($ignore, 'a/b', 42))->toBeTrue()
        ->and(GitHub::isIgnored($ignore, 'c/d', 7))->toBeTrue()
        ->and(GitHub::isIgnored($ignore, 'a/b', 7))->toBeFalse()
        ->and(GitHub::isIgnored($ignore, 'a/b', 420))->toBeFalse()
        ->and(GitHub::isIgnored([], 'a/b', 42))->toBeFalse();
});

// Everything from the cutoff rightwards is blocked; earlier columns stay listed.
it('blocks every column from the cutoff onwards', function () {
    $blocked = Board::blockedStatuses(['Open', 'Ready', 'On Hold', 'In Progress', 'Done'], 'On Hold');

    expect($blocked)->toBe(['On Hold', 'In Progress', 'Done'])
        ->and($blocked)->not->toContain('Ready');
});

it('fails loudly when the cutoff column is gone', function () {
    Board::blockedStatuses(['Open'], 'On Hold');
})->throws(RuntimeException::class, 'Board has no "On Hold" status — got Open');
