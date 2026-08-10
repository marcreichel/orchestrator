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
