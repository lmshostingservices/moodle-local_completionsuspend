<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Language strings for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']  = 'Completion Auto-Suspend';
$string['pluginname_help'] = 'Automatically switches a student\'s enrolment from active to suspended ' .
    'the moment they complete a course, with per-course and per-category scope control.';

// Settings.
$string['masterswitch']              = 'Enable Completion Auto-Suspend (master switch)';
$string['masterswitch_help']         = 'Master switch. While off, no enrolments are ever changed.';
$string['protectstaff']              = 'Never suspend teachers, managers or admins';
$string['protectstaff_help']         = 'Skips anyone who can edit the course (moodle/course:update).';
$string['reactivateonreset']         = 'Re-activate on completion reset';
$string['reactivateonreset_help']    = 'If completion is reset or removed, switch plugin-suspended ' .
    'enrolments back to active.';
$string['includesubcategories']      = 'Include sub-categories';
$string['includesubcategories_help'] = 'When a category is selected, also apply to courses in all sub-categories.';
$string['retroactive']               = 'Retroactively suspend already-completed learners';
$string['retroactive_help']          = 'The scheduled task suspends students who completed before the course was enabled.';
$string['simulationmode']            = 'Simulation mode (dry run)';
$string['simulationmode_help']       = 'Log what would happen without changing any enrolment.';

// Dashboard tabs.
$string['dashboard']      = 'Completion Auto-Suspend Dashboard';
$string['tab_courses']    = 'Courses';
$string['tab_categories'] = 'Categories';
$string['tab_activity']   = 'Activity';
$string['tab_settings']   = 'Settings';

// Table headers.
$string['course']         = 'Course';
$string['category']       = 'Category';
$string['enrolled']       = 'Enrolled';
$string['completed']      = 'Completed';
$string['suspended']      = 'Suspended';
$string['enabled']        = 'Enabled';
$string['actions']        = 'Actions';

// Actions.
$string['enablecourse']        = 'Enable for this course';
$string['disablecourse']       = 'Disable for this course';
$string['runreconciliation']   = 'Run reconciliation now';
$string['reconciliationdone']  = 'Reconciliation complete: {$a->suspended} suspended, {$a->reactivated} re-activated.';

// Audit log.
$string['auditlog']            = 'Activity log';
$string['log_suspended']       = 'Suspended';
$string['log_reactivated']     = 'Re-activated';
$string['log_simulated']       = 'Simulated (no change)';
$string['log_event']           = 'Course completed event';
$string['log_task']            = 'Reconciliation task';
$string['exportlog']           = 'Export log (CSV)';

// Privacy.
$string['privacy:metadata:local_completionsuspend_log']             = 'Audit log of all suspension and re-activation actions.';
$string['privacy:metadata:local_completionsuspend_log:userid']      = 'The user whose enrolment was changed.';
$string['privacy:metadata:local_completionsuspend_log:courseid']    = 'The course involved.';
$string['privacy:metadata:local_completionsuspend_log:action']      = 'The action taken (suspended, reactivated, simulated).';
$string['privacy:metadata:local_completionsuspend_log:timecreated'] = 'When the action was taken.';
$string['privacy:metadata:local_completionsuspend_log:simulated']   = 'Whether this was a simulation (dry run).';

// Capabilities.
$string['completionsuspend:manage'] = 'Manage Completion Auto-Suspend settings and scope';
