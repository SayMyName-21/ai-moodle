<?php
// This file is part of Moodle - http://moodle.org/
//
// Course Structure from Book PDF
// Upload a PDF book, review the chapters it found, then build the course topics.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');

$courseid = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$retry = optional_param('retry', 0, PARAM_BOOL);
$guidance = trim(optional_param('guidance', '', PARAM_TEXT));
$edited = optional_param('titles', '', PARAM_RAW);

// Ticked by default, but an unticked box submits nothing, so only trust this on POST.
$includesubtopics = ($_SERVER['REQUEST_METHOD'] === 'POST')
    ? optional_param('includesubtopics', 0, PARAM_BOOL)
    : 1;

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('moodle/course:update', $context);

$PAGE->set_url('/local/course_structure/index.php', array('id' => $courseid));
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursestructure', 'local_course_structure'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo local_course_structure_tabs($courseid, 'structure');
echo $OUTPUT->heading(get_string('generatecoursestructure', 'local_course_structure'));

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

// What the book appears to be divided into, awaiting the teacher's word.
$proposal = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bookfile'])) {
    $upload = $_FILES['bookfile'];

    if ($upload['error'] === UPLOAD_ERR_OK) {
        $filename = $upload['name'];

        // The book has to outlive this request: the chapters are only proposed
        // here, and the lectures are written from it later.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_course_structure', 'book', 0);
        $fs->create_file_from_pathname(array(
            'contextid' => $context->id,
            'component' => 'local_course_structure',
            'filearea'  => 'book',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => clean_param($filename, PARAM_FILE),
        ), $upload['tmp_name']);

        try {
            $proposal = \mod_book\ai_service::extract_chapters($upload['tmp_name'],
                strtolower(pathinfo($filename, PATHINFO_EXTENSION)));
        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('processingerror', 'local_course_structure') . ': ' .
                $e->getMessage(), 'notifyerror');
        }

    } else {
        $errors = array(
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        );
        echo $OUTPUT->notification(
            get_string('uploaderror', 'local_course_structure') . ' ' .
            ($errors[$upload['error']] ?? 'Unknown error'), 'notifyerror');
    }

} else if ($retry && confirm_sesskey()) {
    $book = local_course_structure_stored_book($context);

    if ($book === null) {
        echo $OUTPUT->notification(
            get_string('nobookstored', 'local_course_structure'), 'notifyerror');
    } else {
        list($path, $filename) = $book;
        try {
            // The book is read again with the teacher's correction in hand.
            $proposal = \mod_book\ai_service::extract_chapters($path,
                strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $guidance);
        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('processingerror', 'local_course_structure') . ': ' .
                $e->getMessage(), 'notifyerror');
        }
    }

} else if ($confirm && confirm_sesskey()) {
    // The teacher may have edited the list, so the text area is what counts and
    // the proposal only supplies the sub-topics for titles that survived it.
    $summaries = array();
    foreach ($SESSION->local_course_structure_proposal ?? array() as $chapter) {
        $summaries[trim($chapter['title'])] = $chapter['summary'] ?? '';
    }

    $chapters = array();
    foreach (preg_split('/\R/u', (string)$edited) as $line) {
        $title = trim(clean_param($line, PARAM_TEXT));
        if ($title === '') {
            continue;
        }
        $chapters[] = array('title' => $title, 'summary' => $summaries[$title] ?? '');
    }

    if (empty($chapters)) {
        echo $OUTPUT->notification(
            get_string('nochaptersfound', 'local_course_structure'), 'notifyerror');
    } else {
        local_course_structure_apply($courseid, $chapters, $includesubtopics);
        unset($SESSION->local_course_structure_proposal);

        echo $OUTPUT->notification(
            get_string('structurecreated', 'local_course_structure', count($chapters)),
            'notifysuccess');

        echo '<div class="alert alert-success">';
        echo '<h4>' . get_string('createdchapters', 'local_course_structure') . '</h4>';
        echo '<ul>';
        foreach ($chapters as $chapter) {
            echo '<li><strong>' . s($chapter['title']) . '</strong>';
            if ($includesubtopics && !empty($chapter['summary'])) {
                // Titles were escaped when the list was built.
                echo $chapter['summary'];
            }
            echo '</li>';
        }
        echo '</ul></div>';

        echo '<a class="btn btn-primary" href="' .
             new moodle_url('/local/course_structure/lectures.php', array('id' => $courseid)) .
             '">' . get_string('tablectures', 'local_course_structure') . '</a> ';
        echo '<a class="btn btn-secondary" href="' .
             new moodle_url('/course/view.php', array('id' => $courseid)) . '">' .
             get_string('backtocourse', 'local_course_structure') . '</a>';

        echo $OUTPUT->footer();
        die();
    }
}

