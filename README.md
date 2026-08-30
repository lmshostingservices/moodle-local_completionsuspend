# Completion Auto-Suspend (`local_completionsuspend`)

A local plugin for Moodle that automatically switches a student's enrolment from **active** to **suspended** the moment they complete a course, with a dashboard to control exactly which courses and categories it applies to.

## Key features

- **Instant suspension** — reacts to `\core\event\course_completed` and suspends the student across every enrolment method they hold in that course
- **Course & category scope** — enable per course or per category, with an optional cascade into sub-categories
- **All enrolment methods** — manual, self, cohort sync, LTI, LDAP; whichever method the student enrolled through
- **Staff protection** — never suspends anyone who can edit the course (`moodle/course:update`)
- **Retroactive backfill** — a scheduled task suspends students who completed before you enabled the course
- **Auto re-activation** — if a completion is reset, enrolments this plugin suspended switch back to active, unless somebody else has changed the enrolment since
- **Simulation mode** — a dry run that logs every action without changing enrolments; the safe way to roll out
- **Transactional audit log** — every change is recorded with user, course, enrolment method, event vs. task, and real vs. simulated. The enrolment change and its audit row are written together, so they can never diverge
- **GDPR Privacy API** — full privacy provider with export and deletion support
- No AI credits required; no external API calls

## How it works

**Immediate path.** An observer on `\core\event\course_completed` calls `update_user_enrol()` through each enrolment plugin, inside a transaction with the audit write. Failures are caught and reported rather than thrown, so a problem here can never interrupt core completion processing.

**Reconciliation task.** Runs every 30 minutes. It backfills learners who completed before the course entered scope (windowed on the task's last run time) and re-activates learners whose completion has since been reset. The dashboard's **Run reconciliation now** button performs a full scan instead of an incremental one.

## Installation

1. Site administration → Plugins → Install plugins → upload the ZIP
2. Site administration → Plugins → Local plugins → Completion Auto-Suspend
3. Turn on **Simulation mode** first, then the **master switch**
4. Open the dashboard → Courses or Categories tab → enable the scope you want
5. Let the task run, review the Activity tab, then turn simulation mode off

## Upgrading from 1.0.2

1.0.2 could not write to its own audit log on MySQL or MariaDB, so the log is expected to be empty. More importantly, if you ran 1.0.2 with the master switch on and simulation mode off, some learners may have been suspended without a corresponding log row, and the plugin cannot re-activate those automatically. Check for suspended enrolments in courses that were in scope before upgrading.

The upgrade renames the offending column and adds one field and one index. No configuration is lost.

## Capabilities

| Capability | Default | Purpose |
| --- | --- | --- |
| `local/completionsuspend:manage` | Manager | View the dashboard, set scope, run reconciliation |

## Compatibility

Moodle 4.4 – 5.2 · PHP 8.0+ · MySQL, MariaDB or PostgreSQL

## Testing

```
vendor/bin/phpunit local/completionsuspend/tests/lib_test.php
```

## Licence

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

support@lmshostingservices.com
