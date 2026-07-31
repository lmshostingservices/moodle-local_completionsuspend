# Completion Auto-Suspend (`local_completionsuspend`)

A local plugin for Moodle that automatically switches a student's enrolment from **active** to **suspended** the moment they complete a course — with a dashboard to control exactly which courses and categories it applies to.

## Key features

- **Instant suspension** — reacts to `core\event\course_completed` and suspends the student immediately across every enrolment method
- **Course & category scope** — enable per course or per whole category, with optional cascade into sub-categories
- **All enrolment methods** — manual, self, cohort sync, LTI, LDAP — whichever method the student enrolled through
- **Staff protection** — never suspends teachers, managers, or admins (anyone with `moodle/course:update`)
- **Retroactive backfill** — scheduled task suspends students who completed before you enabled the course
- **Auto re-activation** — if a completion is reset, enrolments this plugin suspended switch back to active
- **Simulation mode** — dry-run logs every action without changing enrolments — a safe first roll-out
- **Full audit log** — every change is recorded: user, course, method, event vs. task, real vs. simulated
- **GDPR Privacy API** — complete privacy provider with export and deletion support
- No AI credits required; no external API calls

## How it works

**Immediate path**: an observer on `\core\event\course_completed` calls `update_user_enrol()` via each enrolment plugin.

**Reconciliation task** (every 30 minutes): retroactive backfill and auto re-activation on completion reset.

## Installation

1. Download the ZIP from lms-labs.com → Plugins → Completion Auto-Suspend
2. Moodle → Site administration → Plugins → Install plugins → upload ZIP
3. Open Site administration → Plugins → Local plugins → Completion Auto-Suspend
4. Turn on the **master switch**
5. Open the dashboard → Courses tab → enable the courses you want

## Compatibility

Moodle 4.4 – 5.x · PHP 7.4+ · MySQL or PostgreSQL

## Licence

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

support@lmshostingservices.com · https://lms-labs.com/docs/completion-auto-suspend
