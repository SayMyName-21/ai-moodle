<?php
// This file is part of Moodle - http://moodle.org/
//
// Generate lecture presentations from the stored book and attach them to sections.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');
require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');
require_once($CFG->dirroot . '/local/course_structure/classes/sources.php');

$courseid = required_param('id', PARAM_INT);
$go = optional_param('go', 0, PARAM_BOOL);
$count = optional_param('count', 0, PARAM_INT);
$replace = optional_param('replace', 0, PARAM_BOOL);
$sources = optional_param('sources', 0, PARAM_BOOL);

list($course, $context) = local_course_structure_page($courseid, 'lectures', 'tablectures');

// The book was kept when the course structure was generated.
$fs = get_file_storage();
$stored = $fs->get_area_files($context->id, 'local_course_structure', 'book', 0, 'itemid', false);
$bookfile = reset($stored);

if (!$bookfile) {
    echo $OUTPUT->notification(get_string('nobookstored', 'local_course_structure'), 'notifyerror');
    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/index.php',
        array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

// The book is read from a real path, so keep a working copy outside the file store.
$workdir = local_course_structure_workdir();
$bookpath = $workdir . '/' . $bookfile->get_contenthash() . '.pdf';
if (!file_exists($bookpath)) {
    $bookfile->copy_content_to($bookpath);
}

$outline = \mod_book\ai_service::get_outline($bookpath);
$sections = \mod_book\ai_service::list_sections($outline);

// A presentation covers one chapter only, so there can be no fewer of them than
// the book has chapters, or a chapter would go without.
$chaptercount = count(array_unique(array_column($sections, 'chapterindex')));
if ($count > 0) {
    $count = min(max($count, $chaptercount), count($sections));
}

// Match each chapter to the course section carrying its name.
$sectionof = array();
foreach ($DB->get_records('course_sections', array('course' => $courseid)) as $section) {
    if ($section->name !== null && trim($section->name) !== '') {
        $sectionof[trim($section->name)] = (int)$section->section;
    }
}

/**
 * Share the book's sections out over a number of presentations, in book order.
 *
 * A presentation belongs to one chapter and never reaches across two, so the
 * presentations are handed to the chapters first and only then filled. Every
 * chapter keeps at least one, and the spare ones go to whichever chapter is
 * carrying the most sections each time.
 *
 * @param array $sections Sections as listed by the AI service
 * @param int $count Presentations wanted
 * @return array One entry per presentation, each with its chapter and section keys
 */
function local_course_structure_spread($sections, $count) {
    $bychapter = array();
    foreach ($sections as $key => $section) {
        $bychapter[$section['chapterindex']][] = $key;
    }

    $share = array();
    foreach ($bychapter as $chapter => $keys) {
        $share[$chapter] = 1;
    }

    $spare = $count - count($bychapter);
    while ($spare > 0) {
        $neediest = null;
        $heaviest = 0;
        foreach ($bychapter as $chapter => $keys) {
            // A chapter cannot be split more finely than one section each.
            if ($share[$chapter] >= count($keys)) {
                continue;
            }
            $load = count($keys) / $share[$chapter];
            if ($load > $heaviest) {
                $heaviest = $load;
                $neediest = $chapter;
            }
        }
        if ($neediest === null) {
            break;
        }
        $share[$neediest]++;
        $spare--;
    }

    $spread = array();
    foreach ($bychapter as $chapter => $keys) {
        $wanted = $share[$chapter];
        $base = intdiv(count($keys), $wanted);
        $extra = count($keys) % $wanted;
        $pos = 0;

        for ($i = 0; $i < $wanted; $i++) {
            $take = $base + ($i < $extra ? 1 : 0);
            $spread[] = array(
                'chapter' => $chapter,
                'keys' => array_slice($keys, $pos, $take),
            );
            $pos += $take;
        }
    }

    return $spread;
}

if ($go && confirm_sesskey()) {
    \core_php_time_limit::raise(3600);
    raise_memory_limit(MEMORY_EXTRA);

    // One checkbox set and one title arrive per presentation the reader asked for.
    $groups = array();
    for ($i = 0; $i < $count; $i++) {
        $picked = optional_param_array('sec_' . $i, array(), PARAM_INT);
        if (empty($picked)) {
            continue;
        }

        // A presentation belongs to one chapter and is filed under that chapter's
        // topic. The form offers only one chapter's parts per presentation, so this
        // stands guard over a request that did not come from the form.
        $spans = array();
        foreach ($picked as $key) {
            if (isset($sections[$key])) {
                $spans[$sections[$key]['chapterindex']] = true;
            }
        }
        if (count($spans) > 1) {
            echo $OUTPUT->notification(
                get_string('mixedchapters', 'local_course_structure', $i + 1), 'notifyerror');
            echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/lectures.php',
                array('id' => $courseid)));
            echo $OUTPUT->footer();
            die();
        }

        $title = trim(optional_param('title_' . $i, '', PARAM_TEXT));
        if ($title === '') {
            $title = get_string('lecturenumber', 'local_course_structure', $i + 1);
        }

        $groups[] = array('title' => $title, 'sections' => array_values($picked));
    }

    $plan = \mod_book\ai_service::plan_from_selection($outline, $groups);

    if (empty($plan)) {
        echo $OUTPUT->notification(
            get_string('nothingselected', 'local_course_structure'), 'notifyerror');
        echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/lectures.php',
            array('id' => $courseid)));
        echo $OUTPUT->footer();
        die();
    }

    // Presentations are marked by their position in the run, so a regrouping would
    // otherwise be skipped as already built. Clearing the earlier run first gives
    // the new grouping a clean course to land in. Exams are course-wide and may
    // already have attempts against them, so they are left for the reader to remove.
    if ($replace) {
        $old = $DB->get_records_select('course_modules',
            'course = :course AND (' . $DB->sql_like('idnumber', ':deck') .
            ' OR ' . $DB->sql_like('idnumber', ':pptx') .
            ' OR ' . $DB->sql_like('idnumber', ':lab') . ')',
            array('course' => $courseid, 'deck' => 'csgen-%',
                  'pptx' => 'csgenppt-%', 'lab' => 'csgenlab-%'),
            '', 'id');

        foreach ($old as $cm) {
            ob_start();
            course_delete_module($cm->id);
            ob_end_clean();
        }

        if (!empty($old)) {
            rebuild_course_cache($courseid, true);
            echo $OUTPUT->notification(
                get_string('replacedold', 'local_course_structure', count($old)),
                'notifymessage');
            flush();
        }
    }

    // The daily token allowance is small enough that a run can stop part way through,
    // so anything already built is kept and only the gaps are filled. Delete a
    // presentation in the course to have it built again.
    $existing = array();
    $partial = array();
    $rows = $DB->get_records_sql(
        "SELECT cm.id, cm.idnumber FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
          WHERE cm.course = :course AND " . $DB->sql_like('cm.idnumber', ':marker'),
        array('course' => $courseid, 'marker' => 'csgen-%'));

    foreach ($rows as $row) {
        $index = (int)substr($row->idnumber, 6);
        // A deck whose source text was only partly covered is worth building again.
        if (substr($row->idnumber, -5) === '-part') {
            $partial[$index] = $row->id;
        } else {
            $existing[$index] = true;
        }
    }

    if (!empty($existing)) {
        echo $OUTPUT->notification(
            get_string('alreadybuilt', 'local_course_structure', count($existing)), 'notifymessage');
        flush();
    }

    $made = 0;
    foreach ($plan as $index => $presentation) {
        if (isset($existing[$index])) {
            continue;
        }

        echo $OUTPUT->notification(
            get_string('generatingnum', 'local_course_structure', (object)array(
                'n' => $index + 1, 'total' => count($plan), 'title' => s($presentation['title']))),
            'notifymessage');
        flush();

        $slides = array();
        $missed = 0;
        foreach ($presentation['chunks'] as $chunk) {
            try {
                $text = \mod_book\ai_service::extract_pages($bookpath,
                    $chunk['from'] + $outline['offset'], $chunk['to'] + $outline['offset']);
                $slides[] = array(
                    'heading' => $presentation['title'],
                    'bullets' => $chunk['titles'],
                );
                $slides = array_merge($slides,
                    \mod_book\ai_service::generate_slides($presentation['title'], $text));
            } catch (Exception $e) {
                $missed++;
                echo $OUTPUT->notification(
                    get_string('lecturefailed', 'local_course_structure', (object)array(
                        'title' => s($presentation['title']), 'error' => s($e->getMessage()))),
                    'notifyerror');
                flush();
            }
        }

        if (empty($slides)) {
            continue;
        }

        // English reading on the same topics, looked up only once the presentation
        // is known to be worth building.
        $intro = '';
        if ($sources) {
            $topics = array($presentation['title']);
            foreach ($presentation['chunks'] as $chunk) {
                foreach ($chunk['titles'] as $lesson) {
                    // The book's own numbering is no help to a search.
                    $topics[] = preg_replace('/^\s*\d+[\.\)]?\s*/u', '', $lesson);
                }
            }

            $links = \local_course_structure\sources::lookup($topics);
            if (!empty($links)) {
                $intro = '<p>' . get_string('furtherreading', 'local_course_structure') .
                         '</p><ul>';
                foreach ($links as $link) {
                    $intro .= '<li><a href="' . s($link['url']) .
                              '" target="_blank" rel="noopener noreferrer">' .
                              s($link['title']) . '</a></li>';
                }
                $intro .= '</ul>';
            }
        }

        // Replace an earlier incomplete attempt at this same presentation.
        if (isset($partial[$index])) {
            ob_start();
            course_delete_module($partial[$index]);
            ob_end_clean();
        }

        try {
            $pdfpath = $workdir . '/deck-' . uniqid() . '.pdf';
            \local_course_structure\slide_deck::render(
                $presentation['title'], $presentation['chapter'], $slides, $pdfpath);

            $draftid = file_get_unused_draft_itemid();
            $fs->create_file_from_pathname(array(
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $draftid,
                'filepath'  => '/',
                'filename'  => clean_param($presentation['title'], PARAM_FILE) . '.pdf',
            ), $pdfpath);
            unlink($pdfpath);

            $module = $DB->get_record('modules', array('name' => 'resource'), '*', MUST_EXIST);

            $data = new stdClass();
            $data->course = $course->id;
            $data->modulename = 'resource';
            $data->module = $module->id;
            $data->section = $sectionof[trim($presentation['chapter'])]
                ?? ($presentation['chapterindex'] + 1);
            $data->name = $presentation['title'];
            $data->intro = $intro;
            $data->introformat = FORMAT_HTML;
            $data->visible = 1;
            $data->files = $draftid;
            $data->display = RESOURCELIB_DISPLAY_AUTO;
            // The reading is only worth showing on the course page if there is any.
            $data->printintro = ($intro === '') ? 0 : 1;
            $data->showsize = 1;
            $data->showtype = 0;
            $data->showdate = 0;
            $data->popupwidth = 620;
            $data->popupheight = 450;
            $data->filterfiles = 0;
            $data->cmidnumber = 'csgen-' . $index . ($missed > 0 ? '-part' : '');
            $data->groupmode = 0;
            $data->groupingid = 0;
            $data->completion = 0;
            $data->completionview = 0;
            $data->completionexpected = 0;
            $data->availabilityconditionsjson = '';

            ob_start();
            add_moduleinfo($data, $course);
            ob_end_clean();
            $made++;

        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($presentation['title']), 'error' => s($e->getMessage()))),
                'notifyerror');
            flush();
        }
    }

    rebuild_course_cache($courseid, true);
    echo $OUTPUT->notification(
        get_string('lecturesdone', 'local_course_structure', $made), 'notifysuccess');
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

