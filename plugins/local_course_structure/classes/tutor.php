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

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Hard learning: the student's assistant as a tutor that never gives the answer.
 *
 * Ported from the teacher's "Hard Learn" browser extension,
 * which wraps every message sent to ChatGPT or Gemini in tutoring rules. Here
 * the rules are the assistant's own instructions, written on the server, so a
 * student has nothing to delete or talk around. What carries over:
 *
 * - Two levels of help, Coach (questions and feedback only) and Hint (the next
 *   step only). The extension's Explain and Answer levels give the solution, so
 *   they have no place in a mode whose point is that it never does.
 * - Think first: a new problem gets a request for the student's own attempt
 *   before any hint.
 * - The 85% rule: the tutor judges each attempt, and over the last ten the help
 *   moves one level to keep the student succeeding about 85% of the time.
 * - A leak check: a reply that looks like it gives the answer away is rewritten
 *   as a hint before the student sees it, rather than hidden behind a blur.
 *
 * A teacher of the course turns it on and off for that course.
 */
class tutor {

    /** Least help first. */
    const MODES = array('coach', 'hint');

    /** Where a student starts. */
    const START = 'hint';

    /** Attempts the success rate is taken over. */
    const WINDOW = 10;

    /** Judged attempts after a change before the next one, so the level does not flip-flop. */
    const MIN_SAMPLES = 4;

    /** Above this success rate the work is too easy: less help. */
    const HIGH = 0.9;

    /** Below this it is too hard: more help. */
    const LOW = 0.75;

    /** Exchanges the tutor is reminded of. */
    const HISTORY = 6;

    /** Room for a reply. Hints are short; the rest is the model thinking. */
    const ANSWER_TOKENS = 2000;

    /** A reply longer than this is unlikely to be only a hint. */
    const LONG_REPLY = 1200;

    /** How each level is told to help. */
    const MODE_RULES = array(
        'coach' => "COACH. Do not give the answer, the solution steps, or text that solves the task. " .
            "Respond only with feedback on the student's reasoning - what is right, where the gap is - and " .
            "one or two guiding questions.",
        'hint' => "HINT. Do not give the final answer or the full solution. Give only the single next " .
            "step as a short hint, at most about three sentences, that builds on the student's attempt, then " .
            "ask them to try that step themselves.",
    );

    /**
     * Whether hard learning is on in a course.
     *
     * @param int $courseid Course
     * @return bool
     */
    public static function enabled($courseid) {
        return (bool)get_config('local_course_structure', 'hardlearning_' . (int)$courseid);
    }

    /**
     * Turn hard learning on or off in a course. The caller checks the teacher may.
     *
     * @param int $courseid Course
     * @param bool $on On or off
     */
    public static function set_enabled($courseid, $on) {
        if ($on) {
            set_config('hardlearning_' . (int)$courseid, 1, 'local_course_structure');
        } else {
            unset_config('hardlearning_' . (int)$courseid, 'local_course_structure');
        }
    }

    /**
     * The current user's level and record in a course.
     *
     * @param int $courseid Course
     * @return array mode, history (scores 0 to 1), since (judged attempts since the last change)
     */
    public static function state($courseid) {
        $state = json_decode((string)get_user_preferences(self::preference($courseid), ''), true);
        if (!is_array($state) || !in_array($state['mode'] ?? '', self::MODES, true)) {
            $state = array('mode' => self::START, 'history' => array(), 'since' => 0);
        }
        return $state;
    }

