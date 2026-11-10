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

/** Questions in each lecture's lab quiz. */
define('LOCAL_COURSE_STRUCTURE_LAB_QUESTIONS', 5);

/** Questions written per lecture towards an exam pool. */
define('LOCAL_COURSE_STRUCTURE_EXAM_PER_LECTURE', 10);

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
