<?php
// This file is part of Moodle - http://moodle.org/
//
// Produce editable PowerPoint versions of the generated presentations.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');
require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');
require_once($CFG->dirroot . '/local/course_structure/classes/pptx_deck.php');

$courseid = required_param('id', PARAM_INT);
$topptx = optional_param('topptx', 0, PARAM_INT);

list($course, $context) = local_course_structure_page($courseid, 'powerpoint', 'tabpowerpoint');

$fs = get_file_storage();
$workdir = local_course_structure_workdir();

if ($topptx && confirm_sesskey()) {
    \core_php_time_limit::raise(600);

    local_course_structure_convert_decks($course, ($topptx === -1) ? null : array($topptx),
        local_course_structure_page_say());

    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/powerpoint.php',
        array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

$decks = local_course_structure_decks($courseid);

echo '<div class="card"><div class="card-body">';
echo '<p>' . get_string('existingdecksdesc', 'local_course_structure') . '</p>';

if (empty($decks)) {
    local_course_structure_no_decks($courseid);
} else {
    $remaining = 0;
    echo '<table class="table table-sm"><tbody>';
    foreach ($decks as $deck) {
        echo '<tr><td>' . s($deck->name) . '</td><td class="text-right">';
        if ($deck->haspptx) {
            echo '<span class="text-muted">' .
                 get_string('haspptx', 'local_course_structure') . '</span>';
        } else {
            $remaining++;
            echo '<form method="post" class="m-0">';
            echo '<input type="hidden" name="id" value="' . $courseid . '">';
            echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
            echo '<input type="hidden" name="topptx" value="' . $deck->cmid . '">';
            echo '<button type="submit" class="btn btn-sm btn-outline-primary">' .
                 get_string('topptx', 'local_course_structure') . '</button>';
            echo '</form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    if ($remaining > 1) {
        echo '<form method="post">';
        echo '<input type="hidden" name="id" value="' . $courseid . '">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="topptx" value="-1">';
        echo '<button type="submit" class="btn btn-primary">' .
             get_string('topptxall', 'local_course_structure') . '</button>';
        echo '</form>';
    }
}
echo '</div></div>';

echo $OUTPUT->footer();
