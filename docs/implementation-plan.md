# Content scheduling implementation plan

Status: initial implementation exists; production acceptance remains outstanding.
Assessment date: 2026-10-31.

## Implementation record (2026-10-31)

The package now contains a persistent engine, schema installer, publish-later form
extension/processor, scheduling screens, content menu integration, dashboard tab,
and run command. The host installs it through a tracked Composer path repository
at `packages/ibexa-content-scheduler`, with a vendor symlink, bundle/routes, and a
minute-by-minute async sweep in its existing Scheduler.

Decisions made during implementation:

- Storage is fixed to `ibexa.persistence.connection` for this release, ensuring
  repository mutations and schedule records use the same connection. No separate
  storage connection is offered.
- Schema installation is an explicit preview/execute command for the initial
  three tables. It bypasses the host's accounting migrations. Subsequent schema
  versions require a versioned upgrade path before changing existing tables.
- Per-content locks and mutations share a repository transaction, including its
  persistence-cache transaction lifecycle. Running state is not independently
  committed, so crash recovery uses database rollback rather than expiring leases.
- Publish later saves validated editor fields first, then redirects to a separate
  date/time confirmation form. It uses no modal, custom JavaScript, or asset build.
- One pending publication and one pending visibility change are allowed per item.
  Visibility checks hidden state independently of publication version, allowing a
  publication to precede an already-scheduled hide.
- Rescheduling reconfirms saved content/schema and the published-version baseline,
  while retaining the original requester for execution authorization.
- Input now uses native date/time pickers and a timezone selector. The server
  calculates offsets and offers first/second occurrence choices for ambiguous
  fall-back times. Invalid submissions retain values on create and reschedule.
- Failures/conflicts require explicit rescheduling, except transient database
  errors, which retry with backoff up to three attempts.
- Manual publication/deletion and changed drafts are reconciled by the runner;
  there is no repository event subscriber in the initial release.

Automated checks cover scheduling/cancellation/reconfirmation, stale revisions,
duplicate delivery, disabled/revoked users, rollback, retries, form CSRF, unsaved
editor fields, full publication validation, explicit languages, and DST handling.
Host-kernel tests verify service/route/template registration and exact connection
identity. These are not native publication or frontend visibility acceptance tests.

Outstanding production acceptance and future work are tracked in the
[roadmap and open decisions](roadmap.md).

The original phased plan below remains the reference for scope and acceptance.

## Goal and reference behavior

