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
 * The course assistant.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Answers questions about a course, within what the asker is allowed to see.
 *
 * What a student may be told is decided here, by building their context out of
 * material they can already open for themselves and nothing else. The prompt is
 * not what keeps quiz questions and grades away from them: those are never read
 * from the database on a student's behalf, so no amount of coaxing can draw them
 * out. A prompt can be argued with; an empty context cannot.
 */
class chat {

    /**
     * Characters of course material to put before the model.
     *
     * The free tier allows 8000 tokens a minute covering the question and the
     * answer together, and Cyrillic runs to about one token per two characters,
     * so the material has to leave room for a reply worth reading.
     */
    const CONTEXT_CHARS = 10000;

    /** Tokens allowed for an answer. */
    const ANSWER_TOKENS = 2000;

    /** Characters of the book to add when the presentations fall short. */
    const BOOK_CHARS = 6000;

    /** Pages of the book to offer when it is consulted. */
    const BOOK_PAGES = 4;

    /** How much of a question's wording the presentations must cover to satisfy it. */
    const COVERAGE = 0.5;

    /**
     * Whether this user is a teacher of this course rather than a student.
     *
     * The same capability the rest of the plugin treats as "may run this", so a
     * person who can generate the material can also ask about all of it.
     *
     * @param \context_course $context Course context
     * @return bool
     */
    public static function is_teacher($context) {
        return has_capability('moodle/course:update', $context);
    }

    /**
     * Answer a question about a course.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @param string $question What was asked
     * @return array The reply, and what it was drawn from
     */
    public static function ask($course, $context, $question) {
        $teacher = self::is_teacher($context);

        $material = $teacher
            ? self::teacher_material($course, $context, $question)
            : self::student_material($course, $context, $question);

        $used = array('decks');
        $book = '';

        // The book is a last resort: it is slow to read and large to send, and the
        // presentations were written from it, so they usually answer first.
        if (!self::covered($question, $material)) {
            $book = self::book_excerpt($course, $context, $question);
        }

        if ($book !== '') {
            $used[] = 'book';
            // Room is made for the excerpt rather than it being appended and then
            // trimmed straight back off, which would leave it consulted for nothing.
            $room = self::CONTEXT_CHARS - \core_text::strlen($book) - 40;
            $material = \core_text::substr($material, 0, max(1500, $room)) .
                "\n\n=== FROM THE TEXTBOOK ===\n" . $book;
        } else {
            $material = \core_text::substr($material, 0, self::CONTEXT_CHARS);
        }

        $prompt = ($teacher ? self::teacher_prompt() : self::student_prompt()) .
            "\n\n=== COURSE: " . $course->fullname . " ===\n" . $material .
            "\n\n=== QUESTION ===\n" . \core_text::substr($question, 0, 1000);

        $reply = \mod_book\ai_service::ask_model($prompt, self::ANSWER_TOKENS);

        $answer = trim((string)($reply['answer'] ?? ''));
        if ($answer === '') {
            throw new \Exception(get_string('chatnoanswer', 'local_course_structure'));
        }

        return array('answer' => $answer, 'used' => $used, 'teacher' => $teacher);
    }

    /**
     * How a student's assistant is told to behave.
     *
     * This is manners, not security. Everything it is forbidden to say is absent
     * from what it was given, so the rules below cannot be talked around.
     *
     * @return string
     */
    private static function student_prompt() {
        return "You are a study assistant for a student in this course.\n\n" .
            "Help them understand the subject. Explain in your own words, give " .
            "examples, and point them at the lecture or topic that covers what they " .
            "asked. Answer in the language the student writes in.\n\n" .
            "Only use the course material given below. If it does not cover the " .
            "question, say so and suggest who to ask, rather than inventing an " .
            "answer.\n\n" .
            "You do not have marks, grades, quiz questions or quiz answers, and you " .
            "must not guess at them. If you are asked for any of those, say that you " .
            "cannot help with that and that their teacher can.\n\n" .
            "Return JSON: {\"answer\":\"...\"}";
    }

