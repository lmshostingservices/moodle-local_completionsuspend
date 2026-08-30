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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Library functions for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Determine whether a course is in scope for auto-suspension.
 *
 * A course is in scope when it is directly enabled, or when any ancestor
 * category is enabled and the subcategory cascade is switched on.
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

    // The site course is never in scope.
    if ($courseid == SITEID) {
        return false;
    }

    // Direct course target.
    if (
        $DB->record_exists(
            'local_completionsuspend_target',
            ['targettype' => 'course', 'targetid' => $courseid, 'enabled' => 1]
        )
    ) {
        return true;
    }

    $course = $DB->get_record('course', ['id' => $courseid], 'category');
    if (!$course) {
        return false;
    }

    // The course's own category always counts. Ancestors only count when the cascade is on.
    $cascade = (bool)get_config('local_completionsuspend', 'includesubcategories');
    $catid = (int)$course->category;
    $seen = [];
    $depth = 0;

    while ($catid > 0 && $depth < 50) {
        // Guard against a malformed category tree containing a cycle.
        if (isset($seen[$catid])) {
            break;
        }
        $seen[$catid] = true;
        $depth++;

        if (
            $DB->record_exists(
                'local_completionsuspend_target',
                ['targettype' => 'category', 'targetid' => $catid, 'enabled' => 1]
            )
        ) {
            return true;
        }

        if (!$cascade) {
            // Without the cascade, only the course's immediate category is considered.
            break;
        }

        $cat = $DB->get_record('course_categories', ['id' => $catid], 'parent');
        $catid = $cat ? (int)$cat->parent : 0;
    }

    return false;
}

/**
 * Suspend all active enrolments for a user in a course via every enrolment plugin.
 *
 * Skips the user if they have the moodle/course:update capability (staff protection).
 *
 * The audit row is written inside the same transaction as the enrolment change, so the
 * two can never diverge: if the log write fails the suspension is rolled back with it.
 *
 * @param int    $userid
 * @param int    $courseid
 * @param bool   $simulated If true, log the action but do not change enrolments.
 * @param string $triggertype 'event' or 'task'.
 * @return int Number of enrolments suspended (or that would be, when simulating).
 */
