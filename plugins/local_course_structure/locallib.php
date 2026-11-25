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
 * Shared by the generator's pages.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');

/** Most questions one quiz or exam pool may be written with. */
define('LOCAL_COURSE_STRUCTURE_QUIZ_MAX', 60);

/** Questions asked for in one request; more do not fit the free tier's minute. */
define('LOCAL_COURSE_STRUCTURE_QUIZ_BATCH', 5);

/** Characters of the book sent with each request for questions. */
define('LOCAL_COURSE_STRUCTURE_QUIZ_BOOK_CHARS', 3500);

/**
 * The row of tabs the generator's pages share.
 *
 * @param int $courseid Course
 * @param string $active Which page is being looked at
 * @return string Markup for the tabs
 */
function local_course_structure_tabs($courseid, $active) {
    global $OUTPUT;

    $pages = array(
        'structure'  => array('index.php', 'tabstructure'),
        'lectures'   => array('lectures.php', 'tablectures'),
        'powerpoint' => array('powerpoint.php', 'tabpowerpoint'),
        'quizzes'    => array('quizzes.php', 'tabquizzes'),
        'revise'     => array('revise.php', 'tabrevise'),
        'join'       => array('join.php', 'tabjoin'),
        'extras'     => array('extras.php', 'tabextras'),
    );

    $tabs = array();
    foreach ($pages as $key => $page) {
        list($file, $label) = $page;
        $tabs[] = new tabobject($key,
            new moodle_url('/local/course_structure/' . $file, array('id' => $courseid)),
            get_string($label, 'local_course_structure'));
    }

    return $OUTPUT->tabtree($tabs, $active);
}

/**
 * Open one of the generator's pages, with its tabs in place.
 *
 * @param int $courseid Course
 * @param string $active Which page this is
 * @param string $heading String id for the page's heading
 * @return array The course and its context
 */
function local_course_structure_page($courseid, $active, $heading) {
    global $DB, $PAGE, $OUTPUT;

    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    require_login($course);
    require_capability('moodle/course:update', $context);

    $PAGE->set_url('/local/course_structure/' . $active . '.php', array('id' => $courseid));
    $PAGE->set_context($context);
    $PAGE->set_title(get_string($heading, 'local_course_structure'));
    $PAGE->set_heading($course->fullname);
    $PAGE->set_pagelayout('incourse');

    echo $OUTPUT->header();
    echo local_course_structure_tabs($courseid, $active);
    echo $OUTPUT->heading(get_string($heading, 'local_course_structure'));

    return array($course, $context);
}

/**
 * Somewhere outside the file store to work on PDFs, which need a real path.
 *
 * @return string Path to the scratch directory
 */
function local_course_structure_workdir() {
    return make_temp_directory('local_course_structure');
}

/**
 * The book kept from the upload, somewhere Docling can be handed it.
 *
 * @param context_course $context Course context
 * @return array|null Path and file name, or null if no book is stored
 */
function local_course_structure_stored_book($context) {
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'local_course_structure', 'book',
        0, 'itemid', false);
    $file = reset($files);
    if (!$file) {
        return null;
    }

    $path = local_course_structure_workdir() . '/' . $file->get_contenthash() . '.pdf';
    if (!file_exists($path)) {
        $file->copy_content_to($path);
    }

    return array($path, $file->get_filename());
}

/**
 * The presentations the generator has built, with any PowerPoint versions.
 *
 * @param int $courseid Course
 * @return array Decks keyed by course module id
 */
function local_course_structure_decks($courseid) {
    global $DB;

    $decks = $DB->get_records_sql(
        "SELECT cm.id AS cmid, cm.idnumber, r.name, cs.section, cs.name AS chapter
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
           JOIN {resource} r ON r.id = cm.instance
           JOIN {course_sections} cs ON cs.id = cm.section
          WHERE cm.course = :course AND " . $DB->sql_like('cm.idnumber', ':marker') . "
       ORDER BY cs.section, cm.id",
        array('course' => $courseid, 'marker' => 'csgen-%'));

    $converted = $DB->get_records_sql(
        "SELECT cm.id, cm.idnumber FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
          WHERE cm.course = :course AND " . $DB->sql_like('cm.idnumber', ':marker'),
        array('course' => $courseid, 'marker' => 'csgenppt-%'));

    $done = array();
    foreach ($converted as $row) {
        $done[(int)substr($row->idnumber, 9)] = true;
    }

    foreach ($decks as $cmid => $deck) {
        $decks[$cmid]->index = (int)substr($deck->idnumber, 6);
        $decks[$cmid]->haspptx = isset($done[$decks[$cmid]->index]);
    }
    return $decks;
}

/**
 * Read a presentation's wording back out of its PDF.
 *
 * @param \file_storage $fs File storage
 * @param int $cmid Course module holding the presentation
 * @param string $workdir Scratch directory
 * @return string The deck as plain text
 */
function local_course_structure_deck_text($fs, $cmid, $workdir) {
    $files = $fs->get_area_files(context_module::instance($cmid)->id,
        'mod_resource', 'content', 0, 'itemid', false);
    $file = reset($files);
    if (!$file) {
        return '';
    }

    $path = $workdir . '/src-' . uniqid() . '.pdf';
    $file->copy_content_to($path);
    $slides = \local_course_structure\slide_deck::parse($path);
    unlink($path);

    $text = '';
    foreach ($slides as $slide) {
        $text .= $slide['heading'] . "\n";
        foreach ($slide['bullets'] as $bullet) {
            $text .= '- ' . $bullet . "\n";
        }
    }
    return $text;
}

/**
 * Whether the generator has already created a given activity.
 *
 * @param int $courseid Course
 * @param string $idnumber Marker
 * @return bool
 */
function local_course_structure_exists($courseid, $idnumber) {
    global $DB;
    return $DB->record_exists('course_modules',
        array('course' => $courseid, 'idnumber' => $idnumber));
}

/**
 * Write a presentation's slides from the book, one request per chunk.
 *
 * Each chunk opens with a divider slide listing the lessons it covers. Those
 * lines are also how a finished deck is traced back to the book later, when
 * presentations are joined, so they are written as the book has them.
 *
 * @param string $bookpath Path to the book
 * @param int $offset Shift from printed to PDF page numbers
 * @param array $presentation One entry of plan_from_selection
 * @param callable $failed Told the error of each chunk that could not be written
 * @return array The slides, and how many chunks were missed
 */
