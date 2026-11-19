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
 * Generator work done in the background.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Work from the generator's tabs, run by the background worker.
 *
 * Building presentations, joining or revising them, writing quizzes - each
 * takes from a minute to an hour on the free AI plan, which is longer than a
 * request from the course assistant can wait. So when the teacher approves such
 * a change in the assistant, it becomes a job: queued as an ad hoc task that
 * runs as the teacher who approved it, through the same code as the tabs, with
 * its progress kept here for the assistant to report.
 *
 * Kept in the plugin's config, like a book's reading, so the web server and the
 * worker container both see it. A job is 'queued', 'running', 'done' or
 * 'failed', and carries its kind, its parameters, the teacher's summary of it,
 * timestamps, and the lines of progress it has reported.
 */
class job {

    /** Jobs kept per course; older finished ones are forgotten. */
    const KEEP = 20;

    /** Progress lines kept per job. */
    const LOG_LINES = 40;

    /** The kinds of work, each a generator tab or part of one. */
    const KINDS = array('presentations', 'join', 'revise', 'powerpoint', 'quiz', 'reading');

    /**
     * Queue a piece of work, to run as the current user.
     *
     * @param \stdClass $course The course
     * @param string $kind One of KINDS
     * @param array $params What the work needs, as run() expects it
     * @param string $summary The teacher's description of it
     * @return string The job's id
     */
    public static function start($course, $kind, array $params, $summary) {
        global $USER;

        if (!in_array($kind, self::KINDS, true)) {
            throw new \coding_exception('Unknown kind of job: ' . $kind);
        }

        $id = random_string(12);
        self::save($course->id, $id, array(
            'id' => $id,
            'courseid' => (int)$course->id,
            'userid' => (int)$USER->id,
            'kind' => $kind,
            'params' => $params,
            'summary' => (string)$summary,
            'status' => 'queued',
            'log' => array(),
            'queued' => time(),
        ));

        $task = new task\run_job();
        $task->set_custom_data(array('courseid' => (int)$course->id, 'id' => $id));
        // The worker sets this user up before running it, so every permission
        // check along the way is the teacher's own.
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        self::prune($course->id);
        return $id;
    }

