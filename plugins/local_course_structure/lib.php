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
function local_course_structure_chat_widget() {
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

    require_once($CFG->dirroot . '/local/course_structure/classes/tutor.php');
    $hard = \local_course_structure\tutor::enabled($COURSE->id);

    $strings = array(
        'title' => get_string($teacher ? 'chattitleteacher' : 'chattitle',
            'local_course_structure'),
        'intro' => get_string($teacher ? 'chatintroteacher' : ($hard ? 'chatintrotutor' : 'chatintro'),
            'local_course_structure'),
        'hardlearning' => get_string('chathardlearning', 'local_course_structure'),
        'hardlearninghelp' => get_string('chathardlearninghelp', 'local_course_structure'),
        'tutorbadge' => get_string('tutorbadge', 'local_course_structure', '{mode}'),
        'tutorcoach' => get_string('tutormodecoach', 'local_course_structure'),
        'tutorhint' => get_string('tutormodehint', 'local_course_structure'),
        'tutorcorrect' => get_string('tutorcheckcorrect', 'local_course_structure'),
        'tutorpartial' => get_string('tutorcheckpartial', 'local_course_structure'),
        'tutorwrong' => get_string('tutorcheckwrong', 'local_course_structure'),
        'tutormore' => get_string('tutormorehelp', 'local_course_structure'),
        'tutorless' => get_string('tutorlesshelp', 'local_course_structure'),
        'placeholder' => get_string('chatplaceholder', 'local_course_structure'),
        'send' => get_string('chatsend', 'local_course_structure'),
        'open' => get_string('chatopen', 'local_course_structure'),
        'close' => get_string('chatclose', 'local_course_structure'),
        'thinking' => get_string($teacher ? 'chatworking' : 'chatthinking', 'local_course_structure'),
        'failed' => get_string('chatfailed', 'local_course_structure'),
        'frombook' => get_string('chatfrombook', 'local_course_structure'),
        'planintro' => get_string('chatplanintro', 'local_course_structure'),
        'apply' => get_string('chatapply', 'local_course_structure'),
        'discard' => get_string('chatdiscard', 'local_course_structure'),
        'applying' => get_string('chatapplying', 'local_course_structure'),
        'applied' => get_string('chatapplied', 'local_course_structure'),
        'discarded' => get_string('chatdiscarded', 'local_course_structure'),
        'reload' => get_string('chatreload', 'local_course_structure'),
        'jobsintro' => get_string('chatjobsintro', 'local_course_structure'),
        'videointro' => get_string('chatvideointro', 'local_course_structure', '{deck}'),
        'videoaccept' => get_string('videoaccept', 'local_course_structure'),
        'videoacceptplayer' => get_string('videoacceptplayer', 'local_course_structure'),
        'videodecline' => get_string('videodecline', 'local_course_structure'),
        'videoanother' => get_string('chatvideoanother', 'local_course_structure'),
        'videoplaceholder' => get_string('chatvideoplaceholder', 'local_course_structure'),
        'videosearching' => get_string('chatvideosearching', 'local_course_structure'),
        'videoaccepted' => get_string('chatvideoaccepted', 'local_course_structure'),
        'videodeclined' => get_string('videodeclined', 'local_course_structure'),
        'linkintro' => get_string('chatlinkintro', 'local_course_structure', '{deck}'),
        'linkaccept' => get_string('linkaccept', 'local_course_structure'),
        'linkdecline' => get_string('linkdecline', 'local_course_structure'),
        'linkanother' => get_string('linkfindanother', 'local_course_structure'),
        'linkplaceholder' => get_string('linkinstructionsexample', 'local_course_structure'),
        'linksearching' => get_string('linkfinding', 'local_course_structure'),
        'linkaccepted' => get_string('chatlinkaccepted', 'local_course_structure'),
        'linkdeclined' => get_string('linkdeclined', 'local_course_structure'),
        'linkunchecked' => get_string('linkunchecked', 'local_course_structure'),
        'attach' => get_string($teacher ? 'chatattach' : 'chatattachimage', 'local_course_structure'),
        'imagereading' => get_string('chatimagereading', 'local_course_structure'),
    );

    $config = json_encode(array(
        'url' => $CFG->wwwroot . '/local/course_structure/chat.php',
        'courseid' => (int)$COURSE->id,
        'sesskey' => sesskey(),
        'teacher' => $teacher,
        'hardlearning' => $hard,
        'tutormode' => (!$teacher && $hard) ? \local_course_structure\tutor::state($COURSE->id)['mode'] : '',
        'strings' => $strings,
    ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return '
<div id="lcs-chat" data-config="' . s($config) . '"></div>
<style>
#lcs-chat-btn{position:fixed;right:20px;bottom:20px;z-index:1040;border:0;border-radius:50%;
 width:56px;height:56px;font-size:24px;cursor:pointer;background:#0f6cbf;color:#fff;
 box-shadow:0 2px 10px rgba(0,0,0,.3)}
#lcs-chat-panel{position:fixed;right:20px;bottom:86px;z-index:1040;width:420px;max-width:calc(100vw - 40px);
 max-height:70vh;display:none;flex-direction:column;background:#fff;border:1px solid #ccc;
 border-radius:8px;box-shadow:0 2px 16px rgba(0,0,0,.25);overflow:hidden}
#lcs-chat-panel.lcs-open{display:flex}
#lcs-chat-head{padding:10px 12px;background:#0f6cbf;color:#fff;font-weight:600;flex:0 0 auto;
 display:flex;align-items:center;gap:8px;flex-wrap:wrap}
