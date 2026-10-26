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
 * The book stored for the course.
 *
 * @param context_course $context Course context
 * @return stored_file|null The book, or null if none is stored
 */
function local_course_structure_book_file($context) {
    $files = get_file_storage()->get_area_files($context->id, 'local_course_structure',
        'book', 0, 'itemid', false);
    $file = reset($files);

    return $file ?: null;
}

// Uploading a book, or asking for it to be read again, only starts the reading in
// the background and comes straight back here to watch it. A textbook takes
// minutes to hours to read, far longer than a page can be left loading.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bookfile'])) {
    require_sesskey();
    $upload = $_FILES['bookfile'];

    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $errors = array(
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        );
        redirect($PAGE->url, get_string('uploaderror', 'local_course_structure') . ' ' .
            ($errors[$upload['error']] ?? 'Unknown error'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // The book has to outlive this request: it is read in the background, and the
    // lectures are written from it later.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'local_course_structure', 'book', 0);
    $book = $fs->create_file_from_pathname(array(
        'contextid' => $context->id,
        'component' => 'local_course_structure',
        'filearea'  => 'book',
        'itemid'    => 0,
        'filepath'  => '/',
        'filename'  => clean_param($upload['name'], PARAM_FILE),
    ), $upload['tmp_name']);

    \local_course_structure\reading::start($courseid, $book, '', $includesubtopics);
    redirect($PAGE->url);
}

if ($retry) {
    require_sesskey();
    $book = local_course_structure_book_file($context);
    if ($book === null) {
        redirect($PAGE->url, get_string('nobookstored', 'local_course_structure'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // The book is read again with the teacher's correction in hand. Pages already
    // read are kept, so this repeats the chapter detection rather than the reading.
    $previous = \local_course_structure\reading::get($courseid);
    \local_course_structure\reading::start($courseid, $book, $guidance,
        $previous['includesubtopics'] ?? true);
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo local_course_structure_tabs($courseid, 'structure');
echo $OUTPUT->heading(get_string('generatecoursestructure', 'local_course_structure'));

$reading = \local_course_structure\reading::get($courseid);
$status = $reading['status'] ?? '';

// What the book appears to be divided into, awaiting the teacher's word.
$proposal = null;

if ($confirm && confirm_sesskey()) {
    // The teacher may have edited the list, so the text area is what counts and
    // the proposal only supplies the sub-topics for titles that survived it. A
    // proposal made before readings moved to the background lives in the session.
    $proposed = ($status === 'ready') ? $reading['proposal']
        : ($SESSION->local_course_structure_proposal ?? array());
    $summaries = array();
    foreach ($proposed as $chapter) {
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
        \local_course_structure\reading::clear($courseid);

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

// Still being read: say how far it has got, and look again shortly.
if ($status === 'queued' || $status === 'reading') {
    $progress = \mod_book\ai_service::reading_progress($reading['contenthash']);
    $ispdf = strtolower(pathinfo($reading['filename'], PATHINFO_EXTENSION)) === 'pdf';
    $a = (object)array(
        'filename' => s($reading['filename']),
        'read' => $progress['read'],
        'total' => $progress['total'],
    );

    echo '<div class="card"><div class="card-body">';
    echo $OUTPUT->heading(get_string('readingheading', 'local_course_structure'), 4);

    if ($status === 'queued') {
        echo '<p>' . get_string('readingqueued', 'local_course_structure', $a->filename) . '</p>';
        // The worker picks a task up within a minute; much longer means it is not
        // running at all.
        if (time() - $reading['queued'] > 180) {
            echo $OUTPUT->notification(
                get_string('readingqueuedslow', 'local_course_structure'), 'notifywarning');
        }
    } else if (!$ispdf || $progress['done']) {
        echo '<p>' . get_string('readingchapters', 'local_course_structure') . '</p>';
    } else if ($progress['total']) {
        $percent = (int)floor(100 * $progress['read'] / $progress['total']);
        echo '<p>' . get_string('readingpages', 'local_course_structure', $a) . '</p>';
        echo '<div class="progress mb-3" style="height: 1.5rem;">' .
             '<div class="progress-bar" role="progressbar" style="width: ' . $percent . '%;" ' .
             'aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">' .
             $percent . '%</div></div>';
    } else {
        echo '<p>' . get_string('readingpagesnototal', 'local_course_structure', $a) . '</p>';
    }

    if (!empty($reading['started'])) {
        echo '<p class="text-muted small">' . get_string('readingstarted', 'local_course_structure',
            userdate($reading['started'], get_string('strftimedatetimeshort', 'langconfig'))) . '</p>';
    }
    echo '<p class="text-muted small mb-0">' .
         get_string('readingclose', 'local_course_structure') . '</p>';
    echo '</div></div>';

    $PAGE->requires->js_init_code('setTimeout(function() { window.location.reload(); }, 15000);');
    echo $OUTPUT->footer();
    die();
}

if ($status === 'ready') {
    $proposal = $reading['proposal'];
    $guidance = $reading['guidance'];
    $includesubtopics = $reading['includesubtopics'];
}

// The reading stopped. Trying again carries on from the last pages it finished.
if ($status === 'failed') {
    echo $OUTPUT->notification(get_string('readingfailed', 'local_course_structure',
        s($reading['error'] ?? '')), 'notifyerror');

    echo '<div class="card mb-4"><div class="card-body">';
    echo '<p>' . get_string('readingfailedhelp', 'local_course_structure',
        s($reading['filename'])) . '</p>';
    echo '<form method="post">';
    echo '<input type="hidden" name="id" value="' . $courseid . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="retry" value="1">';
    echo '<input type="hidden" name="guidance" value="' . s($reading['guidance'] ?? '') . '">';
    echo '<button type="submit" class="btn btn-primary">' .
         get_string('readingfailedretry', 'local_course_structure') . '</button>';
    echo '</form>';
    echo '</div></div>';
}

// Nothing is created until this is agreed to.
if (!empty($proposal)) {

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
