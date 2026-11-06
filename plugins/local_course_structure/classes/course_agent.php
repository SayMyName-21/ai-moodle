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
 * The teacher's assistant, able to change the course.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Lets the model read and change a course on a teacher's behalf.
 *
 * Reading happens at once. Changing never does: every change the model wants is
 * written down as a proposal, tried out inside a transaction that is rolled back
 * so a mistake shows up before the teacher sees it, and only carried out when the
 * teacher presses Apply. Everything runs as the teacher, through Moodle's own
 * permission checks, so the assistant can do exactly what the teacher could do
 * by hand and nothing more.
 *
 * Moodle's web service functions are the model's general toolkit, the same ones
 * an MCP server would offer. They cannot create activities, change an activity's
 * settings, write questions or rename a topic, so those have tools of their own,
 * built on the code behind Moodle's own editing pages.
 */
class course_agent {

    /** Turns the model may take before it has to answer. */
    const MAX_TURNS = 8;

    /**
     * Seconds the model may keep working. Apache gives a request five minutes, and
     * the free tier makes the model wait between turns.
     */
    const TIME_BUDGET = 240;

    /** Characters of a tool's result passed back to the model. */
    const RESULT_CHARS = 5000;

    /** Seconds a proposal waits to be applied before it lapses. */
    const PLAN_LIFETIME = 3600;

    /** Exchanges remembered, so a teacher can refine what they asked for. */
    const HISTORY = 3;

    /**
     * @var array Each tool call of the last request with the start of its result,
     *            for working out afterwards why a conversation went the way it did.
     */
    public static $trace = array();

    /** Form fields that carry no setting of the activity. */
    const FORM_NOISE = '/^(sesskey|_qf__|mform_|submitbutton|cancel|return|sr|add|update|' .
        'coursemodule|module|modulename|instance|course|section|showgradingmanagement|' .
        'gradingman|buttonar|completionunlocked|availabilityconditionsjson)/';