#lcs-chat-head .lcs-title{flex:1 1 auto}
#lcs-chat-head label{font-weight:400;font-size:12px;margin:0;display:flex;align-items:center;gap:4px;cursor:pointer}
#lcs-chat-head .lcs-badge{font-weight:400;font-size:12px;background:rgba(255,255,255,.2);border-radius:10px;padding:1px 8px}
#lcs-chat-log{flex:1 1 auto;overflow-y:auto;padding:12px;font-size:14px;line-height:1.45}
#lcs-chat-log p{margin:0 0 10px}
#lcs-chat-log .lcs-you{font-weight:600}
#lcs-chat-log .lcs-note{color:#666;font-size:12px}
#lcs-chat-form{display:flex;border-top:1px solid #ddd;flex:0 0 auto}
#lcs-chat-q{flex:1;border:0;padding:10px;font:inherit;resize:none;height:44px}
#lcs-chat-send{border:0;background:#0f6cbf;color:#fff;padding:0 14px;cursor:pointer}
#lcs-chat-log .lcs-plan{border:1px solid #cfe2f3;background:#f3f8fd;border-radius:6px;padding:8px 10px;margin:0 0 10px}
#lcs-chat-log .lcs-plan ul{margin:0 0 8px;padding-left:18px}
#lcs-chat-log .lcs-details{font-size:12px;color:#555;white-space:pre-wrap;margin:2px 0 6px}
#lcs-chat-log .lcs-answer{margin:0 0 10px}
#lcs-chat-log .lcs-answer h1,#lcs-chat-log .lcs-answer h2,#lcs-chat-log .lcs-answer h3,
#lcs-chat-log .lcs-answer h4{font-size:15px;font-weight:600;margin:12px 0 6px}
#lcs-chat-log .lcs-answer ul,#lcs-chat-log .lcs-answer ol{margin:0 0 10px;padding-left:20px}
#lcs-chat-log .lcs-answer li{margin:0 0 3px}
#lcs-chat-log .lcs-answer table{display:block;overflow-x:auto;border-collapse:collapse;
 font-size:12px;margin:0 0 10px}
#lcs-chat-log .lcs-answer th,#lcs-chat-log .lcs-answer td{border:1px solid #ccc;padding:4px 6px;
 vertical-align:top;min-width:70px}
