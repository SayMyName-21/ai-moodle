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
 * YouTube videos to go with a presentation.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

/**
 * Finds a video for a presentation, for the teacher to accept or turn down.
 *
 * The model searches the web for videos on what the presentation covers. What it
 * reports is not trusted as it stands: every video is looked up on YouTube
 * itself, which answers only for a video that exists and may be embedded, and
 * the title YouTube gives must resemble the one the model claimed, so that a
 * made-up or mistyped id pointing at some other video is dropped. The teacher
 * then sees the video, plays it, and accepts it, declines it, or says what to
 * look for instead. Nothing reaches the course until a video is accepted.
 *
 * Kept per course in the plugin's config: for each presentation the videos
 * accepted, those declined (never offered again), the teacher's instructions
 * since the last acceptance, and the suggestion awaiting an answer.
 */
class videos {

    /** Candidates asked of the model per search; the first that checks out is offered. */
    const CANDIDATES = 5;

    /** Instructions kept per presentation, so "shorter" builds on "in English". */
    const NOTES = 4;

    /** Seconds to wait for YouTube to answer. */
    const TIMEOUT = 10;

    /**
     * Where a presentation's videos stand.
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
     * Find a video for a presentation and hold it for the teacher's answer.
     *
     * A suggestion still waiting when another is asked for counts as declined.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $instructions What the teacher wants instead, if anything
     * @return array The video: id, url, title, channel, thumbnail, language, reason
     * @throws \Exception if no suitable video is found
     */
    public static function suggest($course, $cmid, $instructions = '') {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');

        $decks = local_course_structure_decks($course->id);
        if (!isset($decks[$cmid])) {
            throw new \Exception(get_string('nosuchdeck', 'local_course_structure'));
        }
        $deck = $decks[$cmid];

        $state = self::deck($course->id, $cmid);
        if ($state['suggestion']) {
            $state['declined'][] = $state['suggestion']['id'];
            $state['suggestion'] = null;
        }
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
        $headings = array_slice(array_keys($headings), 0, 15);

        $exclude = array_merge($state['declined'], array_column($state['accepted'], 'id'));
        $video = self::search($deck, $headings, $state['notes'], $exclude);

        $state['suggestion'] = $video;
        self::save_deck($course->id, $cmid, $state);
        return $video;
    }

    /**
     * Add the waiting suggestion under the presentation on the course page.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param bool $embed Show it as a player rather than as a link
     * @return array The video
     * @throws \Exception if nothing is waiting
     */
    public static function accept($course, $cmid, $embed = false) {
        $state = self::deck($course->id, $cmid);
        $video = $state['suggestion'];
        if (!$video) {
            throw new \Exception(get_string('videonothingwaiting', 'local_course_structure'));
        }
        $video['display'] = $embed ? 'embed' : 'link';
        $state['accepted'][] = $video;
        $state['suggestion'] = null;
        $state['notes'] = array();
        self::publish($cmid, $state['accepted']);
        self::save_deck($course->id, $cmid, $state);
        return $video;
    }

    /**
     * Turn down the waiting suggestion; it is not offered again.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     */
    public static function decline($course, $cmid) {
        $state = self::deck($course->id, $cmid);
        if ($state['suggestion']) {
            $state['declined'][] = $state['suggestion']['id'];
            $state['suggestion'] = null;
            self::save_deck($course->id, $cmid, $state);
        }
    }

    /**
     * Show an accepted video as a player or as a link.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $videoid YouTube id
     * @param bool $embed Player rather than link
     * @return array The video
     * @throws \Exception if it is not among the presentation's videos
     */
    public static function set_display($course, $cmid, $videoid, $embed) {
        $state = self::deck($course->id, $cmid);
        foreach ($state['accepted'] as $index => $video) {
            if ($video['id'] === (string)$videoid) {
                $state['accepted'][$index]['display'] = $embed ? 'embed' : 'link';
                self::publish($cmid, $state['accepted']);
                self::save_deck($course->id, $cmid, $state);
                return $state['accepted'][$index];
            }
        }
        throw new \Exception('Video ' . $videoid . ' is not under this presentation.');
    }

    /**
     * Whether an accepted video is shown as a player.
     *
     * Videos accepted before there was a choice were all shown as players, and
     * stay so until someone changes them.
     *
     * @param array $video An accepted video
     * @return bool
     */
    public static function embedded($video) {
        return ($video['display'] ?? 'embed') === 'embed';
    }

