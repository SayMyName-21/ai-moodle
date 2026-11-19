<?php
// This file is part of Moodle - http://moodle.org/
//
// Answers a question from the course assistant.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/classes/chat.php');
require_once($CFG->dirroot . '/local/course_structure/classes/chat_images.php');

$courseid = required_param('id', PARAM_INT);
$question = trim(optional_param('question', '', PARAM_TEXT));
// Set when the teacher answers a proposal (apply or discard) or a video
// (videoaccept, videodecline or videoanother).
$decision = optional_param('decision', '', PARAM_ALPHA);
$planid = optional_param('plan', '', PARAM_ALPHANUM);
$cmid = optional_param('cmid', 0, PARAM_INT);
$instructions = trim(optional_param('instructions', '', PARAM_TEXT));
// Background work the widget is watching, by id, comma-separated.
$jobids = preg_replace('/[^A-Za-z0-9,]/', '', optional_param('jobs', '', PARAM_RAW_TRIMMED));
$attaching = optional_param('attach', 0, PARAM_BOOL);
// A teacher turning hard learning on (1) or off (0) for the course; -1 when not.
$hardlearning = optional_param('hardlearning', -1, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Enrolment is checked here rather than taken from the page that asked, so a
// request made by hand gets no further than one made by the widget.
require_login($course);
require_sesskey();

echo $OUTPUT->header();

// A picture is for anyone to show the assistant; any other file is a book, for a teacher.
$upload = $attaching ? ($_FILES['file'] ?? null) : null;
if ($attaching && (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))) {
    echo json_encode(array('error' => get_string('chatattachfailed', 'local_course_structure',
        get_string('uploaderror', 'local_course_structure'))));
    die();
}
$picture = $attaching && \local_course_structure\chat_images::is_image($upload['tmp_name']);

// The assistant shares one daily allowance with lecture generation, so a single
// person cannot spend all of it and leave the course unable to build anything.
// Describing a picture takes a request of its own, so it counts as a question.
$spend = function() {
    $limit = (int)(get_config('local_course_structure', 'chatdailylimit') ?: 40);
    $today = userdate(time(), '%Y-%m-%d');
    $record = json_decode((string)get_user_preferences('local_course_structure_chat', ''), true);
    if (!is_array($record) || ($record['day'] ?? '') !== $today) {
        $record = array('day' => $today, 'count' => 0);
    }
    if ($record['count'] >= $limit) {
        echo json_encode(array('error' => get_string('chatlimit', 'local_course_structure', $limit)));
        die();
    }
    $record['count']++;
    set_user_preference('local_course_structure_chat', json_encode($record));
};

if ($picture) {
    $spend();
    try {
        \core_php_time_limit::raise(120);
        $image = \local_course_structure\chat_images::add($course, $upload['tmp_name'], $upload['name']);
        $said = \local_course_structure\chat::is_teacher($context) ? 'chatimageattached' : 'chatimageattachedstudent';
        echo json_encode(array('attached' => get_string($said, 'local_course_structure', s($image['filename']))));
    } catch (Exception $e) {
        echo json_encode(array('error' => get_string('chatattachfailed', 'local_course_structure', $e->getMessage())));
    }
    die();
}

// Hard learning is the course's teachers' to decide, one course at a time.
if ($hardlearning >= 0) {
    require_once($CFG->dirroot . '/local/course_structure/classes/tutor.php');
    require_capability('moodle/course:update', $context);
    \local_course_structure\tutor::set_enabled($course->id, $hardlearning > 0);
    echo json_encode(array('hardlearning' => \local_course_structure\tutor::enabled($course->id),
        'said' => get_string($hardlearning > 0 ? 'chathardlearningon' : 'chathardlearningoff',
            'local_course_structure')));
    die();
}

if ($decision !== '' || $jobids !== '' || $attaching) {
    require_once($CFG->dirroot . '/local/course_structure/classes/course_agent.php');
    // Every change is checked again as it is made, but nobody else has a plan to
    // decide on, a video to answer or a job to watch in the first place.
    require_capability('moodle/course:update', $context);
}

// How far the background work has got.
if ($jobids !== '') {
    $jobs = array();
    foreach (array_slice(explode(',', $jobids), 0, 10) as $id) {
        $job = \local_course_structure\job::get($courseid, $id);
        if ($job !== null) {
            $jobs[] = array('id' => $id, 'status' => $job['status'],
                'line' => \local_course_structure\job::describe($job));
        }
    }
    echo json_encode(array('jobs' => $jobs));
    die();
}

// A book to give the assistant, which waits until the teacher says what it is for.
if ($attaching) {
    $file = \local_course_structure\course_agent::attach($course, $upload['tmp_name'], $upload['name']);
    echo json_encode(array('attached' => get_string('chatattached', 'local_course_structure',
        s($file->get_filename()))));
    die();
}

if ($decision !== '') {
    try {
        switch ($decision) {
            case 'apply':
                \core_php_time_limit::raise(300);
                $result = \local_course_structure\course_agent::apply($course, $planid);
                echo json_encode(array('applied' => $result['done'], 'jobs' => $result['jobs'],
                    'failed' => $result['failed']));
                break;
            case 'discard':
                \local_course_structure\course_agent::discard($course, $planid);
                echo json_encode(array('discarded' => true));
                break;
            case 'videoaccept':
                $video = \local_course_structure\videos::accept($course, $cmid, optional_param('embed', 0, PARAM_BOOL));
                echo json_encode(array('accepted' => $video['title']));
                break;
            case 'videodecline':
                \local_course_structure\videos::decline($course, $cmid);
                echo json_encode(array('declined' => true));
                break;
            case 'videoanother':
                \core_php_time_limit::raise(300);
                $video = \local_course_structure\videos::suggest($course, $cmid, $instructions);
                echo json_encode(array('video' => $video));
                break;
            case 'linkaccept':
                $link = \local_course_structure\links::accept($course, $cmid);
                echo json_encode(array('accepted' => $link['title']));
                break;
            case 'linkdecline':
                \local_course_structure\links::decline($course, $cmid);
                echo json_encode(array('declined' => true));
                break;
            case 'linkanother':
                \core_php_time_limit::raise(300);
                $link = \local_course_structure\links::suggest($course, $cmid, $instructions);
                echo json_encode(array('link' => $link));
                break;
            default:
                echo json_encode(array('error' => 'Unknown decision.'));
        }
    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()));
    }
    die();
}

if ($question === '') {
    echo json_encode(array('error' => get_string('chatempty', 'local_course_structure')));
    die();
}

$spend();

try {
    // A teacher's request can take several turns with the model, each of which
    // the free tier may make wait.
    \core_php_time_limit::raise(300);
    $result = \local_course_structure\chat::ask($course, $context, $question);

    // The model answers in Markdown - tables, headings, lists - which the widget
    // cannot show as it is. Rendered here, where format_text also cleans it, so
    // the widget can put it in the page as it stands.
    echo json_encode(array(
        'answer' => format_text($result['answer'], FORMAT_MARKDOWN,
            array('context' => $context, 'filter' => false)),
        'used' => $result['used'],
        'plan' => $result['plan'],
        'videos' => $result['videos'] ?? array(),
        'links' => $result['links'] ?? array(),
        // Only what the widget shows. The tutor's key terms are the answer, so they
        // must not reach the page, where they could be read off the response.
        'tutor' => isset($result['tutor']) ? array_intersect_key($result['tutor'],
            array_flip(array('mode', 'eval', 'change'))) : null,
    ));

} catch (Exception $e) {
    echo json_encode(array('error' => $e->getMessage()));
}
