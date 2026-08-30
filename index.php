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
 * Admin dashboard for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/completionsuspend/lib.php');

require_login();
admin_externalpage_setup('local_completionsuspend_dashboard');

$context = context_system::instance();

$tab = optional_param('tab', 'courses', PARAM_ALPHA);
if (!in_array($tab, ['courses', 'categories', 'activity'], true)) {
    $tab = 'courses';
}
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

$perpage = 50;
$baseurl = new moodle_url('/local/completionsuspend/index.php', ['tab' => $tab]);

// Handle enable/disable of a target.
if ($action !== '' && confirm_sesskey()) {
    require_capability('local/completionsuspend:manage', $context);

    $targettype = optional_param('type', 'course', PARAM_ALPHA);
    if (!in_array($targettype, ['course', 'category'], true)) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    $targetid = required_param('targetid', PARAM_INT);

    // Make sure the target actually exists before storing it.
    $exists = $targettype === 'course'
        ? $DB->record_exists('course', ['id' => $targetid])
        : $DB->record_exists('course_categories', ['id' => $targetid]);
    if (!$exists) {
        throw new moodle_exception('invalidparameter', 'debug');
    }

    $existing = $DB->get_record(
        'local_completionsuspend_target',
        ['targettype' => $targettype, 'targetid' => $targetid]
    );

    if ($action === 'enable') {
        if ($existing) {
            $DB->set_field('local_completionsuspend_target', 'enabled', 1, ['id' => $existing->id]);
        } else {
            $DB->insert_record('local_completionsuspend_target', (object)[
                'targettype' => $targettype,
                'targetid' => $targetid,
                'enabled' => 1,
                'timecreated' => time(),
            ]);
        }
    } else if ($action === 'disable' && $existing) {
        $DB->set_field('local_completionsuspend_target', 'enabled', 0, ['id' => $existing->id]);
    }

    redirect(new moodle_url(
        '/local/completionsuspend/index.php',
        ['tab' => $tab, 'page' => $page, 'search' => $search]
    ));
}

