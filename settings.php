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
 * Admin settings for local_completionsuspend.
 *
 * @package    local_completionsuspend
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category('local_completionsuspend_cat',
        get_string('pluginname', 'local_completionsuspend')));

    // Link to the dashboard.
    $ADMIN->add('local_completionsuspend_cat', new admin_externalpage(
        'local_completionsuspend_dashboard',
        get_string('dashboard', 'local_completionsuspend'),
        new moodle_url('/local/completionsuspend/index.php'),
        'local/completionsuspend:manage'
    ));

    // Plugin settings.
    $settings = new admin_settingpage('local_completionsuspend_settings',
        get_string('pluginname', 'local_completionsuspend') . ' — ' . get_string('settings'));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/masterswitch',
        get_string('masterswitch', 'local_completionsuspend'),
        get_string('masterswitch_help', 'local_completionsuspend'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/protectstaff',
        get_string('protectstaff', 'local_completionsuspend'),
        get_string('protectstaff_help', 'local_completionsuspend'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/reactivateonreset',
        get_string('reactivateonreset', 'local_completionsuspend'),
        get_string('reactivateonreset_help', 'local_completionsuspend'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/includesubcategories',
        get_string('includesubcategories', 'local_completionsuspend'),
        get_string('includesubcategories_help', 'local_completionsuspend'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/retroactive',
        get_string('retroactive', 'local_completionsuspend'),
        get_string('retroactive_help', 'local_completionsuspend'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_completionsuspend/simulationmode',
        get_string('simulationmode', 'local_completionsuspend'),
        get_string('simulationmode_help', 'local_completionsuspend'),
        0
    ));

    $ADMIN->add('local_completionsuspend_cat', $settings);
}