    /**
     * How a teacher's assistant is told to behave.
     *
     * @return string
     */
    private static function teacher_prompt() {
        return "You are an assistant to a teacher of this course.\n\n" .
            "You may discuss anything in the material below, including quiz " .
            "questions, their correct answers, hidden activities and marks. The " .
            "person asking already has every one of these in front of them in the " .
            "course, so answer plainly. Answer in the language the teacher writes in.\n\n" .
            "For matters of fact, keep to the material: where it does not say, say " .
            "that it does not say.\n\n" .
            "A teacher will also ask you to judge rather than to recall - which " .
            "questions are weak, what a lecture is missing, what to teach next. " .
            "Answer those. Form a view from the material, give your reasons, and make " .
            "clear that it is your judgement and not something the course states. " .
            "Declining to judge is not useful to them.\n\n" .
            "Return JSON: {\"answer\":\"...\"}";
    }

    /**
     * Everything a student is allowed to be told about.
     *
     * Moodle is asked what this particular user can see, so a hidden activity, a
     * section they have not reached, or an activity closed to them never reaches
     * the model at all.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @param string $question What was asked, so the right lectures are chosen
     * @return string
     */
    private static function student_material($course, $context, $question = '') {
        global $USER;

        $modinfo = get_fast_modinfo($course, $USER->id);
        $text = "=== TOPICS AND ACTIVITIES ===\n";

        foreach ($modinfo->get_section_info_all() as $number => $section) {
            if (!$section->uservisible) {
                continue;
            }

            $name = trim((string)$section->name);
            $text .= "\nTopic $number" . ($name !== '' ? ": $name" : '') . "\n";

            foreach ($modinfo->sections[$number] ?? array() as $cmid) {
                $cm = $modinfo->cms[$cmid];
                // uservisible is Moodle's own answer to "may this person see it",
                // covering hidden activities, access restrictions and groups.
                if (!$cm->uservisible || $cm->modname === 'quiz') {
                    continue;
                }
                $text .= '  - ' . $cm->get_formatted_name() . "\n";
            }
        }

        $text .= "\n=== LECTURE CONTENT ===\n" . self::deck_text($course, $question);

        return $text;
    }

    /**
     * Everything a teacher may be told about, which is everything.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @param string $question What was asked, so the right lectures are chosen
     * @return string
     */
    private static function teacher_material($course, $context, $question = '') {
        global $DB;

        $modinfo = get_fast_modinfo($course);
        $text = "=== TOPICS AND ACTIVITIES (including hidden) ===\n";

        foreach ($modinfo->get_section_info_all() as $number => $section) {
            $name = trim((string)$section->name);
            $text .= "\nTopic $number" . ($name !== '' ? ": $name" : '') .
                     ($section->visible ? '' : ' [hidden]') . "\n";

            foreach ($modinfo->sections[$number] ?? array() as $cmid) {
                $cm = $modinfo->cms[$cmid];
                $text .= '  - ' . $cm->get_formatted_name() .
                         ' (' . $cm->modname . ')' .
                         ($cm->visible ? '' : ' [hidden]') . "\n";
            }
        }

        $text .= "\n=== QUIZ QUESTIONS AND ANSWERS ===\n" . self::question_bank($context);
        $text .= "\n=== MARKS ===\n" . self::grades($course, $context);
        $text .= "\n=== LECTURE CONTENT ===\n" . self::deck_text($course, $question);

        return $text;
    }

