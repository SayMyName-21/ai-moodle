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
 * Web links to go with a presentation, from anywhere on the web.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Finds a web page for a presentation, for the teacher to accept or turn down.
 *
 * The Wikipedia reading (sources.php) is looked up and added in one go; this is
 * for any page on the web, so nothing reaches the course until the teacher has
 * seen it. The model writes searches, SearXNG (a search service in the stack,
 * asking Google, Bing, Brave, DuckDuckGo and others) runs them, and the model
 * ranks the real results by how closely they match the presentation. The best
 * is then opened: a page that does not load, or whose title is not the one the
 * search reported, is passed over for the next. A teacher can also paste a link
 * from anywhere, which is opened and checked the same way.
 *
 * Kept per course in the plugin's config, as the videos are: for each
 * presentation the links accepted, those declined (never offered again), the
 * teacher's instructions since the last acceptance, and the one waiting.
 */
class links {

    /** Searches the model writes per suggestion. */
    const SEARCHES = 3;

    /** Results shown to the model to rank. */
    const CANDIDATES = 20;

    /** Of its ranking, how many are opened before giving up. */
    const TRIES = 4;

    /** Instructions kept per presentation, so "shorter" builds on "in English". */
    const NOTES = 4;

    /** Seconds to wait for SearXNG, which waits on several engines. */
    const SEARCH_TIMEOUT = 30;

    /** Seconds to wait for a page to open. */
    const PAGE_TIMEOUT = 12;

    /** Pages passed over in this request, and why: for the developer's debugging and the tests. */
    public static $skipped = array();

    /** Sites with a feature of their own here, or that a student cannot learn from. */
    const SKIP = '/(^|\.)(youtube\.com|youtu\.be|facebook\.com|instagram\.com|tiktok\.com|x\.com|twitter\.com|' .
        'pinterest\.[a-z.]+|amazon\.[a-z.]+|ebay\.[a-z.]+|aliexpress\.com|slideshare\.net|slideplayer\.[a-z.]+|' .
        'fliphtml5\.com|scribd\.com|prezi\.com)$/i';

    /**
     * Where a presentation's links stand.
     *
     * @param int $courseid Course
     * @param int $cmid The presentation
     * @return array 'accepted', 'declined', 'notes' and 'suggestion' (or null)
     */
    public static function deck($courseid, $cmid) {
        $state = self::state($courseid)[(int)$cmid] ?? array();
        return array_merge(array('accepted' => array(), 'declined' => array(), 'notes' => array(),
            'suggestion' => null), $state);
    }

