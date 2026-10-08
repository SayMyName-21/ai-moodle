<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course Structure Generator settings.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_course_structure',
        get_string('pluginname', 'local_course_structure'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading('local_course_structure_chat',
        get_string('chatsettings', 'local_course_structure'), ''));

    $settings->add(new admin_setting_configtext('local_course_structure/chatdailylimit',
        get_string('chatdailylimit', 'local_course_structure'),
        get_string('chatdailylimit_desc', 'local_course_structure'),
        40, PARAM_INT, 6));
}
