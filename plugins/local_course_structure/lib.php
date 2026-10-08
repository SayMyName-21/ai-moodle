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
 * Course Structure Generator hooks.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Put the generators in the course navigation for anyone who can edit the course.
 *
 * @param navigation_node $navigation Course navigation
 * @param stdClass $course Course
 * @param context_course $context Course context
 */
function local_course_structure_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('moodle/course:update', $context)) {
        return;
    }

    $navigation->add(
        get_string('coursestructure', 'local_course_structure'),
        new moodle_url('/local/course_structure/index.php', array('id' => $course->id)),
        navigation_node::TYPE_SETTING,
        null,
        'local_course_structure_build',
        new pix_icon('i/import', '')
    );

    $navigation->add(
        get_string('lectures', 'local_course_structure'),
        new moodle_url('/local/course_structure/lectures.php', array('id' => $course->id)),
        navigation_node::TYPE_SETTING,
        null,
        'local_course_structure_lectures',
        new pix_icon('i/files', '')
    );
}

/**
 * Put the course assistant in the corner of every course page.
 *
 * @return string Markup for the widget, or nothing where it does not belong
 */
function local_course_structure_before_footer() {
    global $PAGE, $COURSE, $CFG;

    if (!isloggedin() || isguestuser() || during_initial_install()) {
        return '';
    }

    if (empty($COURSE->id) || $COURSE->id == SITEID) {
        return '';
    }

    $context = context_course::instance($COURSE->id);
    $teacher = has_capability('moodle/course:update', $context);

    if (!$teacher && !is_enrolled($context, null, '', true)) {
        return '';
    }

    // A student sitting a quiz could otherwise ask the assistant what the lecture
    // says about the question in front of them. Teachers are not the risk here and
    // keep the assistant, since the question bank is one of the things it is for.
    // The page type is what every quiz page agrees on, from the view page through
    // to an attempt in progress.
    $onquiz = (strpos((string)$PAGE->pagetype, 'mod-quiz') === 0)
        || (!empty($PAGE->cm) && $PAGE->cm->modname === 'quiz');

    if (!$teacher && $onquiz) {
        return '';
    }

    $strings = array(
        'title' => get_string($teacher ? 'chattitleteacher' : 'chattitle',
            'local_course_structure'),
        'intro' => get_string($teacher ? 'chatintroteacher' : 'chatintro',
            'local_course_structure'),
        'placeholder' => get_string('chatplaceholder', 'local_course_structure'),
        'send' => get_string('chatsend', 'local_course_structure'),
        'open' => get_string('chatopen', 'local_course_structure'),
        'close' => get_string('chatclose', 'local_course_structure'),
        'thinking' => get_string('chatthinking', 'local_course_structure'),
        'failed' => get_string('chatfailed', 'local_course_structure'),
        'frombook' => get_string('chatfrombook', 'local_course_structure'),
    );

    $config = json_encode(array(
        'url' => $CFG->wwwroot . '/local/course_structure/chat.php',
        'courseid' => (int)$COURSE->id,
        'sesskey' => sesskey(),
        'strings' => $strings,
    ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return '
<div id="lcs-chat" data-config="' . s($config) . '"></div>
<style>
#lcs-chat-btn{position:fixed;right:20px;bottom:20px;z-index:1040;border:0;border-radius:50%;
 width:56px;height:56px;font-size:24px;cursor:pointer;background:#0f6cbf;color:#fff;
 box-shadow:0 2px 10px rgba(0,0,0,.3)}
#lcs-chat-panel{position:fixed;right:20px;bottom:86px;z-index:1040;width:360px;max-width:calc(100vw - 40px);
 max-height:70vh;display:none;flex-direction:column;background:#fff;border:1px solid #ccc;
 border-radius:8px;box-shadow:0 2px 16px rgba(0,0,0,.25);overflow:hidden}
#lcs-chat-panel.lcs-open{display:flex}
#lcs-chat-head{padding:10px 12px;background:#0f6cbf;color:#fff;font-weight:600;flex:0 0 auto}
#lcs-chat-log{flex:1 1 auto;overflow-y:auto;padding:12px;font-size:14px;line-height:1.45}
#lcs-chat-log p{margin:0 0 10px}
#lcs-chat-log .lcs-you{font-weight:600}
#lcs-chat-log .lcs-note{color:#666;font-size:12px}
#lcs-chat-form{display:flex;border-top:1px solid #ddd;flex:0 0 auto}
#lcs-chat-q{flex:1;border:0;padding:10px;font:inherit;resize:none;height:44px}
#lcs-chat-send{border:0;background:#0f6cbf;color:#fff;padding:0 14px;cursor:pointer}
</style>
<script>
(function(){
 var root=document.getElementById("lcs-chat");
 if(!root){return;}
 var cfg=JSON.parse(root.getAttribute("data-config"));
 var s=cfg.strings;

 var btn=document.createElement("button");
 btn.id="lcs-chat-btn";btn.type="button";btn.innerHTML="&#128172;";
 btn.setAttribute("aria-label",s.open);btn.title=s.open;

 var panel=document.createElement("div");
 panel.id="lcs-chat-panel";
 panel.innerHTML=\'<div id="lcs-chat-head"></div><div id="lcs-chat-log"></div>\'+
  \'<form id="lcs-chat-form"><textarea id="lcs-chat-q" rows="1"></textarea>\'+
  \'<button id="lcs-chat-send" type="submit"></button></form>\';

 document.body.appendChild(btn);document.body.appendChild(panel);

 var log=panel.querySelector("#lcs-chat-log");
 var q=panel.querySelector("#lcs-chat-q");
 panel.querySelector("#lcs-chat-head").textContent=s.title;
 panel.querySelector("#lcs-chat-send").textContent=s.send;
 q.placeholder=s.placeholder;

 function say(who,text,cls){
  var p=document.createElement("p");
  if(who){var b=document.createElement("span");b.className="lcs-you";b.textContent=who+" ";p.appendChild(b);}
  var t=document.createElement("span");
  if(cls){t.className=cls;}
  t.textContent=text;p.appendChild(t);
  log.appendChild(p);log.scrollTop=log.scrollHeight;
  return p;
 }
 say("",s.intro,"lcs-note");

 btn.addEventListener("click",function(){
  var open=panel.classList.toggle("lcs-open");
  btn.setAttribute("aria-label",open?s.close:s.open);
  btn.title=open?s.close:s.open;
  if(open){q.focus();}
 });

 panel.querySelector("#lcs-chat-form").addEventListener("submit",function(e){
  e.preventDefault();
  var text=q.value.trim();
  if(!text){return;}
  say("\\u25B8",text);
  q.value="";q.disabled=true;
  var pending=say("",s.thinking,"lcs-note");

  var body=new URLSearchParams();
  body.append("id",cfg.courseid);
  body.append("sesskey",cfg.sesskey);
  body.append("question",text);

  fetch(cfg.url,{method:"POST",credentials:"same-origin",
   headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
   body:body.toString()})
  .then(function(r){return r.json();})
  .then(function(d){
   pending.remove();
   if(d && d.error){say("",d.error,"lcs-note");return;}
   say("",d.answer);
   if(d.used && d.used.indexOf("book")!==-1){say("",s.frombook,"lcs-note");}
  })
  .catch(function(){pending.remove();say("",s.failed,"lcs-note");})
  .then(function(){q.disabled=false;q.focus();});
 });
})();
</script>';
}