function local_course_structure_write_slides($bookpath, $offset, $presentation, callable $failed) {
    $slides = array();
    $missed = 0;
    foreach ($presentation['chunks'] as $chunk) {
        try {
            $text = \mod_book\ai_service::extract_pages($bookpath,
                $chunk['from'] + $offset, $chunk['to'] + $offset);
            $slides[] = array(
                'heading' => $presentation['title'],
                'bullets' => $chunk['titles'],
            );
            $slides = array_merge($slides,
                \mod_book\ai_service::generate_slides($presentation['title'], $text));
        } catch (Exception $e) {
            $missed++;
            $failed($e->getMessage());
        }
    }
    return array($slides, $missed);
}

/**
 * Put a slide deck into the course as a file resource.
 *
 * @param stdClass $course The course
 * @param int $topic Section number to file it under
 * @param string $title Presentation title
 * @param string $subtitle Line under the title on the first slide
 * @param array $slides Slides, each with a heading and bullets
 * @param string $idnumber Marker the generator finds it by
 * @param string $intro Description shown on the course page, if any
 * @return int The new course module's id
 */
function local_course_structure_add_deck($course, $topic, $title, $subtitle, $slides, $idnumber, $intro = '') {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir . '/filelib.php');
    require_once($CFG->libdir . '/resourcelib.php');
    require_once($CFG->dirroot . '/course/modlib.php');

    $pdfpath = local_course_structure_workdir() . '/deck-' . uniqid() . '.pdf';
    \local_course_structure\slide_deck::render($title, $subtitle, $slides, $pdfpath);

    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname(array(
        'contextid' => context_user::instance($USER->id)->id,
        'component' => 'user',
        'filearea'  => 'draft',
        'itemid'    => $draftid,
        'filepath'  => '/',
        'filename'  => clean_param($title, PARAM_FILE) . '.pdf',
    ), $pdfpath);
    unlink($pdfpath);

    $module = $DB->get_record('modules', array('name' => 'resource'), '*', MUST_EXIST);

    $data = new stdClass();
    $data->course = $course->id;
    $data->modulename = 'resource';
    $data->module = $module->id;
    $data->section = $topic;
    $data->name = $title;
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
    $data->cmidnumber = $idnumber;
    $data->groupmode = 0;
    $data->groupingid = 0;
    $data->completion = 0;
    $data->completionview = 0;
    $data->completionexpected = 0;
    $data->availabilityconditionsjson = '';

    ob_start();
    try {
        $created = add_moduleinfo($data, $course);
    } finally {
        ob_end_clean();
    }
    return (int)$created->coursemodule;
}

/**
 * The slides of a deck the generator built, read back from its file.
 *
 * @param int $cmid The deck's course module
 * @return array Slides, each with a heading and bullets; empty if unreadable
 */
function local_course_structure_deck_slides($cmid) {
    $files = get_file_storage()->get_area_files(context_module::instance($cmid)->id,
        'mod_resource', 'content', 0, 'itemid', false);
    $file = reset($files);
    if (!$file) {
        return array();
    }
    // Named by content, so the reading kept for it is found again next time.
    $path = local_course_structure_workdir() . '/deck-' . $file->get_contenthash() . '.pdf';
    if (!file_exists($path)) {
        $file->copy_content_to($path);
    }
    return \local_course_structure\slide_deck::parse($path);
}

/**
 * Which of the book's sections a deck was built from.
 *
 * Every chunk of a deck opens with a divider slide naming the lessons it
 * covers - as its bullets now, and after a "·" in its heading in the earliest
 * decks - and a deck is always built from whole sections. A section counts as
 * covered when one of its lessons is named there exactly.
 *
 * @param array $slides The deck's slides
 * @param array $sections Result of list_sections
 * @return array Keys of the sections covered, in book order
 */
function local_course_structure_deck_sections($slides, $sections) {
    $norm = function($text) {
        return trim(preg_replace('/\s+/u', ' ', core_text::strtolower($text)), " .:-–—");
    };

    $named = array();
    foreach ($slides as $slide) {
        $lines = $slide['bullets'];
        $lines[] = $slide['heading'];
        foreach (preg_split('/\s+·\s+/u', $slide['heading']) as $part) {
            $lines[] = $part;
        }
        foreach ($lines as $line) {
            $named[$norm($line)] = true;
        }
    }

    $covered = array();
    foreach ($sections as $key => $section) {
        foreach ($section['lessons'] as $lesson) {
            if (isset($named[$norm($lesson['title'])])) {
                $covered[] = $key;
                break;
            }
        }
    }
    return $covered;
}

/**
 * Script for ticking a book's parts a chapter, or the whole book, at a time.
 *
 * A book can run to eighty parts. Each element marked lcs-ticks holds the
 * parts (lcs-section), a box per chapter (lcs-chapter) and one for the lot
 * (lcs-all), chapter and part matched by data-chapter. The chapter and whole
 * boxes show whether all, some or none of theirs are ticked, and tick or clear
 * all of it when clicked. Each lcs-ticks is separate, as in the Presentations
 * wizard, where every presentation has its own list.
 *
 * @return string A script element
 */
function local_course_structure_tick_script() {
    return '<script>
document.querySelectorAll(".lcs-ticks").forEach(function(root){
 var parts=[].slice.call(root.querySelectorAll(".lcs-section"));
 var chapters=[].slice.call(root.querySelectorAll(".lcs-chapter"));
 var all=root.querySelector(".lcs-all");
 function show(box,list){
  var on=list.filter(function(c){return c.checked;}).length;
  box.checked=list.length>0&&on===list.length;
  box.indeterminate=on>0&&on<list.length;
 }
 function of(chapter){
  return parts.filter(function(c){return c.dataset.chapter===chapter.dataset.chapter;});
 }
 function refresh(){
  chapters.forEach(function(chapter){show(chapter,of(chapter));});
  if(all){show(all,parts);}
 }
 chapters.forEach(function(chapter){
  chapter.addEventListener("change",function(){
   of(chapter).forEach(function(c){c.checked=chapter.checked;});
   refresh();
  });
 });
 if(all){
  all.addEventListener("change",function(){
   parts.forEach(function(c){c.checked=all.checked;});
   refresh();
  });
 }
 parts.forEach(function(c){c.addEventListener("change",refresh);});
 refresh();
});
</script>';
}

