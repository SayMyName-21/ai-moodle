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

    /**
     * @var array Videos found during the last request, each with the presentation's
     *            'cmid' and name ('deck') and the 'video', for the teacher to answer.
     */
    public static $videos = array();

    /** Web links found this turn, shown to the teacher as the videos are: 'cmid', 'deck' and 'link'. */
    public static $links = array();

    /** What a generator task becomes when it is carried out. */
    const TASK_JOBS = array(
        'build_presentations' => 'presentations',
        'join_presentations' => 'join',
        'revise_presentation' => 'revise',
        'convert_to_powerpoint' => 'powerpoint',
        'build_quiz' => 'quiz',
        'add_wikipedia_links' => 'reading',
    );

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
        self::$videos = array();
        self::$links = array();

        // The free tier counts the whole request against one allowance, which the
        // material was sized to fill on its own; what was said earlier would tip a
        // follow-up over it, so its room comes out of the material. So does that of
        // the generator's rules and tools, which came after the material was sized.
        require_once(__DIR__ . '/chat_images.php');
        $history = self::history_text($course->id) . self::attachment_text($course) .
            chat_images::text($course->id, true);
        $extra = \core_text::strlen(self::generator_rules() . json_encode(array_slice(self::tools(), -3)));
        $material = \core_text::substr($material, 0,
            max(2000, \core_text::strlen($material) - \core_text::strlen($history) - $extra));

        $system = $prompt . "\n\n" . self::rules() . "\n\n" . self::generator_rules() .
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

        self::remember($course->id, $question, $answer, array_merge(array_column($actions, 'summary'),
            array_map(function($card) {
                return 'Video shown to the teacher for "' . $card['deck'] . '": ' . $card['video']['title'];
            }, self::$videos), array_map(function($card) {
                return 'Link shown to the teacher for "' . $card['deck'] . '": ' . $card['link']['url'];
            }, self::$links)), 'proposed');

        return array('answer' => $answer, 'plan' => $plan, 'videos' => self::$videos, 'links' => self::$links);
    }

    /**
     * Carry out a plan the teacher has approved.
     *
     * The changes go through together or not at all: one that fails takes the
     * others back with it, so a course is never left half changed.
     *
     * Work for the generator's tabs takes too long to be done here. It is started
     * once the rest has gone through, each piece as a job in the background.
     *
     * @param \stdClass $course The course
     * @param string $planid The plan's id
     * @return array 'done', a line for each change made or started, and 'jobs',
     *               the ids of the jobs started
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

        $now = array_values(array_filter($plan['actions'], function($action) {
            return $action['kind'] !== 'task';
        }));
        $later = array_values(array_filter($plan['actions'], function($action) {
            return $action['kind'] === 'task';
        }));

        $done = array();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($now as $action) {
                self::execute($course, $action);
                $done[] = $action['summary'];
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The teacher gets the reason without the query behind it.
            $message = get_string('chatapplyfailed', 'local_course_structure', (object)array(
                'change' => $now[count($done)]['summary'],
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

        // Topics made from the chapters found end that reading, as on its own page.
        foreach ($now as $action) {
            if ($action['kind'] === 'topics') {
                reading::clear($course->id);
            }
        }

        // Started after the changes above are in, so a presentation built in the
        // background lands in a topic created in the same plan.
        $jobs = array();
        $failed = array();
        foreach ($later as $action) {
            try {
                $job = self::launch($course, $action);
                if ($job !== null) {
                    $jobs[] = $job;
                }
                $done[] = $action['summary'];
            } catch (\Throwable $e) {
                $failed[] = $action['summary'] . ': ' . \core_text::substr($e->getMessage(), 0, 300);
            }
        }

        self::remember($course->id, '', '', $done, 'applied');
        if (!empty($failed)) {
            self::remember($course->id, '', '', $failed, 'failed');
        }
        return array('done' => $done, 'jobs' => $jobs, 'failed' => $failed);
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
            "- For a new activity use propose_new_activity, and for a topic's name or " .
            "description propose_section_update.\n" .
            "- A quiz or exam on the course's material - any question types, any number of questions - is " .
            "always propose_generator_task build_quiz, which writes the questions from the slides and the book. " .
            "Do not write such questions yourself. propose_quiz_questions and the questions of " .
            "propose_new_activity are only for multiple choice questions the teacher dictates word for word.\n" .
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
            "- A picture under PICTURES ATTACHED goes into the course with propose_image. Its description " .
            "can also be used like any other material, for example to write quiz questions about a diagram " .
            "or to turn a photographed worksheet into a page.\n" .
            "- Never set a password. For a new user, leave password out and set " .
            "createpassword to 1: Moodle makes one and emails it to them.\n" .
            "- When you are done, answer in plain text, not JSON, in the language the " .
            "REQUEST is written in - even where the course material is in another.";
    }

    /**
     * What the model is told about the generator's tabs, which it can also use.
     *
     * @return string
     */
    private static function generator_rules() {
        return "YOU CAN ALSO DO EVERYTHING ON THE COURSE GENERATOR'S TABS (Course structure, Presentations, " .
            "Join, Revise, PowerPoint, Quizzes and exams, Videos and reading):\n" .
            "- Look up with generator_info: presentations (each with its cmid, and whether it has a PowerPoint, " .
            "a lab quiz, reading links and videos), book_sections (the parts of the book presentations are " .
            "written from, by key), book_status (the course book's reading and the chapters it found), slides " .
            "(one presentation's slides; give cmid), jobs (background work and how far it has got).\n" .
            "- Propose the work with propose_generator_task. Most of it takes minutes to an hour, so after " .
            "Apply it runs in the background; say so, and that the teacher can ask you how it is going. Tasks: " .
            "build_presentations (presentations: [{title, sections: keys from book_sections, topic: topic " .
            "number}], wikipedia_links, replace_existing), join_presentations (cmids: two or more; optional " .
            "sections, title, topic; hide_originals, default true), revise_presentation (cmid; request: the " .
            "change in the teacher's words), convert_to_powerpoint and add_wikipedia_links " .
            "(optional cmids, else all), build_quiz (cmids it covers, else all; quiz: kind practice - every " .
            "question, checked as answered - or exam - a pool, each attempt drawing shown of them; count, types " .
            "from multichoice, truefalse, match, ordering, shortanswer, numerical, essay, randomsamatch, ddwtos, " .
            "gapselect, multianswer, calculatedmulti, calculatedsimple; instructions; use_book, default true; " .
            "exam time_limit and attempts; optional title and topic. Ask what kind and which presentations if " .
            "the teacher has not said), read_book_again (guidance: what the chapter detection got " .
            "wrong), create_course_topics (optional topics: titles in order, else the chapters found; " .
            "include_subtopics), use_attached_book (include_subtopics).\n" .
            "- For a video to go with a presentation, call find_video with its cmid and what the teacher " .
            "wants. It searches YouTube and shows the teacher a video to accept or decline themselves; propose " .
            "nothing for it, and do not offer to add it: the Accept button under the video does that. Videos are " .
            "added as links unless the teacher asks for an embedded player; then pass embed true. To switch a " .
            "video already added between link and player, use propose_video_display with its id from " .
            "generator_info presentations. Asked for another, call find_video again with what they want instead.\n" .
            "- For a web page to go with a presentation, call find_link with its cmid: it searches the web and " .
            "shows the teacher the closest matching page to accept or decline, as with videos. If the teacher " .
            "gives a link of their own, pass it as url and it is opened, checked and shown the same way. Asked " .
            "for another, call find_link again with what they want instead. Propose nothing for it.";
    }

    /**
     * The tools the model may call, in chat completion form.
     *
     * The generator's three come last, which run() relies on to know their size.
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
                'settings.intro. Not for a quiz on the course material: that is build_quiz.',
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
                'Propose adding multiple choice questions the teacher dictated to an existing quiz. To have ' .
                'questions written from the material, use build_quiz instead.',
                array('cmid' => array('type' => 'integer'), 'questions' => self::question_schema(),
                    'summary' => $summary),
                array('cmid', 'questions', 'summary')),
            $tool('propose_image',
                'Propose putting a picture attached in this conversation into the course: as a new label ' .
                'in a topic (where new_label), at the end of an existing page or label (where ' .
                'page_or_label, with its cmid), or at the end of a topic\'s description (where ' .
                'topic_description).',
                array(
                    'image' => array('type' => 'integer', 'description' => '1 for the latest picture'),
                    'where' => array('type' => 'string',
                        'enum' => array('new_label', 'page_or_label', 'topic_description')),
                    'section' => array('type' => 'integer', 'description' => 'Topic number'),
                    'cmid' => array('type' => 'integer'),
                    'caption' => array('type' => 'string', 'description' => 'Shown under it, if wanted'),
                    'alt' => array('type' => 'string',
                        'description' => 'Short text for those who cannot see it, in the course\'s language'),
                    'visible' => array('type' => 'boolean', 'description' => 'For a new label; default true'),
                    'summary' => $summary,
                ),
                array('where', 'alt', 'summary')),
            $tool('propose_video_display',
                'Propose showing a video already under a presentation as an embedded player or as a link.',
                array(
                    'cmid' => array('type' => 'integer', 'description' => 'The presentation'),
                    'video' => array('type' => 'string', 'description' => 'YouTube id, from generator_info'),
                    'embed' => array('type' => 'boolean', 'description' => 'True for a player, false for a link'),
                    'summary' => $summary,
                ),
                array('cmid', 'video', 'embed', 'summary')),
            $tool('generator_info', 'Look up the course generator\'s presentations, book, jobs or slides.',
                array(
                    'what' => array('type' => 'string',
                        'enum' => array('presentations', 'book_sections', 'book_status', 'slides', 'jobs')),
                    'cmid' => array('type' => 'integer', 'description' => 'For slides'),
                ),
                array('what')),
            $tool('propose_generator_task', 'Propose work from the generator\'s tabs; see the rules for each task.',
                array(
                    'task' => array('type' => 'string', 'enum' => array_merge(array_keys(self::TASK_JOBS),
                        array('read_book_again', 'create_course_topics', 'use_attached_book'))),
                    'presentations' => array('type' => 'array', 'items' => array('type' => 'object',
                        'properties' => array(
                            'title' => array('type' => 'string'),
                            'sections' => array('type' => 'array', 'items' => array('type' => 'integer')),
                            'topic' => array('type' => 'integer'),
                        ))),
                    'cmids' => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'cmid' => array('type' => 'integer'),
                    'sections' => array('type' => 'array', 'items' => array('type' => 'integer')),
                    'title' => array('type' => 'string'),
                    'topic' => array('type' => 'integer'),
                    'request' => array('type' => 'string'),
                    'guidance' => array('type' => 'string'),
                    'topics' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'wikipedia_links' => array('type' => 'boolean'),
                    'replace_existing' => array('type' => 'boolean'),
                    'hide_originals' => array('type' => 'boolean'),
                    'include_subtopics' => array('type' => 'boolean'),
                    'quiz' => array('type' => 'object', 'description' => 'For build_quiz', 'properties' => array(
                        'kind' => array('type' => 'string', 'enum' => array('practice', 'exam')),
                        'count' => array('type' => 'integer', 'description' => 'Questions to write (exam: pool size)'),
                        'shown' => array('type' => 'integer', 'description' => 'Exam: questions per attempt, 0 for half'),
                        'types' => array('type' => 'array', 'items' => array('type' => 'string',
                            'enum' => quiz_builder::TYPES)),
                        'instructions' => array('type' => 'string'),
                        'use_book' => array('type' => 'boolean'),
                        'time_limit' => array('type' => 'integer', 'description' => 'Exam: minutes, 0 for none'),
                        'attempts' => array('type' => 'integer', 'description' => 'Exam: 0 for unlimited'),
                    )),
                    'summary' => $summary,
                ),
                array('task', 'summary')),
            $tool('find_video', 'Search YouTube for a video to go with a presentation, and show it to the teacher.',
                array(
                    'cmid' => array('type' => 'integer'),
                    'instructions' => array('type' => 'string', 'description' => 'What the teacher wants, if anything'),
                    'embed' => array('type' => 'boolean',
                        'description' => 'True only if the teacher asked for an embedded player rather than a link'),
                ),
                array('cmid')),
            $tool('find_link', 'Search the web for a page to link under a presentation, or check a link the ' .
                'teacher gave, and show it to the teacher to accept or decline.',
                array(
                    'cmid' => array('type' => 'integer'),
                    'instructions' => array('type' => 'string', 'description' => 'What the teacher wants, if anything'),
                    'url' => array('type' => 'string', 'description' => 'A link the teacher gave, to check instead of searching'),
                ),
                array('cmid')),
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
                case 'generator_info':
                    return self::generator_info($course, (string)($args['what'] ?? ''), (int)($args['cmid'] ?? 0));
                case 'find_video':
                    return self::find_video($course, (int)($args['cmid'] ?? 0), (string)($args['instructions'] ?? ''),
                        !empty($args['embed']));
                case 'find_link':
                    return self::find_link($course, (int)($args['cmid'] ?? 0), (string)($args['instructions'] ?? ''),
                        trim((string)($args['url'] ?? '')));
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

            case 'propose_generator_task':
                return self::generator_task($course, $args, $summary);

            case 'propose_video_display':
                $cmid = (int)($args['cmid'] ?? 0);
                $id = (string)($args['video'] ?? '');
                if (!in_array($id, array_column(videos::deck($course->id, $cmid)['accepted'], 'id'), true)) {
                    throw new \Exception('Video ' . $id . ' is not under presentation ' . $cmid .
                        '. See generator_info presentations.');
                }
                return array('kind' => 'videodisplay', 'cmid' => $cmid, 'video' => $id,
                    'embed' => !empty($args['embed']), 'summary' => $summary);

            case 'propose_image':
                // Kept by file rather than by number, which moves on when another
                // picture is attached before the teacher presses Apply.
                $picture = chat_images::get($course, (int)($args['image'] ?? 1));
                $where = (string)($args['where'] ?? '');
                if (!in_array($where, array('new_label', 'page_or_label', 'topic_description'), true)) {
                    throw new \Exception('where must be new_label, page_or_label or topic_description.');
                }
                $alt = trim((string)($args['alt'] ?? ''));
                return array('kind' => 'image', 'fileid' => (int)$picture['file']->get_id(),
                    'filename' => $picture['file']->get_filename(), 'where' => $where,
                    'section' => (int)($args['section'] ?? 0), 'cmid' => (int)($args['cmid'] ?? 0),
                    'caption' => trim((string)($args['caption'] ?? '')),
                    'alt' => $alt !== '' ? \core_text::substr($alt, 0, 200) : $picture['file']->get_filename(),
                    'visible' => !isset($args['visible']) || !empty($args['visible']),
                    'summary' => $summary);
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
        // send messages or mail, which a rollback does not call back. Generator
        // work was checked as it was proposed, and is far too slow to try out.
        if ($action['kind'] === 'function' || $action['kind'] === 'task' || $action['kind'] === 'videodisplay') {
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
            case 'topics':
                self::generator();
                require_capability('moodle/course:update', \context_course::instance($course->id));
                local_course_structure_apply($course->id, $action['chapters'], $action['subtopics']);
                return;
            case 'image':
                self::add_image($course, $action);
                return;
            case 'videodisplay':
                require_capability('moodle/course:update', \context_course::instance($course->id));
                videos::set_display($course, $action['cmid'], $action['video'], $action['embed']);
                return;
        }
        throw new \Exception('Unknown kind of change.');
    }

    /**
     * Load what the generator's work is built from.
     */
    private static function generator() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');
    }

    /**
     * Answer generator_info.
     *
     * @param \stdClass $course The course
     * @param string $what presentations, book_sections, book_status, slides or jobs
     * @param int $cmid The presentation, for slides
     * @return string
     */
    private static function generator_info($course, $what, $cmid) {
        self::generator();
        $context = \context_course::instance($course->id);
        require_capability('moodle/course:update', $context);
        $decks = local_course_structure_decks($course->id);

        switch ($what) {
            case 'presentations':
                if (empty($decks)) {
                    return 'No presentations have been built yet.';
                }
                $modinfo = get_fast_modinfo($course);
                $lines = array();
                foreach ($decks as $deck) {
                    $videos = videos::deck($course->id, $deck->cmid);
                    $lines[] = 'cmid ' . $deck->cmid . ': "' . $deck->name . '" in topic ' . $deck->section .
                        ' (' . $deck->chapter . ')' . ($modinfo->get_cm($deck->cmid)->visible ? '' : ' [hidden]') .
                        '; PowerPoint: ' . ($deck->haspptx ? 'yes' : 'no') .
                        '; quiz: ' . (local_course_structure_deck_has_quiz($course->id, $deck) ? 'yes' : 'no') .
                        '; reading links: ' . (self::deck_has_reading($deck->cmid) ? 'yes' : 'no') .
                        '; videos: ' . (empty($videos['accepted']) ? 'none' : implode(', ', array_map(function($video) {
                            return $video['id'] . ' "' . $video['title'] . '" as ' .
                                (videos::embedded($video) ? 'player' : 'link');
                        }, $videos['accepted']))) . ($videos['suggestion'] ? ' (one awaiting an answer)' : '') .
                        '; web links: ' . (function($links) {
                            return (empty($links['accepted']) ? 'none' : implode(', ', array_column($links['accepted'], 'url'))) .
                                ($links['suggestion'] ? ' (one awaiting an answer)' : '');
                        })(links::deck($course->id, $deck->cmid));
                }
                foreach (local_course_structure_quizzes($course->id) as $cmid => $quiz) {
                    $lines[] = 'Quiz cmid ' . $cmid . ': "' . $modinfo->get_cm($cmid)->name . '", ' . $quiz['kind'] .
                        ', from cmids ' . implode(', ', $quiz['decks']);
                }
                return implode("\n", $lines);

            case 'book_sections':
                list(, , $sections) = local_course_structure_open_book($context);
                $lines = array();
                $chapter = null;
                foreach ($sections as $key => $section) {
                    if ($section['chapter'] !== $chapter) {
                        $chapter = $section['chapter'];
                        $lines[] = 'Chapter: ' . $chapter;
                    }
                    $lines[] = '  key ' . $key . ': ' . $section['title'] . ' (' . count($section['lessons']) . ' lessons)';
                }
                return $lines ? implode("\n", $lines) : get_string('nosectionsfound', 'local_course_structure');

            case 'book_status':
                $book = self::book_file($context);
                $reading = reading::get($course->id);
                $lines = array('Stored book: ' . ($book ? $book->get_filename() : 'none'));
                $attached = self::attachment($course);
                if ($attached) {
                    $lines[] = 'Attached in this conversation, not yet used: ' . $attached->get_filename();
                }
                if ($reading) {
                    $lines[] = 'Reading: ' . $reading['status'] .
                        (!empty($reading['guidance']) ? ' (guidance: ' . $reading['guidance'] . ')' : '');
                    if (in_array($reading['status'], array('queued', 'reading'), true)) {
                        $progress = \mod_book\ai_service::reading_progress($reading['contenthash']);
                        $lines[] = 'Pages read: ' . $progress['read'] . ' of ' . ($progress['total'] ?: '?');
                    }
                    if ($reading['status'] === 'failed') {
                        $lines[] = 'Error: ' . ($reading['error'] ?? '');
                    }
                    if ($reading['status'] === 'ready') {
                        $lines[] = 'Chapters found, awaiting create_course_topics:';
                        foreach ($reading['proposal'] as $n => $chapter) {
                            $lines[] = '  ' . ($n + 1) . '. ' . $chapter['title'];
                        }
                    }
                } else if ($book) {
                    $ready = \mod_book\ai_service::reading_progress($book->get_contenthash())['done'];
                    $lines[] = $ready ? 'Read through; presentations can be built from it.'
                        : 'Not read through yet.';
                }
                return implode("\n", $lines);

            case 'slides':
                if (!isset($decks[$cmid])) {
                    return 'ERROR: there is no presentation with cmid ' . $cmid . '.';
                }
                $text = '"' . $decks[$cmid]->name . '"' . "\n";
                foreach (local_course_structure_deck_slides($cmid) as $number => $slide) {
                    $text .= ($number + 1) . '. ' . $slide['heading'] . "\n";
                    foreach ($slide['bullets'] as $bullet) {
                        $text .= '   - ' . $bullet . "\n";
                    }
                }
                return $text;

            case 'jobs':
                $jobs = array_slice(job::all($course->id), 0, 8);
                if (empty($jobs)) {
                    return 'No background work has been started from here.';
                }
                return implode("\n", array_map(function($job) {
                    return userdate($job['queued'], '%Y-%m-%d %H:%M') . ' ' . job::describe($job);
                }, $jobs));
        }
        return 'ERROR: ask for presentations, book_sections, book_status, slides or jobs.';
    }

    /**
     * Whether a presentation lists further reading.
     *
     * @param int $cmid The presentation
     * @return bool
     */
    private static function deck_has_reading($cmid) {
        global $DB;
        $intro = $DB->get_field_sql("SELECT r.intro FROM {resource} r
            JOIN {course_modules} cm ON cm.instance = r.id WHERE cm.id = :cmid", array('cmid' => $cmid));
        return local_course_structure_has_reading((string)$intro);
    }

    /**
     * Turn a propose_generator_task call into work waiting for the teacher.
     *
     * Everything that can be checked is checked here, so the model hears about a
     * mistake at once rather than the teacher hearing about it from a job that
     * failed in the background. The details shown to the teacher say what will
     * actually be done: the book's parts by name, the presentations by title.
     *
     * @param \stdClass $course The course
     * @param array $args The call's arguments
     * @param string $summary The model's summary
     * @return array The change
     * @throws \Exception if the task cannot be done as asked
     */
    private static function generator_task($course, $args, $summary) {
        self::generator();
        $courseid = (int)$course->id;
        $context = \context_course::instance($courseid);
        require_capability('moodle/course:update', $context);

        $task = (string)($args['task'] ?? '');
        $decks = local_course_structure_decks($courseid);
        $topics = local_course_structure_course_topics($course);

        $pick = function($ids) use ($decks) {
            $ids = array_values(array_unique(array_map('intval', (array)$ids)));
            foreach ($ids as $id) {
                if (!isset($decks[$id])) {
                    throw new \Exception('There is no presentation with cmid ' . $id .
                        '. See generator_info presentations.');
                }
            }
            return $ids;
        };
        $names = function($ids) use ($decks) {
            return implode(', ', array_map(function($id) use ($decks) {
                return '"' . $decks[$id]->name . '"';
            }, $ids));
        };
        $keys = function($given, $sections) {
            $given = array_values(array_unique(array_map('intval', (array)$given)));
            foreach ($given as $key) {
                if (!isset($sections[$key])) {
                    throw new \Exception('There is no book section ' . $key . '. Use keys from generator_info book_sections.');
                }
            }
            sort($given);
            return $given;
        };
        $topicname = function($topic) use ($topics) {
            if (!isset($topics[$topic])) {
                throw new \Exception('The course has no topic ' . $topic . '.');
            }
            return 'topic ' . $topic . ' (' . $topics[$topic] . ')';
        };
        $all = empty($args['cmids']);
        $chosen = $all ? array_keys($decks) : $pick($args['cmids']);

        $lines = array();
        $params = array();

        switch ($task) {
            case 'build_presentations':
                list(, $outline, $sections) = local_course_structure_open_book($context);
                $groups = array();
                foreach (array_values((array)($args['presentations'] ?? array())) as $n => $given) {
                    $given = (array)$given;
                    $picked = $keys($given['sections'] ?? array(), $sections);
                    if (empty($picked)) {
                        throw new \Exception('Presentation ' . ($n + 1) . ' has no sections. Use keys from ' .
                            'generator_info book_sections.');
                    }
                    $title = trim((string)($given['title'] ?? ''));
                    if ($title === '') {
                        $title = (count($picked) === 1) ? $sections[$picked[0]]['title']
                            : get_string('lecturenumber', 'local_course_structure', $n + 1);
                    }
                    $topic = isset($given['topic']) ? (int)$given['topic'] : null;
                    $groups[] = array('title' => $title, 'sections' => $picked, 'topic' => $topic);
                    $lines[] = ($n + 1) . '. "' . $title . '"' . ($topic !== null ? ', in ' . $topicname($topic) : '') .
                        ', from: ' . implode('; ', array_map(function($key) use ($sections) {
                            return $sections[$key]['title'];
                        }, $picked));
                }
                if (empty($groups)) {
                    throw new \Exception('Give the presentations to build, each with its sections.');
                }

                $requests = 0;
                foreach (\mod_book\ai_service::plan_from_selection($outline, $groups) as $presentation) {
                    $requests += count($presentation['chunks']);
                }
                $lines[] = get_string('chattaskrequests', 'local_course_structure', (object)array(
                    'requests' => $requests,
                    'minutes' => (int)ceil($requests * \mod_book\ai_service::REQUEST_SPACING / 60)));

                $replace = !empty($args['replace_existing']);
                // Added after what is there, unless that is being cleared: positions
                // already built are skipped, so new presentations at them would not be.
                $first = 0;
                if (!$replace) {
                    foreach ($decks as $deck) {
                        if ($deck->index < 1000) {
                            $first = max($first, $deck->index + 1);
                        }
                    }
                } else if (!empty($decks)) {
                    $lines[] = get_string('chattaskreplace', 'local_course_structure', count($decks));
                }
                if (!empty($args['wikipedia_links'])) {
                    $lines[] = get_string('chattaskwikipedia', 'local_course_structure');
                }
                $params = array('groups' => $groups, 'sources' => !empty($args['wikipedia_links']),
                    'replace' => $replace, 'first' => $first);
                break;

            case 'join_presentations':
                $chosen = $all ? array() : $chosen;
                if (count($chosen) < 2) {
                    throw new \Exception(get_string('joinneedtwo', 'local_course_structure'));
                }
                list(, , $sections) = local_course_structure_open_book($context);
                if (!empty($args['sections'])) {
                    $picked = $keys($args['sections'], $sections);
                } else {
                    list($covered, , $untraced) = local_course_structure_trace_decks($chosen, $decks, $sections);
                    if (!empty($untraced)) {
                        throw new \Exception('Could not tell which parts of the book ' . implode(', ', $untraced) .
                            ' came from. Ask the teacher which, then give sections (keys from generator_info book_sections).');
                    }
                    $picked = array_keys($covered);
                    sort($picked);
                }
                $title = trim((string)($args['title'] ?? ''));
                if ($title === '') {
                    $title = implode(' + ', array_map(function($id) use ($decks) {
                        return $decks[$id]->name;
                    }, $chosen));
                }
                $topic = isset($args['topic']) ? (int)$args['topic'] : (int)$decks[$chosen[0]]->section;
                $hide = array_key_exists('hide_originals', $args) ? !empty($args['hide_originals']) : true;
                $lines[] = get_string('chattaskjoin', 'local_course_structure', $names($chosen));
                $lines[] = '"' . $title . '", in ' . $topicname($topic);
                $lines[] = get_string('chattaskfrom', 'local_course_structure', implode('; ',
                    array_map(function($key) use ($sections) {
                        return $sections[$key]['title'];
                    }, $picked)));
                $lines[] = get_string($hide ? 'chattaskhide' : 'chattaskkeep', 'local_course_structure');
                $params = array('cmids' => $chosen, 'sections' => $picked, 'title' => $title,
                    'topic' => $topic, 'hide' => $hide);
                break;

            case 'revise_presentation':
                $cmid = $pick(array((int)($args['cmid'] ?? 0)))[0];
                $request = trim((string)($args['request'] ?? ''));
                if ($request === '') {
                    throw new \Exception('Give the change to make as request, in the teacher\'s words.');
                }
                $lines[] = '"' . $decks[$cmid]->name . '": ' . $request;
                if ($decks[$cmid]->haspptx || local_course_structure_deck_has_quiz($courseid, $decks[$cmid])) {
                    $lines[] = get_string('revisestale', 'local_course_structure');
                }
                $params = array('cmid' => $cmid, 'request' => $request);
                break;

            case 'convert_to_powerpoint':
                $chosen = array_values(array_filter($chosen, function($id) use ($decks) {
                    return !$decks[$id]->haspptx;
                }));
                if (empty($chosen)) {
                    throw new \Exception(empty($decks) ? get_string('nodecksyet', 'local_course_structure')
                        : 'Every presentation asked for already has a PowerPoint version.');
                }
                $lines[] = $names($chosen);
                $params = array('cmids' => $chosen);
                break;

            case 'build_quiz':
                if (empty($chosen)) {
                    throw new \Exception(get_string('nodecksyet', 'local_course_structure'));
                }
                $quiz = (array)($args['quiz'] ?? array());
                $kind = (($quiz['kind'] ?? '') === 'exam') ? 'exam' : 'practice';
                $types = array_values(array_intersect(quiz_builder::TYPES, (array)($quiz['types'] ?? array())));
                if (empty($types)) {
                    $types = array('multichoice', 'truefalse', 'match', 'shortanswer');
                }
                $count = max(1, min(LOCAL_COURSE_STRUCTURE_QUIZ_MAX, (int)($quiz['count'] ?? 10)));
                $topic = isset($args['topic']) ? (int)$args['topic'] : -1;
                if ($topic >= 0) {
                    $topicname($topic);
                }
                $params = array('kind' => $kind, 'cmids' => $chosen, 'count' => $count,
                    'shown' => (int)($quiz['shown'] ?? 0), 'types' => $types,
                    'instructions' => trim((string)($quiz['instructions'] ?? '')),
                    'name' => trim((string)($args['title'] ?? '')), 'topic' => $topic,
                    'usebook' => !array_key_exists('use_book', $quiz) || !empty($quiz['use_book']),
                    'timelimit' => (int)($quiz['time_limit'] ?? 0), 'attempts' => (int)($quiz['attempts'] ?? 1));
                $typenames = quiz_builder::type_names();
                $lines[] = get_string('quizkind' . $kind, 'local_course_structure') . ($params['name'] !== '' ?
                    ' "' . $params['name'] . '"' : '') . ($topic >= 0 ? ', in ' . $topicname($topic) : '');
                $lines[] = get_string('quizcovers', 'local_course_structure', $names($chosen));
                $lines[] = $count . ' questions' . ($kind === 'exam' ? ', ' . ($params['shown'] ?: 'half') .
                    ' per attempt, ' . ($params['timelimit'] ? $params['timelimit'] . ' minutes' : 'no time limit') .
                    ', ' . ($params['attempts'] ?: 'unlimited') . ' attempt(s)' : '') . ': ' .
                    implode(', ', array_map(function($type) use ($typenames) {
                        return $typenames[$type];
                    }, $types));
                if ($params['instructions'] !== '') {
                    $lines[] = 'Instructions: ' . $params['instructions'];
                }
                $lines[] = $params['usebook'] ? 'From the slides and the book pages behind them.' : 'From the slides only.';
                break;


            case 'add_wikipedia_links':
                if (empty($chosen)) {
                    throw new \Exception(get_string('nodecksyet', 'local_course_structure'));
                }
                $lines[] = $names($chosen);
                $lines[] = get_string('chattaskreading', 'local_course_structure');
                $params = array('cmids' => $chosen);
                break;

            case 'read_book_again':
                $book = self::book_file($context);
                if (!$book) {
                    throw new \Exception(get_string('nobookstored', 'local_course_structure'));
                }
                $guidance = trim((string)($args['guidance'] ?? ''));
                if ($guidance === '') {
                    throw new \Exception('Give guidance: what the chapter detection got wrong, in the teacher\'s words.');
                }
                $lines[] = $book->get_filename() . ': ' . $guidance;
                $params = array('guidance' => $guidance);
                break;

            case 'create_course_topics':
                $reading = reading::get($courseid);
                $proposal = (($reading['status'] ?? '') === 'ready') ? $reading['proposal'] : array();
                $summaries = array();
                foreach ($proposal as $chapter) {
                    $summaries[trim($chapter['title'])] = $chapter['summary'] ?? '';
                }
                $titles = empty($args['topics']) ? array_column($proposal, 'title') : (array)$args['topics'];
                $chapters = array();
                foreach ($titles as $title) {
                    $title = trim(clean_param((string)$title, PARAM_TEXT));
                    if ($title !== '') {
                        $chapters[] = array('title' => $title, 'summary' => $summaries[$title] ?? '');
                    }
                }
                if (empty($chapters)) {
                    throw new \Exception('Give the topics, or read the book first: no chapters are waiting to ' .
                        'be made into topics (see generator_info book_status).');
                }
                $subtopics = array_key_exists('include_subtopics', $args) ? !empty($args['include_subtopics'])
                    : (bool)($reading['includesubtopics'] ?? true);
                foreach ($chapters as $n => $chapter) {
                    $lines[] = 'Topic ' . ($n + 1) . ': ' . $chapter['title'];
                }
                $lines[] = get_string('chattasktopics', 'local_course_structure');
                return array('kind' => 'topics', 'chapters' => $chapters, 'subtopics' => $subtopics,
                    'lines' => $lines, 'summary' => $summary);

            case 'use_attached_book':
                $file = self::attachment($course);
                if (!$file) {
                    throw new \Exception('No file is attached. Ask the teacher to attach the book with the ' .
                        'paperclip button under the chat, then ask again.');
                }
                $stored = self::book_file($context);
                $lines[] = $file->get_filename() . ' (' . display_size($file->get_filesize()) . ')';
                if ($stored) {
                    $lines[] = get_string('chattaskreplacebook', 'local_course_structure', $stored->get_filename());
                }
                $lines[] = get_string('step2', 'local_course_structure');
                $params = array('subtopics' => array_key_exists('include_subtopics', $args)
                    ? !empty($args['include_subtopics']) : true);
                break;

            default:
                throw new \Exception('There is no generator task called "' . $task . '".');
        }

        return array('kind' => 'task', 'task' => $task, 'params' => $params, 'lines' => $lines,
            'summary' => $summary);
    }

    /**
     * Start approved generator work.
     *
     * @param \stdClass $course The course
     * @param array $action The change
     * @return string|null The job's id, or null for work that reports elsewhere
     */
    private static function launch($course, $action) {
        self::generator();
        $context = \context_course::instance($course->id);
        require_capability('moodle/course:update', $context);

        switch ($action['task']) {
            case 'read_book_again':
                $book = self::book_file($context);
                if (!$book) {
                    throw new \Exception(get_string('nobookstored', 'local_course_structure'));
                }
                $previous = reading::get($course->id);
                reading::start($course->id, $book, $action['params']['guidance'],
                    $previous['includesubtopics'] ?? true);
                return null;

            case 'use_attached_book':
                $file = self::attachment($course);
                if (!$file) {
                    throw new \Exception('The attached file is no longer here. Attach it again.');
                }
                $fs = get_file_storage();
                $fs->delete_area_files($context->id, 'local_course_structure', 'book', 0);
                $book = $fs->create_file_from_storedfile(array(
                    'contextid' => $context->id,
                    'component' => 'local_course_structure',
                    'filearea' => 'book',
                    'itemid' => 0,
                ), $file);
                $file->delete();
                reading::start($course->id, $book, '', $action['params']['subtopics']);
                return null;
        }
        return job::start($course, self::TASK_JOBS[$action['task']], $action['params'], $action['summary']);
    }

    /**
     * Find a video for a presentation and hold it for the teacher.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $instructions What the teacher wants
     * @return string What the model is told
     */
    private static function find_video($course, $cmid, $instructions, $embed = false) {
        self::generator();
        require_capability('moodle/course:update', \context_course::instance($course->id));
        $decks = local_course_structure_decks($course->id);
        if (!isset($decks[$cmid])) {
            return 'ERROR: there is no presentation with cmid ' . $cmid . '. See generator_info presentations.';
        }
        $video = videos::suggest($course, $cmid, $instructions);

        // Asked twice for the same presentation, the teacher answers the latest.
        self::$videos = array_values(array_filter(self::$videos, function($card) use ($cmid) {
            return $card['cmid'] !== $cmid;
        }));
        self::$videos[] = array('cmid' => $cmid, 'deck' => $decks[$cmid]->name, 'video' => $video, 'embed' => $embed);

        return 'Shown to the teacher, who accepts or declines it on a card under your answer: "' .
            $video['title'] . '" by ' . $video['channel'] .
            ($video['language'] !== '' ? ', in ' . $video['language'] : '') . '. ' . $video['reason'] .
            ' Propose nothing for it; just tell the teacher what you found.';
    }

    /**
     * Find a web page for a presentation, or check one the teacher gave, and hold it for them.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $instructions What the teacher wants
     * @param string $url A link the teacher gave, or ''
     * @return string What the model is told
     */
    private static function find_link($course, $cmid, $instructions, $url) {
        self::generator();
        require_capability('moodle/course:update', \context_course::instance($course->id));
        $decks = local_course_structure_decks($course->id);
        if (!isset($decks[$cmid])) {
            return 'ERROR: there is no presentation with cmid ' . $cmid . '. See generator_info presentations.';
        }
        $link = ($url !== '') ? links::suggest_url($course, $cmid, $url) : links::suggest($course, $cmid, $instructions);
        self::$links = array_values(array_filter(self::$links, function($card) use ($cmid) {
            return $card['cmid'] !== $cmid;
        }));
        self::$links[] = array('cmid' => $cmid, 'deck' => $decks[$cmid]->name, 'link' => $link);
        return 'Shown to the teacher, who accepts or declines it on a card under your answer: "' . $link['title'] .
            '" (' . $link['url'] . ')' . ($link['language'] !== '' ? ', in ' . $link['language'] : '') . '. ' .
            $link['reason'] . ' Propose nothing for it; just tell the teacher what you found.';
    }

    /**
     * The book stored for the course.
     *
     * @param \context_course $context Course context
     * @return \stored_file|null
     */
    private static function book_file($context) {
        $files = get_file_storage()->get_area_files($context->id, 'local_course_structure', 'book',
            0, 'itemid', false);
        return reset($files) ?: null;
    }

    /**
     * Keep a file the teacher attached in the assistant, until they say what it is for.
     *
     * One file per teacher and course; a new one replaces the last.
     *
     * @param \stdClass $course The course
     * @param string $path Where the upload is
     * @param string $filename Its name
     * @return \stored_file
     */
    public static function attach($course, $path, $filename) {
        global $USER;
        $context = \context_course::instance($course->id);
        require_capability('moodle/course:update', $context);

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_course_structure', 'attachment', $USER->id);
        return $fs->create_file_from_pathname(array(
            'contextid' => $context->id,
            'component' => 'local_course_structure',
            'filearea' => 'attachment',
            'itemid' => $USER->id,
            'filepath' => '/',
            'filename' => clean_param($filename, PARAM_FILE) ?: 'book.pdf',
        ), $path);
    }

    /**
     * The file the teacher attached, if any.
     *
     * @param \stdClass $course The course
     * @return \stored_file|null
     */
    private static function attachment($course) {
        global $USER;
        $files = get_file_storage()->get_area_files(\context_course::instance($course->id)->id,
            'local_course_structure', 'attachment', $USER->id, 'itemid', false);
        return reset($files) ?: null;
    }

    /**
     * What the model is told about an attached file.
     *
     * @param \stdClass $course The course
     * @return string
     */
    private static function attachment_text($course) {
        $file = self::attachment($course);
        return $file ? "\n\n=== ATTACHED BY THE TEACHER ===\n" . $file->get_filename() . ' (' .
            display_size($file->get_filesize()) . '). It can be made the course book with use_attached_book.' : '';
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
        return $created;
    }

    /**
     * Put a picture attached in the conversation into the course.
     *
     * The picture is copied into the files of whatever shows it, as a picture
     * added through the editor would be, so it stays when the conversation's
     * copy is gone and is shown to whoever may see that activity or topic.
     *
     * @param \stdClass $course The course
     * @param array $action The change: fileid, where, section, cmid, caption, alt, visible
     * @throws \Exception if the place named cannot take it
     */
    private static function add_image($course, $action) {
        global $DB;
        $file = chat_images::file($course, $action['fileid']);
        $fs = get_file_storage();

        $html = function($name) use ($action) {
            return '<div class="lcs-image"><p><img src="@@PLUGINFILE@@/' . rawurlencode($name) . '" alt="' .
                s($action['alt']) . '" style="max-width:100%;height:auto"></p>' .
                ($action['caption'] !== '' ? '<p><em>' . s($action['caption']) . '</em></p>' : '') . '</div>';
        };
        $copy = function($contextid, $component, $filearea, $itemid) use ($fs, $file) {
            $name = $fs->get_unused_filename($contextid, $component, $filearea, $itemid, '/', $file->get_filename());
            $fs->create_file_from_storedfile(array('contextid' => $contextid, 'component' => $component,
                'filearea' => $filearea, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $name), $file);
            return $name;
        };

        switch ($action['where']) {
            case 'new_label':
                $created = self::create_activity($course, array('type' => 'label', 'section' => $action['section'],
                    'name' => $action['caption'] !== '' ? $action['caption'] : $action['alt'],
                    'settings' => array('intro' => $html($file->get_filename()),
                        'visible' => $action['visible'] ? 1 : 0),
                    'questions' => array()));
                $copy(\context_module::instance($created->coursemodule)->id, 'mod_label', 'intro', 0);
                break;

            case 'page_or_label':
                $cm = get_fast_modinfo($course)->get_cm($action['cmid']);
                $places = array('page' => 'content', 'label' => 'intro');
                if (!isset($places[$cm->modname])) {
                    throw new \Exception('A picture can be added to a page or a label, not to a ' . $cm->modname .
                        '. Propose a new label beside it instead.');
                }
                $context = \context_module::instance($cm->id);
                require_capability('moodle/course:manageactivities', $context);
                $field = $places[$cm->modname];
                $name = $copy($context->id, 'mod_' . $cm->modname, $field, 0);
                $record = $DB->get_record($cm->modname, array('id' => $cm->instance), '*', MUST_EXIST);
                $update = array('id' => $record->id, $field => $record->$field . $html($name),
                    'timemodified' => time());
                if ($cm->modname === 'page') {
                    // Browsers keep a page's files by its revision.
                    $update['revision'] = $record->revision + 1;
                }
                $DB->update_record($cm->modname, (object)$update);
                break;

            case 'topic_description':
                $context = \context_course::instance($course->id);
                require_capability('moodle/course:update', $context);
                $section = $DB->get_record('course_sections',
                    array('course' => $course->id, 'section' => $action['section']));
                if (!$section) {
                    throw new \Exception('The course has no topic ' . $action['section'] . '.');
                }
                $name = $copy($context->id, 'course', 'section', $section->id);
                $DB->update_record('course_sections', (object)array('id' => $section->id,
                    'summary' => (string)$section->summary . $html($name), 'summaryformat' => FORMAT_HTML,
                    'timemodified' => time()));
                break;
        }
        rebuild_course_cache($course->id, true);
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
            case 'task':
            case 'topics':
                $lines = $action['lines'];
                break;
            case 'videodisplay':
                $lines = array('Video ' . $action['video'] . ' under cmid ' . $action['cmid'] . ' -> ' .
                    ($action['embed'] ? 'embedded player' : 'link'));
                break;
            case 'image':
                $places = array('new_label' => 'new label in topic ' . $action['section'] .
                        ($action['visible'] ? '' : ', hidden'),
                    'page_or_label' => 'end of cmid ' . $action['cmid'],
                    'topic_description' => 'description of topic ' . $action['section']);
                $lines = array($action['filename'] . ' -> ' . $places[$action['where']], 'alt: ' . $action['alt']);
                if ($action['caption'] !== '') {
                    $lines[] = 'caption: ' . $action['caption'];
                }
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