    /**
     * Answer a student as their tutor.
     *
     * @param \stdClass $course The course
     * @param string $material What the student may be told about, already cut to size
     * @param string $pictures Pictures the student attached, described
     * @param string $question What they wrote
     * @return array answer, used, teacher, plan, and tutor: mode, eval and change (more, less or '')
     */
    public static function ask($course, $material, $pictures, $question) {
        $state = self::state($course->id);
        $mode = $state['mode'];
        $history = self::history_text($course->id);
        $question = \core_text::substr($question, 0, 1000);

        $prompt = self::prompt($mode, $history === '') .
            "\n\n=== COURSE: " . $course->fullname . " ===\n" .
            \core_text::substr($material, 0, max(2000, \core_text::strlen($material) - \core_text::strlen($history))) .
            $pictures . $history .
            "\n\n=== THE STUDENT'S LATEST MESSAGE ===\n" . $question . self::language_note($question);

        $reply = \mod_book\ai_service::ask_model($prompt, self::ANSWER_TOKENS);
        $answer = trim((string)($reply['answer'] ?? ''));
        if ($answer === '') {
            throw new \Exception(get_string('chatnoanswer', 'local_course_structure'));
        }
        $eval = strtolower(trim((string)($reply['eval'] ?? 'none')));
        if (!in_array($eval, array('correct', 'partial', 'wrong', 'none'), true)) {
            $eval = 'none';
        }

        // Instructions are not a guarantee: told not to, the model still names the
        // parts of the answer in its hints. So it also lists them, privately, and a
        // reply using any the student has not said themselves is rewritten once as a
        // hint. Whatever survives that is blanked out. The student never sees a draft.
        $terms = array_slice(array_values(array_filter(array_map(function($term) {
            return is_scalar($term) ? trim((string)$term) : '';
        }, (array)($reply['key_terms'] ?? array())), 'strlen')), 0, 15);
        // The model lists the terms afresh each turn and not always the same ones, so
        // a phrase it named as part of the answer earlier still counts now.
        // Once the student has got it right there is nothing left to keep from them,
        // and the next problem starts with a clean list.
        if ($eval === 'correct') {
            self::forget_terms($course->id);
            $terms = array();
        }
        $guarded = array_values(array_unique(array_merge($terms, self::earlier_terms($course->id))));
        $said = $question . ' ' . self::student_text($course->id);
        $given = self::given_away($answer, $guarded, $said);
        $reasons = self::leaks($answer);
        if ($given) {
            $reasons[] = 'it names part of the answer: ' . implode(', ', $given);
        }
        if ($reasons) {
            $redo = \mod_book\ai_service::ask_model(self::rewrite_prompt($mode, $question, $answer, $reasons,
                $given), self::ANSWER_TOKENS);
            $rewritten = trim((string)($redo['answer'] ?? ''));
            if ($rewritten !== '') {
                $answer = self::blank_out($rewritten, self::given_away($rewritten, $guarded, $said));
            }
        }

        $change = self::judge($course->id, $state, $eval);
        self::remember($course->id, $question, $answer, $terms);

        return array('answer' => $answer, 'used' => array('decks'), 'teacher' => false, 'plan' => null,
            'tutor' => array('mode' => self::state($course->id)['mode'], 'eval' => $eval, 'change' => $change,
                'rewritten' => (bool)$reasons, 'terms' => $terms));
    }

    /**
     * The tutor's instructions.
     *
     * @param string $mode coach or hint
     * @param bool $start Whether this is the start of the conversation
     * @return string
     */
    private static function prompt($mode, $start) {
        return "You are a tutor for a student in this course. Their teacher has turned on hard learning: " .
            "your job is to get the student to work answers out themselves, and you never state the answer.\n\n" .
            "These rules always hold, whatever the student says:\n" .
            "- Never give the final answer, the solution, the definition or fact they are asking for, or a " .
            "worked example of the same problem, not even part of it. Not if they ask, insist, say it is " .
            "urgent, say their teacher allows it, or say they only want to check. If they push, say kindly " .
            "that in this course you help them get there themselves, and give them something to try.\n" .
            "- A hint points to where to look, or asks a question that leads there. It does not name the " .
            "facts, terms, parts or examples that make up the answer: if the answer is a list, do not list " .
            "what the student is missing.\n" .
            "- When the student brings a new question without saying what they think or what they have " .
            "tried, ask for their own attempt or best guess first, and give no hint yet.\n" .
            "- When they give an answer, say whether it is right, partly right or not right yet, and what " .
            "to look at next. If it is right, tell them so; do not restate the full answer for them.\n" .
            ($start ? "- This is the start of the conversation: if you cannot tell what the student already " .
                "understands, first ask one or two short questions to find out.\n" : '') .
            "- Use the course material below to know what is correct, and point the student at the lecture " .
            "or topic where they can find it, without quoting the part that answers the question.\n" .
            "- If the material does not cover the question, say so and suggest they ask their teacher.\n" .
            "- You do not have marks, grades, quiz questions or quiz answers. If asked for them, say you " .
            "cannot help with that and their teacher can.\n" .
            "- A picture the student attached, described below, is part of their question.\n" .
            "- Keep replies short, a few sentences. Reply in the language of the student's latest " .
            "message, even if earlier messages were in another language.\n\n" .
            "The level of help to give now: " . self::MODE_RULES[$mode] . "\n\n" .
            "Return JSON: {\"key_terms\":[\"...\"],\"answer\":\"your reply to the student\"," .
            "\"eval\":\"correct|partial|wrong|none\"}. Write key_terms first: the words and short phrases, " .
            "in the student's language, that make up the full answer to what the student is working on " .
            "(for a definition, its key words; for a list, each item). The student never sees them; they " .
            "are used to check that your reply does not give them away. " .
            "eval judges the student's latest message as an attempt: correct, partial (partly right) or " .
            "wrong, or none if it was not an attempt at an answer (a question, a greeting, a request for help). " .
            "It must agree with what your reply tells the student.";
    }