    /**
     * Work on a teacher's request until the model has answered.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @param string $question What the teacher asked
     * @param string $prompt How the assistant is to behave
     * @param string $material Course material, given on the first turn only
     * @return array 'answer', and 'plan' with an id and the summary of each
     *               proposed change if there are any
     */
    public static function run($course, $context, $question, $prompt, $material) {
        global $SESSION;

        $started = time();
        $actions = array();
        self::$trace = array();

        // The free tier counts the whole request against one allowance, which the
        // material was sized to fill on its own; what was said earlier would tip a
        // follow-up over it, so its room comes out of the material.
        $history = self::history_text($course->id);
        $material = \core_text::substr($material, 0,
            max(2000, \core_text::strlen($material) - \core_text::strlen($history)));

        $system = $prompt . "\n\n" . self::rules() .
            "\n\n=== COURSE STRUCTURE (with ids) ===\n" . self::structure($course) . $history;

        // Said beside the request rather than among the rules: with the course in
        // Macedonian, a question asked in English was still answered in Russian.
        $request = "=== REQUEST ===\n" . \core_text::substr($question, 0, 1000) .
            "\n\n(Answer in " . self::language_of($question) . ".)";

        $messages = array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => "=== COURSE MATERIAL ===\n" . $material . "\n\n" . $request),
        );

        $answer = '';
        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            $reply = \mod_book\ai_service::chat_with_tools($messages, self::tools());
            $calls = $reply['tool_calls'] ?? array();

            if (empty($calls)) {
                $answer = trim((string)($reply['content'] ?? ''));
                self::$trace[] = 'answer after ' . ($turn + 1) . ' turn(s), ' . \core_text::strlen($answer) . ' chars';
                break;
            }

            // The material is the bulk of every request, and once read it has done
            // its job, so later turns go without it.
            $messages[1]['content'] = $request;

            $messages[] = array(
                'role' => 'assistant',
                'content' => (string)($reply['content'] ?? ''),
                'tool_calls' => $calls,
            );
            foreach ($calls as $call) {
                $arguments = json_decode((string)($call['function']['arguments'] ?? ''), true);
                $result = self::dispatch((string)($call['function']['name'] ?? ''),
                    is_array($arguments) ? $arguments : array(), $course, $actions);
                self::$trace[] = ($call['function']['name'] ?? '?') . ' ' .
                    \core_text::substr((string)($call['function']['arguments'] ?? ''), 0, 300) .
                    ' => ' . \core_text::substr($result, 0, 200);
                $messages[] = array(
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => \core_text::substr($result, 0, self::RESULT_CHARS),
                );
            }

            if (time() - $started > self::TIME_BUDGET) {
                break;
            }
        }

        if ($answer === '') {
            $answer = get_string(empty($actions) ? 'chatnoanswer' : 'chatplanonly', 'local_course_structure');
        }

        $plan = null;
        if (!empty($actions)) {
            $id = random_string(16);
            $SESSION->local_course_structure_plans = array($id => array(
                'courseid' => (int)$course->id,
                'created' => time(),
                'actions' => $actions,
            ));
            // The teacher sees what each change contains, not only what the model
            // says it does: a page of invented book titles reads well in a summary.
            $plan = array('id' => $id, 'changes' => array_map(function($action) {
                return array('summary' => $action['summary'], 'details' => self::details($action));
            }, $actions));
        }

        self::remember($course->id, $question, $answer, array_column($actions, 'summary'), 'proposed');

        return array('answer' => $answer, 'plan' => $plan);
    }

    /**
     * Carry out a plan the teacher has approved.
     *
     * The changes go through together or not at all: one that fails takes the
     * others back with it, so a course is never left half changed.
     *
     * @param \stdClass $course The course
     * @param string $planid The plan's id
     * @return array A line for each change made
     * @throws \Exception if the plan has lapsed or a change fails
     */
    public static function apply($course, $planid) {
        global $DB, $SESSION;

        $plan = $SESSION->local_course_structure_plans[$planid] ?? null;
        if (!$plan || $plan['courseid'] !== (int)$course->id
                || time() - $plan['created'] > self::PLAN_LIFETIME) {
            throw new \Exception(get_string('chatplanexpired', 'local_course_structure'));
        }
        // A plan is carried out once, even if the button is pressed twice.
        unset($SESSION->local_course_structure_plans[$planid]);

        $done = array();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($plan['actions'] as $action) {
                self::execute($course, $action);
                $done[] = $action['summary'];
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The teacher gets the reason without the query behind it.
            $message = get_string('chatapplyfailed', 'local_course_structure', (object)array(
                'change' => $plan['actions'][count($done)]['summary'],
                'error' => \core_text::substr(strtok($e->getMessage(), "\n"), 0, 300),
            ));
            try {
                $transaction->rollback($e);
            } catch (\Throwable $ignored) {
                // Rolling back rethrows; the message above already says why.
                unset($ignored);
            }
            // The course's cached module list was rebuilt along the way, with the
            // activities that have just been taken back still in it.
            rebuild_course_cache($course->id, true);
            self::remember($course->id, '', '', array_column($plan['actions'], 'summary'), 'failed');
            throw new \Exception($message);
        }

        rebuild_course_cache($course->id, true);
        self::remember($course->id, '', '', $done, 'applied');
        return $done;
    }

    /**
     * Drop a plan the teacher has turned down.
     *
     * @param \stdClass $course The course
     * @param string $planid The plan's id
     */
    public static function discard($course, $planid) {
        global $SESSION;
        $plan = $SESSION->local_course_structure_plans[$planid] ?? null;
        unset($SESSION->local_course_structure_plans[$planid]);
        if ($plan) {
            self::remember($course->id, '', '', array_column($plan['actions'], 'summary'), 'discarded');
        }
    }

    /**
     * What the model is told about working with the course.
     *
     * @return string
     */
    private static function rules() {
        return "YOU CAN ALSO CHANGE THE COURSE, using the tools you have.\n" .
            "- Reading happens at once. A change never does: each propose_ tool only " .
            "adds the change to a list that the teacher approves by pressing Apply. So " .
            "never say a change has been made; say what you have proposed.\n" .
            "- Each proposal needs a short summary of the change, in the teacher's " .
            "language, naming the activity or topic it touches.\n" .
            "- Topics and activities are identified by the ids under COURSE STRUCTURE.\n" .
            "- If a request could mean more than one activity or topic, ask which " .
            "rather than guessing. Do what was asked and nothing more: a setting asked " .
            "for one activity is not also given to the others named in the request.\n" .
            "- Do not invent facts, such as book titles or authors, to fill a page: " .
            "use the course material, or say you are not sure.\n" .
            "- Dates are given as 'YYYY-MM-DD HH:MM' in the teacher's time zone, never " .
            "as timestamps. Durations may be given as '30 minutes', '2 hours' and so on.\n" .
            "- For an activity's settings (dates, time limit, attempts, name, " .
            "description, grade...), read them with get_activity_settings, then use " .
            "propose_activity_settings with the field names it shows.\n" .
            "- For a new activity use propose_new_activity, for a topic's name or " .
            "description propose_section_update, and for quiz questions " .
            "propose_quiz_questions.\n" .
            "- To save something you wrote earlier in this conversation - a lesson plan, " .
            "notes, a handout - into the course, use propose_page_from_answer, which saves " .
            "it word for word. Asked to write something and save it in one go, write it in " .
            "settings.content of propose_new_activity (Markdown is fine). Material meant " .
            "for the teacher, such as a lesson plan, stays hidden from students unless the " .
            "teacher says otherwise.\n" .
            "- For anything else use Moodle's web service functions: " .
            "find_moodle_functions, describe_moodle_function, then read_moodle or " .
            "propose_moodle_change. Useful ones: core_courseformat_update_course " .
            "(action: cm_move, cm_hide, cm_show, cm_duplicate, cm_delete, section_add, " .
            "section_move_after, section_hide, section_show, section_delete; ids are " .
            "cmids or section ids, targetsectionid/targetcmid where needed), " .
            "enrol_manual_enrol_users, core_enrol_get_enrolled_users, " .
            "core_grades_update_grades, mod_quiz_get_quizzes_by_courses, " .
            "core_calendar_create_calendar_events.\n" .
            "- Never set a password. For a new user, leave password out and set " .
            "createpassword to 1: Moodle makes one and emails it to them.\n" .
            "- When you are done, answer in plain text, not JSON, in the language the " .
            "REQUEST is written in - even where the course material is in another.";
    }

    /**
     * The tools the model may call, in chat completion form.
     *
     * @return array
     */
    private static function tools() {
        $object = array('type' => 'object', 'additionalProperties' => true);
        $summary = array('type' => 'string',
            'description' => 'One line, in the teacher\'s language, saying what this change does.');

        $tool = function($name, $description, $properties, $required) {
            return array('type' => 'function', 'function' => array(
                'name' => $name,
                'description' => $description,
                'parameters' => array('type' => 'object', 'properties' => $properties,
                    'required' => $required),
            ));
        };

        return array(
            $tool('find_moodle_functions',
                'Search Moodle\'s web service functions by English keywords, e.g. "enrol user" or ' .
                '"forum discussion". Returns names, whether each reads or writes, and what it does.',
                array('query' => array('type' => 'string')), array('query')),
            $tool('describe_moodle_function',
                'The parameters a Moodle web service function takes.',
                array('name' => array('type' => 'string')), array('name')),
            $tool('read_moodle',
                'Run a Moodle web service function that only reads, and get its result now.',
                array('function' => array('type' => 'string'), 'arguments' => $object),
                array('function', 'arguments')),
            $tool('propose_moodle_change',
                'Propose running a Moodle web service function that changes something. It runs ' .
                'only if the teacher approves.',
                array('function' => array('type' => 'string'), 'arguments' => $object,
                    'summary' => $summary),
                array('function', 'arguments', 'summary')),
            $tool('get_activity_settings',
                'The current settings of an activity, under the field names its edit form uses.',
                array('cmid' => array('type' => 'integer')), array('cmid')),
            $tool('propose_activity_settings',
                'Propose changing settings of an activity, using field names from ' .
                'get_activity_settings. Text fields take plain text or HTML; dates take ' .
                '"YYYY-MM-DD HH:MM", or 0 for none.',
                array('cmid' => array('type' => 'integer'), 'settings' => $object,
                    'summary' => $summary),
                array('cmid', 'settings', 'summary')),
            $tool('propose_new_activity',
                'Propose adding an activity. type is the module name: page, url, label, quiz, ' .
                'assign, forum, folder, choice, feedback, glossary, lesson, h5pactivity... For a ' .
                'page give settings.content; for a url settings.externalurl; for a label ' .
                'settings.intro. For a quiz, questions may be given to put in it.',
                array(
                    'type' => array('type' => 'string'),
                    'section' => array('type' => 'integer', 'description' => 'Topic number'),
                    'name' => array('type' => 'string'),
                    'settings' => $object,
                    'questions' => self::question_schema(),
                    'summary' => $summary,
                ),
                array('type', 'section', 'name', 'summary')),
            $tool('propose_page_from_answer',
                'Propose saving one of your earlier answers in this conversation - a lesson plan, ' .
                'notes, a handout - as a page in the course, word for word, without retyping it. ' .
                'answer is the number shown under EARLIER IN THIS CONVERSATION: 1 is your latest ' .
                'answer, which is nearly always the one meant by "that" or "this"; if the same thing ' .
                'was asked for twice, the latest is the one wanted. The page is hidden from ' .
                'students unless visible is true.',
                array(
                    'answer' => array('type' => 'integer', 'description' => '1 for your latest answer'),
                    'section' => array('type' => 'integer', 'description' => 'Topic number'),
                    'name' => array('type' => 'string'),
                    'visible' => array('type' => 'boolean', 'description' => 'Show it to students'),
                    'summary' => $summary,
                ),
                array('section', 'name', 'summary')),
            $tool('propose_section_update',
                'Propose renaming a topic or changing its description.',
                array(
                    'section' => array('type' => 'integer', 'description' => 'Topic number'),
                    'name' => array('type' => 'string'),
                    'description' => array('type' => 'string', 'description' => 'HTML or plain text'),
                    'summary' => $summary,
                ),
                array('section', 'summary')),
            $tool('propose_quiz_questions',
                'Propose adding multiple-choice questions to a quiz.',
                array('cmid' => array('type' => 'integer'), 'questions' => self::question_schema(),
                    'summary' => $summary),
                array('cmid', 'questions', 'summary')),
        );
    }

    /**
     * The shape of the questions a quiz can be given.
     *
     * @return array JSON schema
     */
    private static function question_schema() {
        return array('type' => 'array', 'items' => array(
            'type' => 'object',
            'properties' => array(
                'question' => array('type' => 'string'),
                'options' => array('type' => 'array', 'items' => array('type' => 'string')),
                'correct' => array('type' => 'integer', 'description' => 'Index of the right option, from 0'),
            ),
            'required' => array('question', 'options', 'correct'),
        ));
    }

    /**
     * Carry out one tool call.
     *
     * @param string $name Tool
     * @param array $args Its arguments
     * @param \stdClass $course The course
     * @param array $actions Proposals so far, added to
     * @return string What the model is told
     */
    private static function dispatch($name, $args, $course, &$actions) {
        try {
            switch ($name) {
                case 'find_moodle_functions':
                    return self::find_functions((string)($args['query'] ?? ''));
                case 'describe_moodle_function':
                    return self::describe_function((string)($args['name'] ?? ''));
                case 'read_moodle':
                    return self::read_function((string)($args['function'] ?? ''),
                        (array)($args['arguments'] ?? array()));
                case 'get_activity_settings':
                    return self::activity_settings($course, (int)($args['cmid'] ?? 0));
            }

            $action = self::proposal($name, $args, $course);
            self::try_out($course, $action);
            $actions[] = $action;
            return 'Proposed (' . count($actions) . ' change' . (count($actions) > 1 ? 's' : '') .
                ' awaiting the teacher\'s approval): ' . $action['summary'];

        } catch (\Throwable $e) {
            return 'ERROR: ' . self::error_text($e);
        }
    }

    /**
     * Turn a propose_ call into a change waiting to be applied.
     *
     * @param string $name Tool
     * @param array $args Its arguments
     * @param \stdClass $course The course
     * @return array The change
     * @throws \Exception if the call is malformed
     */
    private static function proposal($name, $args, $course) {
        $summary = trim((string)($args['summary'] ?? ''));
        if ($summary === '') {
            throw new \Exception('Give a summary of the change.');
        }

        switch ($name) {
            case 'propose_moodle_change':
                $function = (string)($args['function'] ?? '');
                $info = external_api::external_function_info($function);
                if ($info->type === 'read') {
                    throw new \Exception($function . ' only reads; use read_moodle for it.');
                }
                $arguments = (array)($args['arguments'] ?? array());
                external_api::validate_parameters($info->parameters_desc, $arguments);
                self::check_function($info, $arguments, $course);
                return array('kind' => 'function', 'function' => $function,
                    'arguments' => $arguments, 'summary' => $summary);

            case 'propose_activity_settings':
                return array('kind' => 'update', 'cmid' => (int)($args['cmid'] ?? 0),
                    'settings' => (array)($args['settings'] ?? array()), 'summary' => $summary);

            case 'propose_new_activity':
                return array('kind' => 'create', 'type' => clean_param((string)($args['type'] ?? ''), PARAM_PLUGIN),
                    'section' => (int)($args['section'] ?? 0), 'name' => trim((string)($args['name'] ?? '')),
                    'settings' => (array)($args['settings'] ?? array()),
                    'questions' => self::clean_questions($args['questions'] ?? array()),
                    'summary' => $summary);

            case 'propose_page_from_answer':
                // The answer is taken from what the teacher was shown, not from the
                // model, which would otherwise rewrite it from a shortened memory.
                return array('kind' => 'create', 'type' => 'page',
                    'section' => (int)($args['section'] ?? 0), 'name' => trim((string)($args['name'] ?? '')),
                    'settings' => array(
                        'content' => self::earlier_answer($course->id, (int)($args['answer'] ?? 1)),
                        'visible' => empty($args['visible']) ? 0 : 1,
                    ),
                    'questions' => array(), 'summary' => $summary);

            case 'propose_section_update':
                return array('kind' => 'section', 'section' => (int)($args['section'] ?? 0),
                    'name' => isset($args['name']) ? trim((string)$args['name']) : null,
                    'description' => isset($args['description']) ? (string)$args['description'] : null,
                    'summary' => $summary);

            case 'propose_quiz_questions':
                $questions = self::clean_questions($args['questions'] ?? array());
                if (empty($questions)) {
                    throw new \Exception('Give at least one question with options and the index of the right one.');
                }
                return array('kind' => 'questions', 'cmid' => (int)($args['cmid'] ?? 0),
                    'questions' => $questions, 'summary' => $summary);
        }

        throw new \Exception('There is no tool called ' . $name . '.');
    }

    /**
     * Refuse a web service change the teacher could not make, before it is offered.
     *
     * These are not tried out, so without this a change the teacher has no right
     * to make was shown with an Apply button and failed only when pressed. The
     * function still checks for itself when it runs; this is so the model hears
     * no in time to tell the teacher.
     *
     * @param \stdClass $info The function, from external_function_info
     * @param array $arguments What it would be called with
     * @param \stdClass $course The course the teacher is in
     * @throws \Exception if the teacher could not make the change
     */
    private static function check_function($info, $arguments, $course) {
        // A password the model makes up is weak, and it would be shown in the
        // proposal for anyone looking at the screen to read.
        if (self::values_of($arguments, array('password')) !== array()) {
            throw new \Exception('Do not set passwords. Leave password out and set ' .
                'createpassword to 1, so Moodle makes one and emails it to the user.');
        }

        $courseids = array_unique(array_map('intval',
            self::values_of($arguments, array('courseid', 'courseids'))));
        if (empty($courseids)) {
            $courseids = array((int)$course->id);
        }

        // The list a function declares is of what it may need, any one of which
        // can be enough - calendar events take managing all entries or one's own.
        $capabilities = array_filter(array_map('trim', explode(',', (string)($info->capabilities ?? ''))));
        $system = \context_system::instance();

        foreach ($courseids as $courseid) {
            $other = get_course($courseid);
            if (!can_access_course($other)) {
                throw new \Exception('The teacher has no access to course ' . $courseid .
                    ', so cannot make this change there.');
            }
            $context = \context_course::instance($courseid);
            $allowed = empty($capabilities);
            foreach ($capabilities as $capability) {
                if (get_capability_info($capability) &&
                        (has_capability($capability, $context) || has_capability($capability, $system))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new \Exception('The teacher does not have permission for this (' .
                    implode(', ', $capabilities) . '), so it cannot be proposed. Tell them.');
            }
        }
    }

    /**
     * Every value found under the given keys, however deep.
     *
     * @param array $array Arguments
     * @param array $keys Keys to look for
     * @return array Scalar values found
     */
    private static function values_of($array, $keys) {
        $found = array();
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $inner = self::values_of($value, $keys);
                if (in_array($key, $keys, true)) {
                    // courseids: a list of scalars under the key itself.
                    $inner = array_merge($inner, array_filter($value, 'is_scalar'));
                }
                $found = array_merge($found, $inner);
            } else if (in_array($key, $keys, true) && $value !== '' && $value !== null) {
                $found[] = $value;
            }
        }
        return $found;
    }

    /**
     * Make a change and take it straight back, to find out whether it works.
     *
     * The model hears about a mistake while it can still put it right, rather than
     * the teacher hearing about it after pressing Apply.
     *
     * @param \stdClass $course The course
     * @param array $action The change
     * @throws \Throwable whatever the change would have failed with
     */
    private static function try_out($course, $action) {
        global $DB;

        // A web service function is checked against its parameters instead: some
        // send messages or mail, which a rollback does not call back.
        if ($action['kind'] === 'function') {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        $failure = null;
        try {
            self::execute($course, $action);
        } catch (\Throwable $e) {
            $failure = $e;
        }
        try {
            $transaction->rollback(new \Exception('Trial run'));
        } catch (\Exception $expected) {
            // Rolling back always rethrows what it is given.
            unset($expected);
        }
        rebuild_course_cache($course->id, true);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Make a change.
     *
     * @param \stdClass $course The course
     * @param array $action The change
     * @throws \Exception if it cannot be made
     */
    private static function execute($course, $action) {
        switch ($action['kind']) {
            case 'function':
                self::run_function($action['function'], $action['arguments']);
                return;
            case 'update':
                self::update_activity($course, $action['cmid'], $action['settings']);
                return;
            case 'create':
                self::create_activity($course, $action);
                return;
            case 'section':
                self::update_section($course, $action);
                return;
            case 'questions':
                $cm = get_coursemodule_from_id('quiz', $action['cmid'], $course->id, false, MUST_EXIST);
                self::add_questions($course, $cm, $action['questions']);
                return;
        }
        throw new \Exception('Unknown kind of change.');
    }

    /**
     * Search the web service functions.
     *
     * @param string $query Keywords
     * @return string The best matches, one a line
     */
    private static function find_functions($query) {
        $words = array_filter(preg_split('/[^a-z0-9]+/', \core_text::strtolower($query)),
            function($word) {
                return strlen($word) > 1;
            });
        if (empty($words)) {
            return 'ERROR: give some keywords.';
        }

        $scores = array();
        foreach (self::function_index() as $name => $entry) {
            $score = 0;
            foreach ($words as $word) {
                if (strpos($name, $word) !== false) {
                    $score += 3;
                } else if (strpos(\core_text::strtolower($entry['description']), $word) !== false) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $scores[$name] = $score;
            }
        }
        arsort($scores);

        $index = self::function_index();
        $lines = array();
        foreach (array_slice(array_keys($scores), 0, 12) as $name) {
            $lines[] = $name . ' [' . $index[$name]['type'] . '] ' . $index[$name]['description'];
        }
        return $lines ? implode("\n", $lines) : 'No function matches. Try other words.';
    }

    /**
     * Every web service function worth offering, with what it does.
     *
     * Reading the descriptions loads every function's class, which takes over a
     * second, so the index is kept in Moodle's cache.
     *
     * @return array Keyed by name: 'type' read or write, and a one-line 'description'
     */
    private static function function_index() {
        global $DB;
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, 'local_course_structure', 'functions');
        $count = $DB->count_records('external_functions');
        $cached = $cache->get('index');
        if (is_array($cached) && ($cached['count'] ?? 0) === $count) {
            return $index = $cached['functions'];
        }

        $index = array();
        foreach ($DB->get_fieldset_select('external_functions', 'name', '1 = 1') as $name) {
            try {
                $info = external_api::external_function_info($name);
            } catch (\Throwable $e) {
                continue;
            }
            if (!empty($info->deprecated)) {
                continue;
            }
            $description = preg_replace('/\s+/', ' ', strip_tags((string)$info->description));
            $index[$name] = array(
                'type' => ($info->type === 'read') ? 'read' : 'write',
                'description' => \core_text::substr($description, 0, 160),
            );
        }
        ksort($index);
        $cache->set('index', array('count' => $count, 'functions' => $index));
        return $index;
    }

    /**
     * The parameters a web service function takes.
     *
     * @param string $name Function
     * @return string Its parameters, one a line, indented by nesting
     */
    private static function describe_function($name) {
        $info = external_api::external_function_info($name);
        return $name . ' [' . (($info->type === 'read') ? 'read' : 'write') . ']: ' .
            preg_replace('/\s+/', ' ', strip_tags((string)$info->description)) . "\nParameters:\n" .
            self::describe_structure($info->parameters_desc, 1);
    }

    /**
     * Describe one level of a web service function's parameters.
     *
     * @param mixed $structure An external_description
     * @param int $depth Nesting depth
     * @return string
     */
    private static function describe_structure($structure, $depth) {
        $indent = str_repeat('  ', $depth);
        $text = '';
        if ($structure instanceof external_single_structure) {
            foreach ($structure->keys as $key => $value) {
                $required = ($value->required === VALUE_REQUIRED) ? 'required'
                    : (($value->required === VALUE_DEFAULT) ? 'default ' . json_encode($value->default) : 'optional');
                if ($value instanceof external_value) {
                    $text .= $indent . $key . ' (' . $value->type . ', ' . $required . '): ' .
                        preg_replace('/\s+/', ' ', (string)$value->desc) . "\n";
                } else {
                    $kind = ($value instanceof external_multiple_structure) ? 'list' : 'object';
                    $text .= $indent . $key . ' (' . $kind . ', ' . $required . '): ' .
                        preg_replace('/\s+/', ' ', (string)$value->desc) . "\n" .
                        self::describe_structure($value, $depth + 1);
                }
            }
        } else if ($structure instanceof external_multiple_structure) {
            $text .= self::describe_structure($structure->content, $depth);
        } else if ($structure instanceof external_value) {
            $text .= $indent . 'each: ' . $structure->type . "\n";
        }
        return $text;
    }

    /**
     * Run a web service function that only reads.
     *
     * @param string $name Function
     * @param array $arguments Its arguments
     * @return string Its result as JSON
     */
    private static function read_function($name, $arguments) {
        $info = external_api::external_function_info($name);
        if ($info->type !== 'read') {
            return 'ERROR: ' . $name . ' changes things; use propose_moodle_change for it.';
        }

        $json = json_encode(self::run_function($name, $arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (\core_text::strlen($json) > self::RESULT_CHARS)
            ? \core_text::substr($json, 0, self::RESULT_CHARS - 20) . ' ...(cut short)' : $json;
    }

    /**
     * Run a web service function as the current user.
     *
     * This is external_api::call_external_function() without its login check,
     * which refuses any script without a cookie - the cron and command line among
     * them - and asks for a session key the request has already been checked for.
     * The rest is the same: the function gets a page and course of its own, its
     * parameters are validated, a plugin may override it, and its result is
     * cleaned. A failure is thrown rather than returned, with its full message.
     *
     * @param string $name Function
     * @param array $arguments Its arguments
     * @return mixed Its result
     * @throws \Throwable whatever the function failed with
     */
    private static function run_function($name, $arguments) {
        global $PAGE, $COURSE, $SITE;

        $info = external_api::external_function_info($name);
        $page = $PAGE;
        $course = $COURSE;
        try {
            $PAGE = new \moodle_page();
            $COURSE = clone($SITE);

            $params = array_values(call_user_func(array($info->classname, 'validate_parameters'),
                $info->parameters_desc, $arguments));

            $result = false;
            foreach (get_plugins_with_function('override_webservice_execution') as $plugins) {
                foreach ($plugins as $callback) {
                    $result = $callback($info, $params);
                    if ($result !== false) {
                        break 2;
                    }
                }
            }
            if ($result === false) {
                $result = call_user_func_array(array($info->classname, $info->methodname), $params);
            }

            if ($info->returns_desc !== null) {
                $result = call_user_func(array($info->classname, 'clean_returnvalue'), $info->returns_desc, $result);
            }
            return $result;
        } finally {
            $PAGE = $page;
            $COURSE = $course;
        }
    }

    /**
     * An activity's settings, under the names its edit form uses.
     *
     * @param \stdClass $course The course
     * @param int $cmid Course module id
     * @return string One setting a line
     */
    private static function activity_settings($course, $cmid) {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);
        list($cm, $context, $module, $data, $cw) = get_moduleinfo_data($cm, $course);
        require_capability('moodle/course:manageactivities', $context);
        $values = array_merge(self::form_values($course, $cm, $cm->modname, $cw->section, $data), (array)$data);

        $lines = array($cm->modname . ' "' . format_string($cm->name) . '" (cmid ' . $cm->id . ')');
        foreach ($values as $key => $value) {
            if (preg_match(self::FORM_NOISE, $key)) {
                continue;
            }
            if (is_array($value) && array_key_exists('text', $value)) {
                $value = \core_text::substr(trim(strip_tags((string)$value['text'])), 0, 300);
            } else if (!is_scalar($value) && $value !== null) {
                continue;
            }
            if (is_numeric($value) && (int)$value > 100000000 && self::is_date_field($key)) {
                $value = userdate((int)$value, '%Y-%m-%d %H:%M');
            } else if (is_numeric($value) && self::is_date_field($key)) {
                $value = $value . ' (not set)';
            } else if (is_numeric($value) && preg_match('/limit|period|delay|duration/', $key)) {
                $value = $value . ' (seconds)';
            }
            $lines[] = $key . ': ' . (is_bool($value) ? (int)$value : $value);
        }
        return implode("\n", $lines);
    }

    /**
     * Change an activity's settings.
     *
     * The activity is loaded the way its edit page loads it, and saved through
     * the same function that page saves through, so the module's own update code
     * runs - calendar events, grade items and all - just as for an edit by hand.
     *
     * @param \stdClass $course The course
     * @param int $cmid Course module id
     * @param array $settings New values by form field name
     */
    private static function update_activity($course, $cmid, $settings) {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);
        // Only for the permission check; the full values come from below.
        can_update_moduleinfo($cm);
        list($cm, $context, $module, $data, $cw) = get_moduleinfo_data($cm, $course);

        // The form's values supply what the stored record holds in another shape,
        // such as a quiz's review options; where both have a value, the stored one
        // is the truth, as the form only offers a default.
        $values = self::fill_editors(self::form_values($course, $cm, $cm->modname, $cw->section, $data));
        $info = (object)array_merge($values, (array)$data);
        self::apply_settings($info, self::page_settings($cm->modname, $settings));

        $info->coursemodule = $cm->id;
        $info->modulename = $cm->modname;
        $info->instance = $cm->instance;

        ob_start();
        try {
            update_moduleinfo($cm, $info, $course, null);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Add an activity.
     *
     * @param \stdClass $course The course
     * @param array $action The change: type, section, name, settings, questions
     */
    private static function create_activity($course, $action) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $modname = $action['type'];
        if ($modname === '' || !file_exists($CFG->dirroot . '/mod/' . $modname . '/mod_form.php')) {
            throw new \Exception('There is no activity type called "' . $action['type'] . '".');
        }
        if ($action['name'] === '' && $modname !== 'label') {
            throw new \Exception('Give the activity a name.');
        }
        if (!get_fast_modinfo($course)->get_section_info($action['section'])) {
            throw new \Exception('The course has no topic ' . $action['section'] . '.');
        }

        // Moodle's own edit page checks the module's permission separately too.
        can_add_moduleinfo($course, $modname, $action['section']);
        require_capability('mod/' . $modname . ':addinstance', \context_course::instance($course->id));
        list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($course, $modname, $action['section']);

        $values = self::fill_editors(self::form_values($course, null, $modname, $action['section'], $data));
        $info = (object)array_merge($values, (array)$data);
        $info->name = $action['name'];
        $info->visible = 1;

        self::apply_settings($info, self::page_settings($modname, $action['settings']));
        // A new page takes its text from the editor only when saved through a
        // form, and otherwise from where the page table keeps it.
        if ($modname === 'page') {
            $info->content = $info->page['text'];
            $info->contentformat = $info->page['format'];
        }

        ob_start();
        try {
            $created = add_moduleinfo($info, $course);
        } finally {
            ob_end_clean();
        }

        if ($modname === 'quiz' && !empty($action['questions'])) {
            $cm = get_coursemodule_from_id('quiz', $created->coursemodule, $course->id, false, MUST_EXIST);
            self::add_questions($course, $cm, $action['questions']);
        }
    }

    /**
     * Rename a topic or change its description.
     *
     * @param \stdClass $course The course
     * @param array $action The change: section, name, description
     */
    private static function update_section($course, $action) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $section = get_fast_modinfo($course)->get_section_info($action['section']);
        if (!$section) {
            throw new \Exception('The course has no topic ' . $action['section'] . '.');
        }
        require_capability('moodle/course:update', \context_course::instance($course->id));

        $data = array();
        if ($action['name'] !== null) {
            $data['name'] = \core_text::substr($action['name'], 0, 255);
        }
        if ($action['description'] !== null) {
            $data['summary'] = self::as_html($action['description']);
            $data['summaryformat'] = FORMAT_HTML;
        }
        if (empty($data)) {
            throw new \Exception('Give a new name or description.');
        }
        course_update_section($course, $section, $data);
    }

    /**
     * Put multiple-choice questions into a quiz.
     *
     * @param \stdClass $course The course
     * @param \stdClass $cm The quiz's course module
     * @param array $questions Questions with a stem, options and correct index
     */
    private static function add_questions($course, $cm, $questions) {
        global $DB;

        $context = \context_course::instance($course->id);
        require_capability('moodle/question:add', $context);
        require_capability('mod/quiz:manage', \context_module::instance($cm->id));

        $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
        $category = quiz_builder::make_category($context, $quiz->name);
        $ids = quiz_builder::import_questions($questions, $category, $course, $context);
        if (count($ids) !== count($questions)) {
            throw new \Exception('Only ' . count($ids) . ' of ' . count($questions) . ' questions could be imported.');
        }
        quiz_builder::add_questions($quiz, $ids);
        quiz_builder::finalise($quiz);
    }

    /**
     * The values an activity's edit form holds, as it would submit them.
     *
     * @param \stdClass $course The course
     * @param \stdClass|null $cm The activity, or null for a new one
     * @param string $modname Module name
     * @param int $section Section number
     * @param \stdClass $data What get_moduleinfo_data or prepare_new_moduleinfo_data gave
     * @return array Field values by name
     */
    private static function form_values($course, $cm, $modname, $section, $data) {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/' . $modname . '/mod_form.php');

        // The form looks the activity up in the current course's module list.
        if ((int)$PAGE->course->id !== (int)$course->id) {
            $PAGE->set_course($course);
        }

        $class = 'mod_' . $modname . '_mod_form';
        $form = new $class($data, $section, $cm, $course);
        $form->set_data($data);

        // A form not yet submitted only says what it holds through its inner form.
        $inner = new \ReflectionProperty('moodleform', '_form');
        $inner->setAccessible(true);
        return $inner->getValue($form)->exportValues();
    }

    /**
     * Give every empty text editor among a form's values an empty text.
     *
     * An editor nobody has typed in reports its text and format as nothing at
     * all, which a module saving it then refuses.
     *
     * @param array $values Form values
     * @return array The same, with editors filled in
     */
    private static function fill_editors($values) {
        foreach ($values as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (array_key_exists('text', $value) || array_key_exists('format', $value)) {
                $values[$key]['text'] = $value['text'] ?? '';
                $values[$key]['format'] = $value['format'] ?? FORMAT_HTML;
                $values[$key]['itemid'] = $value['itemid'] ?? 0;
            } else {
                $values[$key] = self::fill_editors($value);
            }
        }
        return $values;
    }

    /**
     * Put new setting values onto an activity, in the shape each field expects.
     *
     * @param \stdClass $info The activity's values, changed in place
     * @param array $settings New values by field name
     * @throws \Exception for a date that cannot be read
     */
    private static function apply_settings($info, $settings) {
        foreach ($settings as $key => $value) {
            $key = (string)$key;
            if ($key === 'intro' || $key === 'description') {
                $key = 'introeditor';
            }
            $current = $info->$key ?? null;

            if (is_array($current) && array_key_exists('text', $current) && !is_array($value)) {
                // A text editor takes its text inside a structure.
                $current['text'] = self::as_html((string)$value);
                $current['format'] = FORMAT_HTML;
                $current['itemid'] = $current['itemid'] ?? 0;
                $info->$key = $current;
            } else if (is_numeric($value) && (int)$value > 100000000 && self::is_date_field($key)) {
                // A model works a timestamp out wrongly often enough - a day out
                // was seen - that it is made to write the date instead.
                throw new \Exception('Give ' . $key . ' as "YYYY-MM-DD HH:MM", not as a timestamp.');
            } else if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)\s*(s|secs?|seconds?|m|mins?|minutes?|' .
                    'h|hours?|d|days?|w|weeks?)$/i', trim($value), $match)) {
                // Durations are kept in seconds; "30 minutes" is easier to get right.
                $units = array('s' => 1, 'm' => MINSECS, 'h' => HOURSECS, 'd' => DAYSECS, 'w' => WEEKSECS);
                $info->$key = (int)round((float)$match[1] * $units[strtolower($match[2][0])]);
            } else if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                $date = new \DateTime($value, \core_date::get_user_timezone_object());
                $info->$key = $date->getTimestamp();
                // A date set is a date switched on; its form has a tick box for that.
                $enabled = $key . '_enabled';
                if (property_exists($info, $enabled)) {
                    $info->$enabled = 1;
                }
            } else if (is_bool($value)) {
                $info->$key = (int)$value;
            } else {
                $info->$key = $value;
            }
        }
    }

    /**
     * What a change contains, for the teacher to check before approving it.
     *
     * @param array $action The change
     * @return string A few lines
     */
    private static function details($action) {
        $text = function($value) {
            $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
            return \core_text::substr(trim(preg_replace('/\s+/u', ' ', html_to_text($value, 0, false))), 0, 400);
        };
        $settings = function($settings) use ($text) {
            $lines = array();
            foreach ($settings as $key => $value) {
                $lines[] = $key . ': ' . $text($value);
            }
            return $lines;
        };

        switch ($action['kind']) {
            case 'function':
                $lines = array($action['function'] . ' ' .
                    \core_text::substr(json_encode($action['arguments'], JSON_UNESCAPED_UNICODE), 0, 400));
                break;
            case 'update':
                $lines = array_merge(array('cmid ' . $action['cmid']), $settings($action['settings']));
                break;
            case 'create':
                $lines = array_merge(array($action['type'] . ' "' . $action['name'] . '", topic ' . $action['section']),
                    $settings($action['settings']), self::question_lines($action['questions']));
                break;
            case 'section':
                $lines = array('Topic ' . $action['section']);
                if ($action['name'] !== null) {
                    $lines[] = 'name: ' . $action['name'];
                }
                if ($action['description'] !== null) {
                    $lines[] = 'description: ' . $text($action['description']);
                }
                break;
            case 'questions':
                $lines = array_merge(array('cmid ' . $action['cmid']), self::question_lines($action['questions']));
                break;
            default:
                $lines = array();
        }
        return implode("\n", $lines);
    }

    /**
     * Questions as lines, the right option marked.
     *
     * @param array $questions Questions with a stem, options and correct index
     * @return array
     */
    private static function question_lines($questions) {
        $lines = array();
        foreach ($questions as $number => $question) {
            $options = array();
            foreach ($question['options'] as $index => $option) {
                $options[] = ($index === $question['correct'] ? '✓ ' : '') . $option;
            }
            $lines[] = ($number + 1) . '. ' . $question['question'] . ' [' . implode(' | ', $options) . ']';
        }
        return $lines;
    }

    /**
     * The language to answer a request in, judged from its script.
     *
     * The model is apt to answer in whatever language most of what it was given is
     * in, and to take Macedonian for Russian, so it is told plainly.
     *
     * @param string $question The request
     * @return string
     */
    private static function language_of($question) {
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $question);
        $latin = preg_match_all('/\p{Latin}/u', $question);
        return ($cyrillic > $latin)
            ? 'Macedonian (not Russian or Bulgarian), unless the request is clearly in another language'
            : 'the language of the request, which is written in Latin script (most likely English)';
    }

    /**
     * Whether a setting holds a moment in time.
     *
     * @param string $key Form field name
     * @return bool
     */
    private static function is_date_field($key) {
        return (bool)preg_match('/date|due|cutoff|deadline|timeopen|timeclose|timestart|timefinish|' .
            'timeavailable|timedue|expected/', $key);
    }

    /**
     * Settings for a page, with its text under the name its editor goes by.
     *
     * The page table and the model call it content; the form, which is what the
     * page saves from, calls it page.
     *
     * @param string $modname Module name
     * @param array $settings New values by field name
     * @return array The same, with a page's content renamed
     */
    private static function page_settings($modname, $settings) {
        if ($modname === 'page' && array_key_exists('content', $settings)) {
            $settings['page'] = $settings['content'];
            unset($settings['content']);
        }
        return $settings;
    }

    /**
     * Text as HTML, reading anything that is not already HTML as Markdown.
     *
     * Markdown is what the model writes when left to itself - a lesson plan comes
     * as tables and headings - and plain paragraphs come through it as paragraphs.
     * A table cell's <br> does not make a text HTML; a block of HTML does.
     *
     * @param string $text Markdown, plain text or HTML
     * @return string HTML
     */
    private static function as_html($text) {
        if (preg_match('/<(p|div|table|ul|ol|h[1-6]|blockquote|pre)[\s>]/i', $text)) {
            return $text;
        }
        // A page is shown without cleaning, so what the Markdown lets through is
        // cleaned here.
        return clean_text(markdown_to_html($text), FORMAT_HTML);
    }

    /**
     * Keep only questions that are complete.
     *
     * @param mixed $questions What the model gave
     * @return array Questions with a stem, at least two options and a correct index
     */
    private static function clean_questions($questions) {
        $clean = array();
        foreach (is_array($questions) ? $questions : array() as $question) {
            $options = array_values(array_filter(array_map('strval', (array)($question['options'] ?? array())),
                function($option) {
                    return trim($option) !== '';
                }));
            $correct = (int)($question['correct'] ?? -1);
            if (trim((string)($question['question'] ?? '')) !== '' && count($options) >= 2
                    && $correct >= 0 && $correct < count($options)) {
                $clean[] = array('question' => (string)$question['question'], 'options' => $options,
                    'correct' => $correct);
            }
        }
        return $clean;
    }

    /**
     * The course's topics and activities with the ids the tools take.
     *
     * @param \stdClass $course The course
     * @return string
     */
    private static function structure($course) {
        $modinfo = get_fast_modinfo($course);
        $text = 'Course id ' . $course->id . ', format ' . $course->format . "\n";
        foreach ($modinfo->get_section_info_all() as $number => $section) {
            $text .= 'Topic ' . $number . ' (section id ' . $section->id . '): ' .
                get_section_name($course, $section) . ($section->visible ? '' : ' [hidden]') . "\n";
            foreach ($modinfo->sections[$number] ?? array() as $cmid) {
                $cm = $modinfo->cms[$cmid];
                $text .= '  cmid ' . $cm->id . ' ' . $cm->modname . ': ' . $cm->get_formatted_name() .
                    ($cm->visible ? '' : ' [hidden]') . "\n";
            }
        }
        return $text;
    }

    /**
     * Record an exchange, so the next request can refer back to it.
     *
     * @param int $courseid Course
     * @param string $question What was asked, or '' for an outcome of a plan
     * @param string $answer What was answered
     * @param array $changes Summaries of the changes involved
     * @param string $status proposed, applied, discarded or failed
     */
    private static function remember($courseid, $question, $answer, $changes, $status) {
        global $SESSION;
        $history = $SESSION->local_course_structure_history[$courseid] ?? array();
        // The model is reminded of a shortened answer, but the whole of it is kept
        // so that it can be saved into the course exactly as the teacher read it.
        $history[] = array('question' => \core_text::substr($question, 0, 500),
            'answer' => \core_text::substr($answer, 0, 800), 'full' => $answer,
            'changes' => $changes, 'status' => $status);
        $SESSION->local_course_structure_history[$courseid] = array_slice($history, -self::HISTORY * 2);
    }

    /**
     * Recent exchanges, for the model.
     *
     * @param int $courseid Course
     * @return string
     */
    private static function history_text($courseid) {
        global $SESSION;
        $history = $SESSION->local_course_structure_history[$courseid] ?? array();
        if (empty($history)) {
            return '';
        }
        $text = "\n\n=== EARLIER IN THIS CONVERSATION ===\n";
        // Answers are numbered back from the latest, as propose_page_from_answer
        // refers to them.
        $number = count(array_filter($history, function($entry) {
            return $entry['question'] !== '';
        }));
        foreach ($history as $entry) {
            if ($entry['question'] !== '') {
                $text .= 'Teacher: ' . $entry['question'] . "\nYou (answer " . $number .
                    ($number === 1 ? ', your latest' : '') . '): ' . $entry['answer'] . "\n";
                $number--;
            }
            if (!empty($entry['changes'])) {
                $text .= 'Changes ' . $entry['status'] . ': ' . implode('; ', $entry['changes']) . "\n";
            }
        }
        return $text;
    }

    /**
     * One of the assistant's earlier answers, whole.
     *
     * @param int $courseid Course
     * @param int $number 1 for the latest answer, 2 for the one before, and so on
     * @return string The answer as it was given
     * @throws \Exception if there is no such answer
     */
    private static function earlier_answer($courseid, $number) {
        global $SESSION;
        $history = $SESSION->local_course_structure_history[$courseid] ?? array();
        $answers = array_reverse(array_values(array_filter($history, function($entry) {
            return $entry['question'] !== '';
        })));
        $answer = $answers[max(1, $number) - 1] ?? null;
        if (!$answer) {
            throw new \Exception('There is no answer ' . $number . ' earlier in this conversation.');
        }
        // Never the shortened copy: a page cut off part way would look complete.
        if (!isset($answer['full'])) {
            throw new \Exception('Answer ' . $number . ' was not kept whole; ask the teacher to ' .
                'request it again, then save that.');
        }
        return $answer['full'];
    }

    /**
     * What went wrong, in a sentence.
     *
     * @param \Throwable $e The failure
     * @return string
     */
    private static function error_text($e) {
        $text = $e->getMessage();
        if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
            $text .= ' (' . \core_text::substr(trim($e->debuginfo), 0, 300) . ')';
        }
        return $text;
    }
}
