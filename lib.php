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
 * Library functions for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Determine whether a course is in scope for auto-suspension.
 *
 * A course is in scope when it is directly enabled, or when any ancestor
 * category is enabled (with subcategories included).
 *
 * @param int $courseid
 * @return bool
 */
function local_completionsuspend_course_in_scope(int $courseid): bool {
    global $DB;

    // Master switch.
    if (!get_config('local_completionsuspend', 'masterswitch')) {
        return false;
    }

    // Direct course target.
    if ($DB->record_exists('local_completionsuspend_target', ['targettype' => 'course', 'targetid' => $courseid, 'enabled' => 1])) {
        return true;
    }

    // Check ancestor categories when subcategory cascade is on.
    if (!get_config('local_completionsuspend', 'includesubcategories')) {
        return false;
    }

    $course = $DB->get_record('course', ['id' => $courseid], 'category');
    if (!$course) {
        return false;
    }
    $catid = (int)$course->category;
    while ($catid > 0) {
        if ($DB->record_exists('local_completionsuspend_target', ['targettype' => 'category', 'targetid' => $catid, 'enabled' => 1])) {
            return true;
        }
        $cat   = $DB->get_record('course_categories', ['id' => $catid], 'parent');
        $catid = $cat ? (int)$cat->parent : 0;
    }
    return false;
}

/**
 * Suspend all active enrolments for a user in a course via every enrolment plugin.
 *
 * Skips the user if they have moodle/course:update capability (staff protection).
 *
 * @param int  $userid
 * @param int  $courseid
 * @param bool $simulated  If true, log the action but do not actually change enrolments.
 * @param string $trigger  'event' or 'task'
 */
function local_completionsuspend_suspend_user(int $userid, int $courseid, bool $simulated = false, string $trigger = 'event'): void {
    global $DB;

    // Staff protection.
    if (get_config('local_completionsuspend', 'protectstaff')) {
        $context = context_course::instance($courseid);
        if (has_capability('moodle/course:update', $context, $userid)) {
            return;
        }
    }

    $enrolplugins = enrol_get_plugins(true);
    $enrols = $DB->get_records_select(
        'user_enrolments',
        'userid = :uid AND status = :active AND enrolid IN (SELECT id FROM {enrol} WHERE courseid = :cid)',
        ['uid' => $userid, 'active' => ENROL_USER_ACTIVE, 'cid' => $courseid]
    );

    foreach ($enrols as $ue) {
        $enrol = $DB->get_record('enrol', ['id' => $ue->enrolid]);
        if (!$enrol || !isset($enrolplugins[$enrol->enrol])) {
            continue;
        }
        if (!$simulated) {
            $enrolplugins[$enrol->enrol]->update_user_enrol($enrol, $userid, ENROL_USER_SUSPENDED);
        }
        local_completionsuspend_log($userid, $courseid, $ue->enrolid, $simulated ? 'simulated' : 'suspended', $trigger, $simulated);
    }
}

/**
 * Re-activate enrolments that this plugin previously suspended for a user in a course.
 *
 * @param int  $userid
 * @param int  $courseid
 * @param bool $simulated
 */
function local_completionsuspend_reactivate_user(int $userid, int $courseid, bool $simulated = false): void {
    global $DB;

    $enrolplugins = enrol_get_plugins(true);
    // Only re-activate enrolments that we suspended (recorded in the log).
    $suspended = $DB->get_records_select(
        'local_completionsuspend_log',
        'userid = :uid AND courseid = :cid AND action = :act AND simulated = 0',
        ['uid' => $userid, 'cid' => $courseid, 'act' => 'suspended'],
        'timecreated DESC'
    );
    $done = [];
    foreach ($suspended as $log) {
        if (isset($done[$log->enrolid])) {
            continue;
        }
        $done[$log->enrolid] = true;
        $enrol = $DB->get_record('enrol', ['id' => $log->enrolid]);
        if (!$enrol || !isset($enrolplugins[$enrol->enrol])) {
            continue;
        }
        if (!$simulated) {
            $enrolplugins[$enrol->enrol]->update_user_enrol($enrol, $userid, ENROL_USER_ACTIVE);
        }
        local_completionsuspend_log($userid, $courseid, $log->enrolid, $simulated ? 'simulated' : 'reactivated', 'task', $simulated);
    }
}

/**
 * Append a row to the audit log.
 *
 * @param int    $userid
 * @param int    $courseid
 * @param int    $enrolid
 * @param string $action   'suspended', 'reactivated', or 'simulated'
 * @param string $trigger  'event' or 'task'
 * @param bool   $simulated
 */
function local_completionsuspend_log(
    int $userid, int $courseid, int $enrolid, string $action, string $trigger, bool $simulated
): void {
    global $DB;
    $DB->insert_record('local_completionsuspend_log', (object)[
        'userid'      => $userid,
        'courseid'    => $courseid,
        'enrolid'     => $enrolid,
        'action'      => $action,
        'trigger'     => $trigger,
        'simulated'   => (int)$simulated,
        'timecreated' => time(),
    ]);
}
