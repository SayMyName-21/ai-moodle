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
