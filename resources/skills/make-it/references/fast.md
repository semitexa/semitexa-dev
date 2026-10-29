# Profile: make it fast

Cost, measured before and after. The question each change answers: does the
same thing now cost less, and by how much?

## Where the next change comes from

1. `bin/semitexa ai:quality next --limit=10`, the `requests.*` metrics, probed
   through real traced requests: `requests.query-shapes` (distinct queries a
   route runs), `requests.repeated-statements`, `requests.duplicate-queries`.
2. The soak numbers (memory per hour, p99 first hour against last) and the
   Observatory: `bin/semitexa ai:observe show --id=<p-…>` for one request's
   spans and queries.

## Done means

- A number before and a number after, each with the command that produced it,
  in the PR description. A speed claim without its measurement is not reviewed,
  it is believed.
- The metric is recorded: `ai:quality check --json` lower, then
  `ai:quality record --json` in the same commit.
- Behaviour is unchanged: existing tests pass untouched; where a query count is
  the point, a test pins the count (query-count budgets), so a regression fails
  a test, not a dashboard.
- The server is restarted (`bin/semitexa server:restart app`) before measuring
  a route; workers cache classes and templates for their lifetime.

## Not in this phase

Restructuring without a measured cost (that is `right`), and behaviour changes
(that is `work`).