    /**
     * The course's questions with their correct answers. Teachers only.
     *
     * @param \context_course $context Course context
     * @return string
     */
    private static function question_bank($context) {
        global $DB;

        // Question categories hang off the course context and its activities.
        $contextids = $context->get_child_contexts();
        $ids = array($context->id);
        foreach ($contextids as $child) {
            $ids[] = $child->id;
        }

        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        $questions = $DB->get_records_sql(
            "SELECT q.id, q.name, q.questiontext, q.qtype
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid $insql
           ORDER BY q.id", $params, 0, 60);

        if (empty($questions)) {
            return "(no questions in this course)\n";
        }

        // Asked for "a few questions", the model takes whichever it meets first, so
        // a fixed order would hand back the same ones every time. Shuffling here is
        // what makes a request for a random few actually random.
        shuffle($questions);

        $text = '';
        foreach ($questions as $question) {
            $text .= '- ' . trim(html_to_text($question->questiontext, 0, false)) . "\n";

            $answers = $DB->get_records('question_answers',
                array('question' => $question->id), 'id', 'answer, fraction');
            foreach ($answers as $answer) {
                $right = ((float)$answer->fraction > 0) ? ' [CORRECT]' : '';
                $text .= '    * ' . trim(html_to_text($answer->answer, 0, false)) .
                         $right . "\n";
            }
        }

        return $text;
    }

    /**
     * What the course's students have scored. Teachers only.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @return string
     */
    private static function grades($course, $context) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $items = \grade_item::fetch_all(array('courseid' => $course->id));
        if (empty($items)) {
            return "(nothing is graded in this course)\n";
        }

        $text = '';
        foreach ($items as $item) {
            if ($item->itemtype === 'course') {
                continue;
            }

            $grades = \grade_grade::fetch_all(array('itemid' => $item->id));
            $scores = array();
            foreach (($grades ?: array()) as $grade) {
                if ($grade->finalgrade !== null) {
                    $scores[] = (float)$grade->finalgrade;
                }
            }

            $text .= '- ' . $item->get_name() . ': ';
            if (empty($scores)) {
                $text .= "nobody has been graded yet\n";
            } else {
                $text .= count($scores) . ' graded, average ' .
                    round(array_sum($scores) / count($scores), 1) .
                    ' out of ' . round((float)$item->grademax, 1) . "\n";
            }
        }

        return $text;
    }