    /**
     * One job.
     *
     * @param int $courseid Course
     * @param string $id The job's id
     * @return array|null The job, or null if there is no such job
     */
    public static function get($courseid, $id) {
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/', (string)$id)) {
            return null;
        }
        $raw = get_config('local_course_structure', self::key($courseid, $id));
        $job = $raw ? json_decode($raw, true) : null;
        return is_array($job) ? $job : null;
    }

    /**
     * A course's jobs, newest first.
     *
     * @param int $courseid Course
     * @return array Jobs
     */
    public static function all($courseid) {
        $prefix = 'job_' . (int)$courseid . '_';
        $jobs = array();
        foreach ((array)get_config('local_course_structure') as $name => $raw) {
            if (strpos($name, $prefix) === 0) {
                $job = json_decode($raw, true);
                if (is_array($job)) {
                    $jobs[] = $job;
                }
            }
        }
        usort($jobs, function($a, $b) {
            return $b['queued'] <=> $a['queued'];
        });
        return $jobs;
    }

    /**
     * Do a queued job. Called by the background task.
     *
     * Every failure is caught and kept with the job rather than rethrown: Moodle
     * would otherwise run it again on its own schedule, and a second run of half
     * an hour's AI requests is the teacher's decision to make.
     *
     * @param int $courseid Course
     * @param string $id The job's id
     */
    public static function run($courseid, $id) {
        $job = self::get($courseid, $id);
        if ($job === null || $job['status'] !== 'queued') {
            mtrace('Job ' . $id . ' is not waiting to run.');
            return;
        }
        self::update($courseid, $id, array('status' => 'running', 'started' => time()));

        $say = function($message, $type = 'info') use ($courseid, $id) {
            $line = trim(html_entity_decode(strip_tags((string)$message), ENT_QUOTES, 'UTF-8'));
            mtrace($line);
            $job = self::get($courseid, $id);
            if ($job !== null) {
                $job['log'][] = array('type' => $type, 'text' => \core_text::substr($line, 0, 400));
                self::update($courseid, $id, array('log' => array_slice($job['log'], -self::LOG_LINES)));
            }
        };

        try {
            $course = get_course($courseid);
            $result = self::perform($course, $job['kind'], $job['params'], $say);
            self::update($courseid, $id, array('status' => 'done', 'finished' => time(), 'result' => $result));
        } catch (\Throwable $e) {
            $say($e->getMessage(), 'error');
            self::update($courseid, $id, array('status' => 'failed', 'finished' => time(),
                'error' => \core_text::substr($e->getMessage(), 0, 500)));
        }
    }

    /**
     * A job as one line: what it is, how far it has got, and what it last said.
     *
     * @param array $job The job
     * @return string
     */
    public static function describe($job) {
        $last = end($job['log']);
        $line = $job['summary'] . ' — ' . get_string('jobstatus_' . $job['status'], 'local_course_structure');
        if ($job['status'] === 'failed' && !empty($job['error'])) {
            $line .= ': ' . $job['error'];
        } else if ($last) {
            $line .= ': ' . $last['text'];
        }
        return $line;
    }

    /**
     * Do the work itself, through the same code as the generator's tabs.
     *
     * @param \stdClass $course The course
     * @param string $kind One of KINDS
     * @param array $params What start() was given
     * @param callable $say Progress
     * @return mixed What the work returned
     */
    private static function perform($course, $kind, $params, callable $say) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');

        $cmids = isset($params['cmids']) ? array_map('intval', (array)$params['cmids']) : null;

        switch ($kind) {
            case 'presentations':
                return local_course_structure_build_presentations($course, $params['groups'],
                    !empty($params['sources']), !empty($params['replace']), $say, (int)($params['first'] ?? 0));
            case 'join':
                return local_course_structure_join_decks($course, $cmids,
                    isset($params['sections']) ? array_map('intval', (array)$params['sections']) : null,
                    (string)($params['title'] ?? ''), (int)($params['topic'] ?? -1), !empty($params['hide']), $say);
            case 'revise':
                return local_course_structure_revise_deck($course, (int)$params['cmid'],
                    (string)$params['request'], $say);
            case 'powerpoint':
                return local_course_structure_convert_decks($course, $cmids, $say);
            case 'quiz':
                return local_course_structure_build_quiz($course, $params, $say);
            case 'reading':
                return local_course_structure_add_reading($course, $cmids, $say);
        }
        throw new \coding_exception('Unknown kind of job: ' . $kind);
    }

    /**
     * Change part of a job.
     *
     * @param int $courseid Course
     * @param string $id The job's id
     * @param array $changes Fields to set
     */
    private static function update($courseid, $id, array $changes) {
        $job = self::get($courseid, $id);
        if ($job !== null) {
            self::save($courseid, $id, array_merge($job, $changes));
        }
    }

    /**
     * Forget all but the newest jobs, never one still waiting or running.
     *
     * @param int $courseid Course
     */
    private static function prune($courseid) {
        foreach (array_slice(self::all($courseid), self::KEEP) as $job) {
            if (in_array($job['status'], array('done', 'failed'), true)) {
                unset_config(self::key($courseid, $job['id']), 'local_course_structure');
            }
        }
    }

    /**
     * Store a job.
     *
     * @param int $courseid Course
     * @param string $id The job's id
     * @param array $job The job
     */
    private static function save($courseid, $id, array $job) {
        set_config(self::key($courseid, $id), json_encode($job, JSON_UNESCAPED_UNICODE), 'local_course_structure');
    }

    /**
     * Where a job is kept.
     *
     * @param int $courseid Course
     * @param string $id The job's id
     * @return string Config name
     */
    private static function key($courseid, $id) {
        return 'job_' . (int)$courseid . '_' . $id;
    }
}
