# Changelog

All notable changes to `local_completionsuspend` are documented here.

## [1.0.5] - 2026-08-30

### Fixed

- **The upgrade aborted with "Table does not exist" on any site missing one of the
  plugin's tables.** The upgrade step called `field_exists()` directly, and that method
  throws `ddl_table_missing_exception` when the table itself is absent — which happens
  after a partial uninstall, a restore from a dump that excluded the tables, or an
  install that recorded its version but did not finish. The upgrade now recreates any
  missing table from `install.xml` before touching columns, and every migration below it
  is individually guarded, so it is safe to re-run.
- The `trigger` to `triggertype` rename is now skipped when `triggertype` already
  exists, so a partially applied upgrade can be resumed rather than failing.

## [1.0.4] - 2026-08-29

Re-release of 1.0.3. The 1.0.3 tag had already been published against an earlier
commit and release tags are immutable, so the packaging and language-file
corrections below ship under a new version rather than moving the tag.

### Fixed

- ZIP root folder is now `completionsuspend` (the component name without its type
  prefix), matching Moodle's packaging convention.
- Language strings are single-line assignments. Concatenated strings are not parsed
  by AMOS, which would have broken translation imports.
- `require_login()` is now called explicitly on the dashboard page. `admin_externalpage_setup()`
  already enforced it internally, so this is a clarity and static-analysis change, not a
  security fix.
- `thirdpartylibs.xml` restored as an empty declaration.
- Multi-line SQL statements are assigned to a variable before being passed, rather than
  opening inline after the call parenthesis.

## [1.0.3] - 2026-08-29

### Fixed

- **Audit logging failed completely on MySQL and MariaDB.** The log table used `trigger`
  as a column name, which is a reserved word. The table created successfully but every
  `INSERT` failed with a SQL syntax error, so no action was ever recorded. The column is
  now `triggertype`, with an upgrade step that renames it in place.
- **Suspensions could be applied without an audit trail.** The enrolment was updated
  before the log row was written, so when the log write failed the learner was left
  suspended with no record — and re-activation, which reads that log, could never restore
  them. Both writes now happen inside one transaction.
- **The Categories tab was empty.** The tab was rendered but never implemented. It now
  lists the full category tree with per-category toggles and course counts.
- **The Courses tab hid most courses.** The listing filtered on `format = 'topics'`, so
  any course using Weeks, Single activity or a custom format was invisible and could not
  be enabled. All courses are now listed, with search and paging.
- **Two invalid language strings** rendered as `[[enrolled]]` and `[[enabled]]` in the
  course table headers.
- **An uncaught exception in the observer could break completion processing** for the
  whole site. Failures are now caught and reported via `debugging()`.
- Category scope no longer requires the sub-category cascade to be switched on. A course
  sitting directly in an enabled category is now in scope either way; the cascade setting
  controls only whether ancestor categories are followed.

### Changed

- Re-activation now records the enrolment's `timemodified` at suspension time and skips
  any enrolment that has been changed by somebody else since. Previously the task could
  silently re-enrol a learner an administrator had suspended for an unrelated reason.
- The reconciliation task now windows its retroactive backfill on the last run time
  instead of rescanning every completion in every in-scope course every 30 minutes.
  The dashboard button still performs a full scan.
- The dashboard fetches enrolment and completion counts in two grouped queries rather
  than two queries per course, and pages at 50 rows.
- Enrolments whose enrolment method has since been disabled are now logged as `skipped`
  rather than silently ignored.
- The admin page uses `admin_externalpage_setup()`, restoring the admin breadcrumb and
  settings search.
- The manual reconciliation button now reports the real number of suspensions and
  re-activations instead of always reporting zero.

### Added

- PHPUnit coverage for scope resolution, staff protection, simulation mode, audit
  logging and re-activation.
- `enroltimemodified` column and a `courseid, action, simulated` index on the log table.

## [1.0.2] - 2026-07-31

- Initial release.