Provide independent content scheduling on Ibexa OSS 5, using public repository
APIs and admin UI extension points. The functional reference is the
[Ibexa scheduling guide](https://doc.ibexa.co/projects/userguide/en/latest/content_management/schedule_publishing/).
The moving `latest` documentation is a behavior reference, not proof that every
feature is available in the installed OSS edition.

| Capability | Planned treatment |
| --- | --- |
| Publish later from the editor | Initial release |
| Cancel and reschedule publication | Initial release |
| Schedule content hiding/revealing | Initial release |
| Pending/failed schedule list and audit history | Initial release |
| My scheduled content dashboard | Following the engine and editor integration |
| Calendar, language/type filters, bulk actions | Subsequent milestone |
| Page block visibility windows | Separate Netgen Layouts integration |
| Rotating content block with airtimes and item limit | Separate Netgen Layouts integration |
| Future-page preview timeline | Separate feasibility investigation |

## Verified host foundations

- Host requires Ibexa OSS 5.0.10, PHP 8.4+, and Symfony 7.4.
- Public ContentService provides `publishVersion(VersionInfo, translations)`,
  `hideContent(ContentInfo)`, and `revealContent(ContentInfo)`.
- Content hiding affects all locations. Revealing preserves independently hidden
  locations; hidden parents can still prevent visibility.
- `ContentEditRightSidebarBuilder` exposes a configuration event for editor
  actions. `ContentEditType` and the content form action dispatcher are candidate
  extension points for a publish-later submission.
- Native publication saves form data before publishing and supplies an explicit
  translation list. Preserve those semantics in delayed publication.
- The sibling Wizhippo bundles establish namespace, dependency injection,
  policies, menu subscriptions, Encore assets, and package-owned test conventions.
- Host Scheduler and Messenger are installed. `src/Scheduler/AppScheduleProvider.php`
  defines recurring tasks; Docker defines separate scheduler and async consumers.
  Running production workers have not been verified.
- Host has `default` and `tocom` DBAL connections. The default ORM manager and
  current Doctrine migrations configuration use `tocom`. Never infer the bundle's
  storage connection from the default ORM manager.
- Installed packages do not include Ibexa Scheduler, Calendar, or Page Builder.
- Netgen Layouts has a time-based layout resolution condition. This is not
  individual block scheduling or future-time preview support.

## Package boundaries

Keep this bundle independently installable with no `App\` dependencies and no
dependency on either sibling bundle. Use public repository services for content
mutations, preserving repository events and normal cache/search integration.
Do not modify Ibexa core tables directly or copy proprietary package code.

Add runtime dependencies as features land, including DBAL, locking, logging,
security, forms, Twig and any chosen worker integration. Add package-owned unit
and host-kernel integration suites with a configurable bootstrap, following the
sibling bundles. Add an Encore entry only when UI assets exist.

## Proposed persistence

Use dedicated bundle tables with a configurable DBAL connection. Prefer the
repository connection if transaction behavior is verified; do not promise atomic
repository and schedule writes across different connections.

Store at least:

- Schedule ID; content ID; action (`publish`, `hide`, `reveal`).
- Exact version number and explicit publication languages for publish actions.
- UTC execution timestamp and submitted timezone identifier.
- Requesting user ID; created/updated timestamps; optimistic revision.
- Expected draft modification marker or fingerprint and published-version baseline.
- State (`pending`, `running`, `completed`, `cancelled`, `failed`, `conflict`).
- Claim/lease expiry, attempt count, next retry time, completion time, and a safe
  failure code/message.

Maintain separate append-only audit entries for creation, rescheduling,
cancellation, claims, retries, execution outcomes, and administrative recovery.
Include actor attribution and old/new scheduling metadata without storing field
contents or secrets in logs.

Index due work by status and execution/retry time; index content and requester
for management views. Design active-schedule uniqueness and per-content ordering
before writing migrations. Package migrations must target the selected connection
explicitly and must not fall through to the host's `tocom` migration manager.

## Proposed execution model

1. Validate and persist a schedule through a single application service.
2. Expose a bundle command that scans all overdue eligible rows, in bounded batches.
3. Invoke it every minute through the host's existing Scheduler or another host
   scheduler. A durable database sweep is the source of truth, rather than one
   long-delay message per publication.
4. Atomically claim rows with expiring leases and serialize conflicting work per
   content item. Use a distributed lock/claim mechanism suitable for multiple nodes.
5. Reload the requester, content, version, translation selection, and current
   authorization before mutation. Restore worker user context in a `finally` block.
6. Recheck cancellation/revision and conflict conditions while holding the claim.
7. Call the public repository API and record the result.
8. Retry transient errors with bounded backoff; mark validation, authorization,
   missing-content, and version conflicts for editorial attention.

Recover expired claims after worker crashes. Establish transaction boundaries and
reconciliation for a crash between repository mutation and completion recording.
Avoid claiming exactly-once execution; prove repeated delivery does not publish
the wrong version or overwrite subsequent manual actions. Preserve overdue work
through restarts and document catch-up ordering for publish/hide pairs.

One-minute polling means execution can be late by the polling interval plus queue,
processing, and cache delays. It is not a second-accurate visibility guarantee.
Optional async dispatch must carry schedule identity/revision and retain the same
claim and stale-message protections.

## Editorial rules to settle before implementation

Recommended defaults, subject to the first milestone's validation:

- Publication binds to one exact saved version and explicit translation set.
  Start with one active publication per content item to limit conflicting changes.
- Schedule from the full editor form, saving current edits and performing
  publication-level validation. Draft-save validation alone is insufficient.
- Detect later edits or autosaves of the scheduled draft. Require reconfirmation
  or mark a conflict; never silently substitute the latest draft/version.
- If the published-version baseline changes before execution, mark a conflict
  instead of publishing older scheduled content over a newer publication.
- Cancellation leaves the draft intact. Manual publication and draft deletion
  should reconcile related schedules through repository events or the runner.
- Validate language availability at execution and preserve native non-translatable
  field behavior. Never silently publish every translation.
- Hide/reveal operate at content scope, not per language or individual location.
- Publishing and revealing are distinct operations. Do not implicitly reveal a
  deliberately hidden item as a side effect of publishing.
- Define ordering for same-time actions, restrictions on contradictory schedules,
  and what a manually changed hidden state means for pending visibility actions.
- Revalidate against content type/schema changes; missing content or translations
  should yield a visible failure/conflict rather than a substitute action.
- Explain ambiguous/nonexistent daylight-saving times in the input UI. Store UTC
  and display the timezone with every scheduled date.

## Permissions and request security

Introduce bundle policies such as `wizhippo_ibexa_content_scheduler/view`, `schedule`, and
`manage`, with exact semantics finalized before registration. Combine them with
native content read/version-read/edit/publish/hide checks and their limitations.
Use authenticated, CSRF-protected mutations; recheck permissions server-side for
every single and bulk action. Filter list/calendar results by accessible content.

Execute as the requesting user with current permissions; revoked access or a
disabled/deleted account must prevent execution. Avoid blanket administrator sudo.
Any later service-account approval model needs its own explicit design.

Grant editorial capability through system roles. Associate/Affiliate agent types
must not grant scheduling privileges, and accounting roles remain isolated.
No agent contract, commission, or onboarding behavior belongs in this bundle.

## Milestones and acceptance criteria

### 1. Integration validation (1–2 developer days)

- Verify form extension/action handling and save-before-schedule behavior for new
  content, existing drafts, unsaved changes, and translations.
- Verify repository transaction nesting and explicitly select storage/migrations
  connection. Determine how publication outcomes can be reconciled after a crash.
- Verify user context switching, disabled users, cache invalidation, and Netgen
  visibility behavior. Check existing draft/version cleanup policies.
- Finalize the editorial rules above and record decisions here.

Exit: documented integration choices and a validated approach for the risks above.

### 2. Persistent engine (3–5 developer days)

- Add schema/migrations, schedule repository, application services, audit history,
  due-work command, claiming/leases, retries, and conflict detection.
- Support publish/hide/reveal and concurrency-safe reschedule/cancel operations.
- Add required dependencies and configurable storage/lock services.

Exit: engine integration tests demonstrate due execution, recovery, authorization,
and duplicate-delivery protection against a real test repository/database.

### 3. Editor and visibility controls (3–5 developer days)

- Add Publish later dialog, timezone input, schedule status, reschedule/cancel
  controls, and content hide/reveal scheduling.
- Use menu events, form extensions, Twig components, and Encore assets where
  appropriate; avoid application template overrides if extension points suffice.
- Detect draft changes and show actionable conflict/failure feedback.

Exit: an editor can schedule the content currently being edited, inspect what is
scheduled, change the time, and cancel without losing the draft.

### 4. Management and dashboard (2–4 developer days)

- Add paginated pending/completed/failed lists, content/user/date/status filters,
  permission-checked management actions, audit details, and My scheduled content.
- Provide explicit administrative retry/recovery actions with audit attribution.

Exit: editors and authorized administrators can find and resolve schedule outcomes.

### 5. Hardening and operations (3–5 developer days)

- Finish the validation matrix below, deployment documentation, worker health
  guidance, backlog/failure monitoring, and retention policy.
- Document migration connection selection, worker invocation, polling latency,
  retries, safe uninstall/disable behavior, and recovery after downtime.
- Register/install the bundle in the host only as part of the later implementation.

Exit: production installation and recovery steps are documented and verified.

Initial content scheduling estimate: **12–21 developer days**, subject to milestone
1. Calendar UI is an additional **4–7 days**. Estimates exclude Netgen block and
timeline work.

## Validation matrix

- New content, updated content, exact version/language, invalid required fields,
  multilingual publication, non-translatable fields, and concurrent draft edits.
- Pending/rescheduled/cancelled jobs, stale revisions/messages, duplicate workers,
  claim expiry, transient failures, permanent failures, and interrupted execution.
- Revoked permissions, disabled/deleted requester, limitation changes, unauthorized
  list/calendar access, and CSRF protection.
- Draft deletion, content deletion, manual publication, newer publication, missing
  translation, changed content schema, and cleanup of scheduled drafts.
- UTC conversion, daylight-saving transitions, equal timestamps, overdue actions,
  worker downtime, and publish-then-hide ordering.
- Multiple locations, hidden parents, independent location hiding, reveal behavior,
  search results, Netgen lists/direct rendering, and HTTP cache invalidation.
- Explicit database connection selection, fresh migrations, upgrades, bounded batch
  queries, and rollback/reconciliation at repository transaction boundaries.

Do not add placeholder tests that merely assert skeleton structure. Introduce
meaningful behavior tests alongside each implemented feature.

## Deferred calendar and Netgen work

Build a calendar from the same permission-filtered schedule query service, with
day/week/month and list views, pagination/loading, event/language filters, and
revision-aware bulk reschedule/cancel. Select its frontend dependency and license
when that milestone begins.

For Netgen, separately investigate block visibility windows, a content collection
with airtimes and a rolling item limit, cache expiry at future boundaries, and
layout editor controls. Block appearance is not repository publication: selecting
a content item must not expose an unpublished or unauthorized draft.

A future preview timeline needs an injectable preview clock across layout
resolution, collections, block rendering, publication projections, and cache keys.
Keep preview isolated from live rendering and repository writes. Historical or
future embedded content, external data, and time-sensitive permissions may prevent
exact page reconstruction; document those limits before committing to parity.
