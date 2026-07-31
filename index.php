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
 * Admin dashboard for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/completionsuspend/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/completionsuspend:manage', $context);

$tab    = optional_param('tab', 'courses', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

// Handle toggle actions (course / category enable / disable).
if ($action && confirm_sesskey()) {
    $targettype = optional_param('type', 'course', PARAM_ALPHA);
    $targetid   = required_param('targetid', PARAM_INT);
    $existing   = $DB->get_record('local_completionsuspend_target', ['targettype' => $targettype, 'targetid' => $targetid]);
    if ($action === 'enable') {
        if ($existing) {
            $DB->set_field('local_completionsuspend_target', 'enabled', 1, ['id' => $existing->id]);
        } else {
            $DB->insert_record('local_completionsuspend_target', (object)[
                'targettype' => $targettype, 'targetid' => $targetid, 'enabled' => 1, 'timecreated' => time(),
            ]);
        }
    } else if ($action === 'disable' && $existing) {
        $DB->set_field('local_completionsuspend_target', 'enabled', 0, ['id' => $existing->id]);
    }
    redirect(new moodle_url('/local/completionsuspend/index.php', ['tab' => $tab]));
}

// Handle manual reconciliation.
if (optional_param('reconcile', 0, PARAM_INT) && confirm_sesskey()) {
    $task = new \local_completionsuspend\task\reconcile();
    $task->execute();
    redirect(new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity']),
        get_string('reconciliationdone', 'local_completionsuspend', (object)['suspended' => 0, 'reactivated' => 0]));
}

$PAGE->set_url('/local/completionsuspend/index.php', ['tab' => $tab]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboard', 'local_completionsuspend'));
$PAGE->set_heading(get_string('dashboard', 'local_completionsuspend'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('dashboard', 'local_completionsuspend'));

// Master switch warning.
if (!get_config('local_completionsuspend', 'masterswitch')) {
    echo $OUTPUT->notification(get_string('masterswitch_help', 'local_completionsuspend'), 'warning');
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_completionsuspend_settings']);
    echo html_writer::link($settingsurl, get_string('settings'), ['class' => 'btn btn-primary mb-3']);
}

// Tab navigation.
$tabs = [
    new tabobject('courses',    new moodle_url('/local/completionsuspend/index.php', ['tab' => 'courses']),    get_string('tab_courses', 'local_completionsuspend')),
    new tabobject('categories', new moodle_url('/local/completionsuspend/index.php', ['tab' => 'categories']), get_string('tab_categories', 'local_completionsuspend')),
    new tabobject('activity',   new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity']),   get_string('tab_activity', 'local_completionsuspend')),
];
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'courses') {
    // List all courses with toggle controls.
    $courses = $DB->get_records('course', ['format' => 'topics'], 'fullname ASC', 'id, fullname, shortname');
    if (empty($courses)) {
        $courses = $DB->get_records_sql('SELECT id, fullname, shortname FROM {course} WHERE id <> 1 ORDER BY fullname');
    }
    $targets = $DB->get_records('local_completionsuspend_target', ['targettype' => 'course'], '', 'targetid, enabled');

    $table = new html_table();
    $table->head  = [get_string('course'), get_string('enrolled'), get_string('completed'), get_string('enabled'), get_string('actions')];
    $table->align = ['left', 'center', 'center', 'center', 'center'];

    foreach ($courses as $c) {
        $enrolled  = count_enrolled_users(context_course::instance($c->id));
        $completed = $DB->count_records_select('course_completions', 'course = :cid AND timecompleted > 0', ['cid' => $c->id]);
        $isenabled = !empty($targets[$c->id]) && $targets[$c->id]->enabled;
        $actionstr = $isenabled
            ? html_writer::link(new moodle_url('/local/completionsuspend/index.php', ['tab' => 'courses', 'action' => 'disable', 'type' => 'course', 'targetid' => $c->id, 'sesskey' => sesskey()]), get_string('disablecourse', 'local_completionsuspend'), ['class' => 'btn btn-sm btn-outline-danger'])
            : html_writer::link(new moodle_url('/local/completionsuspend/index.php', ['tab' => 'courses', 'action' => 'enable',  'type' => 'course', 'targetid' => $c->id, 'sesskey' => sesskey()]), get_string('enablecourse', 'local_completionsuspend'),  ['class' => 'btn btn-sm btn-outline-success']);
        $table->data[] = [format_string($c->fullname), $enrolled, $completed, $isenabled ? '✓' : '', $actionstr];
    }
    echo html_writer::table($table);

} else if ($tab === 'activity') {
    $reconcileurl = new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity', 'reconcile' => 1, 'sesskey' => sesskey()]);
    echo html_writer::link($reconcileurl, get_string('runreconciliation', 'local_completionsuspend'), ['class' => 'btn btn-secondary mb-3']);

    $logs = $DB->get_records('local_completionsuspend_log', null, 'timecreated DESC', '*', 0, 100);
    if ($logs) {
        $table = new html_table();
        $table->head  = [get_string('user'), get_string('course'), get_string('actions'), get_string('time')];
        $table->align = ['left', 'left', 'left', 'left'];
        foreach ($logs as $log) {
            $u = $DB->get_record('user', ['id' => $log->userid]);
            $c = $DB->get_record('course', ['id' => $log->courseid]);
            $table->data[] = [
                $u ? fullname($u) : $log->userid,
                $c ? format_string($c->shortname) : $log->courseid,
                $log->action . ($log->simulated ? ' (simulated)' : ''),
                userdate($log->timecreated),
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('thereareno', 'moodle', 'entries'), 'info');
    }
}

echo $OUTPUT->footer();