function local_completionsuspend_suspend_user(
    int $userid,
    int $courseid,
    bool $simulated = false,
    string $triggertype = 'event'
): int {
    global $DB;

    // Staff protection.
    if (get_config('local_completionsuspend', 'protectstaff')) {
        $context = context_course::instance($courseid);
        if (has_capability('moodle/course:update', $context, $userid)) {
            return 0;
        }
    }

    $enrolplugins = enrol_get_plugins(true);
    $enrols = $DB->get_records_select(
        'user_enrolments',
        'userid = :uid AND status = :active AND enrolid IN (SELECT id FROM {enrol} WHERE courseid = :cid)',
        ['uid' => $userid, 'active' => ENROL_USER_ACTIVE, 'cid' => $courseid]
    );
    if (!$enrols) {
        return 0;
    }

    $count = 0;

    foreach ($enrols as $ue) {
        $enrol = $DB->get_record('enrol', ['id' => $ue->enrolid]);
        if (!$enrol) {
            continue;
        }
        if (!isset($enrolplugins[$enrol->enrol])) {
            // The enrolment plugin is installed but currently disabled, so we cannot
            // change this enrolment. Record why, otherwise the skip is invisible.
            local_completionsuspend_log(
                $userid,
                $courseid,
                (int)$ue->enrolid,
                'skipped',
                $triggertype,
                $simulated,
                (int)$ue->timemodified
            );
            continue;
        }

        // One failure must not abandon the rest of the loop; rollback() rethrows,
        // so the outer catch is what reports it and lets iteration continue.
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                if (!$simulated) {
                    $enrolplugins[$enrol->enrol]->update_user_enrol($enrol, $userid, ENROL_USER_SUSPENDED);
                    // Re-read so the audit row stores the post-change timestamp.
                    $enroltimemodified = (int)$DB->get_field('user_enrolments', 'timemodified', ['id' => $ue->id]);
                } else {
                    $enroltimemodified = (int)$ue->timemodified;
                }

                local_completionsuspend_log(
                    $userid,
                    $courseid,
                    (int)$ue->enrolid,
                    $simulated ? 'simulated' : 'suspended',
                    $triggertype,
                    $simulated,
                    $enroltimemodified
                );

                $transaction->allow_commit();
                $count++;
            } catch (Throwable $e) {
                $transaction->rollback($e);
            }
        } catch (Throwable $e) {
            debugging('local_completionsuspend: could not suspend enrolment ' . $ue->enrolid
                . ' for user ' . $userid . ': ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    return $count;
}

/**
 * Re-activate enrolments that this plugin previously suspended for a user in a course.
 *
 * An enrolment is only re-activated when it has not been touched by anything else since
 * we suspended it. If an administrator has changed the enrolment in the meantime, that
 * decision is left alone.
 *
 * @param int  $userid
 * @param int  $courseid
 * @param bool $simulated
 * @return int Number of enrolments re-activated (or that would be, when simulating).
 */
function local_completionsuspend_reactivate_user(int $userid, int $courseid, bool $simulated = false): int {
    global $DB;

    $enrolplugins = enrol_get_plugins(true);

    // Most recent suspension per enrolment, ignoring ones we have already re-activated.
    $suspended = $DB->get_records_select(
        'local_completionsuspend_log',
        'userid = :uid AND courseid = :cid AND action = :act AND simulated = 0',
        ['uid' => $userid, 'cid' => $courseid, 'act' => 'suspended'],
        'timecreated DESC'
    );

    $done = [];
    $count = 0;

    foreach ($suspended as $log) {
        if (isset($done[$log->enrolid])) {
            continue;
        }
        $done[$log->enrolid] = true;

        $enrol = $DB->get_record('enrol', ['id' => $log->enrolid]);
        if (!$enrol || !isset($enrolplugins[$enrol->enrol])) {
            continue;
        }

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $log->enrolid, 'userid' => $userid]);
        if (!$ue) {
            continue;
        }

        // Only act on enrolments that are still suspended.
        if ((int)$ue->status !== ENROL_USER_SUSPENDED) {
            continue;
        }

        // If the enrolment was modified after we suspended it, somebody else has made a
        // decision about this learner. Leave it alone.
        if (!empty($log->enroltimemodified) && (int)$ue->timemodified > (int)$log->enroltimemodified) {
            continue;
        }

        // One failure must not abandon the rest of the loop; rollback() rethrows,
        // so the outer catch is what reports it and lets iteration continue.
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                if (!$simulated) {
                    $enrolplugins[$enrol->enrol]->update_user_enrol($enrol, $userid, ENROL_USER_ACTIVE);
                }
                local_completionsuspend_log(
                    $userid,
                    $courseid,
                    (int)$log->enrolid,
                    $simulated ? 'simulated' : 'reactivated',
                    'task',
                    $simulated,
                    0
                );
                $transaction->allow_commit();
                $count++;
            } catch (Throwable $e) {
                $transaction->rollback($e);
            }
        } catch (Throwable $e) {
            debugging('local_completionsuspend: could not re-activate enrolment ' . $log->enrolid
                . ' for user ' . $userid . ': ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    return $count;
}

/**
 * Build the enable/disable action link for a course or category row on the dashboard.
 *
 * @param string $type 'course' or 'category'.
 * @param int    $id Target id.
 * @param bool   $isenabled Whether the target is currently enabled.
 * @param string $tab Current tab.
 * @param int    $page Current page number.
 * @param string $search Current search term.
 * @return string HTML for the action link.
 */
function local_completionsuspend_action_link(
    string $type,
    int $id,
    bool $isenabled,
    string $tab,
    int $page = 0,
    string $search = ''
): string {
    $url = new moodle_url('/local/completionsuspend/index.php', [
        'tab' => $tab,
        'page' => $page,
        'search' => $search,
        'action' => $isenabled ? 'disable' : 'enable',
        'type' => $type,
        'targetid' => $id,
        'sesskey' => sesskey(),
    ]);
    $label = $isenabled
        ? get_string('disable' . $type, 'local_completionsuspend')
        : get_string('enable' . $type, 'local_completionsuspend');
    $class = $isenabled ? 'btn btn-sm btn-outline-danger' : 'btn btn-sm btn-outline-success';

    return html_writer::link($url, $label, ['class' => $class]);
}

/**
 * Append a row to the audit log.
 *
 * @param int    $userid
 * @param int    $courseid
 * @param int    $enrolid
 * @param string $action 'suspended', 'reactivated', 'simulated' or 'skipped'.
 * @param string $triggertype 'event' or 'task'.
 * @param bool   $simulated
 * @param int    $enroltimemodified Enrolment timemodified recorded at suspension time.
 * @return int The new record id.
 */
function local_completionsuspend_log(
    int $userid,
    int $courseid,
    int $enrolid,
    string $action,
    string $triggertype,
    bool $simulated,
    int $enroltimemodified = 0
): int {
    global $DB;

    return (int)$DB->insert_record('local_completionsuspend_log', (object)[
        'userid' => $userid,
        'courseid' => $courseid,
        'enrolid' => $enrolid,
        'action' => $action,
        'triggertype' => $triggertype,
        'simulated' => (int)$simulated,
        'enroltimemodified' => $enroltimemodified,
        'timecreated' => time(),
    ]);
}