// Step two: which sections go into each presentation.
if ($count > 0) {
    require_sesskey();

    $defaults = local_course_structure_spread($sections, $count);

    // Costing the seeded split gives the true request count: wherever a section
    // ends up, it is still one request per part of it.
    $seed = array();
    foreach ($defaults as $entry) {
        $seed[] = array('title' => '', 'sections' => $entry['keys']);
    }
    $requests = 0;
    foreach (\mod_book\ai_service::plan_from_selection($outline, $seed) as $presentation) {
        $requests += count($presentation['chunks']);
    }
    $minutes = (int)ceil(($requests * \mod_book\ai_service::REQUEST_SPACING) / 60);

    echo '<div class="card"><div class="card-body">';
    echo '<p>' . get_string('assigndesc', 'local_course_structure') . '</p>';
    echo '<p>' . get_string('willgenerate', 'local_course_structure', (object)array(
        'count' => $count, 'minutes' => $minutes)) . '</p>';

    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="count" value="' . $count . '">';
    echo '<input type="hidden" name="go" value="1">';

    $lastchapter = null;
    foreach ($defaults as $i => $entry) {
        $keys = $entry['keys'];
        $ticked = array_fill_keys($keys, true);
        $default = (count($keys) === 1)
            ? $sections[$keys[0]]['title']
            : get_string('lecturenumber', 'local_course_structure', $i + 1);

        // The presentations of one chapter are shown together under its name.
        if ($entry['chapter'] !== $lastchapter) {
            $chaptername = '';
            foreach ($sections as $section) {
                if ($section['chapterindex'] === $entry['chapter']) {
                    $chaptername = $section['chapter'];
                    break;
                }
            }
            echo '<h5 class="mt-4">' . s($chaptername) . '</h5>';
            $lastchapter = $entry['chapter'];
        }

        echo '<fieldset class="border rounded p-3 mb-3">';
        echo '<legend class="h6">' .
             get_string('lecturenumber', 'local_course_structure', $i + 1) . '</legend>';

        echo '<div class="form-group">';
        echo '<label for="title_' . $i . '">' .
             get_string('lecturetitle', 'local_course_structure') . '</label>';
        echo '<input type="text" class="form-control" id="title_' . $i .
             '" name="title_' . $i . '" value="' . s($default) . '">';
        echo '</div>';

        // Only this chapter's parts are offered, which is what keeps a
        // presentation from reaching across two chapters of the course.
        foreach ($sections as $key => $section) {
            if ($section['chapterindex'] !== $entry['chapter']) {
                continue;
            }

            $boxid = 'sec_' . $i . '_' . $key;
            echo '<div class="form-check">';
            echo '<input type="checkbox" class="form-check-input" id="' . $boxid .
                 '" name="sec_' . $i . '[]" value="' . $key . '"' .
                 (isset($ticked[$key]) ? ' checked' : '') . '>';
            echo '<label class="form-check-label" for="' . $boxid . '">' .
                 s($section['title']) . ' <span class="text-muted">(' .
                 get_string('lessonsin', 'local_course_structure',
                     count($section['lessons'])) . ')</span></label>';
            echo '</div>';
        }

        echo '</fieldset>';
    }

    echo '<div class="form-check mb-3">';
    echo '<input type="checkbox" class="form-check-input" id="sources" ' .
         'name="sources" value="1">';
    echo '<label class="form-check-label" for="sources">' .
         get_string('sources', 'local_course_structure') . '</label>';
    echo '<small class="form-text text-muted">' .
         get_string('sources_help', 'local_course_structure') . '</small>';
    echo '</div>';

    // Anything built by an earlier run sits at a position, not at a set of sections,
    // so a new grouping needs the choice of clearing it out.
    $built = local_course_structure_decks($courseid);
    if (!empty($built)) {
        echo '<div class="alert alert-info">' .
             get_string('replacenote', 'local_course_structure', count($built)) . '</div>';

        echo '<div class="form-check mb-3">';
        echo '<input type="checkbox" class="form-check-input" id="replace" ' .
             'name="replace" value="1">';
        echo '<label class="form-check-label" for="replace">' .
             get_string('replace', 'local_course_structure') . '</label>';
        echo '<small class="form-text text-muted">' .
             get_string('replace_help', 'local_course_structure') . '</small>';
        echo '</div>';
    }

    echo '<div class="alert alert-warning">' .
         get_string('ratelimitnote', 'local_course_structure') . '</div>';

    echo '<button type="submit" class="btn btn-primary">' .
         get_string('generatelectures', 'local_course_structure') . '</button> ';
    echo '<a class="btn btn-secondary" href="' .
         new moodle_url('/local/course_structure/lectures.php', array('id' => $courseid)) .
         '">' . get_string('cancel') . '</a>';
    echo '</form>';
    echo '</div></div>';

    echo $OUTPUT->footer();
    die();
}

