<?php
// This file is part of Moodle - http://moodle.org/
//
// Write lab quizzes and exams from the generated presentations.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');
require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');
require_once($CFG->dirroot . '/local/course_structure/classes/quiz_builder.php');

$courseid = required_param('id', PARAM_INT);
$dolabs = optional_param('dolabs', 0, PARAM_BOOL);
$doexams = optional_param('doexams', 0, PARAM_BOOL);

list($course, $context) = local_course_structure_page($courseid, 'quizzes', 'tabquizzes');

$fs = get_file_storage();
$workdir = local_course_structure_workdir();

if ($dolabs && confirm_sesskey()) {
    \core_php_time_limit::raise(3600);
    raise_memory_limit(MEMORY_EXTRA);

    $made = 0;
    foreach (local_course_structure_decks($courseid) as $deck) {
        $marker = 'csgenlab-' . $deck->index;
        if (local_course_structure_exists($courseid, $marker)) {
            continue;
        }

        echo $OUTPUT->notification(
            get_string('buildingquiz', 'local_course_structure', s($deck->name)), 'notifymessage');
        flush();

        try {
            $text = local_course_structure_deck_text($fs, $deck->cmid, $workdir);
            if (trim($text) === '') {
                throw new Exception('the presentation has no readable text');
            }

            $questions = \mod_book\ai_service::generate_questions(
                $deck->name, $text, LOCAL_COURSE_STRUCTURE_LAB_QUESTIONS);

            $categoryid = \local_course_structure\quiz_builder::make_category(
                $context, $deck->name);
            $ids = \local_course_structure\quiz_builder::import_questions(
                $questions, $categoryid, $course, $context);

            if (empty($ids)) {
                throw new Exception('no questions were stored');
            }

            $quiz = \local_course_structure\quiz_builder::make_quiz($course, (int)$deck->section,
                $deck->name . ' ' . get_string('labquizsuffix', 'local_course_structure'),
                $marker, count($ids));

            \local_course_structure\quiz_builder::add_questions($quiz, $ids);
            \local_course_structure\quiz_builder::shuffle_questions($quiz);
            \local_course_structure\quiz_builder::finalise($quiz);
            $made++;

        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($deck->name), 'error' => s($e->getMessage()))), 'notifyerror');
            flush();
        }
    }

    rebuild_course_cache($courseid, true);
    echo $OUTPUT->notification(
        get_string('quizzesdone', 'local_course_structure', $made), 'notifysuccess');
    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/quizzes.php',
        array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

if ($doexams && confirm_sesskey()) {
    \core_php_time_limit::raise(3600);
    raise_memory_limit(MEMORY_EXTRA);

    $decks = array_values(local_course_structure_decks($courseid));
    $made = 0;

    foreach (array(0, 1) as $half) {
        $marker = 'csgenexam-' . ($half + 1);
        if (local_course_structure_exists($courseid, $marker)) {
            continue;
        }

        $covered = array_slice($decks, $half * 6, 6);
        if (empty($covered)) {
            continue;
        }

        $examname = get_string('examname', 'local_course_structure', $half + 1);
        $pool = array();

        foreach ($covered as $deck) {
            echo $OUTPUT->notification(
                get_string('buildingexam', 'local_course_structure', (object)array(
                    'exam' => s($examname), 'lecture' => s($deck->name))), 'notifymessage');
            flush();

            try {
                $text = local_course_structure_deck_text($fs, $deck->cmid, $workdir);
                if (trim($text) === '') {
                    throw new Exception('the presentation has no readable text');
                }
                // Exam questions are written afresh rather than reused from the labs.
                $pool = array_merge($pool, \mod_book\ai_service::generate_questions(
                    $deck->name, $text, LOCAL_COURSE_STRUCTURE_EXAM_PER_LECTURE));

            } catch (Exception $e) {
                echo $OUTPUT->notification(
                    get_string('lecturefailed', 'local_course_structure', (object)array(
                        'title' => s($deck->name), 'error' => s($e->getMessage()))), 'notifyerror');
                flush();
            }
        }

        if (count($pool) < 2) {
            echo $OUTPUT->notification(
                get_string('examtoosmall', 'local_course_structure', s($examname)), 'notifyerror');
            continue;
        }

        try {
            $categoryid = \local_course_structure\quiz_builder::make_category($context, $examname);
            $ids = \local_course_structure\quiz_builder::import_questions(
                $pool, $categoryid, $course, $context);

            // Half the pool is drawn at random, so each attempt is a different paper.
            $shown = (int)floor(count($ids) / 2);
            if ($shown < 1) {
                throw new Exception('the question pool is too small to draw from');
            }

            $quiz = \local_course_structure\quiz_builder::make_quiz(
                $course, 0, $examname, $marker, $shown);

            \local_course_structure\quiz_builder::add_random($quiz, $categoryid, $shown);
            \local_course_structure\quiz_builder::shuffle_questions($quiz);
            \local_course_structure\quiz_builder::finalise($quiz);
            $made++;

            echo $OUTPUT->notification(
                get_string('exambuilt', 'local_course_structure', (object)array(
                    'exam' => s($examname), 'pool' => count($ids), 'shown' => $shown)),
                'notifysuccess');
            flush();

        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($examname), 'error' => s($e->getMessage()))), 'notifyerror');
            flush();
        }
    }

    rebuild_course_cache($courseid, true);
    echo $OUTPUT->notification(
        get_string('examsdone', 'local_course_structure', $made), 'notifysuccess');
    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/quizzes.php',
        array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

// What can be built from the presentations so far.
$decks = local_course_structure_decks($courseid);

echo '<div class="card"><div class="card-body">';
echo $OUTPUT->heading(get_string('questions', 'local_course_structure'), 4);
echo '<p>' . get_string('questionsdesc', 'local_course_structure') . '</p>';

if (empty($decks)) {
    local_course_structure_no_decks($courseid);
} else {
    $labsdone = 0;
    foreach ($decks as $deck) {
        if (local_course_structure_exists($courseid, 'csgenlab-' . $deck->index)) {
            $labsdone++;
        }
    }
    if ($labsdone > 0) {
        echo '<div class="alert alert-info">' .
             get_string('labsexist', 'local_course_structure', $labsdone) . '</div>';
    }

    echo '<p class="mb-1"><strong>' . get_string('dolabs', 'local_course_structure') .
         '</strong></p>';
    echo '<p class="text-muted">' . get_string('dolabsdesc', 'local_course_structure',
         LOCAL_COURSE_STRUCTURE_LAB_QUESTIONS) . '</p>';
    echo '<form method="post" class="mb-4">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="dolabs" value="1">';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('dolabs', 'local_course_structure') . '</button>';
    echo '</form>';

    echo '<p class="mb-1"><strong>' . get_string('doexams', 'local_course_structure') .
         '</strong></p>';
    echo '<p class="text-muted">' . get_string('doexamsdesc', 'local_course_structure') . '</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="doexams" value="1">';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('doexams', 'local_course_structure') . '</button>';
    echo '</form>';

    echo '<div class="alert alert-warning mt-3">' .
         get_string('ratelimitnote', 'local_course_structure') . '</div>';
}
echo '</div></div>';

echo $OUTPUT->footer();