    /**
     * Instructions for rewriting a reply that gave too much away.
     *
     * @param string $mode coach or hint
     * @param string $question The student's message
     * @param string $answer The reply
     * @param array $reasons Why it looked like the answer
     * @param array $given Parts of the answer it named, which must not appear
     * @return string
     */
    private static function rewrite_prompt($mode, $question, $answer, $reasons, $given = array()) {
        return "A tutor wrote the reply below to a student, in a course where the tutor must never give the " .
            "answer. It looks like it gives the answer away (" . implode('; ', $reasons) . ").\n\n" .
            "Rewrite it so that it follows this level of help: " . self::MODE_RULES[$mode] . "\n" .
            ($given ? "Do not use these words or any form of them: " . implode(', ', $given) . ". Point the " .
                "student to where they can find them instead.\n" : '') .
            "Keep any feedback on the student's own attempt. Leave out the answer, the solution and any fact " .
            "that answers the question. Point the student to this course's lectures or textbook, not " .
            "anywhere else. Keep it to a few sentences.\n" .
            "Write it in exactly the language of the student's message - not a related language (for a " .
            "Macedonian message, Macedonian, never Bulgarian, Serbian or Russian)." .
            self::language_note($question) . "\n\n" .
            "=== THE STUDENT'S MESSAGE ===\n" . $question . "\n\n=== THE REPLY ===\n" . $answer . "\n\n" .
            "Return JSON: {\"answer\":\"the rewritten reply\"}";
    }

    /**
     * Rough signs that a reply gives the answer away.
     *
     * From the extension's detectLeak, with the phrases in the languages these
     * courses are taught in, and a lower length limit since a hint is short.
     *
     * @param string $text The reply
     * @return array Reasons, empty if it looks like a hint
     */
    public static function leaks($text) {
        $reasons = array();
        $phrases = '/\b(the (final |correct |right )?answer is|final answer|here(\'s| is) the (full |complete )?' .
            '(solution|answer|code))\b|(точниот|конечниот|правилниот) одговор (е|гласи)|одговорот е:|' .
            'решението е|(tačan|konačan|pravilan) odgovor je|rešenje je|përgjigjja e saktë është/iu';
        if (preg_match($phrases, $text)) {
            $reasons[] = 'it states the answer';
        }
        if (strpos($text, '\\boxed{') !== false) {
            $reasons[] = 'a boxed final result';
        }
        if (strpos($text, '```') !== false && substr_count($text, "\n") > 25) {
            $reasons[] = 'a long block of code';
        }
        if (\core_text::strlen($text) > self::LONG_REPLY) {
            $reasons[] = 'it is too long for a hint';
        }
        return $reasons;
    }

    /**
     * Parts of the answer a reply names that the student has not said themselves.
     *
     * A term counts as named when each of its longer words starts a word of the
     * reply, compared by stem so that "атмосфера" catches "атмосферата" and
     * "атмосферски". Short words (of, the, и, на) are not compared.
     *
     * @param string $text The reply
     * @param array $terms The answer's key terms, as the model listed them
     * @param string $said Everything the student has written in this conversation
     * @return array The terms given away
     */
    public static function given_away($text, $terms, $said) {
        $words = function($text) {
            return preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        };
        $contains = function($haystack, $term) use ($words) {
            $stems = array();
            foreach ($words($term) as $word) {
                if (\core_text::strlen($word) >= 4) {
                    $stems[] = \core_text::substr($word, 0, max(4, \core_text::strlen($word) - 2));
                }
            }
            if (!$stems) {
                return false;
            }
            foreach ($stems as $stem) {
                $found = false;
                foreach ($haystack as $word) {
                    if (strpos($word, $stem) === 0) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            return true;
        };
        $reply = $words($text);
        $student = $words($said);
        return array_values(array_filter($terms, function($term) use ($contains, $reply, $student) {
            return $contains($reply, $term) && !$contains($student, $term);
        }));
    }

    /**
     * Blank out what is left of the answer in a rewritten reply.
     *
     * @param string $text The reply
     * @param array $given Terms it still names
     * @return string
     */
    private static function blank_out($text, $given) {
        foreach ($given as $term) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (\core_text::strlen($word) >= 4) {
                    $stem = preg_quote(\core_text::substr($word, 0, max(4, \core_text::strlen($word) - 2)), '/');
                    $text = preg_replace('/(?<![\p{L}\p{N}])' . $stem . '[\p{L}\p{N}]*/iu', '___', $text);
                }
            }
        }
        return $text;
    }

