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
 * Where a course's book reading has got to.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * The state of reading a course's book, shared between the page and the task.
 *
 * Reading a textbook through Docling takes minutes to hours, so it runs as a
 * background task while the course structure page watches. The two meet here:
 * the page starts a reading and shows how it is going, and the task reports
 * when the chapters are ready or why it stopped. Kept in the plugin's config so
 * the web server and the worker container both see it.
 *
 * A state is one of 'queued', 'reading', 'ready' or 'failed', and also carries
 * the book's file name and content hash, the teacher's guidance for the chapter
 * detection, timestamps, and once ready the proposed chapters.
 */
class reading {

    /**
     * The state of a course's reading.
     *
     * @param int $courseid Course
     * @return array|null The state, or null if no reading has been started
     */
    public static function get($courseid) {
        $raw = get_config('local_course_structure', 'reading_' . (int)$courseid);
        $state = $raw ? json_decode($raw, true) : null;

        return is_array($state) ? $state : null;
    }

    /**
     * Start reading the course's stored book in the background.
     *
     * Anything already running for the course is superseded: its task notices
     * that the token has changed and leaves the state alone when it finishes.
     *
     * @param int $courseid Course
     * @param \stored_file $book The stored book
     * @param string $guidance The teacher's correction for the chapter detection
     * @param bool $includesubtopics Whether the review should list sub-topics
     */
    public static function start($courseid, \stored_file $book, $guidance, $includesubtopics) {
        $token = random_string(16);

        self::save($courseid, array(
            'status' => 'queued',
            'token' => $token,
            'filename' => $book->get_filename(),
            'contenthash' => $book->get_contenthash(),
            'guidance' => (string)$guidance,
            'includesubtopics' => (bool)$includesubtopics,
            'queued' => time(),
        ));

        $task = new task\read_book();
        $task->set_custom_data(array('courseid' => (int)$courseid, 'token' => $token));
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Change part of a reading's state, if it is still the current one.
     *
     * @param int $courseid Course
     * @param string $token The reading's token
     * @param array $changes Fields to set
     * @return bool False if a newer reading has replaced this one
     */
    public static function update($courseid, $token, array $changes) {
        $state = self::get($courseid);
        if ($state === null || ($state['token'] ?? '') !== $token) {
            return false;
        }

        self::save($courseid, array_merge($state, $changes));

        return true;
    }

    /**
     * Forget a course's reading, once its chapters have been agreed to.
     *
     * @param int $courseid Course
     */
    public static function clear($courseid) {
        unset_config('reading_' . (int)$courseid, 'local_course_structure');
    }

    /**
     * Store a course's reading state.
     *
     * @param int $courseid Course
     * @param array $state The state
     */
    private static function save($courseid, array $state) {
        set_config('reading_' . (int)$courseid, json_encode($state), 'local_course_structure');
    }
}
