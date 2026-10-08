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

    $decks = local_course_structure_decks($courseid);
    $targets = ($topptx === -1) ? $decks : array_intersect_key($decks, array($topptx => true));
    $built = 0;

    foreach ($targets as $deck) {
        if ($deck->haspptx) {
            continue;
        }

        try {
            $modcontext = context_module::instance($deck->cmid);
            $sourcefiles = $fs->get_area_files($modcontext->id, 'mod_resource', 'content',
                0, 'itemid', false);
            $source = reset($sourcefiles);
            if (!$source) {
                throw new Exception('the presentation has no file attached');
            }

            $pdfpath = $workdir . '/src-' . uniqid() . '.pdf';
            $source->copy_content_to($pdfpath);
            // Rebuilding from the PDF keeps this free of any AI request.
            $slides = \local_course_structure\slide_deck::parse($pdfpath);
            unlink($pdfpath);

            if (empty($slides)) {
                throw new Exception('no slides could be read back from the PDF');
            }

            $pptxpath = $workdir . '/deck-' . uniqid() . '.pptx';
            \local_course_structure\pptx_deck::render(
                $deck->name, $deck->chapter, $slides, $pptxpath);

            $draftid = file_get_unused_draft_itemid();
            $fs->create_file_from_pathname(array(
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $draftid,
                'filepath'  => '/',
                'filename'  => clean_param($deck->name, PARAM_FILE) . '.pptx',
            ), $pptxpath);
            unlink($pptxpath);

            $module = $DB->get_record('modules', array('name' => 'resource'), '*', MUST_EXIST);

            $data = new stdClass();
            $data->course = $course->id;
            $data->modulename = 'resource';
            $data->module = $module->id;
            $data->section = (int)$deck->section;
            $data->name = $deck->name . ' ' . get_string('powerpointsuffix', 'local_course_structure');
            $data->intro = '';
            $data->introformat = FORMAT_HTML;
            $data->visible = 1;
            $data->files = $draftid;
            $data->display = RESOURCELIB_DISPLAY_AUTO;
            $data->printintro = 0;
            $data->showsize = 1;
            $data->showtype = 0;
            $data->showdate = 0;
            $data->popupwidth = 620;
            $data->popupheight = 450;
            $data->filterfiles = 0;
            $data->cmidnumber = 'csgenppt-' . $deck->index;
            $data->groupmode = 0;
            $data->groupingid = 0;
            $data->completion = 0;
            $data->completionview = 0;
            $data->completionexpected = 0;
            $data->availabilityconditionsjson = '';

            ob_start();
            add_moduleinfo($data, $course);
            ob_end_clean();
            $built++;

            echo $OUTPUT->notification(
                get_string('converted', 'local_course_structure', s($deck->name)), 'notifysuccess');
            flush();

        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($deck->name), 'error' => s($e->getMessage()))), 'notifyerror');
            flush();
        }
    }

    rebuild_course_cache($courseid, true);
    echo $OUTPUT->notification(
        get_string('convertdone', 'local_course_structure', $built), 'notifysuccess');
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