/**
 * Say that nothing has been built yet, and where to go and build it.
 *
 * @param int $courseid Course
 * @return void
 */
function local_course_structure_no_decks($courseid) {
    global $OUTPUT;

    echo '<p class="text-muted">' .
         get_string('nodecksyet', 'local_course_structure') . '</p>';
    echo '<a class="btn btn-primary" href="' .
         new moodle_url('/local/course_structure/lectures.php', array('id' => $courseid)) .
         '">' . get_string('tablectures', 'local_course_structure') . '</a>';
}

/**
 * Report progress to whoever started the work, as a page's notifications.
 *
 * Every piece of work below says how it is going through a callback taking the
 * message, as HTML, and its kind: 'info', 'error' or 'success'. On a page that is
 * a notification, flushed so it shows while the rest is still being done; in the
 * background it is a line in the job's log.
 *
 * @return callable
 */
function local_course_structure_page_say() {
    return function($message, $type = 'info') {
        global $OUTPUT;
        $kinds = array('info' => 'notifymessage', 'error' => 'notifyerror', 'success' => 'notifysuccess');
        echo $OUTPUT->notification($message, $kinds[$type] ?? 'notifymessage');
        flush();
    };
}

/**
 * The course's topics by number, as the course page names them.
 *
 * @param stdClass $course The course
 * @return array Names keyed by section number
 */
function local_course_structure_course_topics($course) {
    $topics = array();
    foreach (get_fast_modinfo($course)->get_section_info_all() as $info) {
        $topics[(int)$info->section] = get_section_name($course, $info);
    }
    return $topics;
}

/**
 * Each named course section's number, by name, to match a book chapter to it.
 *
 * @param int $courseid Course
 * @return array Section numbers keyed by trimmed name
 */
function local_course_structure_sections_by_name($courseid) {
    global $DB;
    $numbers = array();
    foreach ($DB->get_records('course_sections', array('course' => $courseid)) as $section) {
        if ($section->name !== null && trim($section->name) !== '') {
            $numbers[trim($section->name)] = (int)$section->section;
        }
    }
    return $numbers;
}

/**
 * The stored book, read through, with its outline and its parts.
 *
 * @param context_course $context Course context
 * @return array Path to the book, its outline, and the parts presentations are built from
 * @throws Exception if no book is stored or it is still being read
 */
function local_course_structure_open_book($context) {
    $book = local_course_structure_stored_book($context);
    if (!$book) {
        throw new Exception(get_string('nobookstored', 'local_course_structure'));
    }
    if (!\mod_book\ai_service::reading_progress(basename($book[0], '.pdf'))['done']) {
        throw new Exception(get_string('readingnotdone', 'local_course_structure'));
    }
    $outline = \mod_book\ai_service::get_outline($book[0]);
    return array($book[0], $outline, \mod_book\ai_service::list_sections($outline));
}

/**
 * Name the course's topics after a list of chapters.
 *
 * @param int $courseid Course
 * @param array $chapters Chapters, each with a title and possibly a summary
 * @param bool $includesubtopics Whether to list sub-topics under each topic
 * @return void
 */
function local_course_structure_apply($courseid, $chapters, $includesubtopics) {
    global $DB;

    $existing = array_values($DB->get_records('course_sections',
        array('course' => $courseid), 'section ASC'));

    $index = 1; // Section 0 is the general section and is left alone.

    foreach ($chapters as $chapter) {
        if (trim($chapter['title']) === '') {
            continue;
        }

        $summary = ($includesubtopics && !empty($chapter['summary']))
            ? $chapter['summary'] : '';

        if (isset($existing[$index])) {
            $section = $existing[$index];
            $section->name = $chapter['title'];
            $section->summary = $summary;
            $section->summaryformat = FORMAT_HTML;
            $DB->update_record('course_sections', $section);
        } else {
            $section = new stdClass();
            $section->course = $courseid;
            $section->section = $index;
            $section->name = $chapter['title'];
            $section->summary = $summary;
            $section->summaryformat = FORMAT_HTML;
            $section->sequence = '';
            $section->visible = 1;
            $section->timemodified = time();
            $DB->insert_record('course_sections', $section);
        }

        $index++;
    }

    // Chapter names from an earlier run would otherwise linger on sections the new
    // structure no longer reaches. Sections holding activities are left alone.
    for ($i = $index; $i < count($existing); $i++) {
        $leftover = $existing[$i];
        if (!empty($leftover->sequence)) {
            continue;
        }
        $leftover->name = null;
        $leftover->summary = '';
        $DB->update_record('course_sections', $leftover);
    }

    rebuild_course_cache($courseid, true);
}

/**
 * Build presentations from the book, as the Presentations tab does.
 *
 * The daily token allowance is small enough that a run can stop part way
 * through, so anything already built is kept and only the gaps are filled.
 * Presentations are marked by their position in the run, so a regrouping would
 * otherwise be skipped as already built; $replace clears the earlier run first.
 *
 * @param stdClass $course The course
 * @param array $groups Each with a 'title', the 'sections' (keys) it covers and
 *                      the 'topic' (section number) to file it under, or null
 * @param bool $sources Whether to add English Wikipedia links under each one
 * @param bool $replace Whether to delete the earlier run's presentations first
 * @param callable $say Progress, see local_course_structure_page_say()
 * @param int $first Position of the first presentation: the tab starts at 0, so
 *                   that a stopped run resumes; presentations the assistant adds
 *                   start after those already built
 * @return int Presentations added
 * @throws Exception if the book is not ready or nothing was chosen
 */
