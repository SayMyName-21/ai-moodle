<?php
// This file is part of Moodle - http://moodle.org/
//
// Course Structure from Book PDF
// Upload a PDF book and automatically create course sections based on chapters

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');

$courseid = required_param('id', PARAM_INT);

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['bookfile'])) {

    $uploadedfile = $_FILES['bookfile'];

    if ($uploadedfile['error'] === UPLOAD_ERR_OK) {
        $tmpfile = $uploadedfile['tmp_name'];
        $filename = $uploadedfile['name'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            // Extract chapters using AI
            $chapters = \mod_book\ai_service::extract_chapters($tmpfile, $extension);

            // Lectures are generated later, after this request has ended, so the
            // book has to outlive the upload's temporary file.
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'local_course_structure', 'book', 0);
            $fs->create_file_from_pathname(array(
                'contextid' => $context->id,
                'component' => 'local_course_structure',
                'filearea'  => 'book',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => clean_param($filename, PARAM_FILE),
            ), $tmpfile);

            if (!empty($chapters)) {
                // Get existing sections
                $sections = $DB->get_records('course_sections',
                    array('course' => $courseid),
                    'section ASC');

                $existingsections = array_values($sections);
                $sectionindex = 1; // Start from section 1 (0 is general section)

                foreach ($chapters as $chapter) {
                    if (empty($chapter['title'])) {
                        continue;
                    }

                    $summary = ($includesubtopics && !empty($chapter['summary']))
                        ? $chapter['summary'] : '';

                    // Update existing section or create new one
                    if (isset($existingsections[$sectionindex])) {
                        // Update existing section
                        $section = $existingsections[$sectionindex];
                        $section->name = $chapter['title'];
                        $section->summary = $summary;
                        $section->summaryformat = FORMAT_HTML;
                        $DB->update_record('course_sections', $section);
                    } else {
                        // Create new section
                        $section = new stdClass();
                        $section->course = $courseid;
                        $section->section = $sectionindex;
                        $section->name = $chapter['title'];
                        $section->summary = $summary;
                        $section->summaryformat = FORMAT_HTML;
                        $section->sequence = '';
                        $section->visible = 1;
                        $section->timemodified = time();
                        $DB->insert_record('course_sections', $section);
                    }

                    $sectionindex++;
                }

                // Chapter names from an earlier run would otherwise linger on sections
                // the new structure no longer reaches. Sections holding activities are
                // left alone.
                for ($i = $sectionindex; $i < count($existingsections); $i++) {
                    $leftover = $existingsections[$i];
                    if (!empty($leftover->sequence)) {
                        continue;
                    }
                    $leftover->name = null;
                    $leftover->summary = '';
                    $DB->update_record('course_sections', $leftover);
                }

                // Rebuild course cache
                rebuild_course_cache($courseid, true);

                echo $OUTPUT->notification(
                    get_string('structurecreated', 'local_course_structure', count($chapters)),
                    'notifysuccess'
                );

                // Show created sections
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
                echo '</ul>';
                echo '</div>';

                echo '<a class="btn btn-primary" href="' .
                     new moodle_url('/local/course_structure/lectures.php', array('id' => $courseid)) .
                     '">' . get_string('lectures', 'local_course_structure') . '</a> ';
                echo '<a class="btn btn-secondary" href="' .
                     new moodle_url('/course/view.php', array('id' => $courseid)) . '">' .
                     get_string('backtocourse', 'local_course_structure') . '</a>';

            } else {
                echo $OUTPUT->notification(
                    get_string('nochaptersfound', 'local_course_structure'),
                    'notifyerror'
                );
            }

        } catch (Exception $e) {
            echo $OUTPUT->notification(
                get_string('processingerror', 'local_course_structure') . ': ' . $e->getMessage(),
                'notifyerror'
            );
            // Debug info
            if (debugging('', DEBUG_DEVELOPER)) {
                echo '<pre>Debug trace: ' . $e->getTraceAsString() . '</pre>';
            }
        }
    } else {
        $error_messages = array(
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        );
        $error_msg = isset($error_messages[$uploadedfile['error']])
            ? $error_messages[$uploadedfile['error']]
            : 'Unknown upload error: ' . $uploadedfile['error'];

        echo $OUTPUT->notification(
            get_string('uploaderror', 'local_course_structure') . ' ' . $error_msg,
            'notifyerror'
        );
    }
}

// Display upload form
?>
<div class="card">
    <div class="card-body">
        <p><?php echo get_string('uploadbookdesc', 'local_course_structure'); ?></p>

        <form method="post" enctype="multipart/form-data">
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