    /**
     * Take an accepted video back off the course page.
     *
     * @param \stdClass $course The course
     * @param int $cmid The presentation
     * @param string $videoid YouTube id
     */
    public static function remove($course, $cmid, $videoid) {
        $state = self::deck($course->id, $cmid);
        $state['accepted'] = array_values(array_filter($state['accepted'], function($video) use ($videoid) {
            return $video['id'] !== $videoid;
        }));
        // Taken off is turned down: it would not be wanted back next time.
        $state['declined'][] = (string)$videoid;
        self::publish($cmid, $state['accepted']);
        self::save_deck($course->id, $cmid, $state);
    }

    /**
     * The videos under a presentation, as they go in its description.
     *
     * A video to embed is a plain link, which Moodle's multimedia filter turns
     * into a player on the course page (where the filter is off it stays a
     * link). One to link to is marked for the filter to leave alone, and opens
     * on YouTube. Each line starts with <p>: the filter skips a whole text in
     * which a line starts with an element it is told to leave alone.
     *
     * @param array $accepted Videos
     * @return string HTML, or '' if there are none
     */
    public static function html($accepted) {
        if (empty($accepted)) {
            return '';
        }
        $html = '<p>' . get_string('videosheading', 'local_course_structure') . '</p>';
        foreach ($accepted as $video) {
            $attributes = self::embedded($video) ? '' : ' class="nomediaplugin" target="_blank" rel="noopener"';
            $html .= '<p><a href="' . s($video['url']) . '"' . $attributes . '>' . s($video['title']) . '</a>' .
                ($video['channel'] !== '' ? ' <small>(' . s($video['channel']) . ')</small>' : '') . '</p>';
        }
        return $html;
    }

    /**
     * Find a video, through YouTube's own search if the site has a key for it.
     *
     * @param \stdClass $deck The presentation, from local_course_structure_decks
     * @param array $headings Its slide headings
     * @param array $notes The teacher's instructions
     * @param array $exclude Video ids not to offer
     * @return array The video
     * @throws \Exception if none is found
     */
    private static function search($deck, $headings, $notes, $exclude) {
        $key = trim((string)get_config('local_course_structure', 'youtubekey'));
        return ($key !== '') ? self::search_youtube($deck, $headings, $notes, $exclude, $key)
            : self::search_web($deck, $headings, $notes, $exclude);
    }

    /**
     * What the model is told about the presentation and what the teacher wants.
     *
     * @param \stdClass $deck The presentation
     * @param array $headings Its slide headings
     * @param array $notes The teacher's instructions
     * @return string
     */
    private static function brief($deck, $headings, $notes) {
        $text = "PRESENTATION: " . $deck->name . "\n" .
            "COURSE TOPIC: " . $deck->chapter . "\n" .
            "SLIDES:\n- " . implode("\n- ", $headings) . "\n\n";
        if (!empty($notes)) {
            $text .= "THE TEACHER ASKS FOR (the last is the most recent; follow all of them):\n- " .
                implode("\n- ", $notes) . "\n\n";
        }
        return $text . "Unless the teacher says otherwise, prefer videos in the language the slides are " .
            "written in, and if there are no good ones, take English ones. A video should cover the " .
            "subject of the presentation as a whole or its central part, at a level fit for these " .
            "students, not just mention it.\n\n";
    }

