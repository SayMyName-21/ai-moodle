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
 * Reads a course's book in the background.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure\task;

use local_course_structure\reading;

defined('MOODLE_INTERNAL') || die();

/**
 * Read the stored book through Docling and propose its chapters.
 *
 * The reading is the slow part, minutes to hours for a textbook, and is kept
 * once done, so a retry with new guidance only repeats the chapter detection.
 * Every failure is caught and reported to the course structure page rather than
 * rethrown: Moodle would otherwise retry the task on its own schedule, while the
 * teacher is better placed to decide whether to try again.
 */
class read_book extends \core\task\adhoc_task {

    /**
     * Read the book and record the chapters it proposes.
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');

        $data = $this->get_custom_data();
        $courseid = (int)$data->courseid;
        $token = (string)$data->token;

        if (!reading::update($courseid, $token, array('status' => 'reading', 'started' => time()))) {
            mtrace('A newer reading has replaced this one for course ' . $courseid . '.');
            return;
        }

        try {
            $state = reading::get($courseid);
            $book = local_course_structure_stored_book(\context_course::instance($courseid));
            if ($book === null) {
                throw new \Exception(get_string('nobookstored', 'local_course_structure'));
            }
            list($path, $filename) = $book;

            mtrace('Reading ' . $filename . ' for course ' . $courseid . '.');
            $proposal = \mod_book\ai_service::extract_chapters($path,
                strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $state['guidance'] ?? '');

            if (empty($proposal)) {
                throw new \Exception(get_string('nochaptersfound', 'local_course_structure'));
            }

            reading::update($courseid, $token,
                array('status' => 'ready', 'finished' => time(), 'proposal' => $proposal));
            mtrace('Found ' . count($proposal) . ' chapters.');

        } catch (\Throwable $e) {
            reading::update($courseid, $token,
                array('status' => 'failed', 'finished' => time(), 'error' => $e->getMessage()));
            mtrace('Reading failed: ' . $e->getMessage());
        }
    }
}