#lcs-chat-log .lcs-answer th{background:#f3f3f3}
#lcs-chat-log .lcs-video{position:relative;width:100%;aspect-ratio:16/9;margin:6px 0}
#lcs-chat-log .lcs-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
#lcs-chat-log .lcs-row{display:flex;gap:6px;margin-top:6px}
#lcs-chat-log .lcs-row input{flex:1;min-width:0;font-size:12px}
#lcs-chat-attach{flex:0 0 40px;border:0;background:#f3f3f3;padding:0;cursor:pointer;font-size:18px}
#lcs-chat-log .lcs-thumb{display:block;max-width:160px;max-height:120px;border:1px solid #ddd;border-radius:4px;margin:0 0 4px auto}
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
 var head=panel.querySelector("#lcs-chat-head");
 var title=document.createElement("span");title.className="lcs-title";title.textContent=s.title;
 head.appendChild(title);
 // A teacher turns hard learning on or off for the course here; a student in
 // it sees how much help the tutor is giving now.
 var badge=null;
 if(cfg.teacher){
  var lab=document.createElement("label");lab.title=s.hardlearninghelp;
  var box=document.createElement("input");box.type="checkbox";box.checked=!!cfg.hardlearning;
  lab.appendChild(box);lab.appendChild(document.createTextNode(s.hardlearning));head.appendChild(lab);
  box.addEventListener("change",function(){
   var want=box.checked;box.disabled=true;
   post({hardlearning:want?1:0}).then(function(d){
    if(d && d.error){box.checked=!want;say("",d.error,"lcs-note");return;}
    box.checked=!!d.hardlearning;say("",d.said,"lcs-note");
   }).catch(function(){box.checked=!want;say("",s.failed,"lcs-note");})
   .then(function(){box.disabled=false;});
  });
 }else if(cfg.hardlearning){
  badge=document.createElement("span");badge.className="lcs-badge";head.appendChild(badge);
  setmode(cfg.tutormode);
 }
 function setmode(mode){
  if(badge){badge.textContent=s.tutorbadge.replace("{mode}",mode==="coach"?s.tutorcoach:s.tutorhint);}
 }
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
 // An answer arrives as HTML the server has already cleaned.
 function answer(html){
  var div=document.createElement("div");div.className="lcs-answer";div.innerHTML=html;
  log.appendChild(div);log.scrollTop=log.scrollHeight;
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

  post({question:text})
  .then(function(d){
   pending.remove();
   if(d && d.error){say("",d.error,"lcs-note");return;}
   answer(d.answer);
   if(d.used && d.used.indexOf("book")!==-1){say("",s.frombook,"lcs-note");}
   if(d.tutor){
    var check={correct:s.tutorcorrect,partial:s.tutorpartial,wrong:s.tutorwrong}[d.tutor.eval];
    if(d.tutor.change==="more"){say("",s.tutormore,"lcs-note");}
    else if(d.tutor.change==="less"){say("",s.tutorless,"lcs-note");}
    else if(check){say("",check,"lcs-note");}
    setmode(d.tutor.mode);
   }
   if(d.videos){d.videos.forEach(function(card){showvideo(card.cmid,card.deck,card.video,card.embed);});}
   if(d.links){d.links.forEach(function(card){showlink(card.cmid,card.deck,card.link);});}
   if(d.plan){showplan(d.plan);}
  })
  .catch(function(){pending.remove();say("",s.failed,"lcs-note");})
  .then(function(){q.disabled=false;q.focus();});
 });

 // Enter sends, as in any chat; Shift+Enter starts a new line. Not while an
 // input method is still composing a word, where Enter picks the word.
 q.addEventListener("keydown",function(e){
  if(e.key==="Enter"&&!e.shiftKey&&!e.isComposing&&!q.disabled){
   e.preventDefault();
   panel.querySelector("#lcs-chat-form").requestSubmit();
  }
 });

 function post(params){
  var body=new URLSearchParams();
  body.append("id",cfg.courseid);
  body.append("sesskey",cfg.sesskey);
  for(var k in params){body.append(k,params[k]);}
  return fetch(cfg.url,{method:"POST",credentials:"same-origin",
   headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
   body:body.toString()})
  .then(function(r){return r.json();});
 }

 // Changes the assistant proposes wait here until the teacher decides on them.
 function showplan(plan){
  var box=document.createElement("div");box.className="lcs-plan";
  var head=document.createElement("p");head.className="lcs-note";head.textContent=s.planintro;
  box.appendChild(head);
  var list=document.createElement("ul");
  plan.changes.forEach(function(change){
   var li=document.createElement("li");li.textContent=change.summary;
   if(change.details){
    var more=document.createElement("div");more.className="lcs-details";more.textContent=change.details;
    li.appendChild(more);
   }
   list.appendChild(li);
  });
  box.appendChild(list);
  var row=document.createElement("div");
  var yes=document.createElement("button");yes.type="button";yes.className="btn btn-primary btn-sm";
  yes.textContent=s.apply;
  var no=document.createElement("button");no.type="button";no.className="btn btn-secondary btn-sm ml-2";
  no.textContent=s.discard;
  row.appendChild(yes);row.appendChild(no);box.appendChild(row);
  log.appendChild(box);log.scrollTop=log.scrollHeight;

  function decide(decision){
   yes.disabled=true;no.disabled=true;
   var waiting=(decision==="apply")?say("",s.applying,"lcs-note"):null;
   post({decision:decision,plan:plan.id})
   .then(function(d){
    if(waiting){waiting.remove();}
    row.remove();
    if(d && d.error){say("",d.error,"lcs-note");return;}
    if(!d.applied){say("",s.discarded,"lcs-note");return;}
    if(d.applied.length){say("",s.applied+" "+d.applied.join("; "));}
    if(d.failed && d.failed.length){say("",d.failed.join("; "),"lcs-note");}
    if(d.jobs && d.jobs.length){watch(d.jobs);}
    var p=document.createElement("p");var a=document.createElement("a");
    a.href=location.href;a.textContent=s.reload;p.appendChild(a);
    log.appendChild(p);log.scrollTop=log.scrollHeight;
   })
   .catch(function(){
    if(waiting){waiting.remove();}
    yes.disabled=false;no.disabled=false;say("",s.failed,"lcs-note");
   });
  }
  yes.addEventListener("click",function(){decide("apply");});
  no.addEventListener("click",function(){decide("discard");});
 }

 // Work started in the background, watched until it has all finished.
 function watch(ids){
  var box=document.createElement("div");box.className="lcs-plan";
  var head=document.createElement("p");head.className="lcs-note";head.textContent=s.jobsintro;
  box.appendChild(head);
  var list=document.createElement("ul");box.appendChild(list);
  log.appendChild(box);log.scrollTop=log.scrollHeight;
  function check(){
   post({jobs:ids.join(",")}).then(function(d){
    if(!d || !d.jobs){return;}
    list.innerHTML="";
    var running=false;
    d.jobs.forEach(function(job){
     var li=document.createElement("li");
     var mark=job.status==="done"?"\\u2713 ":(job.status==="failed"?"\\u2717 ":"\\u23F3 ");
     li.textContent=mark+job.line;list.appendChild(li);
     if(job.status==="queued"||job.status==="running"){running=true;}
    });
    if(running){setTimeout(check,15000);}
   }).catch(function(){setTimeout(check,30000);});
  }
  check();
 }

 // A video the assistant found, which the teacher watches and then answers.
 // Accepted as a link unless the teacher asked for a player, which then leads.
 function showvideo(cmid,deck,video,embed){
  var box=document.createElement("div");box.className="lcs-plan";
  var head=document.createElement("p");head.className="lcs-note";
  head.textContent=s.videointro.replace("{deck}",deck);box.appendChild(head);
  var title=document.createElement("p");title.style.margin="0";
  var link=document.createElement("a");link.href=video.url;link.target="_blank";link.textContent=video.title;
  title.appendChild(link);
  if(video.channel){title.appendChild(document.createTextNode(" ("+video.channel+")"));}
  box.appendChild(title);
  var frame=document.createElement("div");frame.className="lcs-video";
  var iframe=document.createElement("iframe");
  iframe.src="https://www.youtube-nocookie.com/embed/"+encodeURIComponent(video.id);
  iframe.title=video.title;iframe.allowFullscreen=true;iframe.setAttribute("allow","encrypted-media; picture-in-picture");
  frame.appendChild(iframe);box.appendChild(frame);
  if(video.reason||video.language){
   var why=document.createElement("div");why.className="lcs-details";
   why.textContent=video.reason+(video.language?" ["+video.language+"]":"");box.appendChild(why);
  }
  var row=document.createElement("div");row.className="lcs-row";
  var yes=document.createElement("button");yes.type="button";yes.textContent=s.videoaccept;
  var player=document.createElement("button");player.type="button";player.textContent=s.videoacceptplayer;
  yes.className="btn btn-sm "+(embed?"btn-outline-primary":"btn-primary");
  player.className="btn btn-sm "+(embed?"btn-primary":"btn-outline-primary");
  var no=document.createElement("button");no.type="button";no.className="btn btn-secondary btn-sm";no.textContent=s.videodecline;
  if(embed){row.appendChild(player);row.appendChild(yes);}else{row.appendChild(yes);row.appendChild(player);}
  row.appendChild(no);box.appendChild(row);
  var row2=document.createElement("div");row2.className="lcs-row";
  var wish=document.createElement("input");wish.type="text";wish.className="form-control form-control-sm";
  wish.placeholder=s.videoplaceholder;wish.maxLength=300;
  var again=document.createElement("button");again.type="button";again.className="btn btn-outline-primary btn-sm";
  again.textContent=s.videoanother;
  row2.appendChild(wish);row2.appendChild(again);box.appendChild(row2);
  log.appendChild(box);log.scrollTop=log.scrollHeight;

  function busy(on){yes.disabled=on;player.disabled=on;no.disabled=on;again.disabled=on;wish.disabled=on;}
  function done(text){row.remove();row2.remove();frame.remove();say("",text,"lcs-note");}
  function accept(asplayer){
   busy(true);
   post({decision:"videoaccept",cmid:cmid,embed:asplayer?1:0}).then(function(d){
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    done(s.videoaccepted);
   }).catch(function(){busy(false);say("",s.failed,"lcs-note");});
  }
  yes.addEventListener("click",function(){accept(false);});
  player.addEventListener("click",function(){accept(true);});
  no.addEventListener("click",function(){
   busy(true);
   post({decision:"videodecline",cmid:cmid}).then(function(d){
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    done(s.videodeclined);
   }).catch(function(){busy(false);say("",s.failed,"lcs-note");});
  });
  function another(){
   busy(true);
   var waiting=say("",s.videosearching,"lcs-note");
   post({decision:"videoanother",cmid:cmid,instructions:wish.value.trim()}).then(function(d){
    waiting.remove();
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    box.remove();showvideo(cmid,deck,d.video,embed);
   }).catch(function(){waiting.remove();busy(false);say("",s.failed,"lcs-note");});
  }
  again.addEventListener("click",another);
  wish.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.isComposing){e.preventDefault();another();}});
 }

 // A web page the assistant found, or a link the teacher gave it, to open and then answer.
 function showlink(cmid,deck,link){
  var box=document.createElement("div");box.className="lcs-plan";
  var head=document.createElement("p");head.className="lcs-note";
  head.textContent=s.linkintro.replace("{deck}",deck);box.appendChild(head);
  var title=document.createElement("p");title.style.margin="0";
  var a=document.createElement("a");a.href=link.url;a.target="_blank";a.rel="noopener nofollow";a.textContent=link.title;
  title.appendChild(a);title.appendChild(document.createTextNode(" ("+link.site+")"));box.appendChild(title);
  var where=document.createElement("div");where.className="lcs-details";where.style.wordBreak="break-all";
  where.textContent=link.url;box.appendChild(where);
  if(link.checked===false){var warn=document.createElement("div");warn.className="lcs-details";
   warn.textContent=s.linkunchecked;box.appendChild(warn);}
  if(link.reason||link.language){
   var why=document.createElement("div");why.className="lcs-details";
   why.textContent=link.reason+(link.language?" ["+link.language+"]":"");box.appendChild(why);
  }
  var row=document.createElement("div");row.className="lcs-row";
  var yes=document.createElement("button");yes.type="button";yes.className="btn btn-primary btn-sm";yes.textContent=s.linkaccept;
  var no=document.createElement("button");no.type="button";no.className="btn btn-secondary btn-sm";no.textContent=s.linkdecline;
  row.appendChild(yes);row.appendChild(no);box.appendChild(row);
  var row2=document.createElement("div");row2.className="lcs-row";
  var wish=document.createElement("input");wish.type="text";wish.className="form-control form-control-sm";
  wish.placeholder=s.linkplaceholder;wish.maxLength=300;
  var again=document.createElement("button");again.type="button";again.className="btn btn-outline-primary btn-sm";
  again.textContent=s.linkanother;
  row2.appendChild(wish);row2.appendChild(again);box.appendChild(row2);
  log.appendChild(box);log.scrollTop=log.scrollHeight;

  function busy(on){yes.disabled=on;no.disabled=on;again.disabled=on;wish.disabled=on;}
  function done(text){row.remove();row2.remove();say("",text,"lcs-note");}
  yes.addEventListener("click",function(){
   busy(true);
   post({decision:"linkaccept",cmid:cmid}).then(function(d){
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    done(s.linkaccepted);
   }).catch(function(){busy(false);say("",s.failed,"lcs-note");});
  });
  no.addEventListener("click",function(){
   busy(true);
   post({decision:"linkdecline",cmid:cmid}).then(function(d){
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    done(s.linkdeclined);
   }).catch(function(){busy(false);say("",s.failed,"lcs-note");});
  });
  function another(){
   busy(true);
   var waiting=say("",s.linksearching,"lcs-note");
   post({decision:"linkanother",cmid:cmid,instructions:wish.value.trim()}).then(function(d){
    waiting.remove();
    if(d && d.error){busy(false);say("",d.error,"lcs-note");return;}
    box.remove();showlink(cmid,deck,d.link);
   }).catch(function(){waiting.remove();busy(false);say("",s.failed,"lcs-note");});
  }
  again.addEventListener("click",another);
  wish.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.isComposing){e.preventDefault();another();}});
 }

 // Anyone can show the assistant a picture - a diagram, a photographed exercise.
 // A teacher can also hand it a book, to be made the course book on request.
 var clip=document.createElement("button");clip.id="lcs-chat-attach";clip.type="button";
 clip.innerHTML="&#128206;";clip.title=s.attach;clip.setAttribute("aria-label",s.attach);
 var picker=document.createElement("input");picker.type="file";picker.hidden=true;
 picker.accept="image/png,image/jpeg,image/gif,image/webp"+(cfg.teacher?",.pdf,.docx,.txt":"");
 var form=panel.querySelector("#lcs-chat-form");
 form.insertBefore(clip,form.firstChild);form.appendChild(picker);
 clip.addEventListener("click",function(){picker.click();});
 function upload(file){
  var image=/^image\//.test(file.type);
  var data=new FormData();
  data.append("id",cfg.courseid);data.append("sesskey",cfg.sesskey);data.append("attach","1");
  data.append("file",file,file.name||"pasted.png");
  if(image){
   var thumb=document.createElement("img");thumb.className="lcs-thumb";thumb.alt=file.name||"";
   thumb.src=URL.createObjectURL(file);log.appendChild(thumb);
  }
  var pending=say("",image?s.imagereading:(file.name+"..."),"lcs-note");
  clip.disabled=true;
  fetch(cfg.url,{method:"POST",credentials:"same-origin",body:data})
  .then(function(r){return r.json();})
  .then(function(d){pending.remove();say("",d.error||d.attached,"lcs-note");})
  .catch(function(){pending.remove();say("",s.failed,"lcs-note");})
  .then(function(){clip.disabled=false;picker.value="";q.focus();});
 }
 picker.addEventListener("change",function(){if(picker.files.length){upload(picker.files[0]);}});
 // A screenshot pasted into the question box is attached as if picked.
 q.addEventListener("paste",function(e){
  var items=(e.clipboardData&&e.clipboardData.files)||[];
  for(var i=0;i<items.length;i++){
   if(/^image\//.test(items[i].type)){e.preventDefault();if(!clip.disabled){upload(items[i]);}return;}
  }
 });
})();
</script>';
}
