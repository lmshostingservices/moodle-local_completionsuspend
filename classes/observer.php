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
 * Event observer for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_completionsuspend;

/**
 * Observer class — responds to Moodle core events.
 */
class observer {
    /**
     * React to the course_completed event.
     *
     * Suspends the completing user's enrolments when the course is in scope.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        global $CFG;

        // Fast-exit when master switch is off.
        if (!get_config('local_completionsuspend', 'masterswitch')) {
            return;
        }

        require_once($CFG->dirroot . '/local/completionsuspend/lib.php');

        $courseid  = (int)$event->courseid;
        $userid    = (int)$event->relateduserid;
        $simulated = (bool)get_config('local_completionsuspend', 'simulationmode');

        if (!local_completionsuspend_course_in_scope($courseid)) {
            return;
        }

        local_completionsuspend_suspend_user($userid, $courseid, $simulated, 'event');
    }
}
