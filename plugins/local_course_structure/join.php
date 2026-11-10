<?php
// This file is part of Moodle - http://moodle.org/
//
// Join presentations into one, written afresh from the book pages they cover.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');

$courseid = required_param('id', PARAM_INT);
$step = optional_param('step', '', PARAM_ALPHA);
$chosen = optional_param_array('decks', array(), PARAM_INT);
$picked = optional_param_array('sections', array(), PARAM_INT);
$title = trim(optional_param('title', '', PARAM_TEXT));
$topic = optional_param('topic', -1, PARAM_INT);
$hide = optional_param('hide', 0, PARAM_BOOL);

list($course, $context) = local_course_structure_page($courseid, 'join', 'tabjoin');

$back = new moodle_url('/local/course_structure/join.php', array('id' => $courseid));
$stop = function($message, $type = 'notifyerror') use ($OUTPUT, $back) {
    echo $OUTPUT->notification($message, $type);
    echo $OUTPUT->continue_button($back);
    echo $OUTPUT->footer();
    die();
};

$decks = local_course_structure_decks($courseid);

// The course's topics, any of which the joined presentation can be filed under.
$coursetopics = array();
foreach (get_fast_modinfo($course)->get_section_info_all() as $info) {
    $coursetopics[(int)$info->section] = get_section_name($course, $info);
}

if ($step !== '') {
    require_sesskey();

    $chosen = array_values(array_intersect(array_unique($chosen), array_keys($decks)));
    if (count($chosen) < 2) {
        $stop(get_string('joinneedtwo', 'local_course_structure'));
    }

    // The new presentation is written from the book, so the book has to be here
    // and read through.
    $book = local_course_structure_stored_book($context);
    if (!$book) {
        $stop(get_string('nobookstored', 'local_course_structure'));
    }
    $bookpath = $book[0];
    if (!\mod_book\ai_service::reading_progress(basename($bookpath, '.pdf'))['done']) {
        $stop(get_string('readingnotdone', 'local_course_structure'), 'notifyinfo');
    }

    \core_php_time_limit::raise(3600);
    raise_memory_limit(MEMORY_EXTRA);

    $outline = \mod_book\ai_service::get_outline($bookpath);
    $sections = \mod_book\ai_service::list_sections($outline);
}

// Step three: write the joined presentation and add it to the course.
if ($step === 'build') {
    $picked = array_values(array_filter(array_unique($picked), function($key) use ($sections) {
        return isset($sections[$key]);
    }));
    sort($picked);
    if (empty($picked)) {
        $stop(get_string('joinnosections', 'local_course_structure'));
    }
    if ($title === '') {
        $title = implode(' + ', array_map(function($cmid) use ($decks) {
            return $decks[$cmid]->name;
        }, $chosen));
    }
    if (!isset($coursetopics[$topic])) {
        $topic = (int)$decks[$chosen[0]]->section;
    }

    $plan = \mod_book\ai_service::plan_from_selection($outline, array(
        array('title' => $title, 'sections' => $picked, 'topic' => $topic)));
    $presentation = reset($plan);

    echo $OUTPUT->notification(get_string('joinwriting', 'local_course_structure', (object)array(
        'title' => s($title), 'requests' => count($presentation['chunks']) + 1)), 'notifymessage');
    flush();

    list($slides, $missed) = local_course_structure_write_slides($bookpath, $outline['offset'],
        $presentation, function($error) use ($OUTPUT, $title) {
            echo $OUTPUT->notification(
                get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($title), 'error' => s($error))), 'notifyerror');
            flush();
        });

    if (empty($slides)) {
        $stop(get_string('joinfailed', 'local_course_structure', s($title)));
    }

    // The divider slides opening each part, which the joining pass leaves alone.
    $chunktitles = array();
    foreach ($presentation['chunks'] as $chunk) {
        $chunktitles[] = $chunk['titles'];
    }
    $dividers = array();
    foreach ($slides as $position => $slide) {
        if ($slide['heading'] === $title && in_array($slide['bullets'], $chunktitles, true)) {
            $dividers[] = $position;
        }
    }

    $written = count($slides);
    try {
        $slides = \mod_book\ai_service::join_slides($title, $slides, $dividers);
        echo $OUTPUT->notification(get_string('joinunified', 'local_course_structure', (object)array(
            'before' => $written, 'after' => count($slides))), 'notifymessage');
    } catch (Exception $e) {
        // Written from the book part by part, the deck is complete without this
        // pass; it only reads less as one lecture.
        echo $OUTPUT->notification(get_string('joinnotunified', 'local_course_structure',
            s($e->getMessage())), 'notifymessage');
    }
    flush();

    // Above any index a run of the Presentations tab hands out, which counts from
    // 0 in plan order and would otherwise take this deck for one it already built.
    $index = 1000;
    foreach ($decks as $deck) {
        $index = max($index, $deck->index + 1);
    }

    $cmid = local_course_structure_add_deck($course, $topic, $title,
        $coursetopics[$topic] ?? '', $slides, 'csgen-' . $index . ($missed > 0 ? '-part' : ''));

    if ($hide) {
        foreach ($chosen as $old) {
            set_coursemodule_visible($old, 0);
        }
    }
    rebuild_course_cache($courseid, true);

    echo $OUTPUT->notification(get_string('joined', 'local_course_structure', (object)array(
        'title' => s($title), 'slides' => count($slides), 'topic' => s($coursetopics[$topic] ?? ''))),
        'notifysuccess');
    if ($hide) {
        echo $OUTPUT->notification(get_string('joinhidden', 'local_course_structure', count($chosen)),
            'notifymessage');
    }
    echo html_writer::link(new moodle_url('/mod/resource/view.php', array('id' => $cmid)),
        get_string('joinopen', 'local_course_structure'), array('class' => 'btn btn-primary mr-2'));
    echo html_writer::link(new moodle_url('/course/view.php', array('id' => $courseid)),
        get_string('joinbacktocourse', 'local_course_structure'), array('class' => 'btn btn-secondary'));
    echo $OUTPUT->footer();
    die();
}

