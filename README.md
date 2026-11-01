# Ibexa Content Scheduler

Independent scheduled publication and visibility management for the **Ibexa 5
admin UI**. Package: `wizhippo/ibexa-content-scheduler`.

## Features

- **Publish later** in the editor: save current edits with full field validation,
  then choose translations and confirm the date/time on a separate screen.
- Publish an exact saved draft version and explicit translations.
- Schedule content-wide hiding and revealing through native repository APIs.
- Reschedule, reconfirm changed drafts, cancel schedules, and keep drafts intact.
- My scheduled content dashboard tab, permission-filtered management lists,
  execution status, safe failure details, and audit history.
- Database-backed overdue-work sweeps, per-content transaction locks, optimistic
  revisions, bounded retries for transient database failures, and crash rollback.
- Native date/time pickers and a timezone selector, with automatic daylight-saving
  adjustments and a first/second occurrence choice for repeated times.

See the [roadmap and open decisions](docs/roadmap.md) for outstanding production
acceptance, calendar and bulk actions, Netgen integration, and future-page preview.
The [implementation plan](docs/implementation-plan.md) records the original design.
This bundle is independent of the content assistant, version comparison, and
Ibexa's commercial scheduler/calendar.

## Requirements and installation

PHP 8.4+, Ibexa 5, Symfony 7.4, and Doctrine DBAL 3.10. Runtime dependencies are
in `composer.json`. No asset build is required; the UI uses native admin styles
and server-rendered forms.

