<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub
    |--------------------------------------------------------------------------
    |
    | A personal access token with the `repo` and `read:project` scopes. The
    | project board lookup fails without `read:project`.
    |
    */

    'github_token' => env('GITHUB_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Repositories
    |--------------------------------------------------------------------------
    |
    | GitHub repository full name => Polyscope repository id. Issues and pull
    | requests are only searched in these repos, and only workspaces belonging
    | to them are listed.
    |
    */

    'repos' => [
        'artemeon/core-ng' => 'f64cd685',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra issue lists
    |--------------------------------------------------------------------------
    |
    | Free-form searches shown next to the issues assigned to me, each as its
    | own section. They are filtered top to bottom — an issue already shown by
    | an earlier list never repeats in a later one. Set either to null to hide
    | that section.
    |
    */

    'other_issues' => 'is:open is:issue label:"✻ Claude"',

    'unassigned_bugs' => 'is:open is:issue no:assignee type:Bug',

    /*
    |--------------------------------------------------------------------------
    | Ignored issues
    |--------------------------------------------------------------------------
    |
    | Either a bare number (matches that number in any repo) or the fully
    | qualified `owner/repo#number`.
    |
    */

    'ignore' => [
        14230,
    ],

    /*
    |--------------------------------------------------------------------------
    | Project board
    |--------------------------------------------------------------------------
    |
    | Board columns are ordered, so `cutoff` and everything after it counts as
    | "already in flight" and is hidden — a new column added past the cutoff is
    | excluded automatically. `started` is where the claim checkbox moves an
    | issue when you play it.
    |
    */

    'board' => [
        'org' => 'artemeon',
        'name' => 'SCRUM Board',
        'cutoff' => 'On Hold',
        'started' => 'In Progress',
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace prompts
    |--------------------------------------------------------------------------
    |
    | The first prompt sent to a freshly created workspace. `%d` is the issue
    | or pull request number.
    |
    */

    'prompts' => [
        'issue' => '/implement-issue %d',
        'pull_request' => '/my-pr-review %d',
    ],

];
