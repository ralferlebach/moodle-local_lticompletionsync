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
 * Administration settings and pages.
 *
 * @package    local_lticompletionsync
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $category = new admin_category('local_lticompletionsync', get_string('pluginname', 'local_lticompletionsync'));
    $ADMIN->add('localplugins', $category);

    $ADMIN->add('local_lticompletionsync', new admin_externalpage(
        'local_lticompletionsync_connections',
        get_string('connections', 'local_lticompletionsync'),
        new moodle_url('/local/lticompletionsync/index.php'),
        'local/lticompletionsync:manageconnections'
    ));

    $settings = new admin_settingpage('local_lticompletionsync_settings', get_string('settings', 'local_lticompletionsync'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_lticompletionsync/allowprivatetargets',
            get_string('allowprivatetargets', 'local_lticompletionsync'),
            get_string('allowprivatetargets_desc', 'local_lticompletionsync'),
            0
        ));
        $settings->add(new admin_setting_configtext(
            'local_lticompletionsync/maxattempts',
            get_string('maxattempts', 'local_lticompletionsync'),
            get_string('maxattempts_desc', 'local_lticompletionsync'),
            8,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configduration(
            'local_lticompletionsync/backoffbase',
            get_string('backoffbase', 'local_lticompletionsync'),
            get_string('backoffbase_desc', 'local_lticompletionsync'),
            60
        ));
        $settings->add(new admin_setting_configduration(
            'local_lticompletionsync/backoffmax',
            get_string('backoffmax', 'local_lticompletionsync'),
            get_string('backoffmax_desc', 'local_lticompletionsync'),
            21600
        ));
        $settings->add(new admin_setting_configduration(
            'local_lticompletionsync/messagettl',
            get_string('messagettl', 'local_lticompletionsync'),
            get_string('messagettl_desc', 'local_lticompletionsync'),
            120
        ));
        $settings->add(new admin_setting_configduration(
            'local_lticompletionsync/clockskew',
            get_string('clockskew', 'local_lticompletionsync'),
            get_string('clockskew_desc', 'local_lticompletionsync'),
            60
        ));
        $settings->add(new admin_setting_configtext(
            'local_lticompletionsync/journalretention',
            get_string('journalretention', 'local_lticompletionsync'),
            get_string('journalretention_desc', 'local_lticompletionsync'),
            90,
            PARAM_INT
        ));
    }
    $ADMIN->add('local_lticompletionsync', $settings);
}
