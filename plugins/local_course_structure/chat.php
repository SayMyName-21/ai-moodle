<?php
// This file is part of Moodle - http://moodle.org/
//
// Answers a question from the course assistant.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/classes/chat.php');

$courseid = required_param('id', PARAM_INT);
$question = trim(optional_param('question', '', PARAM_TEXT));
// Set when the teacher answers a proposal: apply or discard.
$decision = optional_param('decision', '', PARAM_ALPHA);
$planid = optional_param('plan', '', PARAM_ALPHANUM);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Enrolment is checked here rather than taken from the page that asked, so a
// request made by hand gets no further than one made by the widget.
require_login($course);
require_sesskey();

echo $OUTPUT->header();

if ($decision !== '') {
    require_once($CFG->dirroot . '/local/course_structure/classes/course_agent.php');
    // Every change is checked again as it is made, but nobody else has a plan to
    // decide on in the first place.
    require_capability('moodle/course:update', $context);
    try {
        if ($decision === 'apply') {
            \core_php_time_limit::raise(300);
            $done = \local_course_structure\course_agent::apply($course, $planid);
            echo json_encode(array('applied' => $done));
        } else {
            \local_course_structure\course_agent::discard($course, $planid);
            echo json_encode(array('discarded' => true));
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

// The assistant shares one daily allowance with lecture generation, so a single
// person cannot spend all of it and leave the course unable to build anything.
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
    ));

} catch (Exception $e) {
    echo json_encode(array('error' => $e->getMessage()));
}