// Nothing is created until this is agreed to.
if (!empty($proposal)) {
    $SESSION->local_course_structure_proposal = $proposal;

    echo '<div class="card"><div class="card-body">';
    echo $OUTPUT->heading(get_string('previewheading', 'local_course_structure'), 4);
    echo '<p>' . get_string('previewdesc', 'local_course_structure',
        count($proposal)) . '</p>';

    echo '<ol class="mb-3">';
    foreach ($proposal as $chapter) {
        echo '<li>' . s($chapter['title']) . '</li>';
    }
    echo '</ol>';

    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="confirm" value="1">';

    echo '<div class="form-group">';
    echo '<label for="titles"><strong>' .
         get_string('previewedit', 'local_course_structure') . '</strong></label>';
    echo '<p class="text-muted small mb-1">' .
         get_string('previewedit_help', 'local_course_structure') . '</p>';
    echo '<textarea class="form-control" id="titles" name="titles" rows="' .
         min(20, max(6, count($proposal) + 1)) . '">' .
         s(implode("\n", array_column($proposal, 'title'))) . '</textarea>';
    echo '</div>';

    echo '<div class="form-check mb-3">';
    echo '<input type="checkbox" class="form-check-input" id="includesubtopics" ' .
         'name="includesubtopics" value="1"' . ($includesubtopics ? ' checked' : '') . '>';
    echo '<label class="form-check-label" for="includesubtopics">' .
         get_string('includesubtopics', 'local_course_structure') . '</label>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-primary">' .
         get_string('previewconfirm', 'local_course_structure') . '</button> ';
    echo '<a class="btn btn-secondary" href="' .
         new moodle_url('/course/view.php', array('id' => $courseid)) . '">' .
         get_string('cancel') . '</a>';
    echo '</form>';
    echo '</div></div>';

    // Reading the book differently, rather than correcting the list by hand.
    echo '<div class="card mt-4"><div class="card-body">';
    echo $OUTPUT->heading(get_string('previewretry', 'local_course_structure'), 4);
    echo '<p>' . get_string('previewretry_help', 'local_course_structure') . '</p>';

    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="retry" value="1">';
    echo '<div class="form-group">';
    echo '<textarea class="form-control" name="guidance" rows="2" placeholder="' .
         s(get_string('previewretryexample', 'local_course_structure')) . '">' .
         s($guidance) . '</textarea>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-outline-primary">' .
         get_string('previewretrybutton', 'local_course_structure') . '</button>';
    echo '</form>';
    echo '</div></div>';

    echo $OUTPUT->footer();
    die();
}

// Display upload form
?>
<div class="card">
    <div class="card-body">
        <p><?php echo get_string('uploadbookdesc', 'local_course_structure'); ?></p>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $courseid; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="form-group">
                <label for="bookfile"><?php echo get_string('selectbookfile', 'local_course_structure'); ?></label>
                <input type="file" class="form-control-file" id="bookfile" name="bookfile"
                       accept=".pdf,.docx,.txt" required>
                <small class="form-text text-muted">
                    <?php echo get_string('acceptedformats', 'local_course_structure'); ?>
                    <br>
                    <?php echo get_string('maxfilesize', 'local_course_structure', '256 MB'); ?>
                </small>
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="includesubtopics"
                       name="includesubtopics" value="1"
                       <?php echo $includesubtopics ? 'checked' : ''; ?>>
                <label class="form-check-label" for="includesubtopics">
                    <?php echo get_string('includesubtopics', 'local_course_structure'); ?>
                </label>
                <small class="form-text text-muted">
                    <?php echo get_string('includesubtopics_help', 'local_course_structure'); ?>
                </small>
            </div>

            <div class="alert alert-info">
                <strong><?php echo get_string('howworks', 'local_course_structure'); ?></strong>
                <ol>
                    <li><?php echo get_string('step1', 'local_course_structure'); ?></li>
                    <li><?php echo get_string('step2', 'local_course_structure'); ?></li>
                    <li><?php echo get_string('step3', 'local_course_structure'); ?></li>
                </ol>
            </div>

            <button type="submit" class="btn btn-primary">
                <?php echo get_string('generatestructure', 'local_course_structure'); ?>
            </button>
            <a href="<?php echo new moodle_url('/course/view.php', array('id' => $courseid)); ?>"
               class="btn btn-secondary">
                <?php echo get_string('cancel'); ?>
            </a>
        </form>
    </div>
</div>

<?php
echo $OUTPUT->footer();