// Handle a manual reconciliation run.
if (optional_param('reconcile', 0, PARAM_INT) && confirm_sesskey()) {
    require_capability('local/completionsuspend:manage', $context);

    $task = new \local_completionsuspend\task\reconcile();
    $result = $task->run_now();

    redirect(
        new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity']),
        get_string('reconciliationdone', 'local_completionsuspend', (object)[
            'suspended' => $result['suspended'],
            'reactivated' => $result['reactivated'],
        ]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('dashboard', 'local_completionsuspend'));
$PAGE->set_heading(get_string('dashboard', 'local_completionsuspend'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('dashboard', 'local_completionsuspend'));

if (!get_config('local_completionsuspend', 'masterswitch')) {
    echo $OUTPUT->notification(get_string('masterswitchoff', 'local_completionsuspend'), 'warning');
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_completionsuspend_settings']);
    echo html_writer::link($settingsurl, get_string('settings'), ['class' => 'btn btn-primary mb-3']);
} else if (get_config('local_completionsuspend', 'simulationmode')) {
    echo $OUTPUT->notification(get_string('simulationactive', 'local_completionsuspend'), 'info');
}

$tabs = [
    new tabobject(
        'courses',
        new moodle_url('/local/completionsuspend/index.php', ['tab' => 'courses']),
        get_string('tab_courses', 'local_completionsuspend')
    ),
    new tabobject(
        'categories',
        new moodle_url('/local/completionsuspend/index.php', ['tab' => 'categories']),
        get_string('tab_categories', 'local_completionsuspend')
    ),
    new tabobject(
        'activity',
        new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity']),
        get_string('tab_activity', 'local_completionsuspend')
    ),
];
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'courses') {
    // Search box.
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => new moodle_url('/local/completionsuspend/index.php'),
        'class' => 'mb-3 d-flex gap-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'courses']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'search',
        'value' => $search,
        'class' => 'form-control',
        'style' => 'max-width:320px',
        'placeholder' => get_string('searchcourses', 'local_completionsuspend'),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('search'),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');

    // All real courses, in any format. The previous release filtered on format = 'topics',
    // which silently hid every course using another format.
    $where = 'c.id <> :siteid';
    $params = ['siteid' => SITEID];
    if ($search !== '') {
        $where .= ' AND (' . $DB->sql_like('c.fullname', ':s1', false) . ' OR '
            . $DB->sql_like('c.shortname', ':s2', false) . ')';
        $params['s1'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['s2'] = '%' . $DB->sql_like_escape($search) . '%';
    }

    $total = $DB->count_records_sql("SELECT COUNT(1) FROM {course} c WHERE {$where}", $params);
    $courses = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname
           FROM {course} c
          WHERE {$where}
       ORDER BY c.fullname ASC",
        $params,
        $page * $perpage,
        $perpage
    );

    if (!$courses) {
        echo $OUTPUT->notification(get_string('nocoursesfound', 'local_completionsuspend'), 'info');
    } else {
        $courseids = array_keys($courses);
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');

        // Enrolment counts for the whole page in one query rather than one call per course.
        $enrolled = $DB->get_records_sql_menu(
            "SELECT e.courseid, COUNT(DISTINCT ue.userid)
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.courseid {$insql}
           GROUP BY e.courseid",
            $inparams
        );

        // Completion counts, likewise.
        $completed = $DB->get_records_sql_menu(
            "SELECT cc.course, COUNT(1)
               FROM {course_completions} cc
              WHERE cc.course {$insql} AND cc.timecompleted > 0
           GROUP BY cc.course",
            $inparams
        );

        $targets = $DB->get_records_menu(
            'local_completionsuspend_target',
            ['targettype' => 'course'],
            '',
            'targetid, enabled'
        );

        $table = new html_table();
        $table->head = [
            get_string('course'),
            get_string('enrolled', 'local_completionsuspend'),
            get_string('completed'),
            get_string('enabled', 'admin'),
            get_string('actions'),
        ];
        $table->align = ['left', 'center', 'center', 'center', 'center'];
        $table->attributes['class'] = 'generaltable';

        foreach ($courses as $c) {
            $isenabled = !empty($targets[$c->id]);
            $table->data[] = [
                html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $c->id]),
                    format_string($c->fullname)
                ),
                $enrolled[$c->id] ?? 0,
                $completed[$c->id] ?? 0,
                $isenabled ? $OUTPUT->pix_icon('i/checked', get_string('yes')) : '',
                local_completionsuspend_action_link('course', (int)$c->id, $isenabled, $tab, $page, $search),
            ];
        }
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar(
            $total,
            $page,
            $perpage,
            new moodle_url('/local/completionsuspend/index.php', ['tab' => 'courses', 'search' => $search])
        );
    }
} else if ($tab === 'categories') {
    // Full category tree, indented, each row toggleable.
    $categories = core_course_category::make_categories_list();
    $targets = $DB->get_records_menu(
        'local_completionsuspend_target',
        ['targettype' => 'category'],
        '',
        'targetid, enabled'
    );

    if (!$categories) {
        echo $OUTPUT->notification(get_string('nocategoriesfound', 'local_completionsuspend'), 'info');
    } else {
        $coursecounts = $DB->get_records_sql_menu(
            'SELECT category, COUNT(1) FROM {course} WHERE id <> :siteid GROUP BY category',
            ['siteid' => SITEID]
        );

        $table = new html_table();
        $table->head = [
            get_string('category'),
            get_string('courses'),
            get_string('enabled', 'admin'),
            get_string('actions'),
        ];
        $table->align = ['left', 'center', 'center', 'center'];
        $table->attributes['class'] = 'generaltable';

        foreach ($categories as $catid => $catname) {
            $isenabled = !empty($targets[$catid]);
            $table->data[] = [
                html_writer::link(new moodle_url('/course/index.php', ['categoryid' => $catid]), $catname),
                $coursecounts[$catid] ?? 0,
                $isenabled ? $OUTPUT->pix_icon('i/checked', get_string('yes')) : '',
                local_completionsuspend_action_link('category', (int)$catid, $isenabled, $tab, $page, $search),
            ];
        }
        echo html_writer::table($table);

        if (get_config('local_completionsuspend', 'includesubcategories')) {
            echo $OUTPUT->notification(get_string('cascadeon', 'local_completionsuspend'), 'info');
        }
    }
} else {
    // Activity tab.
    $reconcileurl = new moodle_url('/local/completionsuspend/index.php', [
        'tab' => 'activity',
        'reconcile' => 1,
        'sesskey' => sesskey(),
    ]);
    echo html_writer::link(
        $reconcileurl,
        get_string('runreconciliation', 'local_completionsuspend'),
        ['class' => 'btn btn-secondary mb-3']
    );

    $total = $DB->count_records('local_completionsuspend_log');
    $logs = $DB->get_records(
        'local_completionsuspend_log',
        null,
        'timecreated DESC',
        '*',
        $page * $perpage,
        $perpage
    );

    if (!$logs) {
        echo $OUTPUT->notification(get_string('nologentries', 'local_completionsuspend'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('user'),
            get_string('course'),
            get_string('action', 'local_completionsuspend'),
            get_string('trigger', 'local_completionsuspend'),
            get_string('time'),
        ];
        $table->align = ['left', 'left', 'left', 'left', 'left'];
        $table->attributes['class'] = 'generaltable';

        foreach ($logs as $log) {
            $u = $DB->get_record('user', ['id' => $log->userid]);
            $c = $DB->get_record('course', ['id' => $log->courseid], 'id, shortname');

            $actionlabel = get_string('log_' . $log->action, 'local_completionsuspend');
            if ($log->simulated) {
                $actionlabel .= ' ' . html_writer::tag(
                    'span',
                    get_string('log_simulated', 'local_completionsuspend'),
                    ['class' => 'badge bg-secondary text-white']
                );
            }

            $table->data[] = [
                $u ? fullname($u) : $log->userid,
                $c ? format_string($c->shortname) : $log->courseid,
                $actionlabel,
                get_string('log_' . $log->triggertype, 'local_completionsuspend'),
                userdate($log->timecreated),
            ];
        }
        echo html_writer::table($table);
        echo $OUTPUT->paging_bar(
            $total,
            $page,
            $perpage,
            new moodle_url('/local/completionsuspend/index.php', ['tab' => 'activity'])
        );
    }
}

echo $OUTPUT->footer();