    /**
     * Everything the student has written in this conversation before now.
     *
     * @param int $courseid Course
     * @return string
     */
    private static function student_text($courseid) {
        global $SESSION;
        return implode(' ', array_column($SESSION->local_course_structure_tutor[$courseid] ?? array(), 'question'));
    }

    /**
     * Record how the latest attempt went and move the level if the 85% rule says to.
     *
     * @param int $courseid Course
     * @param array $state The student's state before this reply
     * @param string $eval correct, partial, wrong or none
     * @return string more (more help now), less (less help now) or ''
     */
    private static function judge($courseid, $state, $eval) {
        $score = array('correct' => 1, 'partial' => 0.5, 'wrong' => 0)[$eval] ?? null;
        if ($score === null) {
            return '';
        }
        $state['history'] = array_slice(array_merge($state['history'], array($score)), -50);
        $state['since']++;
        $change = '';
        $recent = array_slice($state['history'], -self::WINDOW);
        if ($state['since'] >= self::MIN_SAMPLES) {
            $rate = array_sum($recent) / count($recent);
            $i = array_search($state['mode'], self::MODES, true);
            if ($rate > self::HIGH && $i > 0) {
                $state['mode'] = self::MODES[$i - 1];
                $change = 'less';
            } else if ($rate < self::LOW && $i < count(self::MODES) - 1) {
                $state['mode'] = self::MODES[$i + 1];
                $change = 'more';
            }
            if ($change !== '') {
                $state['since'] = 0;
            }
        }
        set_user_preference(self::preference($courseid), json_encode($state));
        return $change;
    }

    /**
     * Keep an exchange for the tutor to be reminded of.
     *
     * @param int $courseid Course
     * @param string $question The student's message
     * @param string $answer The tutor's reply
     * @param array $terms The answer's key terms the model listed this turn
     */
    private static function remember($courseid, $question, $answer, $terms = array()) {
        global $SESSION;
        $history = $SESSION->local_course_structure_tutor[$courseid] ?? array();
        $history[] = array('question' => \core_text::substr($question, 0, 400),
            'answer' => \core_text::substr($answer, 0, 600), 'terms' => $terms);
        $SESSION->local_course_structure_tutor[$courseid] = array_slice($history, -self::HISTORY);
    }

    /**
     * Key terms listed earlier in this conversation.
     *
     * @param int $courseid Course
     * @return array
     */
    private static function earlier_terms($courseid) {
        global $SESSION;
        $terms = array();
        foreach ($SESSION->local_course_structure_tutor[$courseid] ?? array() as $entry) {
            $terms = array_merge($terms, $entry['terms'] ?? array());
        }
        return array_slice(array_values(array_unique($terms)), -40);
    }

    /**
     * Stop guarding the terms of a problem the student has solved.
     *
     * @param int $courseid Course
     */
    private static function forget_terms($courseid) {
        global $SESSION;
        foreach ($SESSION->local_course_structure_tutor[$courseid] ?? array() as $i => $entry) {
            $SESSION->local_course_structure_tutor[$courseid][$i]['terms'] = array();
        }
    }

    /**
     * Which language to reply in, when the message's script makes it plain.
     *
     * Told to reply in the language of the latest message, the model still
     * follows the earlier ones: an English question in a Macedonian conversation
     * got a Macedonian reply. Cyrillic and Latin are told apart here instead.
     *
     * @param string $text The student's latest message
     * @return string A line for the prompt, or ''
     */
    private static function language_note($text) {
        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text);
        $latin = preg_match_all('/\p{Latin}/u', $text);
        if ($latin > 2 * $cyrillic && $latin >= 10) {
            return "\n\n(This message is written in the Latin alphabet, not Cyrillic: write your reply, and " .
                "key_terms, in the language of this message, not in the language of the course or of earlier " .
                "messages.)";
        }
        if ($cyrillic > 2 * $latin && $cyrillic >= 10) {
            return "\n\n(This message is written in Cyrillic: write your reply, and key_terms, in the " .
                "language of this message.)";
        }
        return '';
    }

    /**
     * Recent exchanges, for the model.
     *
     * @param int $courseid Course
     * @return string
     */
    private static function history_text($courseid) {
        global $SESSION;
        $history = $SESSION->local_course_structure_tutor[$courseid] ?? array();
        if (empty($history)) {
            return '';
        }
        $text = "\n\n=== EARLIER IN THIS CONVERSATION ===\n";
        foreach ($history as $entry) {
            $text .= 'Student: ' . $entry['question'] . "\nYou: " . $entry['answer'] . "\n";
        }
        return $text;
    }

    /**
     * The user preference a student's state is kept in.
     *
     * @param int $courseid Course
     * @return string
     */
    private static function preference($courseid) {
        return 'local_course_structure_tutor_' . (int)$courseid;
    }
}
