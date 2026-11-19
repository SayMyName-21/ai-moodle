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
 * Builds quizzes from generated questions.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

class quiz_builder {

    /**
     * The question types a generated quiz can contain, in the order offered.
     *
     * randomsamatch is not written by the model as such: it asks for short
     * question-and-term pairs (termpairs), which become short answer questions
     * the matching question draws from.
     */
    const TYPES = array('multichoice', 'truefalse', 'match', 'ordering', 'shortanswer', 'numerical', 'essay',
        'randomsamatch', 'ddwtos', 'gapselect', 'multianswer', 'calculatedmulti', 'calculatedsimple');

    /** Data sets generated for each calculated question. */
    const DATASET_ITEMS = 10;

    /**
     * Each type's name as Moodle shows it.
     *
     * @return array Keyed by type
     */
    public static function type_names() {
        $names = array();
        foreach (self::TYPES as $type) {
            $names[$type] = get_string('pluginname', 'qtype_' . $type);
        }
        return $names;
    }

    /**
     * Check a question the model wrote and put it in a fixed shape.
     *
     * Anything that would not import, or would import as a broken question -
     * a gap with no word, a formula Moodle cannot work out - is dropped here
     * rather than left for a student to find.
     *
     * @param array $raw As the model wrote it
     * @return array|null The question, or null if it is unusable
     */
    public static function clean_question($raw) {
        $text = function($value) {
            return is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string)$value)) : '';
        };
        $list = function($values) use ($text) {
            return array_values(array_filter(array_map($text, is_array($values) ? $values : array()), 'strlen'));
        };

        $type = $text($raw['type'] ?? '');
        $question = $text($raw['question'] ?? '');
        $feedback = $text($raw['feedback'] ?? '');
        $q = array('type' => $type, 'question' => $question, 'feedback' => $feedback);

        switch ($type) {
            case 'multichoice':
                $options = $list($raw['options'] ?? array());
                $correct = array_values(array_unique(array_filter(array_map('intval', (array)($raw['correct'] ?? array())),
                    function($index) use ($options) {
                        return $index >= 0 && $index < count($options);
                    })));
                if ($question === '' || count($options) < 3 || count(array_unique($options)) !== count($options)
                        || empty($correct) || count($correct) === count($options)) {
                    return null;
                }
                return $q + array('options' => $options, 'correct' => $correct);

            case 'truefalse':
                if ($question === '' || !isset($raw['answer'])) {
                    return null;
                }
                $answer = $raw['answer'];
                if (is_string($answer)) {
                    $answer = in_array(strtolower(trim($answer)), array('true', 'yes', '1', 'точно', 'да'), true);
                }
                return $q + array('answer' => (bool)$answer);

            case 'match':
                $pairs = array();
                foreach ((array)($raw['pairs'] ?? array()) as $pair) {
                    $left = $text($pair['left'] ?? '');
                    $right = $text($pair['right'] ?? '');
                    if ($left !== '' && $right !== '') {
                        $pairs[$left] = $right;
                    }
                }
                // Moodle needs at least two to match and three answers to choose from.
                if ($question === '' || count($pairs) < 3) {
                    return null;
                }
                return $q + array('pairs' => $pairs);

            case 'ordering':
                $items = $list($raw['items'] ?? array());
                if ($question === '' || count($items) < 3 || count(array_unique($items)) !== count($items)) {
                    return null;
                }
                return $q + array('items' => array_slice($items, 0, 8));

            case 'shortanswer':
                // An asterisk is Moodle's wildcard, which would accept anything.
                $answers = array_values(array_unique(array_filter(array_map(function($answer) {
                    return trim(str_replace('*', '', $answer));
                }, $list($raw['answers'] ?? array())), 'strlen')));
                if ($question === '' || empty($answers)) {
                    return null;
                }
                return $q + array('answers' => array_slice($answers, 0, 6));

            case 'numerical':
                if ($question === '' || !is_numeric($raw['answer'] ?? null)) {
                    return null;
                }
                return $q + array('answer' => (float)$raw['answer'],
                    'tolerance' => abs((float)(is_numeric($raw['tolerance'] ?? null) ? $raw['tolerance'] : 0)));

            case 'essay':
                if ($question === '') {
                    return null;
                }
                return $q + array('guide' => $text($raw['guide'] ?? ''));

            case 'ddwtos':
            case 'gapselect':
                $gaps = $list($raw['gaps'] ?? array());
                $distractors = array_values(array_diff($list($raw['distractors'] ?? array()), $gaps));
                if (!self::gaps_match($question, count($gaps)) || preg_match('/\[\[|\]\]/', implode(' ', $gaps))) {
                    return null;
                }
                // Any word fits any gap, so gaps listed together could be filled in any order.
                list($q['question'], $gaps) = self::untangle_lists($question, $gaps, function($gap) {
                    return $gap;
                }, function($run) {
                    return true;
                });
                $distractors = array_values(array_diff($distractors, $gaps));
                return $q + array('gaps' => $gaps, 'distractors' => array_slice($distractors, 0, 4));

            case 'multianswer':
                $gaps = array();
                foreach ((array)($raw['gaps'] ?? array()) as $gap) {
                    $kind = $text($gap['kind'] ?? '');
                    if ($kind === 'choice') {
                        $options = $list($gap['options'] ?? array());
                        $correct = (int)($gap['correct'] ?? -1);
                        if (count($options) < 2 || $correct < 0 || $correct >= count($options)) {
                            return null;
                        }
                        $gaps[] = array('kind' => 'choice', 'options' => $options, 'correct' => $correct);
                    } else if ($kind === 'word') {
                        $answers = $list($gap['answers'] ?? array());
                        if (empty($answers)) {
                            return null;
                        }
                        $gaps[] = array('kind' => 'word', 'answers' => $answers);
                    } else if ($kind === 'number' && is_numeric($gap['value'] ?? null)) {
                        $gaps[] = array('kind' => 'number', 'value' => (float)$gap['value'],
                            'tolerance' => abs((float)(is_numeric($gap['tolerance'] ?? null) ? $gap['tolerance'] : 0)));
                    } else {
                        return null;
                    }
                }
                if (!self::gaps_match($question, count($gaps))) {
                    return null;
                }
                // A typed gap takes any word, and a drop-down offering another gap's answer
                // takes that one; numbers in a list ("from [[1]] to [[2]]") have an order.
                list($q['question'], $gaps) = self::untangle_lists($question, $gaps, function($gap) {
                    return $gap['kind'] === 'word' ? $gap['answers'][0] : $gap['options'][$gap['correct']];
                }, function($run) {
                    foreach ($run as $i => $a) {
                        foreach ($run as $j => $b) {
                            if ($i === $j || $a['kind'] === 'number' || $b['kind'] === 'number') {
                                continue;
                            }
                            $right = ($a['kind'] === 'word') ? $a['answers'][0] : $a['options'][$a['correct']];
                            if ($b['kind'] === 'word' || in_array($right, $b['options'], true)) {
                                return true;
                            }
                        }
                    }
                    return false;
                });
                return $q + array('gaps' => $gaps);

            case 'calculatedsimple':
            case 'calculatedmulti':
                return self::clean_calculated($q, $raw);

            case 'termpairs':
                $pairs = array();
                foreach ((array)($raw['pairs'] ?? array()) as $pair) {
                    $ask = $text($pair['question'] ?? '');
                    $answer = trim(str_replace('*', '', $text($pair['answer'] ?? '')));
                    if ($ask !== '' && $answer !== '' && !in_array($answer, $pairs, true)) {
                        $pairs[$ask] = $answer;
                    }
                }
                if (count($pairs) < 3) {
                    return null;
                }
                return $q + array('pairs' => array_slice($pairs, 0, 6, true));
        }
        return null;
    }

    /**
     * Whether a text has gaps [[1]] to [[n]], each once.
     *
     * @param string $text Question text
     * @param int $count Gaps expected
     * @return bool
     */
    private static function gaps_match($text, $count) {
        if ($count < 1 || !preg_match_all('/\[\[(\d+)\]\]/', $text, $matches)) {
            return false;
        }
        $found = array_map('intval', $matches[1]);
        sort($found);
        return $found === range(1, $count);
    }

    /** What may stand between two gaps of one list: a comma, "and", "or", in the course languages likely here. */
    const LIST_JOIN = '/^\s*(?:[,;\/&]\s*)?(?:(?:и|или|и\/или|како и|ни|and|or|and\/or|as well as|nor|dhe|ose|ve|veya)\s+)?$/iu';

    /**
     * Fill in all but the first gap of each list whose gaps could be swapped.
     *
     * "The biosphere includes [[1]], [[2]] and [[3]]" is true in any order,
     * but Moodle accepts only the one in the key. Writing the later words into
     * the sentence ("[[1]], the hydrosphere and the lithosphere") leaves one
     * gap that only one word fits. The gaps left are renumbered in order.
     *
     * @param string $text Question text with gaps [[1]] to [[n]]
     * @param array $gaps The gaps, gap 1 first
     * @param callable $word The text that fills a gap
     * @param callable $swappable Whether the gaps of one list could be swapped
     * @return array The text and the gaps left
     */
    private static function untangle_lists($text, $gaps, $word, $swappable) {
        preg_match_all('/\[\[(\d+)\]\]/', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        // Gaps in the order they appear, split into runs joined only by list words.
        $runs = array();
        $end = null;
        foreach ($matches as $match) {
            $start = $match[0][1];
            if ($end !== null && preg_match(self::LIST_JOIN, substr($text, $end, $start - $end))) {
                $runs[count($runs) - 1][] = (int)$match[1][0];
            } else {
                $runs[] = array((int)$match[1][0]);
            }
            $end = $start + strlen($match[0][0]);
        }
        $fill = array();
        foreach ($runs as $run) {
            if (count($run) > 1 && $swappable(array_map(function($n) use ($gaps) {
                return $gaps[$n - 1];
            }, $run))) {
                $fill += array_fill_keys(array_slice($run, 1), true);
            }
        }
        if (!$fill) {
            return array($text, $gaps);
        }
        $kept = array();
        $text = preg_replace_callback('/\[\[(\d+)\]\]/', function($match) use ($gaps, $word, $fill, &$kept) {
            $gap = $gaps[(int)$match[1] - 1];
            if (isset($fill[(int)$match[1]])) {
                return $word($gap);
            }
            $kept[] = $gap;
            return '[[' . count($kept) . ']]';
        }, $text);
        return array($text, $kept);
    }

    /**
     * Check a calculated question and make its data sets.
     *
     * Each formula is worked out for every data set, as Moodle will, so a
     * question that divides by zero or names a variable it never defined goes
     * no further.
     *
     * @param array $q The question so far
     * @param array $raw As the model wrote it
     * @return array|null
     */
    private static function clean_calculated($q, $raw) {
        global $CFG;
        require_once($CFG->dirroot . '/question/type/calculated/questiontype.php');

        $variables = array();
        foreach ((array)($raw['variables'] ?? array()) as $variable) {
            $name = (string)($variable['name'] ?? '');
            $min = $variable['min'] ?? null;
            $max = $variable['max'] ?? null;
            if (!preg_match('/^[a-z][a-z0-9_]{0,9}$/i', $name) || !is_numeric($min) || !is_numeric($max)
                    || (float)$min > (float)$max) {
                return null;
            }
            $variables[$name] = array('min' => (float)$min, 'max' => (float)$max,
                'decimals' => max(0, min(4, (int)($variable['decimals'] ?? 0))));
        }
        if ($q['question'] === '' || empty($variables)) {
            return null;
        }

        $formulas = ($q['type'] === 'calculatedmulti')
            ? array_values(array_map('trim', array_filter((array)($raw['options'] ?? array()), 'is_string')))
            : array(trim((string)($raw['formula'] ?? '')));
        $correct = ($q['type'] === 'calculatedmulti') ? (int)($raw['correct'] ?? -1) : 0;
        if (empty($formulas) || $correct < 0 || $correct >= count($formulas)
                || ($q['type'] === 'calculatedmulti' && count($formulas) < 3)) {
            return null;
        }

        $qtype = \question_bank::get_qtype('calculated');
        foreach ($formulas as $formula) {
            if ($formula === '' || qtype_calculated_find_formula_errors($formula) !== false) {
                return null;
            }
            // Every variable a formula uses must be defined, and appear in the text.
            foreach ($qtype->find_dataset_names($formula) as $name) {
                if (!isset($variables[$name]) || strpos($q['question'], '{' . $name . '}') === false) {
                    return null;
                }
            }
        }

        // The same data sets the import stores, worked through every formula.
        $items = array();
        for ($i = 0; $i < self::DATASET_ITEMS; $i++) {
            $item = array();
            foreach ($variables as $name => $variable) {
                $scale = pow(10, $variable['decimals']);
                $value = mt_rand((int)round($variable['min'] * $scale), (int)round($variable['max'] * $scale)) / $scale;
                $item[$name] = $value;
            }
            $values = array();
            foreach ($formulas as $formula) {
                try {
                    $value = $qtype->substitute_variables_and_eval($formula, $item);
                } catch (\Throwable $e) {
                    return null;
                }
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    return null;
                }
                $values[] = round((float)$value, 6);
            }
            // Options that come to the same number cannot be told apart.
            if (count(array_unique($values)) !== count($values)) {
                return null;
            }
            $items[] = $item;
        }

        return $q + array('variables' => $variables, 'formulas' => $formulas, 'correct' => $correct,
            'decimals' => max(0, min(6, (int)($raw['decimals'] ?? 2))), 'items' => $items);
    }

    /**
     * Questions as Moodle XML.
     *
     * @param array $questions From clean_question(), none of them termpairs
     * @param int $choose For a random short-answer matching question, how many to draw; 0 for none
     * @param string $matchtitle Its text
     * @return string
     */
    public static function to_xml($questions, $choose = 0, $matchtitle = '') {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
        foreach ($questions as $index => $question) {
            $xml .= self::question_xml($question, $index + 1);
        }
        if ($choose > 0) {
            $xml .= '<question type="randomsamatch">' . self::name_xml(count($questions) + 1, 'randomsamatch', $matchtitle) .
                self::html_xml('questiontext', $matchtitle) . self::html_xml('generalfeedback', '') .
                '<defaultgrade>1</defaultgrade><penalty>0.3333333</penalty><hidden>0</hidden>' .
                '<choose>' . (int)$choose . '</choose><subcats>0</subcats>' . self::combined_feedback_xml() .
                "</question>\n";
        }
        return $xml . '</quiz>';
    }

    /**
     * One question as Moodle XML.
     *
     * @param array $q The question
     * @param int $number Its number, for its name
     * @return string
     */
    private static function question_xml($q, $number) {
        $type = $q['type'];
        $head = '<question type="' . $type . '">' . self::name_xml($number, $type, $q['question']);
        $grade = '<defaultgrade>1</defaultgrade><penalty>0.3333333</penalty><hidden>0</hidden>';
        $feedback = self::html_xml('generalfeedback', $q['feedback']);
        $answer = function($fraction, $text, $extra = '', $format = 'moodle_auto_format') {
            return '<answer fraction="' . $fraction . '" format="' . $format . '"><text>' . self::x($text) .
                '</text><feedback format="html"><text></text></feedback>' . $extra . '</answer>';
        };

        switch ($type) {
            case 'multichoice':
                $right = count($q['correct']);
                $wrong = count($q['options']) - $right;
                $body = '<single>' . ($right === 1 ? 'true' : 'false') . '</single><shuffleanswers>true</shuffleanswers>' .
                    '<answernumbering>abc</answernumbering>' . self::combined_feedback_xml();
                foreach ($q['options'] as $index => $option) {
                    $fraction = in_array($index, $q['correct'], true) ? 100 / $right : ($right === 1 ? 0 : -100 / $wrong);
                    $body .= $answer(round($fraction, 5), s($option), '', 'html');
                }
                break;

            case 'truefalse':
                $body = $answer($q['answer'] ? 100 : 0, 'true') . $answer($q['answer'] ? 0 : 100, 'false');
                break;

            case 'match':
                $body = '<shuffleanswers>true</shuffleanswers>' . self::combined_feedback_xml();
                foreach ($q['pairs'] as $left => $right) {
                    $body .= '<subquestion format="html"><text>' . self::x(s($left)) . '</text><answer><text>' .
                        self::x($right) . '</text></answer></subquestion>';
                }
                break;

            case 'ordering':
                $body = '<layouttype>VERTICAL</layouttype><selecttype>ALL</selecttype><selectcount>0</selectcount>' .
                    '<gradingtype>RELATIVE_NEXT_EXCLUDE_LAST</gradingtype><showgrading>SHOW</showgrading>' .
                    '<numberingstyle>none</numberingstyle>' . self::combined_feedback_xml() .
                    // Without it the importer makes up two hints with no text, which fail to save.
                    '<shownumcorrect/>';
                foreach ($q['items'] as $index => $item) {
                    $body .= $answer($index + 1, s($item), '', 'html');
                }
                break;

            case 'shortanswer':
                $body = '<usecase>0</usecase>';
                foreach ($q['answers'] as $accepted) {
                    $body .= $answer(100, $accepted);
                }
                break;

            case 'numerical':
                $body = $answer(100, (string)$q['answer'], '<tolerance>' . $q['tolerance'] . '</tolerance>');
                break;

            case 'essay':
                $grade = '<defaultgrade>1</defaultgrade><penalty>0</penalty><hidden>0</hidden>';
                $body = '<responseformat>editor</responseformat><responserequired>1</responserequired>' .
                    '<responsefieldlines>10</responsefieldlines><attachments>0</attachments>' .
                    '<attachmentsrequired>0</attachmentsrequired>' . self::html_xml('graderinfo', $q['guide']) .
                    self::html_xml('responsetemplate', '');
                break;

            case 'ddwtos':
            case 'gapselect':
                // A word used for more than one gap is one choice, offered again.
                $choices = array_values(array_unique(array_merge($q['gaps'], $q['distractors'])));
                $text = preg_replace_callback('/\[\[(\d+)\]\]/', function($match) use ($q, $choices) {
                    return '[[' . (array_search($q['gaps'][(int)$match[1] - 1], $choices, true) + 1) . ']]';
                }, s($q['question']));
                $counts = array_count_values($q['gaps']);
                $head = '<question type="' . $type . '">' . self::name_xml($number, $type, $q['question']) .
                    self::html_xml('questiontext', $text, false);
                $tag = ($type === 'ddwtos') ? 'dragbox' : 'selectoption';
                $body = '<shuffleanswers>1</shuffleanswers>' . self::combined_feedback_xml() . '<shownumcorrect/>';
                foreach ($choices as $choice) {
                    $body .= '<' . $tag . '><text>' . self::x($choice) . '</text><group>1</group>' .
                        ($type === 'ddwtos' && ($counts[$choice] ?? 0) > 1 ? '<infinite/>' : '') . '</' . $tag . '>';
                }
                return $head . $feedback . $grade . $body . "</question>\n";

            case 'multianswer':
                $text = preg_replace_callback('/\[\[(\d+)\]\]/', function($match) use ($q) {
                    return self::cloze_gap($q['gaps'][(int)$match[1] - 1]);
                }, s($q['question']));
                return '<question type="multianswer">' . self::name_xml($number, $type, $q['question']) .
                    self::html_xml('questiontext', $text, false) . $feedback . "</question>\n";

            case 'calculatedsimple':
            case 'calculatedmulti':
                $body = '<synchronize>0</synchronize><single>true</single><shuffleanswers>true</shuffleanswers>' .
                    '<answernumbering>abc</answernumbering>' . self::combined_feedback_xml() .
                    '<unitgradingtype>0</unitgradingtype><unitpenalty>0.1</unitpenalty><showunits>3</showunits>' .
                    '<unitsleft>0</unitsleft>';
                $extra = '<tolerance>0.01</tolerance><tolerancetype>1</tolerancetype>' .
                    '<correctanswerformat>1</correctanswerformat><correctanswerlength>' . $q['decimals'] .
                    '</correctanswerlength>';
                foreach ($q['formulas'] as $index => $formula) {
                    $text = ($type === 'calculatedmulti') ? '{=' . $formula . '}' : $formula;
                    $body .= $answer($index === $q['correct'] ? 100 : 0, $text, $extra,
                        $type === 'calculatedmulti' ? 'html' : 'moodle_auto_format');
                }
                $body .= '<dataset_definitions>';
                foreach ($q['variables'] as $name => $variable) {
                    $body .= '<dataset_definition><status><text>private</text></status><name><text>' . $name .
                        '</text></name><type>calculated</type><distribution><text>uniform</text></distribution>' .
                        '<minimum><text>' . $variable['min'] . '</text></minimum><maximum><text>' . $variable['max'] .
                        '</text></maximum><decimals><text>' . $variable['decimals'] . '</text></decimals>' .
                        '<itemcount>' . count($q['items']) . '</itemcount><dataset_items>';
                    foreach ($q['items'] as $index => $item) {
                        $body .= '<dataset_item><number>' . ($index + 1) . '</number><value>' . $item[$name] .
                            '</value></dataset_item>';
                    }
                    $body .= '</dataset_items><number_of_items>' . count($q['items']) .
                        '</number_of_items></dataset_definition>';
                }
                $body .= '</dataset_definitions>';
                break;

            default:
                return '';
        }
        return $head . self::html_xml('questiontext', s($q['question']), false) . $feedback . $grade . $body .
            "</question>\n";
    }

    /**
     * One gap of a cloze question, in Moodle's embedded-answer syntax.
     *
     * @param array $gap A choice, word or number
     * @return string
     */
    private static function cloze_gap($gap) {
        // Characters the syntax uses for itself are escaped with a backslash.
        $escape = function($text) {
            return str_replace(array('\\', '}', '#', '~', '/', '"'), array('\\\\', '\\}', '\\#', '\\~', '\\/', '\\"'),
                s($text));
        };
        switch ($gap['kind']) {
            case 'choice':
                $parts = array();
                foreach ($gap['options'] as $index => $option) {
                    $parts[] = ($index === $gap['correct'] ? '=' : '') . $escape($option);
                }
                return '{1:MULTICHOICE_S:' . implode('~', $parts) . '}';
            case 'word':
                return '{1:SHORTANSWER:' . implode('~', array_map(function($answer) use ($escape) {
                    return '=' . $escape(str_replace('*', '', $answer));
                }, $gap['answers'])) . '}';
            default:
                return '{1:NUMERICAL:=' . $gap['value'] . ':' . $gap['tolerance'] . '}';
        }
    }

    /**
     * A question's name: its number and type, then the start of its text.
     *
     * @param int $number Number
     * @param string $type Type
     * @param string $text Text
     * @return string
     */
    private static function name_xml($number, $type, $text) {
        $text = trim(preg_replace('/\[\[\d+\]\]|\{[a-z0-9_]+\}/i', '…', $text));
        return '<name><text>' . self::x('Q' . $number . ' ' . $type . ': ' . \core_text::substr($text, 0, 60)) .
            '</text></name>';
    }

    /**
     * An HTML text element.
     *
     * @param string $tag Element
     * @param string $text Plain text, or HTML if $plain is false
     * @param bool $plain Whether to escape it as plain text
     * @return string
     */
    private static function html_xml($tag, $text, $plain = true) {
        $html = ($text === '') ? '' : '<p>' . ($plain ? s($text) : $text) . '</p>';
        return '<' . $tag . ' format="html"><text>' . self::x($html) . '</text></' . $tag . '>';
    }

    /**
     * Feedback for a right, partly right and wrong response, left to Moodle's defaults.
     *
     * @return string
     */
    private static function combined_feedback_xml() {
        $xml = '';
        foreach (array('correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback') as $tag) {
            $xml .= '<' . $tag . ' format="html"><text></text></' . $tag . '>';
        }
        return $xml;
    }

    /**
     * Escape text for XML.
     *
     * @param string $text Text
     * @return string
     */
    private static function x($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Import Moodle XML questions into a category.
     *
     * @param string $xml From to_xml()
     * @param int $categoryid Category
     * @param \stdClass $course Course
     * @param \context_course $context Course context
     * @return array Ids of the questions created
     * @throws \Exception if the importer rejects them
     */
    public static function import_xml($xml, $categoryid, $course, $context) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $path = make_temp_directory('local_course_structure') . '/quiz-' . uniqid() . '.xml';
        file_put_contents($path, $xml);

        $before = $DB->get_fieldset_select('question_bank_entries', 'id', 'questioncategoryid = ?', array($categoryid));

        $format = new \qformat_xml();
        $format->setCategory($DB->get_record('question_categories', array('id' => $categoryid)));
        $format->setContexts(array($context));
        $format->setCourse($course);
        $format->setFilename($path);
        $format->setRealfilename('questions.xml');
        // Fractions such as -33.33 for a wrong option are taken to the nearest Moodle allows.
        $format->setMatchgrades('nearest');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);

        ob_start();
        $ok = $format->importprocess();
        $output = ob_get_clean();
        unlink($path);

        if (!$ok) {
            throw new \Exception('The question importer rejected the questions: ' .
                \core_text::substr(trim(html_to_text($output, 0, false)), 0, 400));
        }

        $after = $DB->get_fieldset_select('question_bank_entries', 'id', 'questioncategoryid = ?', array($categoryid));
        $new = array_diff($after, $before);
        if (empty($new)) {
            return array();
        }
        // A cloze question's parts are questions of their own, which belong to it alone.
        list($sql, $params) = $DB->get_in_or_equal($new);
        return array_values($DB->get_fieldset_sql('SELECT q.id FROM {question} q
                JOIN {question_versions} v ON v.questionid = q.id
               WHERE q.parent = 0 AND v.questionbankentryid ' . $sql . '
            ORDER BY v.questionbankentryid', $params));
    }

    /**
     * Create a question category in the course's question bank.
     *
     * @param \context_course $context Course context
     * @param string $name Category name
     * @param int $parent Category to put it under, 0 for the top
     * @return int Category id
     */
    public static function make_category($context, $name, $parent = 0) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $top = question_get_top_category($context->id, true);

        $category = new \stdClass();
        $category->parent = $parent ?: $top->id;
        $category->contextid = $context->id;
        $category->name = \core_text::substr($name, 0, 254);
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->sortorder = 999;
        $category->idnumber = null;

        return $DB->insert_record('question_categories', $category);
    }

    /**
     * Escape the characters GIFT treats as markup.
     *
     * @param string $text Raw text
     * @return string Escaped text
     */
    private static function gift_escape($text) {
        $text = str_replace(array('\\', '~', '=', '#', '{', '}', ':'),
                            array('\\\\', '\~', '\=', '\#', '\{', '\}', '\:'), $text);
        return preg_replace('/\s+/u', ' ', trim($text));
    }

    /**
     * Put questions into a category.
     *
     * Questions go in through the GIFT importer rather than as hand written rows,
     * because a question spans question, question_bank_entries and question_versions
     * and the importer is what keeps those consistent.
     *
     * @param array $questions Questions with a stem, options and correct index
     * @param int $categoryid Category to import into
     * @param \stdClass $course Course
     * @param \context_course $context Course context
     * @return array Ids of the questions created
     */
    public static function import_questions($questions, $categoryid, $course, $context) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/gift/format.php');

        $gift = '';
        foreach ($questions as $index => $question) {
            $gift .= '::Q' . ($index + 1) . '::' . self::gift_escape($question['question']) . " {\n";
            foreach ($question['options'] as $position => $option) {
                $gift .= ($position === (int)$question['correct'] ? '=' : '~') .
                    self::gift_escape($option) . "\n";
            }
            $gift .= "}\n\n";
        }

        $path = make_temp_directory('local_course_structure') . '/gift-' . uniqid() . '.txt';
        file_put_contents($path, $gift);

        $before = $DB->get_fieldset_select('question_bank_entries', 'id',
            'questioncategoryid = ?', array($categoryid));

        $format = new \qformat_gift();
        $format->setCategory($DB->get_record('question_categories', array('id' => $categoryid)));
        $format->setContexts(array($context));
        $format->setCourse($course);
        $format->setFilename($path);
        $format->setRealfilename('questions.txt');
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);

        // The importer reports progress straight to output.
        ob_start();
        $ok = $format->importprocess();
        ob_end_clean();

        unlink($path);

        if (!$ok) {
            throw new \Exception('The question importer rejected the generated questions.');
        }

        $after = $DB->get_fieldset_select('question_bank_entries', 'id',
            'questioncategoryid = ?', array($categoryid));
        $new = array_diff($after, $before);

        if (empty($new)) {
            return array();
        }

        list($sql, $params) = $DB->get_in_or_equal($new);
        return $DB->get_fieldset_select('question_versions', 'questionid',
            'questionbankentryid ' . $sql, $params);
    }

    /**
     * Create a quiz activity.
     *
     * @param \stdClass $course Course
     * @param int $sectionnum Section to place it in
     * @param string $name Quiz name
     * @param string $idnumber Marker so the plugin can recognise its own quizzes
     * @param int $questionsshown How many questions an attempt will show
     * @param array $settings Overrides: 'behaviour', 'attempts', 'timelimit' (seconds), 'intro' (HTML)
     * @return \stdClass The quiz record
     */
    public static function make_quiz($course, $sectionnum, $name, $idnumber, $questionsshown, $settings = array()) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $module = $DB->get_record('modules', array('name' => 'quiz'), '*', MUST_EXIST);

        $data = new \stdClass();
        $data->course = $course->id;
        $data->modulename = 'quiz';
        $data->module = $module->id;
        $data->section = $sectionnum;
        $data->name = $name;
        $data->intro = $settings['intro'] ?? '';
        $data->introformat = FORMAT_HTML;
        $data->visible = 1;
        $data->cmidnumber = $idnumber;

        $data->timeopen = 0;
        $data->timeclose = 0;
        $data->timelimit = (int)($settings['timelimit'] ?? 0);
        $data->overduehandling = 'autosubmit';
        $data->graceperiod = 0;
        $data->preferredbehaviour = $settings['behaviour'] ?? 'deferredfeedback';
        $data->canredoquestions = 0;
        $data->attempts = (int)($settings['attempts'] ?? 0);
        $data->attemptonlast = 0;
        $data->grademethod = QUIZ_GRADEHIGHEST;
        $data->decimalpoints = 2;
        $data->questiondecimalpoints = -1;
        $data->questionsperpage = 1;
        $data->navmethod = 'free';
        // Randomises the order of the options within each question.
        $data->shuffleanswers = 1;
        $data->sumgrades = 0;
        $data->grade = $questionsshown;
        $data->quizpassword = '';
        $data->subnet = '';
        $data->browsersecurity = '-';
        $data->delay1 = 0;
        $data->delay2 = 0;
        $data->showuserpicture = 0;
        $data->showblocks = 0;
        $data->completion = 0;
        $data->completionview = 0;
        $data->completionexpected = 0;
        $data->groupmode = 0;
        $data->groupingid = 0;
        $data->availabilityconditionsjson = '';

        foreach (array('during', 'immediately', 'open', 'closed') as $when) {
            foreach (array('attempt', 'correctness', 'marks', 'specificfeedback',
                           'generalfeedback', 'rightanswer', 'overallfeedback') as $what) {
                $data->{$what . $when} = 1;
            }
        }
        // A mark is only meaningful once the attempt is over - except in practice,
        // where each answer is checked as it is given.
        if ($data->preferredbehaviour === 'deferredfeedback') {
            $data->marksduring = 0;
            $data->correctnessduring = 0;
        }

        $data->feedbacktext = array(array('text' => '', 'format' => FORMAT_HTML));
        $data->feedbackboundaries = array();

        ob_start();
        $info = add_moduleinfo($data, $course);
        ob_end_clean();

        return $DB->get_record('quiz', array('id' => $info->instance), '*', MUST_EXIST);
    }

    /**
     * Put specific questions into a quiz.
     *
     * @param \stdClass $quiz Quiz
     * @param array $questionids Questions to add
     */
    public static function add_questions($quiz, $questionids) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        foreach ($questionids as $questionid) {
            quiz_add_quiz_question($questionid, $quiz, 0, 1);
        }
    }

    /**
     * Have a quiz draw questions at random from a category.
     *
     * @param \stdClass $quiz Quiz
     * @param int $categoryid Category to draw from
     * @param int $number How many to draw per attempt
     */
    public static function add_random($quiz, $categoryid, $number) {
        // What quiz_add_random_questions(), deprecated since 4.3, does in its place.
        $structure = \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id));
        $structure->add_random_questions(0, $number, array(
            'filter' => array(
                'category' => array(
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => array($categoryid),
                    'filteroptions' => array('includesubcategories' => false),
                ),
            ),
        ));
    }

    /**
     * Shuffle the order questions appear in.
     *
     * @param \stdClass $quiz Quiz
     */
    public static function shuffle_questions($quiz) {
        global $DB;
        $DB->set_field('quiz_sections', 'shufflequestions', 1, array('quizid' => $quiz->id));
    }

    /**
     * Set the quiz total to match the marks its questions carry.
     *
     * @param \stdClass $quiz Quiz
     */
    public static function finalise($quiz) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $sumgrades = $DB->get_field_sql(
            'SELECT COALESCE(SUM(maxmark), 0) FROM {quiz_slots} WHERE quizid = ?',
            array($quiz->id));

        $DB->set_field('quiz', 'sumgrades', $sumgrades, array('id' => $quiz->id));
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    }
}