function local_course_structure_build_presentations($course, $groups, $sources, $replace, callable $say, $first = 0) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/local/course_structure/classes/sources.php');

    $courseid = (int)$course->id;
    list($bookpath, $outline) = local_course_structure_open_book(context_course::instance($courseid));
    $coursetopics = local_course_structure_course_topics($course);
    $sectionof = local_course_structure_sections_by_name($courseid);

    foreach ($groups as $i => $group) {
        // Anything that is not one of the course's topics falls back to the topic
        // named after the first section's chapter.
        if (!isset($group['topic']) || !isset($coursetopics[$group['topic']])) {
            $groups[$i]['topic'] = null;
        }
    }

    $plan = array();
    foreach (\mod_book\ai_service::plan_from_selection($outline, $groups) as $index => $presentation) {
        $plan[(int)$first + $index] = $presentation;
    }
    if (empty($plan)) {
        throw new Exception(get_string('nothingselected', 'local_course_structure'));
    }

    // Exams are course-wide and may already have attempts against them, so they
    // are left for the teacher to remove.
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
            $say(get_string('replacedold', 'local_course_structure', count($old)));
        }
    }

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
        $say(get_string('alreadybuilt', 'local_course_structure', count($existing)));
    }

    $made = 0;
    foreach ($plan as $index => $presentation) {
        if (isset($existing[$index])) {
            continue;
        }

        $say(get_string('generatingnum', 'local_course_structure', (object)array(
            'n' => $index - (int)$first + 1, 'total' => count($plan), 'title' => s($presentation['title']))));

        list($slides, $missed) = local_course_structure_write_slides($bookpath, $outline['offset'],
            $presentation, function($error) use ($say, $presentation) {
                $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($presentation['title']), 'error' => s($error))), 'error');
            });

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
                    $topics[] = $lesson;
                }
            }
            $intro = local_course_structure_intro_block('reading',
                local_course_structure_reading_html(\local_course_structure\sources::lookup(
                    local_course_structure_search_topics($topics))));
        }

        // Replace an earlier incomplete attempt at this same presentation.
        if (isset($partial[$index])) {
            ob_start();
            course_delete_module($partial[$index]);
            ob_end_clean();
        }

        try {
            $topic = $presentation['topic'] ?? ($sectionof[trim($presentation['chapter'])]
                ?? ($presentation['chapterindex'] + 1));

            // The deck is labelled with the topic it is filed under, which for a
            // presentation drawing on several chapters says more than the first.
            local_course_structure_add_deck($course, $topic, $presentation['title'],
                $coursetopics[$topic] ?? $presentation['chapter'], $slides,
                'csgen-' . $index . ($missed > 0 ? '-part' : ''), $intro);
            $made++;

        } catch (Exception $e) {
            $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($presentation['title']), 'error' => s($e->getMessage()))), 'error');
        }
    }

    rebuild_course_cache($courseid, true);
    $say(get_string('lecturesdone', 'local_course_structure', $made), 'success');
    return $made;
}

/**
 * The book sections a set of presentations were built from.
 *
 * @param array $cmids The presentations
 * @param array $decks Result of local_course_structure_decks
 * @param array $sections Result of list_sections
 * @return array Sections covered (keys as keys), the names of the presentations
 *               each came from, and the names of any that could not be traced
 */
function local_course_structure_trace_decks($cmids, $decks, $sections) {
    $covered = array();
    $from = array();
    $untraced = array();
    foreach ($cmids as $cmid) {
        $slides = local_course_structure_deck_slides($cmid);
        $found = $slides ? local_course_structure_deck_sections($slides, $sections) : array();
        if (empty($found)) {
            $untraced[] = $decks[$cmid]->name;
        }
        foreach ($found as $key) {
            $covered[$key] = true;
            $from[$key][] = $decks[$cmid]->name;
        }
    }
    return array($covered, $from, $untraced);
}

/**
 * Join presentations into one, written afresh from the book pages they cover.
 *
 * @param stdClass $course The course
 * @param array $chosen Course module ids of the presentations, two or more
 * @param array|null $picked Book section keys to write from, or null for the ones
 *                           the presentations were built from
 * @param string $title Title of the new presentation, '' for the old ones' names joined
 * @param int $topic Section number to file it under, -1 for the first one's
 * @param bool $hide Whether to hide the originals from students
 * @param callable $say Progress, see local_course_structure_page_say()
 * @return int The new presentation's course module id
 * @throws Exception if it cannot be written
 */
function local_course_structure_join_decks($course, $chosen, $picked, $title, $topic, $hide, callable $say) {
    global $CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    $courseid = (int)$course->id;
    $decks = local_course_structure_decks($courseid);
    $coursetopics = local_course_structure_course_topics($course);

    $chosen = array_values(array_intersect(array_unique($chosen), array_keys($decks)));
    if (count($chosen) < 2) {
        throw new Exception(get_string('joinneedtwo', 'local_course_structure'));
    }

    list($bookpath, $outline, $sections) = local_course_structure_open_book(context_course::instance($courseid));

    if ($picked === null) {
        list($covered, , $untraced) = local_course_structure_trace_decks($chosen, $decks, $sections);
        foreach ($untraced as $name) {
            $say(get_string('joinnotraced', 'local_course_structure', s($name)));
        }
        $picked = array_keys($covered);
    }
    $picked = array_values(array_filter(array_unique($picked), function($key) use ($sections) {
        return isset($sections[$key]);
    }));
    sort($picked);
    if (empty($picked)) {
        throw new Exception(get_string('joinnosections', 'local_course_structure'));
    }
    if (trim($title) === '') {
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

    $say(get_string('joinwriting', 'local_course_structure', (object)array(
        'title' => s($title), 'requests' => count($presentation['chunks']) + 1)));

    list($slides, $missed) = local_course_structure_write_slides($bookpath, $outline['offset'],
        $presentation, function($error) use ($say, $title) {
            $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($title), 'error' => s($error))), 'error');
        });

    if (empty($slides)) {
        throw new Exception(get_string('joinfailed', 'local_course_structure', s($title)));
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
        $say(get_string('joinunified', 'local_course_structure', (object)array(
            'before' => $written, 'after' => count($slides))));
    } catch (Exception $e) {
        // Written from the book part by part, the deck is complete without this
        // pass; it only reads less as one lecture.
        $say(get_string('joinnotunified', 'local_course_structure', s($e->getMessage())));
    }

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

    $say(get_string('joined', 'local_course_structure', (object)array(
        'title' => s($title), 'slides' => count($slides), 'topic' => s($coursetopics[$topic] ?? ''))),
        'success');
    if ($hide) {
        $say(get_string('joinhidden', 'local_course_structure', count($chosen)));
    }
    return $cmid;
}

