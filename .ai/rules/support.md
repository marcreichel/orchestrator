---
paths:
  - app/Support/GitHub.php
  - app/Support/Workspaces.php
---

# Support

## GitHub search and GraphQL traps
Multiple bare `repo:` qualifiers are ANDed by GitHub and always return 0 hits. They must be ORed inside parens — `(repo:a/b OR repo:c/d)` — which only parses when `advanced_search=true` is sent with the search request.

GitHub's GraphQL endpoint answers HTTP 200 with an `errors` array on failure, so `->throw()` never fires. Failures have to be raised by inspecting the body (see GitHub::graphql()).

## Orca CLI quirks
Workspaces are Orca worktrees, driven through the `orca` CLI (`Workspaces::orca()`), not an SDK. `worktree list` returns every worktree of every repo, including each repo's main checkout and archived ones — `all()` keeps only mapped, non-main, non-archived ones. `createdAt` is epoch milliseconds, `branch` is a full ref (`refs/heads/…`), and the linked issue/PR is in `linkedItems` as number + URL with no title.

One `worktree create --reference <url> --agent … --prompt …` makes the worktree, attaches the issue/PR and starts the agent. Don't split it back into create + send.

Orca answers `{ok, result}` on stdout and a non-zero exit on failure; either raises. In tests the call is faked with `Process::fake`, but array commands reach the fake shell-quoted (`'worktree' 'list'`), so patterns must match the quotes — see `fakeOrca()`. An unmatched pattern quietly runs the real CLI unless `preventStrayProcesses()` is on.

## byRef() returns null on failure, never an empty array
`Workspaces::byRef()` returns `null` when the Orca lookup failed and an array (possibly empty) when it succeeded. The distinction is load-bearing: the issue and pull request lists cache their played/reviewed ✓ marks, and an outage that read as "nothing has been played" would cache a run with every mark dropped, which the next page load then paints. On null they keep the marks the last good lookup left; on an empty array they clear them. Do not collapse the two back into `[]`.

`Workspaces::all()` is wrapped in `Cache::lock(...)->block()`. The three lists are `#[Isolate]`d and their `wire:init` requests go out together, so without it all three miss the 15s cache in the same instant and each fetches the whole list. The lock, not the cache alone, is what makes it one fetch per refresh — the "fetches the workspaces once for every list of a refresh" test covers it.

## Searches return one page plus a truncated flag
`searchItems()`, `searchMany()`, `issues()` and `pullRequests()` all return `['items' => [...], 'truncated' => bool]`, not a bare list. Only the first page (`per_page=100`) is ever requested, so `truncated` is `total_count > count($items)` and the sections render it as a `+` on the count. Without it a capped list reads as the whole picture — and the board filter then thins that arbitrary 100 further, so the number on screen looks authoritative when it is not.