// Step one: how many presentations.
echo '<div class="card"><div class="card-body">';
echo '<p>' . get_string('lecturesdesc', 'local_course_structure') . '</p>';

if (empty($sections)) {
    echo $OUTPUT->notification(
        get_string('nosectionsfound', 'local_course_structure'), 'notifyerror');
} else {
    echo '<p>' . get_string('sectionsfound', 'local_course_structure',
        count($sections)) . '</p>';

    echo '<ul class="mb-3">';
    $lastchapter = null;
    foreach ($sections as $section) {
        if ($section['chapterindex'] !== $lastchapter) {
            echo '</ul><p class="mt-3 mb-1"><strong>' . s($section['chapter']) .
                 '</strong></p><ul>';
            $lastchapter = $section['chapterindex'];
        }
        echo '<li>' . s($section['title']) . ' <span class="text-muted">(' .
             get_string('lessonsin', 'local_course_structure',
                 count($section['lessons'])) . ')</span></li>';
    }
    echo '</ul>';

    echo '<p class="text-muted">' . get_string('onechapteronly', 'local_course_structure',
        $chaptercount) . '</p>';

    echo '<form method="post" class="form-inline">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<label class="mr-2" for="count">' .
         get_string('howmany', 'local_course_structure') . '</label>';
    echo '<input type="number" class="form-control mr-2" id="count" name="count" min="' .
         $chaptercount . '" max="' . count($sections) . '" value="' . count($sections) .
         '" required>';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('continuetoassign', 'local_course_structure') . '</button>';
    echo '</form>';
}
echo '</div></div>';

echo $OUTPUT->footer();