/**
 * Rewrite a presentation to a teacher's instruction, keeping its place in the course.
 *
 * @param stdClass $course The course
 * @param int $cmid The presentation
 * @param string $request What should change
 * @param callable $say Progress, see local_course_structure_page_say()
 * @return array Slides before and after
 * @throws Exception if the presentation cannot be read or rewritten
 */
function local_course_structure_revise_deck($course, $cmid, $request, callable $say) {
    $decks = local_course_structure_decks($course->id);
    if (!isset($decks[$cmid])) {
        throw new Exception(get_string('nosuchdeck', 'local_course_structure'));
    }
    $deck = $decks[$cmid];
    if (trim($request) === '') {
        throw new Exception(get_string('reviserequest', 'local_course_structure'));
    }

    // The presentation itself is the record of what it says, so it is read back
    // rather than built from the book again.
    $current = local_course_structure_deck_slides($cmid);
    if (empty($current)) {
        throw new Exception(get_string('deckunreadable', 'local_course_structure', s($deck->name)));
    }

    $revised = \mod_book\ai_service::revise_slides($deck->name, $current, $request);

    $newpath = local_course_structure_workdir() . '/revised-' . uniqid() . '.pdf';
    \local_course_structure\slide_deck::render($deck->name, $deck->chapter, $revised, $newpath);

    // The presentation keeps its place in the course; only its file changes.
    $fs = get_file_storage();
    $modcontext = context_module::instance($cmid);
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

    rebuild_course_cache($course->id, true);

    $say(get_string('revised', 'local_course_structure', (object)array(
        'title' => s($deck->name), 'before' => count($current), 'after' => count($revised))), 'success');

    // Anything made from the old slides is now out of step with them.
    if ($deck->haspptx || local_course_structure_deck_has_quiz($course->id, $deck)) {
        $say(get_string('revisestale', 'local_course_structure'));
    }
    return array(count($current), count($revised));
}

/**
 * Add editable PowerPoint versions of presentations, rebuilt from their PDFs.
 *
 * @param stdClass $course The course
 * @param array|null $cmids The presentations, or null for every one without one
 * @param callable $say Progress, see local_course_structure_page_say()
 * @return int PowerPoint files added
 */
function local_course_structure_convert_decks($course, $cmids, callable $say) {
    global $CFG, $DB, $USER;
    require_once($CFG->libdir . '/filelib.php');
    require_once($CFG->libdir . '/resourcelib.php');
    require_once($CFG->dirroot . '/course/modlib.php');
    require_once($CFG->dirroot . '/local/course_structure/classes/pptx_deck.php');

    $fs = get_file_storage();
    $workdir = local_course_structure_workdir();
    $decks = local_course_structure_decks($course->id);
    $targets = ($cmids === null) ? $decks : array_intersect_key($decks, array_flip($cmids));
    $built = 0;

    foreach ($targets as $deck) {
        if ($deck->haspptx) {
            continue;
        }

        try {
            // Rebuilding from the PDF keeps this free of any AI request.
            $slides = local_course_structure_deck_slides($deck->cmid);
            if (empty($slides)) {
                throw new Exception('no slides could be read back from the PDF');
            }

            $pptxpath = $workdir . '/deck-' . uniqid() . '.pptx';
            \local_course_structure\pptx_deck::render($deck->name, $deck->chapter, $slides, $pptxpath);

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
            try {
                add_moduleinfo($data, $course);
            } finally {
                ob_end_clean();
            }
            $built++;

            $say(get_string('converted', 'local_course_structure', s($deck->name)), 'success');

        } catch (Exception $e) {
            $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($deck->name), 'error' => s($e->getMessage()))), 'error');
        }
    }

    rebuild_course_cache($course->id, true);
    $say(get_string('convertdone', 'local_course_structure', $built), 'success');
    return $built;
}

/**
 * What a quiz on a presentation is written from: its slides, and the book pages it was built from.
 *
 * @param stdClass $deck The presentation, from local_course_structure_decks
 * @param array|null $book Result of local_course_structure_open_book, or null to use the slides alone
 * @param callable $say Progress
 * @return array The slides as text, and the book's pages as text ('' if none)
 */
function local_course_structure_quiz_material($deck, $book, callable $say) {
    $slides = local_course_structure_deck_text(get_file_storage(), $deck->cmid, local_course_structure_workdir());
    if ($book === null) {
        return array($slides, '');
    }

    list($bookpath, $outline, $sections) = $book;
    list($covered) = local_course_structure_trace_decks(array($deck->cmid), array($deck->cmid => $deck), $sections);
    if (empty($covered)) {
        $say(get_string('quiznotraced', 'local_course_structure', s($deck->name)));
        return array($slides, '');
    }
    $plan = \mod_book\ai_service::plan_from_selection($outline,
        array(array('title' => $deck->name, 'sections' => array_keys($covered))));
    $text = '';
    foreach (reset($plan)['chunks'] ?? array() as $chunk) {
        $text .= \mod_book\ai_service::extract_pages($bookpath, $chunk['from'] + $outline['offset'],
            $chunk['to'] + $outline['offset']) . "\n";
    }
    return array($slides, trim($text));
}

/**
 * Build a practice quiz or an exam from the presentations chosen.
 *
 * The questions are written presentation by presentation, a few at a time, each
 * request given the slides and a different stretch of the book pages behind
 * them, so a long chapter is asked about throughout rather than only where it
 * starts. Each question is given its type beforehand, spreading the types the
 * teacher chose evenly over the quiz.
 *
 * @param stdClass $course The course
 * @param array $options 'kind' (practice or exam), 'cmids', 'count' (questions to write), 'shown' (an exam's
 *                       questions per attempt, 0 for half), 'types', 'instructions', 'name' ('' for a default),
 *                       'topic' (section number, -1 for the default), 'usebook', 'timelimit' (minutes),
 *                       'attempts' (0 for unlimited)
 * @param callable $say Progress, see local_course_structure_page_say()
 * @return int The new quiz's course module id
 * @throws Exception if nothing could be built
 */
