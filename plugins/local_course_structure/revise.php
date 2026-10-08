<?php
// This file is part of Moodle - http://moodle.org/
//
// Rewrite a generated presentation to a teacher's instruction.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');
require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');

$courseid = required_param('id', PARAM_INT);
$revise = optional_param('revise', 0, PARAM_INT);
$request = trim(optional_param('request', '', PARAM_TEXT));

list($course, $context) = local_course_structure_page($courseid, 'revise', 'tabrevise');

$fs = get_file_storage();
$workdir = local_course_structure_workdir();

if ($revise && confirm_sesskey()) {
    $decks = local_course_structure_decks($courseid);

    if (!isset($decks[$revise])) {
        echo $OUTPUT->notification(
            get_string('nosuchdeck', 'local_course_structure'), 'notifyerror');
        echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/revise.php',
            array('id' => $courseid)));
        echo $OUTPUT->footer();
        die();
    }

    $deck = $decks[$revise];
    $modcontext = context_module::instance($deck->cmid);

    // The presentation itself is the record of what it says, so it is read back
    // rather than built from the book again.
    $current = array();
    $deckfiles = $fs->get_area_files($modcontext->id, 'mod_resource', 'content',
        0, 'itemid', false);
    $deckfile = reset($deckfiles);

    if ($deckfile) {
        $readpath = $workdir . '/revise-' . uniqid() . '.pdf';
        $deckfile->copy_content_to($readpath);
        $current = \local_course_structure\slide_deck::parse($readpath);
        unlink($readpath);
    }

    if (empty($current)) {
        echo $OUTPUT->notification(
            get_string('deckunreadable', 'local_course_structure', s($deck->name)),
            'notifyerror');
        echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/revise.php',
            array('id' => $courseid)));
        echo $OUTPUT->footer();
        die();
    }

    if ($request === '') {
        echo '<div class="card"><div class="card-body">';
        echo $OUTPUT->heading(get_string('revisetitle', 'local_course_structure',
            s($deck->name)), 4);
        echo '<p>' . get_string('revisedesc', 'local_course_structure') . '</p>';

        echo '<p class="mb-1"><strong>' .
             get_string('currentslides', 'local_course_structure') . '</strong></p>';
        echo '<ol class="mb-3">';
        foreach ($current as $slide) {
            echo '<li>' . s($slide['heading']) . ' <span class="text-muted">(' .
                 get_string('bulletcount', 'local_course_structure',
                     count($slide['bullets'])) . ')</span></li>';
        }
        echo '</ol>';

        echo '<form method="post">';
        echo '<input type="hidden" name="id" value="' . $courseid . '">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="revise" value="' . $deck->cmid . '">';
        echo '<div class="form-group">';
        echo '<label for="request">' .
             get_string('reviserequest', 'local_course_structure') . '</label>';
        echo '<textarea class="form-control" id="request" name="request" rows="4" ' .
             'required placeholder="' .
             s(get_string('reviseexample', 'local_course_structure')) . '"></textarea>';
        echo '</div>';
        echo '<button type="submit" class="btn btn-primary">' .
             get_string('revisebutton', 'local_course_structure') . '</button> ';
        echo '<a class="btn btn-secondary" href="' .
             new moodle_url('/local/course_structure/revise.php', array('id' => $courseid)) .
             '">' . get_string('cancel') . '</a>';
        echo '</form>';
        echo '</div></div>';

        echo $OUTPUT->footer();
        die();
    }

    \core_php_time_limit::raise(600);
    raise_memory_limit(MEMORY_EXTRA);

    try {
        $revised = \mod_book\ai_service::revise_slides($deck->name, $current, $request);

        $newpath = $workdir . '/revised-' . uniqid() . '.pdf';
        \local_course_structure\slide_deck::render(
            $deck->name, $deck->chapter, $revised, $newpath);

        // The presentation keeps its place in the course; only its file changes.
        $fs->delete_area_files($modcontext->id, 'mod_resource', 'content', 0);
        $fs->create_file_from_pathname(array(
            'contextid' => $modcontext->id,
            'component' => 'mod_resource',
            'filearea'  => 'content',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => clean_param($deck->name, PARAM_FILE) . '.pdf',
            'sortorder' => 1,
        ), $newpath);
        unlink($newpath);

        rebuild_course_cache($courseid, true);

        echo $OUTPUT->notification(
            get_string('revised', 'local_course_structure', (object)array(
                'title' => s($deck->name), 'before' => count($current),
                'after' => count($revised))), 'notifysuccess');

        // Anything made from the old slides is now out of step with them.
        if ($deck->haspptx ||
                local_course_structure_exists($courseid, 'csgenlab-' . $deck->index)) {
            echo $OUTPUT->notification(
                get_string('revisestale', 'local_course_structure'), 'notifymessage');
        }

    } catch (Exception $e) {
        echo $OUTPUT->notification(
            get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($deck->name), 'error' => s($e->getMessage()))), 'notifyerror');
    }

    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/revise.php',
        array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

$decks = local_course_structure_decks($courseid);

echo '<div class="card"><div class="card-body">';
echo '<p>' . get_string('revisedesc', 'local_course_structure') . '</p>';

if (empty($decks)) {
    local_course_structure_no_decks($courseid);
} else {
    echo '<table class="table table-sm"><tbody>';
    foreach ($decks as $deck) {
        echo '<tr><td>' . s($deck->name) . '</td>';
        echo '<td class="text-muted">' . s($deck->chapter) . '</td>';
        echo '<td class="text-right">';
        echo '<form method="post" class="m-0">';
        echo '<input type="hidden" name="id" value="' . $courseid . '">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="revise" value="' . $deck->cmid . '">';
        echo '<button type="submit" class="btn btn-sm btn-outline-primary">' .
             get_string('revise', 'local_course_structure') . '</button>';
        echo '</form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo $OUTPUT->footer();