    /**
     * Find a page for a presentation and hold it for the teacher's answer.
     *
     * A suggestion still waiting when another is asked for counts as declined.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $instructions What the teacher wants instead, if anything
     * @return array The link: url, title, site, language, reason
     * @throws \Exception if nothing suitable is found
     */
    public static function suggest($course, $cmid, $instructions = '') {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');

        $deck = self::the_deck($course->id, $cmid);
        $state = self::set_aside($course->id, $cmid);
        $instructions = trim(\core_text::substr((string)$instructions, 0, 300));
        if ($instructions !== '') {
            $state['notes'] = array_slice(array_merge($state['notes'], array($instructions)), -self::NOTES);
        }

        $headings = array();
        foreach (local_course_structure_deck_slides($cmid) as $slide) {
            if (trim($slide['heading']) !== trim($deck->name)) {
                $headings[trim($slide['heading'])] = true;
            }
        }
        $brief = "PRESENTATION: " . $deck->name . "\nCOURSE TOPIC: " . $deck->chapter . "\nSLIDES:\n- " .
            implode("\n- ", array_slice(array_keys($headings), 0, 15)) . "\n\n" .
            (empty($state['notes']) ? '' : "THE TEACHER ASKED FOR: " . implode('; ', $state['notes']) . "\n\n");

        $exclude = array_flip(array_merge($state['declined'], array_column($state['accepted'], 'url')));
        $candidates = self::find($brief, $exclude);
        if (empty($candidates)) {
            throw new \Exception(get_string('linknone', 'local_course_structure'));
        }

        $list = '';
        foreach ($candidates as $number => $page) {
            $list .= ($number + 1) . '. "' . $page['title'] . '" (' . $page['site'] . '): ' . $page['snippet'] . "\n";
        }
        $choice = \mod_book\ai_service::ask_model("A teacher wants a web page to link under a lecture " .
            "presentation, for students to read or explore. Rank the pages a web search found by how closely " .
            "each matches what the presentation covers.\n\n" . $brief . "PAGES FOUND:\n" . $list . "\n" .
            "Prefer pages a student can learn from: encyclopedias, educational and school sites, universities, " .
            "museums, public institutions, reputable publishers. Leave out shops, forums, social media, " .
            "personal blogs, sites selling homework answers, other people's slide decks and presentations, " .
            "and pages about something else that shares a word with the subject. A page that covers part of " .
            "the presentation well is a good match. Prefer pages in the language of the slides or in English; " .
            "one in another language only if nothing else fits. If the teacher asked for a language, rank only " .
            "pages in that language. Follow everything else the teacher asked for too.\n\n" .
            "Return JSON: {\"ranking\":[numbers from the list, best first, at most " . self::TRIES . ", or an " .
            "empty list if none fits],\"reasons\":{\"number\":\"one sentence on why it suits this presentation, " .
            "in the language the teacher's requests are in, or English if there are none\"},\"languages\":" .
            "{\"number\":\"the page's language\"}}", 2000, false);

        $ranking = array_slice((array)($choice['ranking'] ?? array()), 0, self::TRIES);
        if (empty($ranking)) {
            self::$skipped[] = 'the model ranked none of the ' . count($candidates) . ' pages as fitting';
        }
        foreach ($ranking as $pick) {
            $page = $candidates[(int)$pick - 1] ?? null;
            if (!$page) {
                continue;
            }
            $checked = self::open($page['url'], $page['title']);
            if (!$checked) {
                continue;
            }
            $link = $checked + array(
                'language' => \core_text::substr(trim((string)($choice['languages'][(string)$pick] ?? '')), 0, 40),
                'reason' => \core_text::substr(trim((string)($choice['reasons'][(string)$pick] ?? '')), 0, 400),
                'pasted' => false,
            );
            $state['suggestion'] = $link;
            self::save_deck($course->id, $cmid, $state);
            return $link;
        }
        self::save_deck($course->id, $cmid, $state);
        throw new \Exception(get_string('linknone', 'local_course_structure'));
    }

