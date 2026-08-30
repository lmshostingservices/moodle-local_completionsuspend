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
 * Scheduled task: reconcile completion to suspension state.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_completionsuspend\task;

use core\task\scheduled_task;

/**
 * Reconciliation task.
 *
 * Handles retroactive backfill (suspend learners who completed before the course was
 * added to scope) and re-activation (restore enrolments we suspended whose completion
 * has since been reset).
 */
class reconcile extends scheduled_task {
    /** @var int Maximum category tree depth walked when expanding a category target. */
    const MAX_CAT_DEPTH = 50;

    /**
     * Return the task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreconcile', 'local_completionsuspend');
    }

    /**
     * Run the task from cron.
     */
    public function execute(): void {
        $result = $this->run_reconciliation((int)$this->get_last_run_time());
        mtrace("  local_completionsuspend: suspended {$result['suspended']}, "
            . "reactivated {$result['reactivated']}");
    }

    /**
     * Run a full reconciliation immediately, ignoring the incremental window.
     *
     * Used by the "Run reconciliation now" button on the dashboard.
     *
     * @return array{suspended: int, reactivated: int}
     */
    public function run_now(): array {
        return $this->run_reconciliation(0);
    }

    /**
     * Reconcile every in-scope course.
     *
     * @param int $since Only consider completions recorded after this timestamp.
     *                   Pass 0 for a full scan.
     * @return array{suspended: int, reactivated: int}
     */
    protected function run_reconciliation(int $since): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/completionsuspend/lib.php');

        $totals = ['suspended' => 0, 'reactivated' => 0];

        if (!get_config('local_completionsuspend', 'masterswitch')) {
            return $totals;
        }

        $simulated = (bool)get_config('local_completionsuspend', 'simulationmode');
        $retroactive = (bool)get_config('local_completionsuspend', 'retroactive');
        $reactivate = (bool)get_config('local_completionsuspend', 'reactivateonreset');

        foreach ($this->get_courses_in_scope() as $courseid) {
            if ($retroactive) {
                $totals['suspended'] += $this->backfill_completed($courseid, $simulated, $since);
            }
            if ($reactivate) {
                // Re-activation always scans in full: it is driven by the absence of a
                // completion record, which has no timestamp to window on.
                $totals['reactivated'] += $this->reactivate_reset($courseid, $simulated);
            }
        }

        return $totals;
    }

    /**
     * Resolve every course id currently in scope, from both course and category targets.
     *
     * @return int[]
     */
    protected function get_courses_in_scope(): array {
        global $DB;

        $courseids = $DB->get_fieldset_select(
            'local_completionsuspend_target',
            'targetid',
            'targettype = :type AND enabled = 1',
            ['type' => 'course']
        );

        $cats = $DB->get_fieldset_select(
            'local_completionsuspend_target',
            'targetid',
            'targettype = :type AND enabled = 1',
            ['type' => 'category']
        );

        $cascade = (bool)get_config('local_completionsuspend', 'includesubcategories');

        foreach ($cats as $catid) {
            $courseids = array_merge(
                $courseids,
                $this->courses_in_category((int)$catid, $cascade, [], 0)
            );
        }

        $courseids = array_unique(array_map('intval', $courseids));

        return array_values(array_filter($courseids, function ($id) {
            return $id != SITEID;
        }));
    }

    /**
     * Suspend learners who completed the course but are still actively enrolled.
     *
     * @param int  $courseid
     * @param bool $simulated
     * @param int  $since Only consider completions recorded after this timestamp (0 = all).
     * @return int Number of enrolments suspended.
     */
    protected function backfill_completed(int $courseid, bool $simulated, int $since = 0): int {
        global $DB;

        $params = ['cid' => $courseid];
        $sincesql = '';
        if ($since > 0) {
            // Small overlap so a completion recorded mid-run is not missed.
            $params['since'] = $since - DAYSECS;
            $sincesql = ' AND cc.timecompleted > :since';
        }

        $sql = "SELECT DISTINCT cc.userid
                  FROM {course_completions} cc
                  JOIN {user_enrolments} ue ON ue.userid = cc.userid
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = cc.course
                 WHERE cc.course = :cid
                   AND cc.timecompleted > 0
                   AND ue.status = " . ENROL_USER_ACTIVE . "
                   {$sincesql}";
        $completed = $DB->get_fieldset_sql($sql, $params);

        $count = 0;
        foreach ($completed as $userid) {
            $count += local_completionsuspend_suspend_user((int)$userid, $courseid, $simulated, 'task');
        }

        return $count;
    }

    /**
     * Re-activate users whose completion was reset but who are still suspended by us.
     *
     * @param int  $courseid
     * @param bool $simulated
     * @return int Number of enrolments re-activated.
     */
    protected function reactivate_reset(int $courseid, bool $simulated): int {
        global $DB;

        $sql = "SELECT DISTINCT l.userid
                  FROM {local_completionsuspend_log} l
                 WHERE l.courseid = :cid
                   AND l.action = 'suspended'
                   AND l.simulated = 0
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {course_completions} cc
                        WHERE cc.userid = l.userid
                          AND cc.course = l.courseid
                          AND cc.timecompleted > 0
                   )";
        $userids = $DB->get_fieldset_sql($sql, ['cid' => $courseid]);

        $count = 0;
        foreach ($userids as $userid) {
            $count += local_completionsuspend_reactivate_user((int)$userid, $courseid, $simulated);
        }

        return $count;
    }

    /**
     * Return all course ids in a category, optionally descending into sub-categories.
     *
     * @param int   $catid Category id.
     * @param bool  $cascade Whether to descend into sub-categories.
     * @param array $seen Category ids already visited, guarding against cycles.
     * @param int   $depth Current recursion depth.
     * @return int[]
     */
    protected function courses_in_category(int $catid, bool $cascade, array $seen = [], int $depth = 0): array {
        global $DB;

        if (isset($seen[$catid]) || $depth >= self::MAX_CAT_DEPTH) {
            return [];
        }
        $seen[$catid] = true;

        $ids = $DB->get_fieldset_select('course', 'id', 'category = :cid', ['cid' => $catid]);

        if ($cascade) {
            $subcats = $DB->get_fieldset_select('course_categories', 'id', 'parent = :cid', ['cid' => $catid]);
            foreach ($subcats as $sub) {
                $ids = array_merge($ids, $this->courses_in_category((int)$sub, true, $seen, $depth + 1));
            }
        }

        return $ids;
    }
}
