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
 * Builds quizzes from generated questions.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

class quiz_builder {

    /**
     * Create a question category in the course's question bank.
     *
     * @param \context_course $context Course context
     * @param string $name Category name
     * @return int Category id
     */
    public static function make_category($context, $name) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $top = question_get_top_category($context->id, true);

        $category = new \stdClass();
        $category->parent = $top->id;
        $category->contextid = $context->id;
        $category->name = \core_text::substr($name, 0, 254);
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->sortorder = 999;
        $category->idnumber = null;

        return $DB->insert_record('question_categories', $category);
    }

    /**
     * Escape the characters GIFT treats as markup.
     *
     * @param string $text Raw text
     * @return string Escaped text
     */
    private static function gift_escape($text) {
        $text = str_replace(array('\\', '~', '=', '#', '{', '}', ':'),
                            array('\\\\', '\~', '\=', '\#', '\{', '\}', '\:'), $text);
        return preg_replace('/\s+/u', ' ', trim($text));
    }

    /**
     * Put questions into a category.
     *
     * Questions go in through the GIFT importer rather than as hand written rows,
     * because a question spans question, question_bank_entries and question_versions
     * and the importer is what keeps those consistent.
     *
     * @param array $questions Questions with a stem, options and correct index
     * @param int $categoryid Category to import into
     * @param \stdClass $course Course
     * @param \context_course $context Course context
     * @return array Ids of the questions created
     */
    public static function import_questions($questions, $categoryid, $course, $context) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');

        $gift = '';
        foreach ($questions as $index => $question) {
            $gift .= '::Q' . ($index + 1) . '::' . self::gift_escape($question['question']) . " {\n";
            foreach ($question['options'] as $position => $option) {
                $gift .= ($position === (int)$question['correct'] ? '=' : '~') .
                    self::gift_escape($option) . "\n";
            }
            $gift .= "}\n\n";
        }

        $path = make_temp_directory('local_course_structure') . '/gift-' . uniqid() . '.txt';
        file_put_contents($path, $gift);

        $before = $DB->get_fieldset_select('question_bank_entries', 'id',
            'questioncategoryid = ?', array($categoryid));

        $format = new \qformat_gift();
        $format->setCategory($DB->get_record('question_categories', array('id' => $categoryid)));
        $format->setContexts(array($context));
        $format->setCourse($course);
        $format->setFilename($path);
        $format->setRealfilename('questions.txt');
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);

        // The importer reports progress straight to output.
        ob_start();
        $ok = $format->importprocess();
        ob_end_clean();

        unlink($path);

        if (!$ok) {
            throw new \Exception('The question importer rejected the generated questions.');
        }

        $after = $DB->get_fieldset_select('question_bank_entries', 'id',
            'questioncategoryid = ?', array($categoryid));
        $new = array_diff($after, $before);

        if (empty($new)) {
            return array();
        }

        list($sql, $params) = $DB->get_in_or_equal($new);
        return $DB->get_fieldset_select('question_versions', 'questionid',
            'questionbankentryid ' . $sql, $params);
    }

    /**
     * Create a quiz activity.
     *
     * @param \stdClass $course Course
     * @param int $sectionnum Section to place it in
     * @param string $name Quiz name
     * @param string $idnumber Marker so the plugin can recognise its own quizzes
     * @param int $questionsshown How many questions an attempt will show
     * @return \stdClass The quiz record
     */
    public static function make_quiz($course, $sectionnum, $name, $idnumber, $questionsshown) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $module = $DB->get_record('modules', array('name' => 'quiz'), '*', MUST_EXIST);

        $data = new \stdClass();
        $data->course = $course->id;
        $data->modulename = 'quiz';
        $data->module = $module->id;
        $data->section = $sectionnum;
        $data->name = $name;
        $data->intro = '';
        $data->introformat = FORMAT_HTML;
        $data->visible = 1;
        $data->cmidnumber = $idnumber;

        $data->timeopen = 0;
        $data->timeclose = 0;
        $data->timelimit = 0;
        $data->overduehandling = 'autosubmit';
        $data->graceperiod = 0;
        $data->preferredbehaviour = 'deferredfeedback';
        $data->canredoquestions = 0;
        $data->attempts = 0;
        $data->attemptonlast = 0;
        $data->grademethod = QUIZ_GRADEHIGHEST;
        $data->decimalpoints = 2;
        $data->questiondecimalpoints = -1;
        $data->questionsperpage = 1;
        $data->navmethod = 'free';
        // Randomises the order of the options within each question.
        $data->shuffleanswers = 1;
        $data->sumgrades = 0;
        $data->grade = $questionsshown;
        $data->quizpassword = '';
        $data->subnet = '';
        $data->browsersecurity = '-';
        $data->delay1 = 0;
        $data->delay2 = 0;
        $data->showuserpicture = 0;
        $data->showblocks = 0;
        $data->completion = 0;
        $data->completionview = 0;
        $data->completionexpected = 0;
        $data->groupmode = 0;
        $data->groupingid = 0;
        $data->availabilityconditionsjson = '';

        foreach (array('during', 'immediately', 'open', 'closed') as $when) {
            foreach (array('attempt', 'correctness', 'marks', 'specificfeedback',
                           'generalfeedback', 'rightanswer', 'overallfeedback') as $what) {
                $data->{$what . $when} = 1;
            }
        }
        // A mark is only meaningful once the attempt is over.
        $data->marksduring = 0;
        $data->correctnessduring = 0;

        $data->feedbacktext = array(array('text' => '', 'format' => FORMAT_HTML));
        $data->feedbackboundaries = array();

        ob_start();
        $info = add_moduleinfo($data, $course);
        ob_end_clean();

        return $DB->get_record('quiz', array('id' => $info->instance), '*', MUST_EXIST);
    }

    /**
     * Put specific questions into a quiz.
     *
     * @param \stdClass $quiz Quiz
     * @param array $questionids Questions to add
     */
    public static function add_questions($quiz, $questionids) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        foreach ($questionids as $questionid) {
            quiz_add_quiz_question($questionid, $quiz, 0, 1);
        }
    }

    /**
     * Have a quiz draw questions at random from a category.
     *
     * @param \stdClass $quiz Quiz
     * @param int $categoryid Category to draw from
     * @param int $number How many to draw per attempt
     */
    public static function add_random($quiz, $categoryid, $number) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        quiz_add_random_questions($quiz, 0, $categoryid, $number, false);
    }

    /**
     * Shuffle the order questions appear in.
     *
     * @param \stdClass $quiz Quiz
     */
    public static function shuffle_questions($quiz) {
        global $DB;
        $DB->set_field('quiz_sections', 'shufflequestions', 1, array('quizid' => $quiz->id));
    }

    /**
     * Set the quiz total to match the marks its questions carry.
     *
     * @param \stdClass $quiz Quiz
     */
    public static function finalise($quiz) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sumgrades = $DB->get_field_sql(
            'SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            array($quiz->id));

        $DB->set_field('quiz', 'sumgrades', $sumgrades, array('id' => $quiz->id));
        quiz_update_sumgrades($DB->get_record('quiz', array('id' => $quiz->id), '*', MUST_EXIST));
    }
}