    /**
     * The wording of the lecture presentations in this course.
     *
     * These are read from the decks the plugin built, which any student on the
     * course can open for themselves, so they are safe to draw on.
     *
     * A course runs to far more lecture text than can be put before the model at
     * once, so the lectures that speak to the question go first and the rest fill
     * whatever room is left. Sending them in course order instead would mean only
     * the opening topics were ever answerable.
     *
     * @param \stdClass $course The course
     * @param string $question What was asked
     * @return string
     */
    private static function deck_text($course, $question = '') {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');

        $decks = $DB->get_records_sql(
            "SELECT cm.id AS cmid, r.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
               JOIN {resource} r ON r.id = cm.instance
              WHERE cm.course = :course AND " . $DB->sql_like('cm.idnumber', ':marker') . "
           ORDER BY cm.id",
            array('course' => $course->id, 'marker' => 'csgen-%'));

        if (empty($decks)) {
            return "(no lecture presentations have been built yet)\n";
        }

        $modinfo = get_fast_modinfo($course, $USER->id);
        $fs = get_file_storage();
        $workdir = make_temp_directory('local_course_structure/chat');
        $words = self::keywords($question);
        $blocks = array();

        foreach ($decks as $deck) {
            // A deck the asker cannot open is not theirs to be told about either.
            if (!isset($modinfo->cms[$deck->cmid]) || !$modinfo->cms[$deck->cmid]->uservisible) {
                continue;
            }

            $files = $fs->get_area_files(\context_module::instance($deck->cmid)->id,
                'mod_resource', 'content', 0, 'itemid', false);
            $file = reset($files);
            if (!$file) {
                continue;
            }

            $path = $workdir . '/chat-' . uniqid() . '.pdf';
            $file->copy_content_to($path);
            $slides = slide_deck::parse($path);
            unlink($path);

            $block = "\n## " . $deck->name . "\n";
            foreach ($slides as $slide) {
                $block .= $slide['heading'] . "\n";
                foreach ($slide['bullets'] as $bullet) {
                    $block .= '  - ' . $bullet . "\n";
                }
            }

            $haystack = \core_text::strtolower($deck->name . ' ' . $block);
            $score = 0;
            foreach ($words as $word) {
                $score += substr_count($haystack, $word);
            }

            $blocks[] = array('score' => $score, 'text' => $block);
        }

        if (empty($blocks)) {
            return "(no lecture presentations are open to you)\n";
        }

        usort($blocks, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $text = '';
        foreach ($blocks as $block) {
            if (\core_text::strlen($text) >= self::CONTEXT_CHARS) {
                break;
            }
            $text .= $block['text'];
        }

        return $text;
    }

    /**
     * Whether the material already speaks to the question.
     *
     * A rough test on words rather than meaning, which is all that is needed: it
     * decides only whether the book is worth opening, and opening it needlessly
     * costs time and allowance while opening it when it was needed costs nothing.
     *
     * @param string $question What was asked
     * @param string $material What is already to hand
     * @return bool
     */
    private static function covered($question, $material) {
        $words = self::keywords($question);
        if (empty($words)) {
            return true;
        }

        $haystack = \core_text::strtolower($material);
        $found = 0;
        foreach ($words as $word) {
            if (\core_text::strpos($haystack, $word) !== false) {
                $found++;
            }
        }

        return ($found / count($words)) >= self::COVERAGE;
    }

    /**
     * The pages of the book that speak to a question.
     *
     * @param \stdClass $course The course
     * @param \context_course $context Its context
     * @param string $question What was asked
     * @return string The most relevant pages, or an empty string
     */
    private static function book_excerpt($course, $context, $question) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_course_structure', 'book',
            0, 'itemid', false);
        $book = reset($files);
        if (!$book) {
            return '';
        }

        // Not while it is still being read: the assistant answers without the book
        // rather than make the asker wait for the whole reading.
        if (!\mod_book\ai_service::reading_progress($book->get_contenthash())['done']) {
            return '';
        }

        $workdir = make_temp_directory('local_course_structure/chat');
        $path = $workdir . '/book-' . $book->get_contenthash() . '.pdf';
        if (!file_exists($path)) {
            $book->copy_content_to($path);
        }

        // The same careful reading the rest of the plugin works from, so the
        // assistant quotes the book exactly as the lectures were written from it.
        $pages = \mod_book\ai_service::book_pages($path);

        $words = self::keywords($question);
        if (empty($words)) {
            return '';
        }

        $scored = array();
        foreach ($pages as $number => $page) {
            $haystack = \core_text::strtolower($page);
            $score = 0;
            foreach ($words as $word) {
                $score += substr_count($haystack, $word);
            }
            if ($score > 0) {
                $scored[$number] = $score;
            }
        }

        if (empty($scored)) {
            return '';
        }

        arsort($scored);
        $wanted = array_slice(array_keys($scored), 0, self::BOOK_PAGES, true);
        sort($wanted);

        $excerpt = '';
        foreach ($wanted as $number) {
            $excerpt .= "\n[page " . ($number + 1) . "]\n" . $pages[$number] . "\n";
        }

        return \core_text::substr($excerpt, 0, self::BOOK_CHARS);
    }

    /**
     * The words of a question worth searching for.
     *
     * @param string $question What was asked
     * @return array Lowercased words of real length
     */
    private static function keywords($question) {
        $question = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $question);
        $words = preg_split('/\s+/u', \core_text::strtolower(trim($question)), -1,
            PREG_SPLIT_NO_EMPTY);

        $keywords = array();
        foreach ($words as $word) {
            // Short words are the ones every page has, and they tell us nothing.
            if (\core_text::strlen($word) >= 4) {
                $keywords[$word] = true;
            }
        }

        return array_keys($keywords);
    }
}