    /**
     * Hold a link the teacher pasted, once it has been opened, for them to accept.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $url The link
     * @return array The link
     * @throws \Exception if it is not a web address or does not open
     */
    public static function suggest_url($course, $cmid, $url) {
        self::the_deck($course->id, $cmid);
        $url = trim((string)$url);
        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            $url = 'https://' . $url;
        }
        $url = clean_param(self::ascii_url($url), PARAM_URL);
        if ($url === '' || !preg_match('~^https?://[^/\s]+~i', $url)) {
            throw new \Exception(get_string('linkbadurl', 'local_course_structure'));
        }
        $checked = self::open($url, '');
        if (!$checked) {
            throw new \Exception(get_string('linkwontopen', 'local_course_structure', s($url)));
        }
        $state = self::set_aside($course->id, $cmid);
        $state['suggestion'] = $checked + array('language' => '', 'reason' => '', 'pasted' => true);
        self::save_deck($course->id, $cmid, $state);
        return $state['suggestion'];
    }

    /**
     * Add the waiting link under the presentation on the course page.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @return array The link
     * @throws \Exception if nothing is waiting
     */
    public static function accept($course, $cmid) {
        $state = self::deck($course->id, $cmid);
        $link = $state['suggestion'];
        if (!$link) {
            throw new \Exception(get_string('linknothingwaiting', 'local_course_structure'));
        }
        $state['accepted'][] = $link;
        $state['suggestion'] = null;
        $state['notes'] = array();
        self::publish($cmid, $state['accepted']);
        self::save_deck($course->id, $cmid, $state);
        return $link;
    }

    /**
     * Turn down the waiting link; it is not offered again.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     */
    public static function decline($course, $cmid) {
        self::save_deck($course->id, $cmid, self::set_aside($course->id, $cmid));
    }

    /**
     * Take an accepted link back off the course page.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $url The link
     */
    public static function remove($course, $cmid, $url) {
        $state = self::deck($course->id, $cmid);
        $state['accepted'] = array_values(array_filter($state['accepted'], function($link) use ($url) {
            return $link['url'] !== $url;
        }));
        // Taken off is turned down: it would not be wanted back next time.
        $state['declined'][] = (string)$url;
        self::publish($cmid, $state['accepted']);
        self::save_deck($course->id, $cmid, $state);
    }

    /**
     * The links under a presentation, as they go in its description.
     *
     * Each line starts with <p> and each link is marked for the multimedia
     * filter to leave alone, as with the videos, so a link to a page with a
     * video in it stays a link.
     *
     * @param array $accepted Links
     * @return string HTML, or '' if there are none
     */
    public static function html($accepted) {
        if (empty($accepted)) {
            return '';
        }
        $html = '<p>' . get_string('linksheading', 'local_course_structure') . '</p>';
        foreach ($accepted as $link) {
            $html .= '<p><a href="' . s($link['url']) . '" class="nomediaplugin" target="_blank" ' .
                'rel="noopener nofollow">' . s($link['title']) . '</a> <small>(' . s($link['site']) . ')</small></p>';
        }
        return $html;
    }

    /**
     * Run the model's searches through SearXNG.
     *
     * @param string $brief What the presentation covers and what the teacher wants
     * @param array $exclude Links not to offer, as keys
     * @return array Pages: url, title, site, snippet
     * @throws \Exception if SearXNG cannot be reached
     */
    private static function find($brief, $exclude) {
        $reply = \mod_book\ai_service::ask_model("A teacher wants a web page to link under a lecture " .
            "presentation. Write the searches to type into a search engine to find one.\n\n" . $brief .
            "Write each search as someone looking for such a page would type it: the subject in a few words, " .
            "perhaps the level (\"for students\"). Never use words like lecture, presentation, slides or " .
            "download: they find other people's slide decks rather than pages that teach the subject. Give " .
            self::SEARCHES . " searches: one in the language " .
            "of the slides, one in English, and one more in whichever of the two suits the subject better. If " .
            "the teacher asks for a language, write them all in it. Give each search's two-letter language " .
            "code (Macedonian is mk, not bg).\n\n" .
            "Return JSON: {\"searches\":[{\"query\":\"...\",\"language\":\"mk\"}]}", 1500, false);

        $lists = array();
        $reached = false;
        foreach (array_slice((array)($reply['searches'] ?? array()), 0, self::SEARCHES) as $search) {
            $pages = array();
            $query = trim((string)($search['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $language = clean_param((string)($search['language'] ?? ''), PARAM_ALPHA);
            $results = self::searxng($query, $language);
            self::$skipped[] = 'search "' . $query . '" [' . $language . ']: ' .
                ($results === null ? 'no answer' : count($results) . ' results');
            if ($results === null) {
                continue;
            }
            $reached = true;
            foreach ($results as $result) {
                $url = self::ascii_url((string)($result['url'] ?? ''));
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if (!preg_match('~^https?://~i', $url) || $host === '' || preg_match(self::SKIP, $host) ||
                        isset($exclude[$url]) || isset($pages[$url])) {
                    continue;
                }
                $pages[$url] = array(
                    'url' => $url,
                    'title' => \core_text::substr(trim(html_entity_decode((string)($result['title'] ?? ''),
                        ENT_QUOTES, 'UTF-8')), 0, 200),
                    'site' => preg_replace('/^www\./', '', $host),
                    'snippet' => \core_text::substr(trim(preg_replace('/\s+/u', ' ',
                        (string)($result['content'] ?? ''))), 0, 200),
                );
            }
            $lists[] = array_values($pages);
        }
        if (!$reached) {
            throw new \Exception(get_string('linksearchdown', 'local_course_structure'));
        }
        // Each search's results in turn, so the first search's cannot crowd out the
        // others: an English search's pages reach the model when English was asked for.
        $merged = array();
        for ($i = 0; count($merged) < self::CANDIDATES && $lists; $i++) {
            $lists = array_filter($lists, function($list) use ($i) {
                return isset($list[$i]);
            });
            foreach ($lists as $list) {
                if (!isset($merged[$list[$i]['url']]) && count($merged) < self::CANDIDATES) {
                    $merged[$list[$i]['url']] = $list[$i];
                }
            }
        }
        return array_values($merged);
    }

    /**
     * One search, through SearXNG's JSON answer.
     *
     * The address is the site administrator's, normally the stack's own
     * container, so it is called without the protection against internal
     * addresses that every page found goes through.
     *
     * @param string $query The search
     * @param string $language Two-letter code, or ''
     * @return array|null Results, or null if SearXNG did not answer
     */
    private static function searxng($query, $language) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $base = rtrim(trim((string)get_config('local_course_structure', 'searxngurl')) ?: 'http://searxng:8080', '/');
        $curl = new \curl(array('ignoresecurity' => true));
        $body = $curl->get($base . '/search', array('q' => $query, 'format' => 'json',
            'language' => $language !== '' ? $language : 'all', 'safesearch' => 2),
            array('CURLOPT_TIMEOUT' => self::SEARCH_TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 5));
        $data = json_decode((string)$body, true);
        if ($curl->get_errno() || !is_array($data) || !isset($data['results'])) {
            debugging('SearXNG did not answer for "' . $query . '": ' . $curl->error, DEBUG_DEVELOPER);
            return null;
        }
        return $data['results'];
    }

    /**
     * Open a page and confirm it is what the search said it was.
     *
     * Through Moodle's own curl with its security left on, so a link to the
     * stack's other containers or to the server itself is refused as any
     * internal address is. A page that does not load is no use to a student,
     * and one whose title is not the search's has moved or been replaced.
     *
     * @param string $url The link
     * @param string $title The title the search reported, or '' for none
     * @return array|null url (where it ended up), title, site; null if it fails
     */
    private static function open($url, $title) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        // Many sites turn away anything that does not look like a browser.
        $curl->setopt(array('CURLOPT_TIMEOUT' => self::PAGE_TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 6,
            'CURLOPT_FOLLOWLOCATION' => true, 'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_USERAGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ' .
                'Chrome/130.0 Safari/537.36',
            'CURLOPT_HTTPHEADER' => array('Accept: text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.8',
                'Accept-Language: mk,en;q=0.8')));
        $body = (string)$curl->get($url);
        $info = $curl->get_info();
        $code = (int)($info['http_code'] ?? 0);
        $type = strtolower((string)($info['content_type'] ?? ''));
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));

        // A site that refuses automated visits may still open for a person, so the
        // page is offered with a note that it could not be checked. A blocked
        // address (internal, or one that does not resolve) is not: get_errno is set.
        if (!$curl->get_errno() && in_array($code, array(401, 403, 429), true)) {
            self::$skipped[] = $url . ': ' . $code . ', offered unchecked';
            return array('url' => $url, 'title' => \core_text::substr($title ?: $host, 0, 200), 'site' => $host,
                'checked' => false);
        }
        if ($curl->get_errno() || $code < 200 || $code >= 300) {
            self::$skipped[] = $url . ': ' . ($curl->get_errno() ? $curl->error : 'HTTP ' . $code);
            return null;
        }
        $final = (string)($info['url'] ?? $url) ?: $url;
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($final, PHP_URL_HOST)));

        $found = '';
        if (strpos($type, 'pdf') !== false) {
            $found = urldecode(basename((string)parse_url($final, PHP_URL_PATH)));
        } else if (strpos($type, 'html') !== false || $type === '') {
            if (preg_match('~<title[^>]*>(.*?)</title>~is', substr($body, 0, 300000), $match)) {
                $found = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8')));
            }
            if (!mb_check_encoding($found, 'UTF-8')) {
                $found = '';
            }
        } else {
            self::$skipped[] = $url . ': not a web page (' . $type . ')';
            return null;
        }
        if ($title !== '' && $found !== '' && !self::alike($title, $found)) {
            self::$skipped[] = $url . ': title "' . $found . '" is not "' . $title . '"';
            return null;
        }
        return array('url' => $final, 'title' => \core_text::substr($found ?: ($title ?: $host), 0, 200),
            'site' => $host, 'checked' => true);
    }

    /**
     * A link with its non-ASCII characters encoded, as a browser sends it.
     *
     * Moodle's URL cleaning drops a link with Cyrillic in it, such as
     * https://mk.wikipedia.org/wiki/Биосфера, so the path is percent-encoded
     * and a Cyrillic domain written in its ASCII (punycode) form.
     *
     * @param string $url The link
     * @return string
     */
    public static function ascii_url($url) {
        $url = trim($url);
        if (preg_match('~^(https?://)([^/?#]+)(.*)$~is', $url, $parts)) {
            $host = $parts[2];
            if (preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
                $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
            }
            $url = $parts[1] . $host . $parts[3];
        }
        return preg_replace_callback('/[^\x21-\x7e]/', function($match) {
            return rawurlencode($match[0]);
        }, $url);
    }

    /**
     * Whether two titles share enough words to be the same page's.
     *
     * @param string $a One title
     * @param string $b The other
     * @return bool
     */
    private static function alike($a, $b) {
        $words = function($text) {
            return array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text)),
                function($word) {
                    return \core_text::strlen($word) > 2;
                }));
        };
        $first = $words($a);
        $second = $words($b);
        if (empty($first) || empty($second)) {
            return true;
        }
        $shorter = (count($first) <= count($second)) ? $first : $second;
        $longer = (count($first) <= count($second)) ? $second : $first;
        return count(array_intersect($shorter, $longer)) >= max(1, (int)ceil(count($shorter) / 3));
    }

    /**
     * The presentation, or an error if it is not one of the course's.
     *
     * @param int $courseid Course
     * @param int $cmid The presentation
     * @return \stdClass
     * @throws \Exception
     */
    private static function the_deck($courseid, $cmid) {
        global $CFG;
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');
        $decks = local_course_structure_decks($courseid);
        if (!isset($decks[$cmid])) {
            throw new \Exception(get_string('nosuchdeck', 'local_course_structure'));
        }
        return $decks[$cmid];
    }

    /**
     * A presentation's state with the waiting link, if any, turned down.
     *
     * @param int $courseid Course
     * @param int $cmid The presentation
     * @return array
     */
    private static function set_aside($courseid, $cmid) {
        $state = self::deck($courseid, $cmid);
        if ($state['suggestion']) {
            $state['declined'][] = $state['suggestion']['url'];
            $state['suggestion'] = null;
        }
        return $state;
    }

    /**
     * Put the accepted links under the presentation on the course page.
     *
     * @param int $cmid The presentation
     * @param array $accepted Links
     */
    private static function publish($cmid, $accepted) {
        global $CFG;
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');
        local_course_structure_set_intro_block($cmid, 'links', self::html($accepted));
    }

    /**
     * Every presentation's links in a course.
     *
     * @param int $courseid Course
     * @return array Keyed by course module id
     */
    private static function state($courseid) {
        $raw = get_config('local_course_structure', 'links_' . (int)$courseid);
        $state = $raw ? json_decode($raw, true) : null;
        return is_array($state) ? $state : array();
    }

    /**
     * Store one presentation's links.
     *
     * @param int $courseid Course
     * @param int $cmid The presentation
     * @param array $deck Its state
     */
    private static function save_deck($courseid, $cmid, $deck) {
        $state = self::state($courseid);
        $deck['declined'] = array_values(array_unique($deck['declined']));
        $state[(int)$cmid] = $deck;
        set_config('links_' . (int)$courseid, json_encode($state, JSON_UNESCAPED_UNICODE), 'local_course_structure');
    }
}
