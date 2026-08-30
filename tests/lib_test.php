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
 * Unit tests for local_completionsuspend library functions.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_completionsuspend;

/**
 * Tests for scope resolution, logging and suspension.
 *
 * @covers ::local_completionsuspend_course_in_scope
 * @covers ::local_completionsuspend_suspend_user
 * @covers ::local_completionsuspend_reactivate_user
 */
final class lib_test extends \advanced_testcase {
    /**
     * Load the plugin library before each test.
     */
    public function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/completionsuspend/lib.php');
        $this->resetAfterTest(true);
    }

    /**
     * Nothing is ever in scope while the master switch is off.
     */
    public function test_master_switch_gates_everything(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_completionsuspend_target', (object)[
            'targettype' => 'course',
            'targetid' => $course->id,
            'enabled' => 1,
            'timecreated' => time(),
        ]);

        set_config('masterswitch', 0, 'local_completionsuspend');
        $this->assertFalse(local_completionsuspend_course_in_scope((int)$course->id));

        set_config('masterswitch', 1, 'local_completionsuspend');
        $this->assertTrue(local_completionsuspend_course_in_scope((int)$course->id));
    }

    /**
     * A disabled target does not put the course in scope.
     */
    public function test_disabled_target_is_out_of_scope(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_completionsuspend_target', (object)[
            'targettype' => 'course',
            'targetid' => $course->id,
            'enabled' => 0,
            'timecreated' => time(),
        ]);

        $this->assertFalse(local_completionsuspend_course_in_scope((int)$course->id));
    }

    /**
     * The site course is never in scope, even if somebody targets it.
     */
    public function test_site_course_never_in_scope(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');
        $DB->insert_record('local_completionsuspend_target', (object)[
            'targettype' => 'course',
            'targetid' => SITEID,
            'enabled' => 1,
            'timecreated' => time(),
        ]);

        $this->assertFalse(local_completionsuspend_course_in_scope(SITEID));
    }

    /**
     * An enabled category covers courses directly inside it, and with the cascade on,
     * courses in its sub-categories too.
     */
    public function test_category_scope_and_cascade(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');

        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $direct = $this->getDataGenerator()->create_course(['category' => $parent->id]);
        $nested = $this->getDataGenerator()->create_course(['category' => $child->id]);

        $DB->insert_record('local_completionsuspend_target', (object)[
            'targettype' => 'category',
            'targetid' => $parent->id,
            'enabled' => 1,
            'timecreated' => time(),
        ]);

        set_config('includesubcategories', 1, 'local_completionsuspend');
        $this->assertTrue(local_completionsuspend_course_in_scope((int)$direct->id));
        $this->assertTrue(local_completionsuspend_course_in_scope((int)$nested->id));

        // With the cascade off, only the course's own category counts.
        set_config('includesubcategories', 0, 'local_completionsuspend');
        $this->assertTrue(local_completionsuspend_course_in_scope((int)$direct->id));
        $this->assertFalse(local_completionsuspend_course_in_scope((int)$nested->id));
    }

    /**
     * The audit log accepts a write. This is the regression test for the reserved-word
     * column that made every insert fail on MySQL and MariaDB.
     */
    public function test_log_write_succeeds(): void {
        global $DB;

        $id = local_completionsuspend_log(1, 2, 3, 'suspended', 'task', false, 12345);
        $this->assertGreaterThan(0, $id);

        $record = $DB->get_record('local_completionsuspend_log', ['id' => $id]);
        $this->assertSame('task', $record->triggertype);
        $this->assertSame('suspended', $record->action);
        $this->assertEquals(12345, $record->enroltimemodified);
    }

    /**
     * A student is suspended and an audit row is written.
     */
    public function test_suspend_user_writes_log_and_changes_enrolment(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');
        set_config('protectstaff', 1, 'local_completionsuspend');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $count = local_completionsuspend_suspend_user((int)$student->id, (int)$course->id, false, 'task');

        $this->assertSame(1, $count);
        $this->assertTrue($DB->record_exists('local_completionsuspend_log', [
            'userid' => $student->id,
            'courseid' => $course->id,
            'action' => 'suspended',
        ]));

        $status = $DB->get_field_sql(
            'SELECT ue.status FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND e.courseid = :cid',
            ['uid' => $student->id, 'cid' => $course->id]
        );
        $this->assertEquals(ENROL_USER_SUSPENDED, $status);
    }

    /**
     * Staff protection skips anyone who can edit the course.
     */
    public function test_staff_are_protected(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');
        set_config('protectstaff', 1, 'local_completionsuspend');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $count = local_completionsuspend_suspend_user((int)$teacher->id, (int)$course->id, false, 'task');

        $this->assertSame(0, $count);
        $this->assertFalse($DB->record_exists('local_completionsuspend_log', ['userid' => $teacher->id]));
    }

    /**
     * Simulation mode logs the intent without changing the enrolment.
     */
    public function test_simulation_mode_changes_nothing(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        local_completionsuspend_suspend_user((int)$student->id, (int)$course->id, true, 'task');

        $this->assertTrue($DB->record_exists('local_completionsuspend_log', [
            'userid' => $student->id,
            'action' => 'simulated',
        ]));

        $status = $DB->get_field_sql(
            'SELECT ue.status FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND e.courseid = :cid',
            ['uid' => $student->id, 'cid' => $course->id]
        );
        $this->assertEquals(ENROL_USER_ACTIVE, $status);
    }

    /**
     * Re-activation restores an enrolment we suspended, but leaves alone one that
     * somebody else has changed since.
     */
    public function test_reactivation_respects_external_changes(): void {
        global $DB;

        set_config('masterswitch', 1, 'local_completionsuspend');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');

        local_completionsuspend_suspend_user((int)$student->id, (int)$course->id, false, 'task');
        local_completionsuspend_suspend_user((int)$other->id, (int)$course->id, false, 'task');

        // Simulate an administrator touching the second learner's enrolment afterwards.
        $ue = $DB->get_record_sql(
            'SELECT ue.* FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :uid AND e.courseid = :cid',
            ['uid' => $other->id, 'cid' => $course->id]
        );
        $DB->set_field('user_enrolments', 'timemodified', time() + 500, ['id' => $ue->id]);

        $restored = local_completionsuspend_reactivate_user((int)$student->id, (int)$course->id, false);
        $untouched = local_completionsuspend_reactivate_user((int)$other->id, (int)$course->id, false);

        $this->assertSame(1, $restored);
        $this->assertSame(0, $untouched);
    }
}