// Step two: the book sections the chosen presentations were built from, to check.
if ($step === 'check') {
    $covered = array();
    $from = array();
    foreach ($chosen as $cmid) {
        $slides = local_course_structure_deck_slides($cmid);
        $found = $slides ? local_course_structure_deck_sections($slides, $sections) : array();
        if (empty($found)) {
            echo $OUTPUT->notification(get_string('joinnotraced', 'local_course_structure',
                s($decks[$cmid]->name)), 'notifymessage');
        }
        foreach ($found as $key) {
            $covered[$key] = true;
            $from[$key][] = $decks[$cmid]->name;
        }
    }

    $default = implode(' + ', array_map(function($cmid) use ($decks) {
        return $decks[$cmid]->name;
    }, $chosen));

    echo '<div class="card"><div class="card-body">';
    echo '<p>' . get_string('joincheckdesc', 'local_course_structure') . '</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="step" value="build">';
    foreach ($chosen as $cmid) {
        echo '<input type="hidden" name="decks[]" value="' . $cmid . '">';
    }

    echo '<div class="form-group"><label for="title">' .
         get_string('jointitle', 'local_course_structure') . '</label>';
    echo '<input type="text" class="form-control" id="title" name="title" value="' . s($default) . '">';
    echo '</div>';

    echo '<div class="form-group"><label for="topic">' .
         get_string('jointopic', 'local_course_structure') . '</label>';
    echo '<select class="form-control" id="topic" name="topic">';
    foreach ($coursetopics as $number => $name) {
        $selected = ($number === (int)$decks[$chosen[0]]->section) ? ' selected' : '';
        echo '<option value="' . $number . '"' . $selected . '>' . s($name) . '</option>';
    }
    echo '</select></div>';

    echo '<p class="mb-1"><strong>' . get_string('joinsections', 'local_course_structure') .
         '</strong></p>';
    // A book can run to eighty parts, so each chapter can be ticked as a whole.
    echo '<div class="lcs-ticks">';
    echo '<div class="form-check mb-2 pb-2 border-bottom">';
    echo '<input type="checkbox" class="form-check-input lcs-all" id="lcs-all">';
    echo '<label class="form-check-label font-weight-bold" for="lcs-all">' .
         get_string('joinselectall', 'local_course_structure') . '</label></div>';
    $chapter = null;
    foreach ($sections as $key => $section) {
        if ($section['chapter'] !== $chapter) {
            $chapter = $section['chapter'];
            $chapterid = 'chapter_' . $section['chapterindex'];
            echo '<div class="form-check mt-2">';
            echo '<input type="checkbox" class="form-check-input lcs-chapter" id="' . $chapterid . '" ' .
                 'data-chapter="' . $section['chapterindex'] . '">';
            echo '<label class="form-check-label text-muted small font-weight-bold" for="' . $chapterid .
                 '">' . s($chapter) . '</label></div>';
        }
        $id = 'section_' . $key;
        echo '<div class="form-check ml-4">';
        echo '<input type="checkbox" class="form-check-input lcs-section" id="' . $id . '" name="sections[]" ' .
             'value="' . $key . '" data-chapter="' . $section['chapterindex'] . '"' .
             (isset($covered[$key]) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="' . $id . '">' . s($section['title']);
        if (isset($from[$key])) {
            echo ' <span class="badge badge-info">' . s(implode(', ', $from[$key])) . '</span>';
        }
        echo '</label></div>';
    }
    echo '</div>';
    echo local_course_structure_tick_script();

    echo '<div class="form-check mt-3">';
    echo '<input type="checkbox" class="form-check-input" id="hide" name="hide" value="1" checked>';
    echo '<label class="form-check-label" for="hide">' .
         get_string('joinhide', 'local_course_structure') . '</label>';
    echo '</div>';

    echo '<p class="text-muted small mt-3">' . get_string('joincost', 'local_course_structure') . '</p>';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('joinbuild', 'local_course_structure') . '</button> ';
    echo '<a class="btn btn-secondary" href="' . $back . '">' . get_string('cancel') . '</a>';
    echo '</form>';
    echo '</div></div>';
    echo $OUTPUT->footer();
    die();
}

// Step one: which presentations to join.
echo '<div class="card"><div class="card-body">';
echo '<p>' . get_string('joindesc', 'local_course_structure') . '</p>';

if (count($decks) < 2) {
    if (empty($decks)) {
        local_course_structure_no_decks($courseid);
    } else {
        echo '<p class="text-muted">' . get_string('joinneedtwo', 'local_course_structure') . '</p>';
    }
} else {
    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="step" value="check">';
    echo '<table class="table table-sm"><tbody>';
    foreach ($decks as $deck) {
        $id = 'deck_' . $deck->cmid;
        echo '<tr><td style="width:2em"><input type="checkbox" name="decks[]" id="' . $id . '" ' .
             'value="' . $deck->cmid . '"></td>';
        echo '<td><label for="' . $id . '" class="m-0">' . s($deck->name) . '</label></td>';
        echo '<td class="text-muted">' . s($deck->chapter) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('joinnext', 'local_course_structure') . '</button>';
    echo '<p class="text-muted small mt-2">' . get_string('joinreadnote', 'local_course_structure') . '</p>';
    echo '</form>';
}
echo '</div></div>';

echo $OUTPUT->footer();
