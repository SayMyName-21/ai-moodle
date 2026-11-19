<?php
// This file is part of Moodle - http://moodle.org/
//
// Write a practice quiz or an exam from the presentations the teacher picks.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
require_once($CFG->dirroot . '/local/course_structure/locallib.php');
require_once($CFG->dirroot . '/local/course_structure/classes/slide_deck.php');
require_once($CFG->dirroot . '/local/course_structure/classes/quiz_builder.php');

use local_course_structure\quiz_builder;

$courseid = required_param('id', PARAM_INT);
$build = optional_param('build', 0, PARAM_BOOL);

list($course, $context) = local_course_structure_page($courseid, 'quizzes', 'tabquizzes');

$decks = local_course_structure_decks($courseid);
$topics = local_course_structure_course_topics($course);
$typenames = quiz_builder::type_names();

if ($build && confirm_sesskey()) {
    \core_php_time_limit::raise(3600);
    raise_memory_limit(MEMORY_EXTRA);

    $options = array(
        'kind' => optional_param('kind', 'practice', PARAM_ALPHA),
        'cmids' => optional_param_array('decks', array(), PARAM_INT),
        'count' => optional_param('count', 10, PARAM_INT),
        'shown' => optional_param('shown', 0, PARAM_INT),
        'types' => optional_param_array('types', array(), PARAM_ALPHA),
        'instructions' => optional_param('instructions', '', PARAM_TEXT),
        'name' => optional_param('name', '', PARAM_TEXT),
        'topic' => optional_param('topic', -1, PARAM_INT),
        'usebook' => optional_param('usebook', 0, PARAM_BOOL),
        'timelimit' => optional_param('timelimit', 0, PARAM_INT),
        'attempts' => optional_param('attempts', 1, PARAM_INT),
    );
    try {
        $cmid = local_course_structure_build_quiz($course, $options, local_course_structure_page_say());
        echo html_writer::link(new moodle_url('/mod/quiz/view.php', array('id' => $cmid)),
            get_string('quizopen', 'local_course_structure'), array('class' => 'btn btn-secondary mr-2'));
    } catch (Exception $e) {
        echo $OUTPUT->notification(s($e->getMessage()), 'notifyerror');
    }
    echo $OUTPUT->continue_button(new moodle_url('/local/course_structure/quizzes.php', array('id' => $courseid)));
    echo $OUTPUT->footer();
    die();
}

echo '<div class="card"><div class="card-body">';
echo $OUTPUT->heading(get_string('questions', 'local_course_structure'), 4);
echo '<p>' . get_string('questionsdesc', 'local_course_structure') . '</p>';

if (empty($decks)) {
    local_course_structure_no_decks($courseid);
    echo '</div></div>';
    echo $OUTPUT->footer();
    die();
}

// What is in the course already.
$built = local_course_structure_quizzes($courseid);
if ($built) {
    $modinfo = get_fast_modinfo($course);
    echo '<p class="mb-1"><strong>' . get_string('quizzesbuilt', 'local_course_structure') . '</strong></p><ul>';
    foreach ($built as $cmid => $quiz) {
        $covers = array_map(function($id) use ($decks) {
            return isset($decks[$id]) ? $decks[$id]->name : '?';
        }, $quiz['decks']);
        echo '<li>' . html_writer::link(new moodle_url('/mod/quiz/view.php', array('id' => $cmid)),
                s($modinfo->get_cm($cmid)->name)) .
             ' <span class="text-muted">(' . get_string('quizkind' . $quiz['kind'], 'local_course_structure') . '; ' .
             s(implode(', ', $covers)) . ')</span></li>';
    }
    echo '</ul>';
}

$field = function($label, $control, $help = '') {
    return '<div class="form-group mb-3"><label class="font-weight-bold d-block mb-1">' . $label . '</label>' .
        $control . ($help !== '' ? '<small class="form-text text-muted">' . $help . '</small>' : '') . '</div>';
};
$check = function($name, $value, $label, $checked) {
    $id = 'lcs-' . $name . '-' . $value;
    return '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $name . '[]" value="' .
        s($value) . '" id="' . $id . '"' . ($checked ? ' checked' : '') . '><label class="form-check-label" for="' .
        $id . '">' . $label . '</label></div>';
};

echo '<form method="post" id="lcs-quizform">';
echo '<input type="hidden" name="id" value="' . $courseid . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="build" value="1">';

// What kind of quiz.
$kinds = '';
foreach (array('practice', 'exam') as $kind) {
    $kinds .= '<div class="form-check"><input class="form-check-input" type="radio" name="kind" value="' . $kind .
        '" id="lcs-kind-' . $kind . '"' . ($kind === 'practice' ? ' checked' : '') . '>' .
        '<label class="form-check-label" for="lcs-kind-' . $kind . '"><strong>' .
        get_string('quizkind' . $kind, 'local_course_structure') . '</strong> - ' .
        get_string('quizkind' . $kind . 'desc', 'local_course_structure') . '</label></div>';
}
echo $field(get_string('quizkind', 'local_course_structure'), $kinds);