The source repository is [wizhippo/ibexa-content-scheduler](https://github.com/wizhippo/ibexa-content-scheduler).
This application requires it through Composer as `dev-main`, installed under
`vendor/wizhippo/ibexa-content-scheduler`. Publish package changes to that repository
and update the host's Composer lock file so they survive reinstallation. The host
registers the bundle and imports its routes.

For another consuming application, require the package through a Composer path
or VCS repository, register
`Wizhippo\IbexaContentScheduler\WizhippoIbexaContentSchedulerBundle` in
`config/bundles.php`, and import `@WizhippoIbexaContentSchedulerBundle/config/routes.yaml`.

Before serving traffic or starting the sweep, preview and install the tables:

```sh
php bin/console wizhippo:content-scheduler:install --env=prod
php bin/console wizhippo:content-scheduler:install --execute --env=prod
```

The installer creates only missing `wizhippo_schedule`, `wizhippo_schedule_lock`,
and `wizhippo_schedule_audit` tables, with indexes. It uses the exact
**`ibexa.persistence.connection`** service. It does not use the host's default ORM
manager or its accounting migrations, and it never alters existing tables.
This is the initial schema installer; future schema changes need explicit,
versioned upgrades. Deploy the schema before the registered UI is accessed.

## Worker integration

The host's `AppScheduleProvider` dispatches
`App\Message\Scheduler\RunContentSchedulesAsyncMessage` every minute through the
existing async transport. Its handler calls the bundle runner. Keep both
`scheduler_default` and `async` Messenger consumers running and restart existing
consumers after deployment. The local development compose override may disable
the scheduler consumer; the package does not start or restart worker processes.

Other hosts can invoke the independent command from cron or their own Scheduler:

```sh
php bin/console wizhippo:content-scheduler:run --limit=50 --env=prod
```

Each invocation handles at most 50 overdue schedules by default (maximum 500),
ordered by planned time. Repeated sweeps catch up after downtime. Large backlogs
need more invocations or a higher limit. Publication time can be delayed by the
poll interval, queue backlog, execution, and frontend cache behavior.

Exit status is nonzero when a batch records failed/conflicting actions. Results
are also persisted and logged with schedule ID and exception class. Transient
database failures retry after 60/120 seconds, up to three attempts. Other failures
require editorial review and explicit rescheduling. Do not blindly replay content
mutations from logs.

## Editorial behavior and permissions

Fresh logins and sessions restored from remember-me cookies are supported.
Anonymous users remain excluded; all policy and repository permission checks
apply to both kinds of authenticated session.

Grant `wizhippo_ibexa_content_scheduler/view` to view accessible schedules, plus
`wizhippo_ibexa_content_scheduler/schedule` to create/reschedule. Owners can cancel their own
accessible schedules. `wizhippo_ibexa_content_scheduler/manage` permits management of other
users' accessible schedules. All operations also enforce native content/version
read and relevant edit/publish/hide permissions, including limitations. Administrators
with wildcard policies qualify automatically. Use system roles, not Associate/
Affiliate business types, and keep accounting roles isolated.

When upgrading from `content_scheduler`, assign the corresponding
`wizhippo_ibexa_content_scheduler` policies to existing roles and clear the
application cache. Policies stored under the old module no longer grant access;
review role assignments before removing them.

Execution reloads the original requester, rejects disabled accounts, rechecks
current policies, and restores worker identity afterward. Administrative
rescheduling retains the original requester; it does not grant that user new rights.
Mutations require POST and CSRF tokens. Revision checks reject stale forms.

Only one pending publication and one pending visibility action are permitted per
content item. Publication and visibility are separate. Publishing does not reveal
hidden content. Visibility applies across locations and preserves independent
location hiding; hidden parents still affect frontend visibility.

A publication records a hash of saved fields, schema, languages, and modification
time, plus the current published-version baseline. Execution detects changes and
records a conflict instead of choosing a newer draft or overwriting a newer
publication. Rescheduling explicitly reconfirms the saved state. Manual publication
or deleting a scheduled draft produces a conflict/failure at the next sweep.
Visibility schedules check their original hidden state, independently of version
publication. Select a date, time, and timezone; no UTC offset needs to be typed.
The server uses the timezone's rules for that date. A skipped spring-forward time
is rejected with an explanation. If a fall-back time occurs twice, the form asks
you to choose its first or second occurrence. Failed submissions preserve entered
values on both create and reschedule screens.

## Transactions, recovery, and limits

Schedule edits and execution serialize through a per-content database row. The
runner uses repository transactions so database changes and persistence caches
share the transaction boundary. Scheduler workers cannot execute a committed
schedule twice; cancellation/rescheduling must win the same lock before execution.
On rollback or process death, the database releases locks and leaves uncommitted
work pending. No independently committed running lease needs to be recovered.

This does not promise exactly-once external side effects: repository subscribers
can call external services before a transaction commits. Retrying an interrupted
publication may repeat such effects. Audit retention is currently manual; keep
records according to the host's retention policy. Disabling/removing the worker
does not delete schedules or content. Drain/cancel pending schedules before
uninstalling, and retain tables for audit access.

The transaction tests exercise scheduler operations on SQLite with mocked
repository mutations. Native transaction/cache behavior, actual publication/search/
HTTP-cache visibility, multi-node database races, and concurrent manual repository
writes still need staging acceptance tests. Manual editors do not acquire the
scheduler lock; avoid simultaneous manual writes around execution until that
race is validated or additional repository coordination is added. A missing or
deleted content version is omitted from UI lists rather than disclosed; its
failed audit record remains in the database for operational investigation.

## Development checks

From the consuming application root:

```sh
vendor/bin/phpunit -c vendor/wizhippo/ibexa-content-scheduler/phpunit.xml.dist
vendor/bin/phpstan analyse -c vendor/wizhippo/ibexa-content-scheduler/phpstan.neon.dist
KERNEL_CLASS='App\Kernel' WIZHIPPO_TEST_BOOTSTRAP="$PWD/tests/bootstrap.php" \
  vendor/bin/phpunit -c vendor/wizhippo/ibexa-content-scheduler/phpunit.integration.xml.dist
php bin/console lint:container
php bin/console lint:twig vendor/wizhippo/ibexa-content-scheduler/templates
```

Replace paths/kernel/bootstrap for other hosts. Unit tests use an isolated SQLite
database and mocked repository services; they never publish host content.
Host integration tests check container wiring, connection identity, routes, and
template availability without repository writes. A standalone package checkout
uses its own `vendor` directory after `composer install`.

## Authors and license

- [Douglas Hammond (wizhippo)](https://github.com/wizhippo) — author and maintainer.
- LLM — AI-assisted development and documentation.

**GPL-2.0-only**; see [LICENSE](LICENSE). No Ibexa commercial-license grant is included.
