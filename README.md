# Completion Auto-Suspend (`local_completionsuspend`)

## Summary

A local plugin for Moodle that automatically switches a student's enrolment from active to suspended the moment they complete a course — with a dashboard to control exactly which courses and categories it applies to.

## Description

A local plugin for Moodle that automatically switches a student's enrolment from active to suspended the moment they complete a course — with a dashboard to control exactly which courses and categories it applies to.

## Features

- Instant suspension — reacts to core\event\coursecompleted and suspends the student immediately across every enrolment method
- Course & category scope — enable per course or per whole category, with optional cascade into sub-categories
- All enrolment methods — manual, self, cohort sync, LTI, LDAP — whichever method the student enrolled through
- Staff protection — never suspends teachers, managers, or admins (anyone with moodle/course:update)
- Retroactive backfill — scheduled task suspends students who completed before you enabled the course
- Auto re-activation — if a completion is reset, enrolments this plugin suspended switch back to active
- Simulation mode — dry-run logs every action without changing enrolments — a safe first roll-out
- Full audit log — every change is recorded: user, course, method, event vs. task, real vs. simulated
- GDPR Privacy API — complete privacy provider with export and deletion support
- No AI credits required; no external API calls

## Installation

- Download the ZIP from lms-labs.com → Plugins → Completion Auto-Suspend
- Moodle → Site administration → Plugins → Install plugins → upload ZIP
- Open Site administration → Plugins → Local plugins → Completion Auto-Suspend
- Turn on the master switch
- Open the dashboard → Courses tab → enable the courses you want

## Current Release

Version 1.0.3 republishes the reviewed authoritative source under a new immutable tag because the historical tag contained a different source tree. There are no functional changes in this release.

## Licence

GNU GPL v3 or later.
