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
 * English further reading for a presentation.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

class sources {

    /** Most links to attach to one presentation. */
    const MAX_LINKS = 6;

    /** Most searches to make for one presentation, found or not. */
    const MAX_LOOKUPS = 8;

    /** Seconds to allow one lookup. */
    const TIMEOUT = 10;

    /** Seconds to wait for Wikipedia to answer at all. */
    const CONNECT_TIMEOUT = 5;

    /**
     * Seconds to leave between searches. Wikipedia turns away a caller that asks
     * quickly, and a handful of links is not worth being turned away for.
     */
    const SPACING = 2;

    /**
     * How alike an article's name and the topic searched for must be, as a
     * percentage, before the article is taken to be about that topic.
     */
    const MATCH_THRESHOLD = 60;

    /**
     * Find English reading for a list of topics as the book names them.
     *
     * Each topic is searched on the Macedonian Wikipedia and the article found
     * there is followed to its English counterpart. Going through the book's own
     * language keeps the topic intact where translating the words would not, and
     * the English name comes back from the API rather than being guessed, so a
     * link that is returned is a page that exists.
     *
     * Wikipedia answers every search with its closest guess, however poor, so a
     * hit whose own name bears no resemblance to the topic is dropped. Without
     * that, a heading like "СТОПАНСКО-ЕКОНОМСКИ АКТИВНОСТИ" is answered with an
     * article about a particular village.
     *
     * @param array $topics Topic names as the book writes them
     * @param string $lang Wikipedia to search in
     * @return array Links, each with its English title and url
     */
    public static function lookup($topics, $lang = 'mk') {
        $links = array();
        $lookups = 0;

        foreach ($topics as $topic) {
            if (count($links) >= self::MAX_LINKS || $lookups >= self::MAX_LOOKUPS) {
                break;
            }

            $topic = trim((string)$topic);
            if ($topic === '') {
                continue;
            }
            $lookups++;

            $article = self::search($topic, $lang);
            if ($article === null) {
                continue;
            }

            if (!self::is_about($topic, $article['names'])) {
                continue;
            }

            if (isset($links[$article['english']])) {
                continue;
            }

            $links[$article['english']] = array(
                'title' => $article['english'],
                'url' => 'https://en.wikipedia.org/wiki/' .
                    rawurlencode(str_replace(' ', '_', $article['english'])),
            );
        }

        return array_values($links);
    }

    /**
     * Ask a Wikipedia for its best article on a topic and that article's English name.
     *
     * The names a reader can reach the article by are asked for alongside it. A
     * textbook often uses a plainer word than the article's own title, and those
     * plainer words are exactly what Wikipedia keeps as redirects, so they are
     * what tells an article about the right subject from a chance resemblance.
     *
     * @param string $topic Topic to search for
     * @param string $lang Wikipedia to search in
     * @return array|null Every name the article answers to, and its English name
     */
    private static function search($topic, $lang) {
        // Moodle sets PHP's argument separator to &amp; for building HTML, which
        // a URL cannot use, so the plain separator is asked for by name.
        $url = 'https://' . $lang . '.wikipedia.org/w/api.php?' . http_build_query(array(
            'action' => 'query',
            'generator' => 'search',
            'gsrsearch' => $topic,
            'gsrlimit' => 1,
            'prop' => 'langlinks|redirects',
            'lllang' => 'en',
            'rdlimit' => 50,
            'format' => 'json',
            'formatversion' => 2,
        ), '', '&');

        $data = json_decode(self::fetch($url), true);
        $page = $data['query']['pages'][0] ?? null;

        if (!isset($page['title']) || !isset($page['langlinks'][0]['title'])) {
            return null;
        }

        $names = array((string)$page['title']);
        foreach (($page['redirects'] ?? array()) as $redirect) {
            if (isset($redirect['title'])) {
                $names[] = (string)$redirect['title'];
            }
        }

        return array(
            'names' => $names,
            'english' => (string)$page['langlinks'][0]['title'],
        );
    }

    /**
     * Whether an article is about the topic, judged by any name it answers to.
     *
     * Matching the article's own title catches a topic the book names the same
     * way. Matching one of its redirects catches a topic the book names
     * differently, as "РАСТИТЕЛЕН СВЕТ" does for the article "Флора". An article
     * that answers to no name resembling the topic was simply the closest thing
     * the search could find and is not about it at all.
     *
     * @param string $topic Topic searched for
     * @param array $names Every name the article answers to
     * @return bool
     */
    private static function is_about($topic, $names) {
        $topic = self::normalise($topic);
        if ($topic === '') {
            return false;
        }

        foreach ($names as $name) {
            $name = self::normalise($name);
            if ($name === '') {
                continue;
            }

            // A heading often names a wider subject than the article, or the
            // reverse, as with "ОКЕАНИ И МОРИЊА" and the article "Океан".
            if (mb_strpos($topic, $name) !== false || mb_strpos($name, $topic) !== false) {
                return true;
            }

            similar_text($topic, $name, $percent);
            if ($percent >= self::MATCH_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a name to its bare letters for comparison.
     *
     * @param string $text Name to reduce
     * @return string
     */
    private static function normalise($text) {
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim(\core_text::strtolower($text));
    }

    /**
     * Fetch a URL, returning an empty string unless it answered properly.
     *
     * @param string $url Address to fetch
     * @return string Response body
     */
    private static function fetch($url) {
        global $CFG;

        // Wikipedia turns away callers that do not say who they are, and asks for
        // a way to be reached, which the site's own address provides.
        $agent = 'MoodleCourseStructure/1.0 (' . $CFG->wwwroot . '; further reading lookup)';

        static $lastcall = 0;
        $wait = self::SPACING - (time() - $lastcall);
        if ($lastcall > 0 && $wait > 0) {
            sleep($wait);
        }

        // Wikipedia turns a caller away for a while rather than for good, and a
        // run may arrive while an earlier one is still being counted against it,
        // so a refusal is waited out rather than taken as an answer.
        $backoff = array(5, 15);

        for ($attempt = 0; $attempt <= count($backoff); $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $agent);
            curl_setopt($ch, CURLOPT_HEADER, true);

            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headersize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $lastcall = time();

            $headers = substr((string)$response, 0, $headersize);
            $body = substr((string)$response, $headersize);

            if ($code === 200) {
                return (string)$body;
            }

            if ($code !== 429 || $attempt >= count($backoff)) {
                return '';
            }

            // Wikipedia says how long to wait when it feels like it.
            $wait = $backoff[$attempt];
            if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) {
                $wait = min((int)$m[1], 30);
            }
            sleep($wait);
        }

        return '';
    }
}
