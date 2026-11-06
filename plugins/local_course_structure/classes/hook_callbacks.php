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
 * Hook callbacks.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Where the plugin joins in with Moodle's own pages.
 */
class hook_callbacks {

    /**
     * Put the course assistant in the corner of every course page.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The footer being built
     */
    public static function before_footer_html_generation(
            \core\hook\output\before_footer_html_generation $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/course_structure/lib.php');
        $hook->add_html(local_course_structure_chat_widget());
    }
}
