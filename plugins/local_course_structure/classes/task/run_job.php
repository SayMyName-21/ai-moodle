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
 * Runs generator work the course assistant started.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure\task;

use local_course_structure\job;

defined('MOODLE_INTERNAL') || die();

/**
 * Do one job, as the teacher who approved it.
 */
class run_job extends \core\task\adhoc_task {

    /**
     * Name shown in the task logs.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskrunjob', 'local_course_structure');
    }

    /**
     * Do the job. Failures are kept with the job, never rethrown.
     */
    public function execute() {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $data = $this->get_custom_data();
        job::run((int)$data->courseid, (string)$data->id);
    }
}