function local_course_structure_build_quiz($course, $options, callable $say) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/local/course_structure/classes/quiz_builder.php');

    $courseid = (int)$course->id;
    $context = context_course::instance($courseid);
    $exam = ($options['kind'] ?? '') === 'exam';
    $decks = local_course_structure_decks($courseid);
    $chosen = array_values(array_intersect(array_map('intval', (array)($options['cmids'] ?? array())), array_keys($decks)));
    if (empty($chosen)) {
        throw new Exception(get_string('quiznodecks', 'local_course_structure'));
    }
    $types = array_values(array_intersect(\local_course_structure\quiz_builder::TYPES, (array)($options['types'] ?? array())));
    if (empty($types)) {
        $types = array('multichoice');
    }
    $count = max(1, min(LOCAL_COURSE_STRUCTURE_QUIZ_MAX, (int)($options['count'] ?? 10)));
    $instructions = trim(core_text::substr((string)($options['instructions'] ?? ''), 0, 500));

    // The book, unless it is not wanted or not ready, in which case the slides do.
    $book = null;
    if (!empty($options['usebook'])) {
        try {
            $book = local_course_structure_open_book($context);
        } catch (Exception $e) {
            $say(get_string('quiznobook', 'local_course_structure', s($e->getMessage())));
        }
    }

    // One type per question, the chosen types taking turns. A random short-answer
    // matching question is one question however many terms it draws on.
    $slots = array();
    $matching = in_array('randomsamatch', $types, true);
    $written = array_values(array_diff($types, array('randomsamatch')));
    for ($i = 0; count($slots) < $count - ($matching ? 1 : 0); $i++) {
        $slots[] = $written ? $written[$i % count($written)] : 'multichoice';
    }
    shuffle($slots);

    // Shared out over the presentations as evenly as it goes.
    $share = array();
    foreach ($slots as $i => $type) {
        $share[$chosen[$i % count($chosen)]][] = $type;
    }
    if ($matching) {
        $share[$chosen[0]][] = 'termpairs';
    }

    $questions = array();
    $pairs = array();
    $pairstitle = '';
    foreach ($chosen as $cmid) {
        $deck = $decks[$cmid];
        if (empty($share[$cmid])) {
            continue;
        }
        try {
            list($slides, $booktext) = local_course_structure_quiz_material($deck, $book, $say);
        } catch (Exception $e) {
            $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($deck->name), 'error' => s($e->getMessage()))), 'error');
            continue;
        }
        if (trim($slides . $booktext) === '') {
            $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                'title' => s($deck->name), 'error' => 'the presentation has no readable text')), 'error');
            continue;
        }

        // Within the free tier's tokens a minute: the slides' outline, and a
        // different stretch of the book for each request.
        $stretches = ($booktext === '') ? array('') : local_course_structure_split_text($booktext, LOCAL_COURSE_STRUCTURE_QUIZ_BOOK_CHARS);
        $batches = array_chunk($share[$cmid], LOCAL_COURSE_STRUCTURE_QUIZ_BATCH);
        foreach ($batches as $number => $batch) {
            $say(get_string('quizwriting', 'local_course_structure', (object)array(
                'deck' => s($deck->name), 'count' => count($batch), 'part' => $number + 1, 'parts' => count($batches))));
            $stretch = $stretches[(int)floor($number * count($stretches) / count($batches))];
            $material = "SLIDES:\n" . core_text::substr($slides, 0, $stretch === '' ? 6000 : 1500) .
                ($stretch === '' ? '' : "\n\nFROM THE TEXTBOOK:\n" . $stretch);
            try {
                // What earlier requests asked, briefly, so that this one asks about something else.
                $asked = array_map(function($question) {
                    return core_text::substr(preg_replace('/\[\[\d+\]\]/', '___', $question['question']), 0, 120);
                }, $questions);
                foreach (\mod_book\ai_service::write_quiz_questions($deck->name, $material, $batch, $instructions,
                        $asked) as $raw) {
                    $question = \local_course_structure\quiz_builder::clean_question($raw);
                    if ($question === null) {
                        continue;
                    }
                    if ($question['type'] === 'termpairs') {
                        $pairs += $question['pairs'];
                        $pairstitle = $question['question'];
                    } else if ($question['type'] !== 'randomsamatch') {
                        $questions[] = $question;
                    }
                }
            } catch (Exception $e) {
                $say(get_string('lecturefailed', 'local_course_structure', (object)array(
                    'title' => s($deck->name), 'error' => s($e->getMessage()))), 'error');
            }
        }
    }

    // The model writes more than was asked now and then; never a quiz longer than chosen.
    $questions = array_slice($questions, 0, $count);
    if (empty($questions)) {
        throw new Exception(get_string('quiznoquestions', 'local_course_structure'));
    }

    // Name, place and marker.
    $names = array_map(function($cmid) use ($decks) {
        return $decks[$cmid]->name;
    }, $chosen);
    $marker = '';
    if ($exam) {
        for ($n = 1; local_course_structure_exists($courseid, 'csgenexam-' . $n); $n++) {
            continue;
        }
        $marker = 'csgenexam-' . $n;
        $default = get_string('examname', 'local_course_structure', $n);
    } else {
        if (count($chosen) === 1 && !local_course_structure_exists($courseid, 'csgenlab-' . $decks[$chosen[0]]->index)) {
            $marker = 'csgenlab-' . $decks[$chosen[0]]->index;
        }
        $default = core_text::substr(implode(', ', $names), 0, 200) . ' ' .
            get_string('labquizsuffix', 'local_course_structure');
    }
    if ($marker === '') {
        for ($n = 1; local_course_structure_exists($courseid, 'csgenquiz-' . $n); $n++) {
            continue;
        }
        $marker = 'csgenquiz-' . $n;
    }
    $name = trim((string)($options['name'] ?? '')) ?: $default;
    $topics = local_course_structure_course_topics($course);
    $topic = (int)($options['topic'] ?? -1);
    if ($topic < 0 || ($topic !== 0 && !isset($topics[$topic]))) {
        $topic = $exam ? 0 : (int)$decks[$chosen[0]]->section;
    }

    // Into the question bank, the matching question's terms in a category of their
    // own so that it draws on them alone.
    $categoryid = \local_course_structure\quiz_builder::make_category($context, $name);
    $ids = \local_course_structure\quiz_builder::import_xml(
        \local_course_structure\quiz_builder::to_xml($questions), $categoryid, $course, $context);
    if (empty($ids)) {
        throw new Exception(get_string('quiznoquestions', 'local_course_structure'));
    }
    $matchid = null;
    if ($matching && count($pairs) >= 3) {
        $poolid = \local_course_structure\quiz_builder::make_category($context,
            $name . ' ' . get_string('quizterms', 'local_course_structure'), $categoryid);
        $pool = array();
        foreach ($pairs as $ask => $answer) {
            $pool[] = array('type' => 'shortanswer', 'question' => $ask, 'answers' => array($answer), 'feedback' => '');
        }
        $poolids = \local_course_structure\quiz_builder::import_xml(\local_course_structure\quiz_builder::to_xml($pool,
            min(5, count($pool)), $pairstitle !== '' ? $pairstitle
                : get_string('quiztermsquestion', 'local_course_structure')), $poolid, $course, $context);
        $matchid = end($poolids) ?: null;
    } else if ($matching) {
        $say(get_string('quiznoterms', 'local_course_structure'));
    }

    $total = count($ids) + ($matchid ? 1 : 0);
    $shown = $total;
    if ($exam) {
        $shown = (int)($options['shown'] ?? 0);
        $shown = ($shown > 0) ? min($shown, $total) : max(1, (int)ceil($total / 2));
    }

    $intro = '<p>' . get_string('quizcovers', 'local_course_structure', s(implode(', ', $names))) . '</p>';
    $quiz = \local_course_structure\quiz_builder::make_quiz($course, $topic, $name, $marker, $shown, array(
        // Practice checks each answer as it is given; an exam is marked at the end.
        'behaviour' => $exam ? 'deferredfeedback' : 'immediatefeedback',
        'attempts' => $exam ? max(0, (int)($options['attempts'] ?? 1)) : 0,
        'timelimit' => $exam ? max(0, (int)($options['timelimit'] ?? 0)) * MINSECS : 0,
        'intro' => $intro,
    ));

    if ($exam) {
        // Each attempt draws its own paper from the pool; the matching question,
        // which is random already, is on every one.
        $random = $shown - ($matchid ? 1 : 0);
        if ($random > 0) {
            \local_course_structure\quiz_builder::add_random($quiz, $categoryid, min($random, count($ids)));
        }
        if ($matchid) {
            \local_course_structure\quiz_builder::add_questions($quiz, array($matchid));
        }
    } else {
        \local_course_structure\quiz_builder::add_questions($quiz, array_merge($ids, $matchid ? array($matchid) : array()));
    }
    \local_course_structure\quiz_builder::shuffle_questions($quiz);
    \local_course_structure\quiz_builder::finalise($quiz);

    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $courseid, false, MUST_EXIST);
    local_course_structure_remember_quiz($courseid, (int)$cm->id, $exam ? 'exam' : 'practice', $chosen);
    rebuild_course_cache($courseid, true);

    $kinds = array_count_values(array_column($questions, 'type'));
    if ($matchid) {
        $kinds['randomsamatch'] = 1;
    }
    $typenames = \local_course_structure\quiz_builder::type_names();
    $say(get_string($exam ? 'quizbuiltexam' : 'quizbuilt', 'local_course_structure', (object)array(
        'name' => s($name), 'count' => $total, 'shown' => $shown,
        'types' => s(implode(', ', array_map(function($type, $n) use ($typenames) {
            return $typenames[$type] . ' ' . $n;
        }, array_keys($kinds), $kinds))),
    )), 'success');
    if ($total < $count) {
        $say(get_string('quizshort', 'local_course_structure', (object)array('asked' => $count, 'got' => $total)));
    }
    return (int)$cm->id;
}