// Which presentations.
$list = '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="lcs-alldecks">' .
    '<label class="form-check-label font-italic" for="lcs-alldecks">' . get_string('quizalldecks', 'local_course_structure') .
    '</label></div>';
foreach ($decks as $deck) {
    $list .= $check('decks', $deck->cmid, s($deck->name) . ' <span class="text-muted small">(' .
        s($topics[$deck->section] ?? '') . ')</span>', false);
}
echo $field(get_string('quizdecks', 'local_course_structure'), $list);

// How many, and how they are drawn.
echo $field(get_string('quizcount', 'local_course_structure'),
    '<input type="number" class="form-control" style="max-width:8em" name="count" value="10" min="1" max="' .
    LOCAL_COURSE_STRUCTURE_QUIZ_MAX . '">', get_string('quizcounthelp', 'local_course_structure'));
echo '<div class="lcs-examonly">';
echo $field(get_string('quizshown', 'local_course_structure'),
    '<input type="number" class="form-control" style="max-width:8em" name="shown" value="0" min="0">',
    get_string('quizshownhelp', 'local_course_structure'));
echo $field(get_string('quiztimelimit', 'local_course_structure'),
    '<input type="number" class="form-control" style="max-width:8em" name="timelimit" value="0" min="0">',
    get_string('quiztimelimithelp', 'local_course_structure'));
echo $field(get_string('quizattempts', 'local_course_structure'),
    '<input type="number" class="form-control" style="max-width:8em" name="attempts" value="1" min="0">',
    get_string('quizattemptshelp', 'local_course_structure'));
echo '</div>';

// Question types.
$types = '';
foreach ($typenames as $type => $label) {
    $types .= $check('types', $type, s($label) . ' <span class="text-muted small">' .
        get_string('quiztype_' . $type, 'local_course_structure') . '</span>', true);
}
echo $field(get_string('quiztypes', 'local_course_structure'), $types, get_string('quiztypeshelp', 'local_course_structure'));

// Source, instructions, name and place.
echo $field(get_string('quizsource', 'local_course_structure'),
    '<div class="form-check"><input class="form-check-input" type="checkbox" name="usebook" value="1" id="lcs-usebook" checked>' .
    '<label class="form-check-label" for="lcs-usebook">' . get_string('quizusebook', 'local_course_structure') .
    '</label></div>');
echo $field(get_string('quizinstructions', 'local_course_structure'),
    '<input type="text" class="form-control" name="instructions" maxlength="500" placeholder="' .
    s(get_string('quizinstructionsexample', 'local_course_structure')) . '">');
echo $field(get_string('quizname', 'local_course_structure'),
    '<input type="text" class="form-control" name="name" maxlength="200">', get_string('quiznamehelp', 'local_course_structure'));
$options = '<option value="-1">' . get_string('quiztopicauto', 'local_course_structure') . '</option>';
foreach ($topics as $number => $topic) {
    $options .= '<option value="' . $number . '">' . s($topic) . '</option>';
}
echo $field(get_string('quiztopic', 'local_course_structure'),
    '<select class="custom-select" style="max-width:24em" name="topic">' . $options . '</select>');

echo '<button type="submit" class="btn btn-primary">' . get_string('quizbuild', 'local_course_structure') . '</button>';
echo '</form>';

echo '<div class="alert alert-warning mt-3">' . get_string('quizratenote', 'local_course_structure') . '</div>';
echo '</div></div>';

// Exam settings only for an exam; at least one presentation and one type.
echo '<script>
(function(){
 var form=document.getElementById("lcs-quizform");
 var exam=form.querySelector(".lcs-examonly");
 function kind(){exam.style.display=form.querySelector("#lcs-kind-exam").checked?"":"none";}
 form.querySelectorAll("input[name=kind]").forEach(function(r){r.addEventListener("change",kind);});kind();
 var all=document.getElementById("lcs-alldecks");
 all.addEventListener("change",function(){form.querySelectorAll("input[name=\'decks[]\']").forEach(function(b){b.checked=all.checked;});});
 form.addEventListener("submit",function(e){
  var msg=null;
  if(!form.querySelector("input[name=\'decks[]\']:checked")){msg=' . json_encode(get_string('quiznodecks', 'local_course_structure')) . ';}
  else if(!form.querySelector("input[name=\'types[]\']:checked")){msg=' . json_encode(get_string('quiznotypes', 'local_course_structure')) . ';}
  if(msg){e.preventDefault();var p=document.getElementById("lcs-quizerror");
   if(!p){p=document.createElement("div");p.id="lcs-quizerror";p.className="alert alert-danger mt-2";form.appendChild(p);}
   p.textContent=msg;return;}
  var b=form.querySelector("button[type=submit]");setTimeout(function(){b.disabled=true;},0);
 });
})();
</script>';

echo $OUTPUT->footer();