    /**
     * Search with YouTube's Data API, and have the model choose among what it returns.
     *
     * Two small requests to the model - what to search for, then which result to
     * offer - and the videos themselves come from YouTube, so none can be made up.
     *
     * @param \stdClass $deck The presentation
     * @param array $headings Its slide headings
     * @param array $notes The teacher's instructions
     * @param array $exclude Video ids not to offer
     * @param string $key YouTube Data API key
     * @return array The video chosen
     * @throws \Exception if YouTube refuses or nothing suits
     */
    private static function search_youtube($deck, $headings, $notes, $exclude, $key) {
        $brief = self::brief($deck, $headings, $notes);

        $reply = \mod_book\ai_service::ask_model("A teacher wants a YouTube video to show alongside a lecture " .
            "presentation. Write the searches to type into YouTube to find one.\n\n" . $brief .
            "Write each search as someone looking for such a video would type it: the subject, perhaps " .
            "the level (such as \"for school\"), in a few words. Never use words like lecture, " .
            "presentation or slides, which find recorded talks rather than videos that teach.\n" .
            "Give three searches: one in the language of the slides; one in a closely related language " .
            "with far more videos (Serbian or Russian for Macedonian, for instance); and one in English. " .
            "If the teacher asks for a language, write all three in it. Give each search's two-letter " .
            "language code (Macedonian is mk, not bg).\n\n" .
            "Return JSON: {\"searches\":[{\"query\":\"...\",\"language\":\"mk\"}]}", 1500, false);

        $candidates = array();
        foreach (array_slice((array)($reply['searches'] ?? array()), 0, 3) as $search) {
            $query = trim((string)($search['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $items = self::youtube('search', array('part' => 'snippet', 'type' => 'video', 'maxResults' => 8,
                'videoEmbeddable' => 'true', 'safeSearch' => 'strict', 'q' => $query,
                'relevanceLanguage' => clean_param((string)($search['language'] ?? ''), PARAM_ALPHA)), $key);
            foreach ($items as $item) {
                $id = (string)($item['id']['videoId'] ?? '');
                if ($id !== '' && !in_array($id, $exclude, true) && !isset($candidates[$id])) {
                    $candidates[$id] = array(
                        'title' => html_entity_decode((string)$item['snippet']['title'], ENT_QUOTES, 'UTF-8'),
                        'channel' => html_entity_decode((string)$item['snippet']['channelTitle'], ENT_QUOTES, 'UTF-8'),
                        'description' => \core_text::substr((string)$item['snippet']['description'], 0, 160),
                        'thumbnail' => (string)($item['snippet']['thumbnails']['medium']['url'] ?? ''),
                    );
                }
            }
        }
        if (empty($candidates)) {
            throw new \Exception(get_string('videonone', 'local_course_structure'));
        }

        // How long each is, which a teacher planning a lesson cares about.
        foreach (self::youtube('videos', array('part' => 'contentDetails',
                'id' => implode(',', array_keys($candidates))), $key) as $item) {
            if (isset($candidates[$item['id']])) {
                $length = new \DateInterval((string)($item['contentDetails']['duration'] ?? 'PT0S'));
                $candidates[$item['id']]['minutes'] = $length->h * 60 + $length->i + ($length->s >= 30 ? 1 : 0);
            }
        }

        $ids = array_keys($candidates);
        $list = '';
        foreach ($ids as $number => $id) {
            $video = $candidates[$id];
            $list .= ($number + 1) . '. "' . $video['title'] . '" by ' . $video['channel'] .
                (isset($video['minutes']) ? ', ' . $video['minutes'] . ' min' : '') . ': ' . $video['description'] . "\n";
        }

        $choice = \mod_book\ai_service::ask_model("A teacher wants a YouTube video to show alongside a lecture " .
            "presentation. Choose the one of the videos YouTube found that best teaches its subject.\n\n" .
            $brief . "VIDEOS FOUND:\n" . $list . "\n" .
            "A good video on the subject that does not cover every slide is still a good choice. " .
            "Answer 0 only if none of them is about the subject at all, or every one is plainly unfit " .
            "for students (a recorded conference talk, say, or entertainment).\n\n" .
            "Return JSON: {\"pick\": number from the list or 0 for none, \"language\":\"the video's language\", " .
            "\"reason\":\"one sentence on why it suits this presentation, written in the language the " .
            "teacher's requests are in, or in English if there are none\"}", 1500, false);

        $pick = (int)($choice['pick'] ?? 0);
        if ($pick < 1 || $pick > count($ids)) {
            throw new \Exception(get_string('videonone', 'local_course_structure'));
        }
        $id = $ids[$pick - 1];
        return array(
            'id' => $id,
            'url' => 'https://www.youtube.com/watch?v=' . $id,
            'title' => $candidates[$id]['title'],
            'channel' => $candidates[$id]['channel'],
            'thumbnail' => $candidates[$id]['thumbnail'],
            'language' => \core_text::substr(trim((string)($choice['language'] ?? '')), 0, 40),
            'reason' => \core_text::substr(trim((string)($choice['reason'] ?? '')), 0, 400),
        );
    }

    /**
     * Ask YouTube's Data API for something.
     *
     * @param string $resource 'search' or 'videos'
     * @param array $params Query parameters
     * @param string $key API key
     * @return array The items it returned
     * @throws \Exception if YouTube refuses
     */
    private static function youtube($resource, $params, $key) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $body = $curl->get('https://www.googleapis.com/youtube/v3/' . $resource,
            array_filter($params, 'strlen') + array('key' => $key),
            array('CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 5));
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        $data = json_decode((string)$body, true);
        if ($code !== 200 || !is_array($data)) {
            // The key itself is never repeated; YouTube's reason for refusing is enough.
            $reason = is_array($data) ? (string)($data['error']['errors'][0]['reason'] ?? '') : '';
            throw new \Exception(get_string('videoapifailed', 'local_course_structure',
                $code . ($reason !== '' ? ', ' . s($reason) : '')));
        }
        return (array)($data['items'] ?? array());
    }

    /**
     * Ask the model to search the web, then check what it found against YouTube.
     *
     * Used when the site has no YouTube key. Searching the web costs far more of
     * the AI allowance than choosing among YouTube's own results.
     *
     * @param \stdClass $deck The presentation, from local_course_structure_decks
     * @param array $headings Its slide headings
     * @param array $notes The teacher's instructions
     * @param array $exclude Video ids not to offer
     * @return array The first video that checks out
     * @throws \Exception if none does
     */
    private static function search_web($deck, $headings, $notes, $exclude) {
        $prompt = "Find YouTube videos that a teacher could show alongside this lecture presentation.\n\n" .
            self::brief($deck, $headings, $notes);
        if (!empty($exclude)) {
            $prompt .= "Do not suggest these videos again (YouTube ids): " . implode(', ', $exclude) . "\n\n";
        }
        $prompt .= "Search YouTube for such videos, once or twice at most. " .
            "Give up to " . self::CANDIDATES . " candidates, best first. Only give videos your search " .
            "actually found, with the link and title exactly as YouTube shows them; never make a link up.\n\n" .
            "Return only JSON:\n" .
            "{\"videos\":[{\"url\":\"https://www.youtube.com/watch?v=...\",\"title\":\"...\"," .
            "\"language\":\"...\",\"reason\":\"one sentence on why it suits this presentation, written in " .
            "the language the teacher's requests are in, or in English if there are none\"}]}";

        $reply = \mod_book\ai_service::search_web($prompt, 4000);

        foreach (array_slice((array)($reply['videos'] ?? array()), 0, self::CANDIDATES) as $candidate) {
            $id = self::video_id((string)($candidate['url'] ?? ''));
            if ($id === null || in_array($id, $exclude, true)) {
                continue;
            }
            $found = self::lookup($id);
            if ($found === null) {
                continue;
            }
            $claimed = trim((string)($candidate['title'] ?? ''));
            if ($claimed !== '' && !self::alike($claimed, $found['title'])) {
                continue;
            }
            return array(
                'id' => $id,
                'url' => 'https://www.youtube.com/watch?v=' . $id,
                'title' => $found['title'],
                'channel' => $found['channel'],
                'thumbnail' => $found['thumbnail'],
                'language' => \core_text::substr(trim((string)($candidate['language'] ?? '')), 0, 40),
                'reason' => \core_text::substr(trim((string)($candidate['reason'] ?? '')), 0, 400),
            );
        }

        throw new \Exception(get_string('videonone', 'local_course_structure'));
    }

    /**
     * The id in a link to a YouTube video, in any of its usual forms.
     *
     * @param string $url Link
     * @return string|null The eleven-character id, or null if this is not one
     */
    private static function video_id($url) {
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
                $url, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Ask YouTube about a video.
     *
     * Its oEmbed answer needs no key, and comes only for a video that exists and
     * may be shown on other sites.
     *
     * @param string $id YouTube id
     * @return array|null Its title, channel and thumbnail, or null if YouTube does not know it
     */
    private static function lookup($id) {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $body = $curl->get('https://www.youtube.com/oembed', array(
            'url' => 'https://www.youtube.com/watch?v=' . $id, 'format' => 'json'),
            array('CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 5));
        if ((int)($curl->get_info()['http_code'] ?? 0) !== 200) {
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || trim((string)($data['title'] ?? '')) === '') {
            return null;
        }
        return array(
            'title' => (string)$data['title'],
            'channel' => (string)($data['author_name'] ?? ''),
            'thumbnail' => (string)($data['thumbnail_url'] ?? ''),
        );
    }

    /**
     * Whether two titles are of the same video, allowing for small differences.
     *
     * At least a third of the shorter title's words must appear in the longer.
     *
     * @param string $a One title
     * @param string $b The other
     * @return bool
     */
    private static function alike($a, $b) {
        $words = function($text) {
            return array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text)),
                function($word) {
                    return \core_text::strlen($word) > 1;
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
     * Put the accepted videos under the presentation on the course page.
     *
     * @param int $cmid The presentation
     * @param array $accepted Videos
     */
    private static function publish($cmid, $accepted) {
        global $CFG;
        require_once($CFG->dirroot . '/local/course_structure/locallib.php');
        local_course_structure_set_intro_block($cmid, 'videos', self::html($accepted));
    }

    /**
     * Every presentation's videos in a course.
     *
     * @param int $courseid Course
     * @return array Keyed by course module id
     */
    private static function state($courseid) {
        $raw = get_config('local_course_structure', 'videos_' . (int)$courseid);
        $state = $raw ? json_decode($raw, true) : null;
        return is_array($state) ? $state : array();
    }

    /**
     * Store one presentation's videos.
     *
     * @param int $courseid Course
     * @param int $cmid The presentation
     * @param array $deck Its state
     */
    private static function save_deck($courseid, $cmid, $deck) {
        $state = self::state($courseid);
        $deck['declined'] = array_values(array_unique($deck['declined']));
        $state[(int)$cmid] = $deck;
        set_config('videos_' . (int)$courseid, json_encode($state, JSON_UNESCAPED_UNICODE), 'local_course_structure');
    }
}