/**
 * Split text into pieces of about a given length, at line ends where it can.
 *
 * @param string $text Text
 * @param int $length Characters per piece
 * @return array Pieces
 */
function local_course_structure_split_text($text, $length) {
    $pieces = array();
    $current = '';
    foreach (preg_split('/\n/u', $text) as $line) {
        if ($current !== '' && core_text::strlen($current) + core_text::strlen($line) > $length) {
            $pieces[] = $current;
            $current = '';
        }
        $current .= core_text::substr($line, 0, $length) . "\n";
    }
    if (trim($current) !== '') {
        $pieces[] = $current;
    }
    return $pieces ?: array('');
}

/**
 * Note which presentations a quiz was written from.
 *
 * @param int $courseid Course
 * @param int $cmid The quiz
 * @param string $kind practice or exam
 * @param array $decks Presentations' course module ids
 */
function local_course_structure_remember_quiz($courseid, $cmid, $kind, $decks) {
    $quizzes = local_course_structure_quizzes($courseid);
    $quizzes[$cmid] = array('kind' => $kind, 'decks' => array_values(array_map('intval', $decks)));
    set_config('quizzes_' . $courseid, json_encode($quizzes), 'local_course_structure');
}

/**
 * The quizzes the generator has built that are still in the course.
 *
 * @param int $courseid Course
 * @return array Keyed by course module id: 'kind' and 'decks'
 */
function local_course_structure_quizzes($courseid) {
    $quizzes = json_decode((string)get_config('local_course_structure', 'quizzes_' . $courseid), true);
    if (!is_array($quizzes)) {
        return array();
    }
    $modinfo = get_fast_modinfo($courseid);
    return array_filter($quizzes, function($cmid) use ($modinfo) {
        $cms = $modinfo->get_cms();
        return isset($cms[$cmid]) && !$cms[$cmid]->deletioninprogress;
    }, ARRAY_FILTER_USE_KEY);
}

/**
 * Whether any quiz the generator built was written from a presentation.
 *
 * @param int $courseid Course
 * @param stdClass $deck The presentation
 * @return bool
 */
