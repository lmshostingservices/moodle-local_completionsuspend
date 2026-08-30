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
 * Upgrade script for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run all local_completionsuspend upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_completionsuspend_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082900) {
        $table = new xmldb_table('local_completionsuspend_log');

        // The old column was named "trigger", which is a reserved word in MySQL and MariaDB.
        // Every insert against it failed with a syntax error, so the table is expected to be
        // empty; the rename is still performed so that any manually inserted rows survive.
        $oldfield = new xmldb_field('trigger', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'event', 'action');
        if ($dbman->field_exists($table, $oldfield)) {
            $dbman->rename_field($table, $oldfield, 'triggertype');
        }

        // Records the enrolment's timemodified at the moment of suspension so that
        // re-activation can tell our own suspensions apart from later manual ones.
        $newfield = new xmldb_field('enroltimemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'simulated');
        if (!$dbman->field_exists($table, $newfield)) {
            $dbman->add_field($table, $newfield);
        }

        $index = new xmldb_index('idx_course_action', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'action', 'simulated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082900, 'local', 'completionsuspend');
    }

    return true;
}
