<?php
// This file is part of Moodle - http://moodle.org/
//
// Further reading and videos under each generated presentation.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$cmid = optional_param('cmid', 0, PARAM_INT);
$videoid = optional_param('video', '', PARAM_RAW_TRIMMED);
// A link to take off, or one the teacher pasted; checked by the links class.
$url = optional_param('url', '', PARAM_RAW_TRIMMED);
$instructions = trim(optional_param('instructions', '', PARAM_TEXT));

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);
require_login($course);
require_capability('moodle/course:update', $context);

$here = new moodle_url('/local/course_structure/extras.php', array('id' => $courseid));
$decks = local_course_structure_decks($courseid);

// Answering a suggestion, or asking for one, comes back to the presentation it was for.
if (in_array($action, array('find', 'accept', 'decline', 'remove', 'display',
        'linkfind', 'linkurl', 'linkaccept', 'linkdecline', 'linkremove'), true)) {
    require_sesskey();
    $back = new moodle_url($here, array(), 'deck-' . $cmid);
    if (!isset($decks[$cmid])) {
        redirect($here, get_string('nosuchdeck', 'local_course_structure'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    try {
        switch ($action) {
            case 'find':
                \core_php_time_limit::raise(300);
                \local_course_structure\videos::suggest($course, $cmid, $instructions);
                redirect($back);
                break;
            case 'accept':
                $video = \local_course_structure\videos::accept($course, $cmid, optional_param('embed', 0, PARAM_BOOL));
                redirect($back, get_string('videoaccepted', 'local_course_structure', s($video['title'])), null,
                    \core\output\notification::NOTIFY_SUCCESS);
                break;
            case 'decline':
                \local_course_structure\videos::decline($course, $cmid);
                redirect($back, get_string('videodeclined', 'local_course_structure'));
                break;
            case 'remove':
                \local_course_structure\videos::remove($course, $cmid, $videoid);
                redirect($back, get_string('videoremoved', 'local_course_structure'));
                break;
            case 'display':
                \local_course_structure\videos::set_display($course, $cmid, $videoid,
                    optional_param('embed', 0, PARAM_BOOL));
                redirect($back);
                break;
            case 'linkfind':
                \core_php_time_limit::raise(300);
                \local_course_structure\links::suggest($course, $cmid, $instructions);
                redirect($back);
                break;
            case 'linkurl':
                \core_php_time_limit::raise(120);
                \local_course_structure\links::suggest_url($course, $cmid, $url);
                redirect($back);
                break;
            case 'linkaccept':
                $link = \local_course_structure\links::accept($course, $cmid);
                redirect($back, get_string('linkaccepted', 'local_course_structure', s($link['title'])), null,
                    \core\output\notification::NOTIFY_SUCCESS);
                break;
            case 'linkdecline':
                \local_course_structure\links::decline($course, $cmid);
                redirect($back, get_string('linkdeclined', 'local_course_structure'));
                break;
            case 'linkremove':
                \local_course_structure\links::remove($course, $cmid, $url);
                redirect($back, get_string('linkremoved', 'local_course_structure'));
                break;
        }
    } catch (Exception $e) {
        redirect($back, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

list($course, $context) = local_course_structure_page($courseid, 'extras', 'tabextras');

// Wikipedia is asked a topic at a time with pauses between, so this reports as it goes.
if ($action === 'reading' && confirm_sesskey()) {
    \core_php_time_limit::raise(1800);
    local_course_structure_add_reading($course, ($cmid > 0) ? array($cmid) : null,
        local_course_structure_page_say());
    echo $OUTPUT->continue_button($here);
    echo $OUTPUT->footer();
    die();
}

echo '<div class="card mb-3"><div class="card-body">';
echo '<p>' . get_string('extrasdesc', 'local_course_structure') . '</p>';

if (empty($decks)) {
    local_course_structure_no_decks($courseid);
    echo '</div></div>';
    echo $OUTPUT->footer();
    die();
}

$hidden = function($name, $value) {
    return '<input type="hidden" name="' . $name . '" value="' . s($value) . '">';
};
$form = function($action, $cmid, $inner, $class = 'd-inline') use ($courseid, $hidden) {
    return '<form method="post" class="' . $class . '">' . $hidden('id', $courseid) .
        $hidden('sesskey', sesskey()) . $hidden('action', $action) . $hidden('cmid', $cmid) . $inner . '</form>';
};

echo $form('reading', 0, '<button type="submit" class="btn btn-outline-primary">' .
    get_string('readingfindall', 'local_course_structure') . '</button>');
echo '</div></div>';

$intros = $DB->get_records_sql_menu(
    "SELECT cm.id, r.intro FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
       JOIN {resource} r ON r.id = cm.instance
      WHERE cm.course = :course", array('course' => $courseid));

// A search takes a while, so the button says so and is not pressed twice.
$busy = ' onclick="var b=this;setTimeout(function(){b.disabled=true;b.textContent=' .
    s(json_encode(get_string('videofinding', 'local_course_structure'))) . ';},0);"';

foreach ($decks as $deck) {
    $state = \local_course_structure\videos::deck($courseid, $deck->cmid);

    echo '<div class="card mb-3" id="deck-' . $deck->cmid . '"><div class="card-body">';
    echo $OUTPUT->heading(s($deck->name), 4, 'mb-1');
    echo '<p class="text-muted small">' . s($deck->chapter) . '</p>';

    // Further reading.
    $hasreading = local_course_structure_has_reading($intros[$deck->cmid] ?? '');
    echo '<p class="mb-1"><strong>' . get_string('readingheader', 'local_course_structure') . '</strong> ' .
         '<span class="text-muted">' . get_string($hasreading ? 'readinghas' : 'readingnolinks',
             'local_course_structure') . '</span></p>';
    echo $form('reading', $deck->cmid, '<button type="submit" class="btn btn-sm btn-outline-secondary mb-3">' .
        get_string('readingfind', 'local_course_structure') . '</button>', 'mb-0');

    // Links from anywhere on the web: those under it, the one waiting, and the ways to get another.
    $links = \local_course_structure\links::deck($courseid, $deck->cmid);
    echo '<p class="mb-1"><strong>' . get_string('linksheader', 'local_course_structure') . '</strong></p>';
    if (empty($links['accepted'])) {
        echo '<p class="text-muted">' . get_string('linknoneyet', 'local_course_structure') . '</p>';
    } else {
        echo '<ul>';
        foreach ($links['accepted'] as $link) {
            echo '<li>' . html_writer::link($link['url'], s($link['title']),
                    array('target' => '_blank', 'rel' => 'noopener nofollow')) .
                 ' <span class="text-muted">(' . s($link['site']) . ')</span> ' .
                 $form('linkremove', $deck->cmid, $hidden('url', $link['url']) .
                     '<button type="submit" class="btn btn-link btn-sm p-0 ml-2">' .
                     get_string('linkremove', 'local_course_structure') . '</button>') . '</li>';
        }
        echo '</ul>';
    }
    $link = $links['suggestion'];
    if ($link) {
        echo '<div class="border rounded p-3 mb-3 bg-light">';
        echo '<p class="mb-2"><strong>' . get_string($link['pasted'] ? 'linkpasted' : 'linksuggested',
                'local_course_structure') . ':</strong> ' .
             html_writer::link($link['url'], s($link['title']), array('target' => '_blank', 'rel' => 'noopener nofollow')) .
             ' <span class="text-muted">(' . s($link['site']) . ')</span></p>';
        echo '<p class="mb-2 small text-muted text-break">' . s($link['url']) . '</p>';
        if (isset($link['checked']) && !$link['checked']) {
            echo '<p class="mb-2 small text-warning">' . get_string('linkunchecked', 'local_course_structure') . '</p>';
        }
        if ($link['reason'] !== '') {
            echo '<p class="mb-1 small">' . get_string('linkwhy', 'local_course_structure', s($link['reason'])) . '</p>';
        }
        if ($link['language'] !== '') {
            echo '<p class="mb-2 small text-muted">' .
                 get_string('linklanguage', 'local_course_structure', s($link['language'])) . '</p>';
        }
        echo $form('linkaccept', $deck->cmid, '<button type="submit" class="btn btn-primary btn-sm mr-2">' .
            get_string('linkaccept', 'local_course_structure') . '</button>');
        echo $form('linkdecline', $deck->cmid, '<button type="submit" class="btn btn-secondary btn-sm">' .
            get_string('linkdecline', 'local_course_structure') . '</button>');
        echo '</div>';
    }
    $linkbusy = ' onclick="var b=this;setTimeout(function(){b.disabled=true;b.textContent=' .
        s(json_encode(get_string('linkfinding', 'local_course_structure'))) . ';},0);"';
    echo $form('linkfind', $deck->cmid,
        (empty($links['notes']) ? '' : '<p class="small text-muted mb-1">' .
            get_string('linknotes', 'local_course_structure', s(implode('; ', $links['notes']))) . '</p>') .
        '<div class="input-group mb-2" style="max-width:560px">' .
        '<input type="text" class="form-control form-control-sm" name="instructions" maxlength="300" ' .
        'placeholder="' . s(get_string('linkinstructionsexample', 'local_course_structure')) . '" ' .
        'aria-label="' . s(get_string('linkinstructions', 'local_course_structure')) . '">' .
        '<div class="input-group-append"><button type="submit" class="btn btn-sm btn-outline-primary"' . $linkbusy . '>' .
        get_string($link ? 'linkfindanother' : 'linkfind', 'local_course_structure') .
        '</button></div></div>', 'mb-0');
    echo $form('linkurl', $deck->cmid,
        '<div class="input-group mb-3" style="max-width:560px">' .
        '<input type="url" class="form-control form-control-sm" name="url" maxlength="1000" required ' .
        'placeholder="https://..." aria-label="' . s(get_string('linkpaste', 'local_course_structure')) . '" ' .
        'title="' . s(get_string('linkpaste', 'local_course_structure')) . '">' .
        '<div class="input-group-append"><button type="submit" class="btn btn-sm btn-outline-secondary">' .
        get_string('linkcheck', 'local_course_structure') . '</button></div></div>', 'mb-0');

    // Videos already under it.
    echo '<p class="mb-1"><strong>' . get_string('videosheader', 'local_course_structure') . '</strong></p>';
    if (empty($state['accepted'])) {
        echo '<p class="text-muted">' . get_string('videononeyet', 'local_course_structure') . '</p>';
    } else {
        echo '<ul>';
        foreach ($state['accepted'] as $video) {
            $embedded = \local_course_structure\videos::embedded($video);
            echo '<li>' . html_writer::link($video['url'], s($video['title']), array('target' => '_blank')) .
                 ' <span class="text-muted">(' . s($video['channel']) . ')</span> ' .
                 '<span class="badge badge-light">' .
                     get_string($embedded ? 'videoasplayer' : 'videoaslink', 'local_course_structure') . '</span>' .
                 $form('display', $deck->cmid, $hidden('video', $video['id']) . $hidden('embed', $embedded ? 0 : 1) .
                     '<button type="submit" class="btn btn-link btn-sm p-0 ml-2">' .
                     get_string($embedded ? 'videomakelink' : 'videomakeplayer', 'local_course_structure') .
                     '</button>') . ' ' .
                 $form('remove', $deck->cmid, $hidden('video', $video['id']) .
                     '<button type="submit" class="btn btn-link btn-sm p-0 ml-2">' .
                     get_string('videoremove', 'local_course_structure') . '</button>') . '</li>';
        }
        echo '</ul>';
    }

    // The suggestion waiting for an answer, to watch before deciding.
    $video = $state['suggestion'];
    if ($video) {
        echo '<div class="border rounded p-3 mb-3 bg-light">';
        echo '<p class="mb-2"><strong>' . get_string('videosuggested', 'local_course_structure') . ':</strong> ' .
             html_writer::link($video['url'], s($video['title']), array('target' => '_blank')) .
             ' <span class="text-muted">(' . s($video['channel']) . ')</span></p>';
        echo '<div style="position:relative;max-width:560px;aspect-ratio:16/9" class="mb-2">' .
             '<iframe src="https://www.youtube-nocookie.com/embed/' . s($video['id']) . '" ' .
             'style="position:absolute;inset:0;width:100%;height:100%;border:0" ' .
             'allow="encrypted-media; picture-in-picture" allowfullscreen ' .
             'title="' . s($video['title']) . '"></iframe></div>';
        if ($video['reason'] !== '') {
            echo '<p class="mb-1 small">' . get_string('videowhy', 'local_course_structure', s($video['reason'])) . '</p>';
        }
        if ($video['language'] !== '') {
            echo '<p class="mb-2 small text-muted">' .
                 get_string('videolanguage', 'local_course_structure', s($video['language'])) . '</p>';
        }
        echo $form('accept', $deck->cmid, '<button type="submit" class="btn btn-primary btn-sm mr-2">' .
            get_string('videoaccept', 'local_course_structure') . '</button>');
        echo $form('accept', $deck->cmid, $hidden('embed', 1) .
            '<button type="submit" class="btn btn-outline-primary btn-sm mr-2">' .
            get_string('videoacceptplayer', 'local_course_structure') . '</button>');
        echo $form('decline', $deck->cmid, '<button type="submit" class="btn btn-secondary btn-sm">' .
            get_string('videodecline', 'local_course_structure') . '</button>');
        echo '</div>';
    }

    $notes = empty($state['notes']) ? '' : '<p class="small text-muted mb-1">' .
        get_string('videonotes', 'local_course_structure', s(implode('; ', $state['notes']))) . '</p>';
    echo $form('find', $deck->cmid,
        $notes .
        '<div class="input-group" style="max-width:560px">' .
        '<input type="text" class="form-control form-control-sm" name="instructions" maxlength="300" ' .
        'placeholder="' . s(get_string('videoinstructionsexample', 'local_course_structure')) . '" ' .
        'aria-label="' . s(get_string('videoinstructions', 'local_course_structure')) . '">' .
        '<div class="input-group-append"><button type="submit" class="btn btn-sm btn-outline-primary"' . $busy . '>' .
        get_string($video ? 'videofindanother' : 'videofind', 'local_course_structure') .
        '</button></div></div>', 'mb-0');

    echo '</div></div>';
}

echo $OUTPUT->footer();