function local_course_structure_deck_has_quiz($courseid, $deck) {
    if (local_course_structure_exists($courseid, 'csgenlab-' . $deck->index)) {
        return true;
    }
    foreach (local_course_structure_quizzes($courseid) as $quiz) {
        if (in_array((int)$deck->cmid, $quiz['decks'], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Topics worth searching for, from titles as the book writes them.
 *
 * @param array $titles Presentation and lesson titles
 * @return array The same without the book's numbering, which is no help to a search
 */
function local_course_structure_search_topics($titles) {
    return array_values(array_unique(array_filter(array_map(function($title) {
        return trim(preg_replace('/^\s*\d+[\.\)]?\s*/u', '', (string)$title));
    }, $titles), 'strlen')));
}

/**
 * A list of English Wikipedia links, for under a presentation.
 *
 * @param array $links Links, each with a title and url, as sources::lookup gives them
 * @return string HTML, or '' if there are none
 */
function local_course_structure_reading_html($links) {
    if (empty($links)) {
        return '';
    }
    $html = '<p>' . get_string('furtherreading', 'local_course_structure') . '</p><ul>';
    foreach ($links as $link) {
        $html .= '<li><a href="' . s($link['url']) . '" target="_blank" rel="noopener noreferrer">' .
                 s($link['title']) . '</a></li>';
    }
    return $html . '</ul>';
}

/**
 * Wrap a part of a presentation's description so it can be found and replaced.
 *
 * A presentation's description, shown under it on the course page, holds its
 * further reading and its videos, each of which can be changed on its own. The
 * marks are HTML comments, which the course page does not show.
 *
 * @param string $block 'reading' or 'videos'
 * @param string $html The part's HTML, or '' for none
 * @return string
 */
function local_course_structure_intro_block($block, $html) {
    return ($html === '') ? '' : '<!-- lcs:' . $block . ' -->' . $html . '<!-- /lcs:' . $block . ' -->';
}

/**
 * Put one part of a presentation's description in place, keeping the rest.
 *
 * @param int $cmid The presentation
 * @param string $block 'reading' or 'videos'
 * @param string $html The part's new HTML, or '' to take it out
 */
function local_course_structure_set_intro_block($cmid, $block, $html) {
    global $DB;

    $cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
    $intro = (string)$DB->get_field('resource', 'intro', array('id' => $cm->instance));

    $start = '<!-- lcs:' . $block . ' -->';
    $end = '<!-- /lcs:' . $block . ' -->';
    $intro = preg_replace('/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s', '', $intro);

    // Reading added before the parts were marked is the whole of a description
    // the generator wrote, starting with its heading.
    if ($block === 'reading') {
        $legacy = '<p>' . get_string('furtherreading', 'local_course_structure') . '</p><ul>';
        $from = strpos($intro, $legacy);
        $to = ($from === false) ? false : strpos($intro, '</ul>', $from);
        if ($to !== false) {
            $intro = substr($intro, 0, $from) . substr($intro, $to + 5);
        }
    }

    // Reading, then links, then videos, whichever was added first.
    $part = local_course_structure_intro_block($block, $html);
    $order = array('reading', 'links', 'videos');
    $at = false;
    foreach (array_slice($order, array_search($block, $order, true) + 1) as $later) {
        $at = strpos($intro, '<!-- lcs:' . $later . ' -->');
        if ($at !== false) {
            break;
        }
    }
    if ($at !== false) {
        $intro = substr($intro, 0, $at) . $part . substr($intro, $at);
    } else {
        $intro .= $part;
    }

    // Shown only when there is something to show. The course page goes by the
    // course module's showdescription; the file's own page by printintro, which
    // is kept inside displayoptions (there is no printintro column).
    $show = (trim(preg_replace('/<!--.*?-->/s', '', $intro)) === '') ? 0 : 1;
    $options = (string)$DB->get_field('resource', 'displayoptions', array('id' => $cm->instance));
    $options = ($options === '') ? false : unserialize_array($options);
    $options = is_array($options) ? $options : array('printintro' => 0, 'showsize' => 1);
    $options['printintro'] = $show;

    $DB->update_record('resource', (object)array(
        'id' => $cm->instance,
        'intro' => $intro,
        'introformat' => FORMAT_HTML,
        'displayoptions' => serialize($options),
        'timemodified' => time(),
    ));
    $DB->set_field('course_modules', 'showdescription', $show, array('id' => $cmid));
    rebuild_course_cache($cm->course, true);
}

/**
 * Whether a presentation's description lists further reading.
 *
 * @param string $intro The description
 * @return bool
 */
function local_course_structure_has_reading($intro) {
    return strpos((string)$intro, '<!-- lcs:reading -->') !== false ||
        strpos((string)$intro, '<p>' . get_string('furtherreading', 'local_course_structure') . '</p>') !== false;
}

/**
 * Look up English Wikipedia reading for presentations already in the course.
 *
 * The topics are the presentation's title and the book lessons its divider
 * slides name, as when the reading is added while the presentation is built.
 * A presentation whose lookup finds nothing keeps any reading it had.
 *
 * @param stdClass $course The course
 * @param array|null $cmids The presentations, or null for all of them
 * @param callable $say Progress, see local_course_structure_page_say()
 * @return int Presentations given reading
 */
function local_course_structure_add_reading($course, $cmids, callable $say) {
    global $CFG;
    require_once($CFG->dirroot . '/local/course_structure/classes/sources.php');

    $decks = local_course_structure_decks($course->id);
    if ($cmids !== null) {
        $decks = array_intersect_key($decks, array_flip($cmids));
    }

    $done = 0;
    foreach ($decks as $deck) {
        $titles = array($deck->name);
        foreach (local_course_structure_deck_slides($deck->cmid) as $slide) {
            $parts = preg_split('/\s+·\s+/u', $slide['heading']);
            if (trim($parts[0]) === trim($deck->name)) {
                $titles = array_merge($titles, $slide['bullets'], array_slice($parts, 1));
            }
        }

        $links = \local_course_structure\sources::lookup(local_course_structure_search_topics($titles));
        if (empty($links)) {
            $say(get_string('readingnone', 'local_course_structure', s($deck->name)));
            continue;
        }
        local_course_structure_set_intro_block($deck->cmid, 'reading', local_course_structure_reading_html($links));
        $say(get_string('readingadded', 'local_course_structure', (object)array(
            'title' => s($deck->name), 'count' => count($links))), 'success');
        $done++;
    }
    return $done;
}
