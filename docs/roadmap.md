# Roadmap and open decisions

This document tracks outstanding validation, proposed work, and open decisions.
Future features have no committed delivery date or priority. See the
[README](../README.md) for current behavior and deployment instructions, and the
[implementation plan](implementation-plan.md) for historical design decisions.

## Current scope

The bundle schedules publication of an exact saved version and selected
translations, and content-wide hiding/revealing. It includes rescheduling,
cancellation, management lists, a personal dashboard tab, and audit history.

## Production acceptance

The implementation exists, but production acceptance remains outstanding:

- Exercise actual publish/hide/reveal against an isolated Ibexa repository,
  including multilingual content, hidden parents and locations, search, and HTTP caches.
- Validate concurrent manual edits and publications against scheduler execution.
  Scheduler locks currently coordinate scheduler actions only. Add coordination
  if staging demonstrates an unacceptable race with native repository writers.
- Test multiple workers/nodes, database deadlocks, crash recovery, worker restarts,
  and large backlogs on the target database.
- Verify external subscriber side effects during rollback and retry; repository
  transactions do not guarantee exactly-once effects in external services.
- Review role assignments, browser workflows, pagination of inaccessible rows,
  and cleanup of deleted versions.

## Schema and operations

- Implement versioned schema upgrades before changing the initial tables.
- Define backlog and failure alerts, worker health checks, and operational ownership.
- Decide audit retention, archival, and cleanup policies.

## Calendar and bulk actions

Proposed calendar views would use the existing permission-filtered schedule data.
Decide which day/week/month views and language/content-type filters are needed,
and select a frontend dependency and license if required.

Bulk rescheduling and cancellation need per-item authorization, revision checks,
and clear handling of partial failures before implementation.

## Netgen Layouts integration

Investigate separately:

- Block visibility windows and layout editor controls.
- Rotating collections with item airtimes and a rolling item limit.
- Cache expiry at scheduling boundaries.

Block visibility is separate from repository publication. Define how these
features respect publication state and permissions before adding them.

## Future-page preview

A preview timeline requires a consistent preview clock across layout resolution,
collections, block rendering, projected publication state, and cache keys. It must
remain isolated from live rendering and repository writes.

Investigate embedded content, external data, and time-sensitive permissions before
committing to preview accuracy. This remains a feasibility proposal.
