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
 * Scheduled task: reconcile completion → suspension state.
 *
 * Handles:
 *  - Retroactive backfill: suspend learners who completed before
 *    the course was added to scope.
 *  - Re-activation: if a completion was reset, re-activate
 *    enrolments this plugin previously suspended.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_completionsuspend\task;

use core\task\scheduled_task;

/**
 * Reconciliation task.
 */
class reconcile extends scheduled_task {
    /**
     * Return the task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('pluginname', 'local_completionsuspend') . ' — reconcile';
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/completionsuspend/lib.php');

        if (!get_config('local_completionsuspend', 'masterswitch')) {
            return;
        }

        $simulated = (bool)get_config('local_completionsuspend', 'simulationmode');
        $retroactive = (bool)get_config('local_completionsuspend', 'retroactive');
        $reactivate  = (bool)get_config('local_completionsuspend', 'reactivateonreset');

        // Get all in-scope course IDs.
        $directcourses = $DB->get_fieldset_select(
            'local_completionsuspend_target', 'targetid', "targettype = 'course' AND enabled = 1");

        $allcourses = array_unique($directcourses);

        // Add courses from enabled categories.
        $cats = $DB->get_records('local_completionsuspend_target', ['targettype' => 'category', 'enabled' => 1]);
        foreach ($cats as $cat) {
            $courseids = $this->courses_in_category((int)$cat->targetid);
            $allcourses = array_unique(array_merge($allcourses, $courseids));
        }

        foreach ($allcourses as $courseid) {
            if ($retroactive) {
                $this->backfill_completed($courseid, $simulated);
            }
            if ($reactivate) {
                $this->reactivate_reset($courseid, $simulated);
            }
        }
    }

    /**
     * Suspend learners who completed the course but are still active.
     *
     * @param int  $courseid
     * @param bool $simulated
     */
    private function backfill_completed(int $courseid, bool $simulated): void {
        global $DB;

        // Find completed, active enrolments not already logged as suspended.
        $completed = $DB->get_records_sql("
            SELECT cc.userid
              FROM {course_completions} cc
             WHERE cc.course   = :cid
               AND cc.timecompleted IS NOT NULL
               AND cc.timecompleted > 0
               AND NOT EXISTS (
                   SELECT 1 FROM {local_completionsuspend_log} l
                    WHERE l.userid   = cc.userid
                      AND l.courseid = cc.course
                      AND l.action   = 'suspended'
                      AND l.simulated = 0
               )
        ", ['cid' => $courseid]);

        foreach ($completed as $row) {
            local_completionsuspend_suspend_user((int)$row->userid, $courseid, $simulated, 'task');
        }
    }

    /**
     * Re-activate users whose completion was reset but who are still suspended.
     *
     * @param int  $courseid
     * @param bool $simulated
     */
    private function reactivate_reset(int $courseid, bool $simulated): void {
        global $DB;

        // Find users this plugin suspended who no longer have a completion record.
        $suspended = $DB->get_records_sql("
            SELECT DISTINCT l.userid
              FROM {local_completionsuspend_log} l
             WHERE l.courseid  = :cid
               AND l.action    = 'suspended'
               AND l.simulated = 0
               AND NOT EXISTS (
                   SELECT 1 FROM {course_completions} cc
                    WHERE cc.userid          = l.userid
                      AND cc.course          = l.courseid
                      AND cc.timecompleted IS NOT NULL
                      AND cc.timecompleted   > 0
               )
        ", ['cid' => $courseid]);

        foreach ($suspended as $row) {
            local_completionsuspend_reactivate_user((int)$row->userid, $courseid, $simulated);
        }
    }

    /**
     * Return all course IDs in a category (and sub-categories if setting is on).
     *
     * @param int $catid
     * @return int[]
     */
    private function courses_in_category(int $catid): array {
        global $DB;

        $ids = $DB->get_fieldset_select('course', 'id', 'category = :cid', ['cid' => $catid]);

        if (get_config('local_completionsuspend', 'includesubcategories')) {
            $subcats = $DB->get_fieldset_select('course_categories', 'id', 'parent = :cid', ['cid' => $catid]);
            foreach ($subcats as $sub) {
                $ids = array_merge($ids, $this->courses_in_category((int)$sub));
            }
        }
        return $ids;
    }
}
