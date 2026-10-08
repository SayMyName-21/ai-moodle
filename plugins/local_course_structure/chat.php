<?php
// This file is part of Moodle - http://moodle.org/
//
// Answers a question from the course assistant.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/classes/chat.php');

$courseid = required_param('id', PARAM_INT);
$question = trim(required_param('question', PARAM_TEXT));

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

// Enrolment is checked here rather than taken from the page that asked, so a
// request made by hand gets no further than one made by the widget.
require_login($course);
require_sesskey();

echo $OUTPUT->header();

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
    \core_php_time_limit::raise(120);
    $result = \local_course_structure\chat::ask($course, $context, $question);

    echo json_encode(array(
        'answer' => $result['answer'],
        'used' => $result['used'],
    ));

} catch (Exception $e) {
    echo json_encode(array('error' => $e->getMessage()));
}
