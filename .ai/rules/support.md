---
paths:
  - app/Support/GitHub.php
  - app/Support/Workspaces.php
---

# Support

## GitHub search and GraphQL traps
Multiple bare `repo:` qualifiers are ANDed by GitHub and always return 0 hits. They must be ORed inside parens — `(repo:a/b OR repo:c/d)` — which only parses when `advanced_search=true` is sent with the search request.

GitHub's GraphQL endpoint answers HTTP 200 with an `errors` array on failure, so `->throw()` never fires. Failures have to be raised by inspecting the body (see GitHub::graphql()).

## Polyscope SDK quirks
Workspace timestamps are UTC but carry no zone ("2026-08-08 11:18:15"), so they must be parsed with an explicit UTC: `Carbon::parse($ws->createdAt, 'UTC')`. Without it Carbon reads them in the app timezone and the age is off by the local offset.

The workspace *list* endpoint returns thin resources: `repository`, `agent` and `stats` are null, and a linked issue/PR gives back its number but not its title. Only use fields a listed workspace also has.

`services.polyscope.token` must be null (not an empty string) for the SDK to fall back to the token the Polyscope desktop app stores locally — hence `env('POLYSCOPE_TOKEN') ?: null`.

## An empty workspace list can mean a broken response
The SDK maps any unreadable 200 (empty body, invalid JSON, a body without a `data` key) to an empty array instead of raising — see MakesHttpRequests::decodeResponseBody() and ManagesWorkspaces::workspaces(). So `Polyscope::workspaces()` returning nothing is indistinguishable from an account that really has no workspaces, and it is the only way a list can go silently empty (GitHub's failures all throw).

Treat an empty result as suspect where it would outlive the request: the workspaces component renders it but does not write it to `orchestrator.workspaces`, so one bad answer can't keep painting an empty list on every page load. Telling the two apart for real needs the raw envelope via `Polyscope::client()->get('v1/workspaces')`, which costs the `shouldReceive('workspaces')` mocking used across the tests — not worth it so far.

## byRef() returns null on failure, never an empty array
`Workspaces::byRef()` returns `null` when the Polyscope lookup failed and an array (possibly empty) when it succeeded. The distinction is load-bearing: the issue and pull request lists cache their played/reviewed ✓ marks, and an outage that read as "nothing has been played" would cache a run with every mark dropped, which the next page load then paints. On null they keep the marks the last good lookup left; on an empty array they clear them. Do not collapse the two back into `[]`.

`Workspaces::all()` is wrapped in `Cache::lock(...)->block()`. The three lists are `#[Isolate]`d and their `wire:init` requests go out together, so without it all three miss the 15s cache in the same instant and each fetches the whole list. The lock, not the cache alone, is what makes it one fetch per refresh — the "fetches the workspaces once for every list of a refresh" test covers it.

## Searches return one page plus a truncated flag
`searchItems()`, `searchMany()`, `issues()` and `pullRequests()` all return `['items' => [...], 'truncated' => bool]`, not a bare list. Only the first page (`per_page=100`) is ever requested, so `truncated` is `total_count > count($items)` and the sections render it as a `+` on the count. Without it a capped list reads as the whole picture — and the board filter then thins that arbitrary 100 further, so the number on screen looks authoritative when it is not.
