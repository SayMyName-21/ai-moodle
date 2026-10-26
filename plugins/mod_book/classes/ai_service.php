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
 * AI Service for book chapter extraction
 *
 * @package    mod_book
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_book;

defined('MOODLE_INTERNAL') || die();

class ai_service {

    /**
     * Characters of book text per request. Groq's free tier allows 8000 tokens a
     * minute covering prompt and reply together, and Cyrillic costs roughly one token
     * per two characters, so a larger extract is rejected outright as too big.
     */
    const EXTRACT_CHARS = 7000;

    /** Seconds to leave between requests so the allowance has refilled. */
    const REQUEST_SPACING = 50;

    /** Pages of front matter to search for the table of contents. */
    const FRONT_MATTER_PAGES = 30;

    /**
     * Pages of back matter to search for the table of contents, where it is just
     * as often printed in Macedonian textbooks.
     */
    const BACK_MATTER_PAGES = 15;

    /** Seconds to wait for Docling to answer at all, as opposed to to finish. */
    const DOCLING_CONNECT_TIMEOUT = 5;

    /**
     * Pages sent to Docling in one conversion.
     *
     * The book is read in chunks rather than whole: one conversion of a whole
     * textbook ran for over twenty minutes as a single request that had to survive
     * to the end, while ten pages at a time measured four seconds a page. A chunk
     * that fails costs ten pages rather than the book, and each finished chunk is
     * kept, so an interrupted reading resumes where it stopped.
     */
    const BOOK_CHUNK_PAGES = 10;

    /**
     * Seconds between asking Docling whether a chunk has finished. Its status
     * check answers at once while a task runs, so the pause has to be ours.
     */
    const DOCLING_POLL_INTERVAL = 3;

    /**
     * Extract chapters from a book file using AI
     *
     * @param string $filepath Path to the uploaded book file
     * @param string $filetype Type of file (pdf, docx, txt)
     * @return array Array of chapters with title and content
     * @throws \moodle_exception
     */
    public static function extract_chapters($filepath, $filetype, $guidance = '') {
        $config = get_config('book');

        // API key not required for Ollama (local/free)
        $provider = $config->ai_provider ?? 'groq';
        if ($provider !== 'ollama' && empty($config->ai_api_key)) {
            throw new \moodle_exception('aiapikeynotset', 'mod_book');
        }

        // Read file content
        $content = self::read_file_content($filepath, $filetype);

        if (empty($content)) {
            throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'Unable to read file content');
        }

        // A PDF's content is already its contents list, found or rebuilt.
        $toc = (strtolower($filetype) === 'pdf') ? $content : self::extract_toc_section($content);
        if ($toc === '') {
            throw new \Exception('No table of contents was found in this file.');
        }

        // Call AI API to extract chapters
        $chapters = self::call_ai_api($content, $toc, $config->ai_api_key,
            $config->ai_provider ?? 'claude', $guidance);

        return $chapters;
    }

    /**
     * Read content from uploaded file
     *
     * @param string $filepath Path to file
     * @param string $filetype Type of file
     * @return string File content as text
     */
    private static function read_file_content($filepath, $filetype) {
        $content = '';

        switch (strtolower($filetype)) {
            case 'txt':
                $content = file_get_contents($filepath);
                break;

            case 'pdf':
                $content = self::book_contents($filepath)['toc'];
                break;

            case 'docx':
                $content = self::extract_docx_text($filepath);
                break;

            default:
                throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'Unsupported file type');
        }

        return $content;
    }

    /**
     * A PDF's table of contents, from wherever the book keeps one.
     *
     * The contents are looked for at the front of the book, then at the back. A
     * book with neither, or whose contents page is a scan that cannot be read,
     * has its contents rebuilt from the numbered headings on its own pages. Only
     * a book with none of these fails: turning the opening pages' text into
     * chapters line by line gave nonsense rather than an error.
     *
     * @param string $filepath Path to the PDF
     * @return array 'toc', the contents as lines ending in a page number, and
     *               'printed', whether those are the book's printed page numbers
     *               rather than the PDF's own
     * @throws \Exception if the book offers nothing to take its contents from
     */
    private static function book_contents($filepath) {
        // Docling reads a contents table as a table, which is what it is, and the
        // rows come back as lines with the entry at the front - the shape the
        // detection below expects. Read as Markdown instead it arrives wrapped in
        // pipe delimiters, which put a "|" where the chapter label should be and
        // left the detection finding 23 chapters and no sections.
        $pages = self::book_pages($filepath);

        // Each place is searched as one text, keyed by the page it starts on.
        $places = array(1 => array_slice($pages, 0, self::FRONT_MATTER_PAGES));
        if (count($pages) > self::FRONT_MATTER_PAGES) {
            $back = max(self::FRONT_MATTER_PAGES, count($pages) - self::BACK_MATTER_PAGES);
            $places[$back + 1] = array_slice($pages, $back);
        }
        // Pages that open a contents list whose entries did not come through.
        $unread = array();
        foreach ($places as $first => $place) {
            $toc = self::extract_toc_section(implode("\n", $place));
            if (self::has_entries($toc)) {
                return array('toc' => $toc, 'printed' => true);
            }
            foreach ($place as $i => $text) {
                foreach (preg_split('/\R/u', $text) as $line) {
                    if (self::is_contents_heading($line)) {
                        $unread[] = $first + $i;
                    }
                }
            }
        }

        // Rebuilding asks Docling and the model, so it is done once per book.
        $cache = make_temp_directory('mod_book/bookpages') . '/' . sha1_file($filepath) . '.contents.json';
        if (file_exists($cache)) {
            $cached = json_decode(file_get_contents($cache), true);
            if (is_array($cached) && !empty($cached['toc'])) {
                return $cached;
            }
        }

        $sections = self::heading_sections($pages);
        if (empty($sections)) {
            throw new \Exception('No table of contents could be read in the first ' .
                self::FRONT_MATTER_PAGES . ' or last ' . self::BACK_MATTER_PAGES .
                ' pages of this book, and its pages carry no numbered headings (1.1, 1.2 ...) ' .
                'to rebuild one from.');
        }

        list($names, $complete) = self::chapter_names($filepath, $pages, $sections, $unread);

        $lines = array();
        $chapter = 0;
        foreach ($sections as $section) {
            if ($section['chapter'] !== $chapter) {
                $chapter = $section['chapter'];
                $lines[] = $chapter . '. ' . ($names[$chapter] ??
                    get_string('aichapternumber', 'mod_book', $chapter)) . '.......... ' . $section['page'];
            }
            $lines[] = $section['chapter'] . '.' . $section['section'] . '. ' .
                $section['title'] . '.......... ' . $section['page'];
        }
        $contents = array('toc' => implode("\n", $lines), 'printed' => false);

        // A chapter left without a name because the model could not be reached
        // gets another chance next time.
        if ($complete) {
            file_put_contents($cache, json_encode($contents));
        }
        return $contents;
    }

    /**
     * Whether a stretch of text found as a contents list holds real entries.
     *
     * A contents page that is a scan Docling cannot read still gives its heading,
     * and the watermark across it, but no entries carrying a page or a number.
     *
     * @param string $toc Text found as a contents list
     * @return bool
     */
    private static function has_entries($toc) {
        $real = 0;
        foreach (self::toc_entries($toc) as $entry) {
            if ($entry['page'] > 0 || self::starts_entry($entry['title'])) {
                $real++;
            }
        }
        return $real >= 3;
    }

    /**
     * Rebuild a book's sections from the numbered headings on its pages.
     *
     * A section's number shows up on its first page as a heading and again at the
     * top of each following page as a running header, so where a section starts
     * can be read from the pages themselves. Display type and italic headers are
     * what OCR reads worst, though: "1.10" comes back as "1.40" and "4.2" as
     * "74.2". So the sightings are not taken one by one. The headings kept are
     * the longest run that climbs through the book in order - the same chapter a
     * few sections on, or a following chapter near its start - which a misread
     * number lands outside of, and a section whose number was never read cleanly
     * is simply missing, its pages going to the section before it.
     *
     * @param array $pages Page text, the first entry being page one
     * @return array Sections in book order, each with its chapter and section
     *               number, title and PDF page; empty if the pages carry too few
     *               numbered headings to go by
     */
    private static function heading_sections($pages) {
        $sightings = array();
        foreach ($pages as $i => $text) {
            foreach (preg_split('/\R/u', $text) as $k => $line) {
                if (preg_match('/^(\d{1,2})\.\s?(\d{1,2})\.?\s+(\p{L}.{5,})$/u', trim($line), $match)) {
                    $sightings[] = array(
                        'chapter' => (int)$match[1],
                        'section' => (int)$match[2],
                        'page' => $i + 1,
                        // A running header is the first line or two on a page,
                        // and carries the full title where a heading may wrap.
                        'header' => $k < 2,
                        'title' => self::heading_title($match[3]),
                    );
                }
            }
        }

        // Longest climbing run, counting every sighting along it, so a section
        // seen on six pages outweighs a misreading seen on one.
        $count = count($sightings);
        $length = array();
        $before = array();
        for ($j = 0; $j < $count; $j++) {
            $length[$j] = ($sightings[$j]['section'] <= 3) ? 1 : 0;
            $before[$j] = null;
            for ($i = 0; $i < $j; $i++) {
                if ($length[$i] > 0 && $length[$i] + 1 > $length[$j]
                        && self::heading_follows($sightings[$i], $sightings[$j])) {
                    $length[$j] = $length[$i] + 1;
                    $before[$j] = $i;
                }
            }
        }
        if (empty($length) || max($length) === 0) {
            return array();
        }

        $run = array();
        for ($j = array_search(max($length), $length, true); $j !== null; $j = $before[$j]) {
            array_unshift($run, $sightings[$j]);
        }

        // One entry per section, starting on the page it is first seen.
        $sections = array();
        foreach ($run as $sighting) {
            $key = $sighting['chapter'] . '.' . $sighting['section'];
            if (!isset($sections[$key])) {
                $sections[$key] = array('chapter' => $sighting['chapter'],
                    'section' => $sighting['section'], 'page' => $sighting['page'],
                    'title' => '', 'header' => false);
            }
            // The longest running header has the most of the title; a heading
            // stands in only for a section that has no header.
            $current = $sections[$key];
            if (($sighting['header'] && !$current['header'])
                    || ($sighting['header'] === $current['header']
                        && mb_strlen($sighting['title'], 'UTF-8') > mb_strlen($current['title'], 'UTF-8'))) {
                $sections[$key]['title'] = $sighting['title'];
                $sections[$key]['header'] = $sighting['header'];
            }
        }
        return (count($sections) >= 3) ? array_values($sections) : array();
    }

    /**
     * Whether one numbered heading can come after another in the same book.
     *
     * A chapter may be passed over, since one whose every section number was
     * misread leaves no sightings at all. Without that, the run could not get past
     * it, and would have to choose between the chapters before it and after.
     *
     * @param array $a The earlier sighting
     * @param array $b The later sighting
     * @return bool True for the same section again, one up to three further on
     *              in the same chapter, or one of the first in either of the next
     *              two chapters
     */
    private static function heading_follows($a, $b) {
        if ($b['page'] < $a['page']) {
            return false;
        }
        if ($b['chapter'] === $a['chapter']) {
            return $b['section'] >= $a['section'] && $b['section'] - $a['section'] <= 3;
        }
        $step = $b['chapter'] - $a['chapter'];
        return ($step === 1 || $step === 2) && $b['section'] <= 3;
    }

    /**
     * Name the chapters of a book whose contents were rebuilt from its pages.
     *
     * The name the book gives a chapter is printed in two places: its contents
     * page and its opening page. Both are tried, the contents page first, as it
     * is set in body type that OCR reads well once the page is drawn as a picture.
     * A chapter neither gives a name to is named by the model from its sections.
     *
     * @param string $filepath Path to the PDF
     * @param array $pages Page text, the first entry being page one
     * @param array $sections Result of heading_sections
     * @param array $unread PDF pages that open a contents list Docling could not read
     * @return array Names keyed by chapter number, and whether every chapter got
     *               one; a chapter left out is to be called by its number
     */
    private static function chapter_names($filepath, $pages, $sections, $unread) {
        // Where each chapter's opening page can be: after the previous chapter's
        // last section starts, up to where its own first section does.
        $spans = array();
        $from = 1;
        foreach ($sections as $section) {
            if (!isset($spans[$section['chapter']])) {
                $spans[$section['chapter']] = array($from, $section['page']);
            }
            $from = $section['page'];
        }

        $names = array();
        if (!empty($unread)) {
            try {
                $names = self::names_from_contents($filepath, $unread, array_keys($spans));
            } catch (\Exception $e) {
                // The opening pages and the model are still there to fall back on.
                $names = array();
            }
        }

        foreach ($spans as $number => $span) {
            if (!isset($names[$number])) {
                $heading = self::chapter_heading($pages, $number, $span[0], $span[1]);
                if ($heading !== null) {
                    $names[$number] = $heading;
                }
            }
        }

        $missing = array_diff(array_keys($spans), array_keys($names));
        if (!empty($missing)) {
            $names += self::names_from_model($sections, $names, $missing);
        }

        return array($names, count($names) === count($spans));
    }

    /**
     * Read chapter names off contents pages that Docling could not read directly.
     *
     * Such a page is a scan with a watermark that is real text, and Docling drops
     * every OCR reading lying under real text, which leaves the page empty. Drawn
     * as a picture without its text, the page is the bare scan, watermark gone,
     * and reads cleanly. A page that is text rather than a scan would be blank
     * that way, so if nothing is found it is drawn again with its text.
     *
     * @param string $filepath Path to the PDF
     * @param array $unread PDF pages that open a contents list
     * @param array $numbers The chapter numbers the book was found to have
     * @return array Names keyed by chapter number
     * @throws \Exception if a page cannot be drawn or read
     */
    private static function names_from_contents($filepath, $unread, $numbers) {
        foreach (array(false, true) as $withtext) {
            $names = self::names_on_pages($filepath, $unread, $numbers, $withtext);
            if (!empty($names)) {
                return $names;
            }
        }
        return array();
    }

    /**
     * Read chapter names off contents pages drawn as pictures.
     *
     * @param string $filepath Path to the PDF
     * @param array $unread PDF pages that open a contents list
     * @param array $numbers The chapter numbers the book was found to have
     * @param bool $withtext Whether to draw the pages' text as well as their images
     * @return array Names keyed by chapter number
     * @throws \Exception if a page cannot be drawn or read
     */
    private static function names_on_pages($filepath, $unread, $numbers, $withtext) {
        $total = count(self::book_pages($filepath));

        // A contents list runs on for a page or two past its heading.
        $lines = array();
        foreach ($unread as $first) {
            for ($page = $first; $page <= min($total, $first + 2); $page++) {
                foreach (self::docling_image(self::draw_page($filepath, $page, $withtext)) as $text) {
                    $lines = array_merge($lines, preg_split('/\R/u', $text));
                }
            }
        }

        // Chapter entries, set in capitals with a whole number: "2. РОТАЦИОНО
        // ДВИЖЕЊЕ НА ТВРДО ТЕЛО ........ 53".
        $found = array();
        foreach ($lines as $line) {
            if (!preg_match('/^(\d{1,2})\.\s+(\p{Lu}.*)$/u', trim($line), $match)
                    || !in_array((int)$match[1], $numbers, true)) {
                continue;
            }
            $title = preg_replace('/\s*\.{3,}.*$/u', '', $match[2]);
            $title = trim(self::heading_title($title), " \t.,;:-");
            $letters = preg_replace('/[^\p{L}]/u', '', $title);
            $capitals = preg_replace('/[^\p{Lu}]/u', '', $title);
            if (mb_strlen($letters, 'UTF-8') >= 4
                    && mb_strlen($capitals, 'UTF-8') >= 0.7 * mb_strlen($letters, 'UTF-8')) {
                $found[] = array('number' => (int)$match[1], 'title' => $title);
            }
        }

        // A section number misread as a whole one, such as "1.6." as "6.", is out
        // of order with the chapters around it. The longest climbing run of
        // numbers, in the order the page lists them, leaves it out.
        $count = count($found);
        $length = array();
        $before = array();
        for ($j = 0; $j < $count; $j++) {
            $length[$j] = 1;
            $before[$j] = null;
            for ($i = 0; $i < $j; $i++) {
                if ($found[$i]['number'] < $found[$j]['number'] && $length[$i] + 1 > $length[$j]) {
                    $length[$j] = $length[$i] + 1;
                    $before[$j] = $i;
                }
            }
        }

        $names = array();
        if ($count > 0) {
            for ($j = array_search(max($length), $length, true); $j !== null; $j = $before[$j]) {
                $names[$found[$j]['number']] = $found[$j]['title'];
            }
        }
        return $names;
    }

    /**
     * Draw one page of a PDF as a picture.
     *
     * @param string $filepath Path to the PDF
     * @param int $page PDF page number
     * @param bool $withtext Whether to draw the page's text, or only its images
     *                       and lines, which for a scan is the scan itself
     * @return string Path to a PNG, removed when the request ends
     * @throws \Exception if Ghostscript cannot draw it
     */
    private static function draw_page($filepath, $page, $withtext = true) {
        global $CFG;
        $gs = !empty($CFG->pathtogs) ? $CFG->pathtogs : '/usr/bin/gs';
        $png = make_request_directory() . '/page' . (int)$page . '.png';

        // 300 dpi is what Tesseract is tuned for; grey keeps the file small.
        $command = escapeshellarg($gs) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pnggray -r300' .
            ($withtext ? '' : ' -dFILTERTEXT') .
            ' -dFirstPage=' . (int)$page . ' -dLastPage=' . (int)$page .
            ' -sOutputFile=' . escapeshellarg($png) . ' ' . escapeshellarg($filepath) . ' 2>&1';
        exec($command, $output, $status);

        if ($status !== 0 || !file_exists($png) || filesize($png) === 0) {
            throw new \Exception('Ghostscript could not draw page ' . $page . ': ' .
                implode(' ', array_slice($output, 0, 3)));
        }
        return $png;
    }

    /**
     * Have the model name chapters from the sections in them.
     *
     * The last resort, for a chapter whose name was printed nowhere OCR could
     * read. The name is the model's, not the book's, so it is only ever used
     * where the book's own could not be found.
     *
     * @param array $sections Result of heading_sections
     * @param array $known Names already found, keyed by chapter number
     * @param array $missing Chapter numbers to name
     * @return array Names keyed by chapter number; empty if the model was no help
     */
    private static function names_from_model($sections, $known, $missing) {
        $outline = '';
        foreach ($sections as $section) {
            $number = $section['chapter'];
            if (strpos($outline, "\nCHAPTER " . $number . ':') === false) {
                $outline .= "\nCHAPTER " . $number . ': ' .
                    ($known[$number] ?? '(name unknown)') . "\n";
            }
            $outline .= '  ' . $number . '.' . $section['section'] . '. ' . $section['title'] . "\n";
        }

        $prompt = "Below are the chapters of a textbook with the sections in each. The text " .
            "was read by OCR, so some letters are misread (in Cyrillic, \"ш\" often stands " .
            "for \"т\").\n\n" .
            "Give a title to each chapter whose name is unknown: " . implode(', ', $missing) .
            ". The title should name what the chapter's sections together cover, in the " .
            "book's own language and in capitals like the chapter names already known, " .
            "and be no longer than six words.\n\n" .
            "Return JSON: {\"chapters\":[{\"number\":3,\"title\":\"...\"}]}\n" .
            mb_substr($outline, 0, 6000, 'UTF-8');

        try {
            // The model thinks before it answers, and that comes out of this
            // budget too: at 800 it ran out after naming two of four chapters.
            $reply = self::ask_model($prompt, 3000);
        } catch (\Exception $e) {
            return array();
        }

        $names = array();
        foreach ($reply['chapters'] ?? array() as $item) {
            $number = (int)($item['number'] ?? 0);
            $title = trim((string)($item['title'] ?? ''));
            if (in_array($number, $missing, true) && $title !== ''
                    && mb_strlen($title, 'UTF-8') <= 120) {
                $names[$number] = $title;
            }
        }
        return $names;
    }

    /**
     * Tidy the title read after a section number.
     *
     * @param string $title Text following the number
     * @return string Title without a page number or a second heading run onto it
     */
    private static function heading_title($title) {
        // OCR sometimes reads a heading twice onto one line: "2.1. КИНЕМАТИКА НА
        // ТВРДО 2.4. КИНЕМАЛТИКА НА ТВРДО".
        $title = preg_replace('/\s+\d{1,2}\.\s?\d{1,2}\.\s.*$/u', '', $title);
        // A running header carries the page number at its end.
        $title = preg_replace('/\s+\d{1,4}$/u', '', $title);
        return trim($title);
    }

    /**
     * The heading a numbered chapter opens with, if it was read.
     *
     * @param array $pages Page text, the first entry being page one
     * @param int $number Chapter number
     * @param int $from First page to look on
     * @param int $to Last page to look on, where its first section starts
     * @return string|null The chapter's title, or null if no heading was found
     */
    private static function chapter_heading($pages, $number, $from, $to) {
        for ($page = $from; $page <= $to; $page++) {
            foreach (preg_split('/\R/u', $pages[$page - 1] ?? '') as $line) {
                if (!preg_match('/^' . $number . '\.\s+(\p{L}.{3,})$/u', trim($line), $match)) {
                    continue;
                }
                // A chapter heading is set in capitals, which tells it apart from
                // a numbered point in the running text.
                $letters = preg_replace('/[^\p{L}]/u', '', $match[1]);
                $capitals = preg_replace('/[^\p{Lu}]/u', '', $match[1]);
                if (mb_strlen($capitals, 'UTF-8') >= 0.7 * mb_strlen($letters, 'UTF-8')) {
                    return self::heading_title($match[1]);
                }
            }
        }
        return null;
    }

    /**
     * Ask the model which level of a contents list holds the chapters.
     *
     * The last resort, for a book whose layout gives nothing away. Its answer is
     * checked back against the contents before any of it is believed, and is
     * dropped entirely where the structural scan already found more entries than
     * the model returned, so a confident wrong answer cannot displace a right one.
     *
     * @param string $toc The table of contents
     * @param array $structure What the structural scan made of it
     * @return array Chapter titles, empty if the model was no help
     */
    private static function chapters_by_model($toc, $structure) {
        try {
            $reply = self::ask_model(self::build_chapter_prompt($toc), 2000);
            $chapters = self::finalise_chapters(
                self::normalise_chapters($reply), $toc, $structure);

            return array_column($chapters, 'title');

        } catch (\Exception $e) {
            // Reading the book must not fail because the model was unreachable.
            return array();
        }
    }

    /**
     * The whole book, one entry per page, as Docling reads it.
     *
     * Every part of the plugin that needs the book's wording comes through here,
     * so the book is read the careful way once rather than many times. Reading a
     * textbook through a layout model takes minutes, which is why the result is
     * kept: the wait is paid once per book, and every use afterwards is served
     * from the cache. A new book is read by a background task, which is what lets
     * reading_progress() say how far it has got.
     *
     * Docling is the only reader. If it cannot read the book this fails loudly,
     * rather than quietly handing over a worse reading from another tool.
     *
     * @param string $filepath Path to the PDF
     * @return array Page text, in order, the first entry being page one
     * @throws \Exception if Docling cannot read the book
     */
    public static function book_pages($filepath) {
        $dir = make_temp_directory('mod_book/bookpages');
        $hash = sha1_file($filepath);
        $cache = $dir . '/' . $hash . '.json';
        $partial = $dir . '/' . $hash . '.partial.json';
        $length = $dir . '/' . $hash . '.pages';

        if (file_exists($cache)) {
            $cached = json_decode(file_get_contents($cache), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        \core_php_time_limit::raise(7200);
        raise_memory_limit(MEMORY_EXTRA);

        // Chunks an earlier, interrupted reading already finished.
        $pages = array();
        if (file_exists($partial)) {
            $kept = json_decode(file_get_contents($partial), true);
            if (is_array($kept)) {
                $pages = $kept;
            }
        }

        // Only so progress can be shown as a fraction; the reading itself does not
        // depend on it.
        if (!file_exists($length)) {
            $total = self::docling_page_count($filepath);
            if ($total > 0) {
                file_put_contents($length, $total);
            }
        }

        // Chunks are read until one comes back short: that one held the last page.
        do {
            $from = count($pages) + 1;
            $chunk = self::docling_chunk($filepath, $from, $from + self::BOOK_CHUNK_PAGES - 1);
            $pages = array_merge($pages, $chunk);
            file_put_contents($partial, json_encode($pages));
        } while (count($chunk) === self::BOOK_CHUNK_PAGES);

        if (empty($pages)) {
            throw new \Exception('Docling returned no pages for this PDF.');
        }

        file_put_contents($cache, json_encode($pages));
        @unlink($partial);
        @unlink($length);

        return $pages;
    }

    /**
     * How far the reading of a book has got.
     *
     * Cheap enough to ask on every refresh of a progress page: it only looks at
     * what book_pages() has written so far, and never asks Docling anything.
     *
     * @param string $hash SHA-1 of the PDF's content, which is Moodle's content hash
     * @return array 'read' pages so far, 'total' pages or null if not yet known,
     *               and 'done' once the whole book is read
     */
    public static function reading_progress($hash) {
        $dir = make_temp_directory('mod_book/bookpages');
        $base = $dir . '/' . clean_param($hash, PARAM_ALPHANUM);

        if (file_exists($base . '.json')) {
            $count = count((array)json_decode(file_get_contents($base . '.json'), true));
            return array('read' => $count, 'total' => $count, 'done' => true);
        }

        $read = 0;
        if (file_exists($base . '.partial.json')) {
            $read = count((array)json_decode(file_get_contents($base . '.partial.json'), true));
        }
        $total = file_exists($base . '.pages') ? (int)file_get_contents($base . '.pages') : 0;

        return array('read' => $read, 'total' => $total > 0 ? $total : null, 'done' => false);
    }

    /**
     * How many pages a PDF has, according to Docling.
     *
     * Asking for a page far past the end is refused at once, before anything is
     * converted, with a message that says how long the book is.
     *
     * @param string $filepath Path to the PDF
     * @return int Page count, or zero if Docling did not say
     */
    private static function docling_page_count($filepath) {
        try {
            $result = self::docling_convert($filepath, 1000000, 1000000);
        } catch (\Exception $e) {
            return 0;
        }

        foreach ($result['errors'] ?? array() as $error) {
            if (preg_match('/has (\d+) pages/', (string)($error['error_message'] ?? ''), $match)) {
                return (int)$match[1];
            }
        }

        return 0;
    }

    /**
     * Read a run of pages through Docling.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page
     * @param int $to Last page; may run past the end of the book
     * @return array Text of each page read, in order from $from; empty if $from
     *               lies past the end of the book
     * @throws \Exception if Docling cannot read the pages
     */
    private static function docling_chunk($filepath, $from, $to) {
        $result = self::docling_convert($filepath, $from, $to);

        $document = $result['document']['json_content'] ?? null;
        if (($result['status'] ?? '') === 'success' && is_array($document)) {
            return self::pages_from_document($document, $from);
        }

        // A book whose length is an exact multiple of the chunk size ends with a
        // chunk that starts past the last page, which Docling refuses by saying
        // how long the book is.
        $messages = array();
        foreach ($result['errors'] ?? array() as $error) {
            $message = (string)($error['error_message'] ?? '');
            if (preg_match('/has (\d+) pages, fewer than the requested page_range start/',
                    $message, $match) && (int)$match[1] < $from) {
                return array();
            }
            $messages[] = $message;
        }

        throw new \Exception('Docling could not read pages ' . $from . '-' . $to . ': ' .
            ($messages ? implode('; ', $messages) : 'no document returned'));
    }

    /**
     * Have Docling convert a run of pages and return its answer.
     *
     * The conversion is submitted as a task and then checked on, rather than held
     * open as one long request, so a slow chunk is waited for without any single
     * connection having to last minutes.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page
     * @param int $to Last page
     * @return array Docling's result, which may itself report a failure
     * @throws \Exception if Docling could not be reached or the task did not finish
     */
    private static function docling_convert($filepath, $from, $to) {
        return self::docling_task($filepath, 'book.pdf', 'pages ' . $from . '-' . $to,
            array('page_range' => array((int)$from, (int)$to)));
    }

    /**
     * Have Docling read a picture, such as a page drawn by draw_page().
     *
     * @param string $imagepath Path to the PNG
     * @return array Text of the picture, as the only entry
     * @throws \Exception if Docling cannot read it
     */
    private static function docling_image($imagepath) {
        $result = self::docling_task($imagepath, 'page.png', 'the page image', array());

        $document = $result['document']['json_content'] ?? null;
        if (($result['status'] ?? '') !== 'success' || !is_array($document)) {
            throw new \Exception('Docling could not read the page image.');
        }
        return self::pages_from_document($document, 1);
    }

    /**
     * Submit a file to Docling as a task, wait for it and return the answer.
     *
     * @param string $filepath Path to the file
     * @param string $filename Name to give it, whose extension says what it is
     * @param string $what What is being read, for messages
     * @param array $options Options beyond the reading ones every task shares
     * @return array Docling's result, which may itself report a failure
     * @throws \Exception if Docling could not be reached or the task did not finish
     */
    private static function docling_task($filepath, $filename, $what, $options) {
        $config = get_config('book');
        $url = rtrim(!empty($config->docling_url) ? $config->docling_url
            : 'http://docling:5001', '/');
        $timeout = (int)(!empty($config->docling_timeout) ? $config->docling_timeout : 300);
        $ocrlang = !empty($config->docling_ocrlang) ? $config->docling_ocrlang : 'mkd';

        // Tesseract is the only engine Docling offers with a Macedonian model, so
        // it is asked for by name rather than left to automatic selection, which
        // rejects the language outright. force_ocr stays off: it would overwrite a
        // real text layer with a worse reading of it. The structured form keeps
        // tables as tables and says which page each piece of wording came from.
        $task = self::docling_request($url . '/v1/convert/source/async', array(
            'options' => array_merge(array(
                'to_formats' => array('json'),
                'do_ocr' => true,
                'ocr_preset' => 'tesserocr',
                'ocr_lang' => explode('+', $ocrlang),
            ), $options),
            'sources' => array(array(
                'kind' => 'file',
                'filename' => $filename,
                'base64_string' => base64_encode(file_get_contents($filepath)),
            )),
        ));
        if (empty($task['task_id'])) {
            throw new \Exception('Docling at ' . $url . ' did not accept ' . $what . '.');
        }
        $taskid = rawurlencode($task['task_id']);

        $deadline = time() + $timeout;
        while (true) {
            $status = self::docling_request($url . '/v1/status/poll/' . $taskid);
            $state = $status['task_status'] ?? '';
            if (!in_array($state, array('pending', 'started')) || time() >= $deadline) {
                break;
            }
            sleep(self::DOCLING_POLL_INTERVAL);
        }

        if ($state !== 'success') {
            $why = !empty($status['error_message']) ? $status['error_message']
                : ($state === '' ? 'no answer' : 'still ' . $state . ' after ' . $timeout . 's');
            throw new \Exception('Docling could not read ' . $what . ': ' . $why);
        }

        $result = self::docling_request($url . '/v1/result/' . $taskid);
        if ($result === null) {
            throw new \Exception('Docling finished ' . $what . ' but returned no result.');
        }

        return $result;
    }

    /**
     * Send one request to Docling and decode its JSON answer.
     *
     * @param string $url Full address
     * @param array|null $body JSON body to POST, or null for a GET
     * @return array|null The decoded answer, or null if Docling did not answer
     */
    private static function docling_request($url, $body = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        }
        // Each request is short now the work happens in a task. A service that is
        // down is noticed in seconds.
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::DOCLING_CONNECT_TIMEOUT);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lay a structured Docling document out as one block of text per page.
     *
     * Wording and table rows are gathered per page and put back into reading
     * order by where they sat on the page, because Docling lists them by kind
     * rather than by position. Table rows are joined into a line each, which is
     * what lets a table of contents be read: Docling sees one as a table, and a
     * row rendered as a line is the line the contents parser expects.
     *
     * @param array|null $document A Docling document
     * @param int $from The first page it was asked for
     * @return array Page text, in order from $from to the last page it holds
     */
    private static function pages_from_document($document, $from = 1) {
        if (!is_array($document) || empty($document['pages'])) {
            return array();
        }

        // Pages are keyed by their number in the whole book, even when only a
        // range of it was converted.
        $last = max(array_map('intval', array_keys($document['pages'])));
        $items = array();

        foreach ($document['texts'] ?? array() as $text) {
            $page = $text['prov'][0]['page_no'] ?? 0;
            $body = trim((string)($text['text'] ?? ''));
            if ($page < 1 || $body === '') {
                continue;
            }
            // A bullet has to look like one, or a slide deck read back from its
            // own PDF would lose the shape the deck was written in.
            if (($text['label'] ?? '') === 'list_item') {
                $body = '- ' . $body;
            }
            $items[$page][] = array('top' => self::item_top($text), 'text' => $body);
        }

        foreach ($document['tables'] ?? array() as $table) {
            $page = $table['prov'][0]['page_no'] ?? 0;
            if ($page < 1) {
                continue;
            }

            $rows = array();
            foreach ($table['data']['table_cells'] ?? array() as $cell) {
                $row = (int)($cell['start_row_offset_idx'] ?? 0);
                $body = trim((string)($cell['text'] ?? ''));
                if ($body !== '') {
                    $rows[$row][] = $body;
                }
            }

            ksort($rows);
            $top = self::item_top($table);
            foreach ($rows as $row) {
                $items[$page][] = array('top' => $top--, 'text' => implode(' ', $row));
            }
        }

        $pages = array();
        for ($page = $from; $page <= $last; $page++) {
            $onpage = $items[$page] ?? array();
            // Higher on the page comes first; PDF coordinates grow upwards.
            usort($onpage, function($a, $b) {
                return $b['top'] <=> $a['top'];
            });
            $pages[] = implode("\n", array_column($onpage, 'text'));
        }

        return $pages;
    }

    /**
     * How far up its page an item sits, for putting a page back in reading order.
     *
     * @param array $item A Docling text or table
     * @return float Distance from the foot of the page
     */
    private static function item_top($item) {
        return (float)($item['prov'][0]['bbox']['t'] ?? 0);
    }

    /**
     * Read the book's structure along with the page range each entry covers.
     *
     * @param string $filepath Path to the PDF
     * @return array offset, total pages, and chapters each holding their subtopics
     */
    public static function get_outline($filepath) {
        $contents = self::book_contents($filepath);
        $toc = $contents['toc'];
        $structure = self::detect_main_chapters($toc);

        // Tier three means the book names its chapters neither by label nor by
        // number. Indenting cannot settle it either: Docling reads a contents
        // table as a table and gives every cell in a column the column's left
        // edge, so the indenting is gone from the data. A book can be laid out in
        // ways no rule anticipates, so the model is asked to say which level holds
        // the chapters. It may only choose from entries already in the contents -
        // anything it returns that is not there verbatim is thrown away - so it
        // can pick the wrong level but it cannot invent a chapter.
        if ($structure['tier'] >= 3) {
            $bymodel = self::chapters_by_model($toc, $structure);
            if (count($bymodel) >= 2) {
                $structure = array('titles' => $bymodel, 'tier' => 3);
            }
        }

        $entries = self::toc_entries($toc);
        $mains = $structure['titles'];

        $total = self::pdf_page_count($filepath);
        // Contents rebuilt from the pages already give the PDF's own page numbers.
        $offset = $contents['printed'] ? self::detect_page_offset($filepath, $entries) : 0;

        // An entry runs until the page before the next one starts.
        $count = count($entries);
        for ($i = 0; $i < $count; $i++) {
            $end = max($total - $offset, $entries[$i]['page']);
            for ($j = $i + 1; $j < $count; $j++) {
                if ($entries[$j]['page'] > $entries[$i]['page']) {
                    $end = $entries[$j]['page'] - 1;
                    break;
                }
            }
            $entries[$i]['endpage'] = max($entries[$i]['page'], $end);
        }

        $chapters = array();
        $current = null;
        foreach ($entries as $entry) {
            // Front matter belongs to no chapter; back matter ends the last one.
            $kind = self::matter_kind($entry['title']);
            if ($kind === 'front') {
                continue;
            }
            if ($kind === 'back') {
                if ($current !== null) {
                    $chapters[] = $current;
                    $current = null;
                }
                continue;
            }
            if (in_array($entry['title'], $mains, true)) {
                if ($current !== null) {
                    $chapters[] = $current;
                }
                $current = array(
                    'title' => $entry['title'],
                    'page' => $entry['page'],
                    'subtopics' => array(),
                );
                continue;
            }
            if ($current !== null) {
                $current['subtopics'][] = array(
                    'title' => $entry['title'],
                    'page' => $entry['page'],
                    'endpage' => $entry['endpage'],
                );
            }
        }
        if ($current !== null) {
            $chapters[] = $current;
        }

        foreach ($chapters as $c => $chapter) {
            $ranks = self::rank_subtopics(array_column($chapter['subtopics'], 'title'));
            foreach ($ranks as $k => $rank) {
                $chapters[$c]['subtopics'][$k]['rank'] = $rank;
            }
            // A heading such as "МОДУЛАРНА ЕДИНИЦА 2" may carry no page number of
            // its own, in which case the chapter starts where its first entry does.
            if ($chapter['page'] < 1) {
                foreach ($chapter['subtopics'] as $sub) {
                    if ($sub['page'] > 0) {
                        $chapters[$c]['page'] = $sub['page'];
                        break;
                    }
                }
            }
        }

        return array('offset' => $offset, 'total' => $total, 'chapters' => $chapters);
    }

    /**
     * The parts of the book a presentation can be built from.
     *
     * Each runs to roughly one lecture's worth of material, and which division
     * that is depends on how the chapter is built:
     *
     * - A chapter divided into parts, as geography's ТЕМА 1 is into ЛИТОСФЕРА,
     *   АТМОСФЕРА and so on, gives one section per part, holding its lessons.
     * - A chapter that goes straight into numbered lessons, as a maths module
     *   does, gives one section per lesson, holding whatever hangs under it. Taken
     *   whole, such a chapter can run to fifty pages, far more than one request
     *   to the model can carry.
     * - A chapter with only plain headings gives one section per heading.
     * - A chapter with nothing under it at all is one section by itself.
     *
     * @param array $outline Result of get_outline
     * @return array Sections in book order, each with its title, chapter and lessons
     */
    public static function list_sections($outline) {
        $sections = array();
        $chapters = array_values($outline['chapters']);

        foreach ($chapters as $chapterindex => $chapter) {
            $subs = $chapter['subtopics'];

            $parts = self::group_subtopics($subs, function($sub) {
                return $sub['rank'] === 0;
            });

            if (empty($parts) && in_array(1, array_column($subs, 'rank'), true)) {
                // Review exercises close a run of lessons without belonging to the
                // last of them, so they stand as a section of their own.
                $parts = self::group_subtopics($subs, function($sub) {
                    return $sub['rank'] === 1 || self::matter_kind($sub['title']) === 'review';
                });
            }

            if (empty($parts)) {
                foreach ($subs as $sub) {
                    $parts[] = array('title' => $sub['title'], 'lessons' => array($sub));
                }
            }

            if (empty($parts) && $chapter['page'] > 0) {
                // It runs until the next chapter begins, or to the end of the book.
                $next = $chapters[$chapterindex + 1]['page'] ?? 0;
                $end = ($next > $chapter['page']) ? $next - 1
                    : max($chapter['page'], $outline['total'] - $outline['offset']);
                $parts[] = array('title' => $chapter['title'], 'lessons' => array(array(
                    'title' => $chapter['title'],
                    'page' => $chapter['page'],
                    'endpage' => $end,
                    'rank' => 1,
                )));
            }

            foreach ($parts as $part) {
                $sections[] = array(
                    'title' => $part['title'],
                    'chapter' => $chapter['title'],
                    'chapterindex' => $chapterindex,
                    'lessons' => $part['lessons'],
                );
            }
        }

        return $sections;
    }

    /**
     * Group a chapter's sub-entries under the ones that head a group.
     *
     * Entries before the first head belong to no group. A group whose head is
     * followed by nothing is dropped, unless the head carries pages of its own:
     * a part heading on its own says nothing, but a lesson is its own material.
     *
     * @param array $subs Sub-entries in book order
     * @param callable $ishead Whether an entry starts a group
     * @return array Groups, each with a title and its entries
     */
    private static function group_subtopics($subs, $ishead) {
        $groups = array();
        foreach ($subs as $sub) {
            if ($ishead($sub)) {
                $groups[] = array('title' => $sub['title'], 'head' => $sub, 'lessons' => array());
            } else if (!empty($groups)) {
                $groups[count($groups) - 1]['lessons'][] = $sub;
            }
        }

        $kept = array();
        foreach ($groups as $group) {
            if ($group['head']['rank'] !== 0) {
                array_unshift($group['lessons'], $group['head']);
            }
            if (!empty($group['lessons'])) {
                $kept[] = array('title' => $group['title'], 'lessons' => $group['lessons']);
            }
        }

        return $kept;
    }

    /**
     * Turn a reader's grouping of sections into the presentations to build.
     *
     * Each section keeps its own page ranges, so a presentation drawing on several
     * sections still asks the model about one section at a time and stays inside
     * the per-minute token allowance.
     *
     * @param array $outline Result of get_outline
     * @param array $groups Each with a title and the section keys it covers
     * @return array Presentations, each with a title, its chapter, and its chunks
     */
    public static function plan_from_selection($outline, $groups) {
        $sections = self::list_sections($outline);
        $plan = array();

        foreach ($groups as $group) {
            $chunks = array();
            $chapter = null;
            $chapterindex = 0;

            foreach ($group['sections'] as $key) {
                if (!isset($sections[$key])) {
                    continue;
                }
                // A presentation spanning chapters is filed under the first it touches.
                if ($chapter === null) {
                    $chapter = $sections[$key]['chapter'];
                    $chapterindex = $sections[$key]['chapterindex'];
                }
                $chunks = array_merge($chunks,
                    self::split_lessons($sections[$key]['lessons']));
            }

            if (empty($chunks)) {
                continue;
            }

            $plan[] = array(
                'title' => $group['title'],
                'chapter' => $chapter,
                'chapterindex' => $chapterindex,
                'topic' => $group['topic'] ?? null,
                'chunks' => $chunks,
            );
        }

        return $plan;
    }

    /**
     * Divide a part's lessons into contiguous chunks, one request each.
     *
     * @param array $lessons Lessons in book order
     * @param int $max Most chunks to produce
     * @return array Chunks, each with a page range and the lessons it covers
     */
    private static function split_lessons($lessons, $max = 2) {
        $count = count($lessons);
        $per = (int)ceil($count / min($max, $count));

        $chunks = array();
        for ($i = 0; $i < $count; $i += $per) {
            $slice = array_slice($lessons, $i, $per);
            $chunks[] = array(
                'from' => $slice[0]['page'],
                'to' => $slice[count($slice) - 1]['endpage'],
                'titles' => array_column($slice, 'title'),
            );
        }
        return $chunks;
    }

    /**
     * Pull the text of a page range out of the book.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page, as a PDF page number
     * @param int $to Last page, as a PDF page number
     * @return string Extracted text
     */
    public static function extract_pages($filepath, $from, $to) {
        // Served from the book's one careful reading rather than read afresh, so a
        // generation run spends its time on the model rather than on the PDF.
        $pages = self::book_pages($filepath);

        $from = max(1, (int)$from);
        $to = min(count($pages), (int)$to);
        if ($to < $from) {
            return '';
        }

        return implode("\n", array_slice($pages, $from - 1, $to - $from + 1));
    }


    /**
     * Put a question to the configured model and decode its JSON reply.
     *
     * @param string $prompt Instruction
     * @param int $maxtokens Reply budget
     * @return array Decoded reply
     * @throws \Exception if the request fails or the reply is not JSON
     */
    public static function ask_model($prompt, $maxtokens = 4000) {
        $config = get_config('book');
        $provider = $config->ai_provider ?? 'groq';

        if ($provider === 'ollama') {
            $url = ($config->ollama_url ?? 'http://host.docker.internal:11434') . '/api/generate';
            $payload = array(
                'model' => $config->ollama_model ?? 'llama3',
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
            );
            $headers = array('Content-Type: application/json');
        } else {
            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $payload = array(
                'model' => $config->groq_model ?? 'openai/gpt-oss-120b',
                'messages' => array(array('role' => 'user', 'content' => $prompt)),
                'temperature' => 0.2,
                'max_tokens' => $maxtokens,
                'response_format' => array('type' => 'json_object'),
            );
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($config->ai_api_key ?? ''),
            );
        }

        // Space calls out rather than firing them off and waiting to be refused. A
        // local model bills nothing and refuses nothing, so it runs flat out.
        static $lastcall = 0;
        if ($provider !== 'ollama') {
            $elapsed = time() - $lastcall;
            if ($lastcall > 0 && $elapsed < self::REQUEST_SPACING) {
                sleep(self::REQUEST_SPACING - $elapsed);
            }
            $lastcall = time();
        }

        $response = '';
        $httpcode = 0;
        $backoff = array(25, 40, 60);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // A per-minute refusal is worth waiting out. A daily one is not - the
            // allowance will not return for hours, so retrying only wastes minutes.
            if ($httpcode === 429 && preg_match('/tokens per day|TPD/i', (string)$response)) {
                throw new \Exception('Daily AI token allowance used up. The free tier allows ' .
                    '200,000 tokens a day and it resets on a rolling basis, so try again later ' .
                    'or raise the plan limit.');
            }

            if ($httpcode === 429 && isset($backoff[$attempt])) {
                sleep($backoff[$attempt]);
                continue;
            }
            break;
        }

        if ($httpcode !== 200) {
            throw new \Exception('AI request failed with HTTP ' . $httpcode . '. ' .
                substr((string)$response, 0, 300));
        }

        $result = json_decode($response, true);
        $text = ($provider === 'ollama')
            ? ($result['response'] ?? '')
            : ($result['choices'][0]['message']['content'] ?? '');

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new \Exception('AI reply was not JSON: ' . substr($text, 0, 300));
        }
        return $decoded;
    }

    /**
     * Turn an extract of the book into slides.
     *
     * @param string $title Presentation title
     * @param string $text Text of the pages the presentation covers
     * @return array Slides, each with a heading and bullets
     */
    public static function generate_slides($title, $text) {
        $prompt = "Write a lecture presentation from this textbook extract.\n\n" .
            "Base every slide on what the extract actually says, and carry its concrete " .
            "detail across: figures, dates, names, places, definitions and examples. Do " .
            "not add facts that are not in it. Write in the same language as the extract.\n\n" .
            "Produce 4 to 6 slides. Each slide needs a short heading and 3 to 4 bullets. " .
            "Write each bullet as one or two full sentences that explain the point, not a " .
            "bare label - a student reading the slide alone should understand it. Work " .
            "through the whole extract rather than stopping at its opening pages.\n\n" .
            "Skip the textbook's own exercises, discussion prompts and glossary boxes.\n\n" .
            "Return JSON:\n" .
            "{\"slides\":[{\"heading\":\"...\",\"bullets\":[\"...\",\"...\"]}]}\n\n" .
            "PRESENTATION TITLE: $title\n\n" .
            "EXTRACT:\n" . mb_substr($text, 0, self::EXTRACT_CHARS, 'UTF-8');

        // There is a daily token budget as well as a per-minute one, and a full run of
        // 24 requests has to fit inside it, so the reply is kept tight.
        $reply = self::ask_model($prompt, 1600);

        return self::clean_slides($reply, $title);
    }

    /**
     * Rewrite a presentation that already exists, as a teacher asks.
     *
     * The presentation is handed back in full so that a request touching one part
     * of it can leave the rest alone, and so the model is working from what the
     * slides actually say rather than from the book again.
     *
     * @param string $title The presentation's title
     * @param array $slides The slides as they stand
     * @param string $request What the teacher asked for
     * @return array The revised slides
     */
    public static function revise_slides($title, $slides, $request) {
        $current = '';
        foreach ($slides as $number => $slide) {
            $current .= ($number + 1) . '. ' . $slide['heading'] . "\n";
            foreach ($slide['bullets'] as $bullet) {
                $current .= '   - ' . $bullet . "\n";
            }
        }

        $prompt = "Revise this lecture presentation as the teacher asks.\n\n" .
            "Do not return the whole presentation. Return only the changes, as a list " .
            "of edits. A slide the request does not concern needs no edit and is kept " .
            "as it is.\n\n" .
            "Write in the language the presentation is already in. Do not introduce " .
            "facts that are not already there unless the request asks for them.\n\n" .
            "Each edit is one of:\n" .
            "  {\"action\":\"replace\",\"slide\":N,\"heading\":\"...\",\"bullets\":[\"...\"]}\n" .
            "  {\"action\":\"insert_after\",\"slide\":N,\"heading\":\"...\",\"bullets\":[\"...\"]}\n" .
            "  {\"action\":\"delete\",\"slide\":N}\n\n" .
            "N is the slide's number as given below. Use 0 with insert_after to put a " .
            "slide at the very front. Return JSON:\n" .
            "{\"edits\":[ ... ]}\n\n" .
            "PRESENTATION TITLE: $title\n\n" .
            "THE TEACHER ASKS:\n" . mb_substr($request, 0, 1000, 'UTF-8') . "\n\n" .
            "THE PRESENTATION AS IT STANDS:\n" .
            mb_substr($current, 0, self::EXTRACT_CHARS, 'UTF-8');

        // Only the edits come back, so the reply stays small whatever the size of
        // the presentation, which matters when prompt and reply share one allowance.
        $reply = self::ask_model($prompt, 1800);

        return self::apply_edits($slides, $reply['edits'] ?? array(), $title);
    }

    /**
     * Work a set of edits into a presentation.
     *
     * Edits name slides by their original number, so they are gathered first and
     * applied in one pass. Applying them one at a time would renumber the slides
     * under the edits still to come.
     *
     * @param array $slides The slides as they stand
     * @param array $edits What to change, as the model described it
     * @param string $title Presentation being revised, for the error message
     * @return array The revised slides
     */
    private static function apply_edits($slides, $edits, $title) {
        $replace = array();
        $insert = array();
        $delete = array();

        foreach ($edits as $edit) {
            $action = $edit['action'] ?? '';
            $number = (int)($edit['slide'] ?? 0);

            if ($action === 'delete') {
                $delete[$number] = true;
                continue;
            }

            $heading = trim($edit['heading'] ?? '');
            $bullets = array();
            foreach ($edit['bullets'] ?? array() as $bullet) {
                $bullet = trim((string)$bullet);
                if ($bullet !== '') {
                    $bullets[] = $bullet;
                }
            }
            if ($heading === '' || empty($bullets)) {
                continue;
            }

            $slide = array('heading' => $heading, 'bullets' => $bullets);
            if ($action === 'replace') {
                $replace[$number] = $slide;
            } else if ($action === 'insert_after') {
                $insert[$number][] = $slide;
            }
        }

        if (empty($replace) && empty($insert) && empty($delete)) {
            throw new \Exception('The model suggested no changes to "' . $title . '".');
        }

        $revised = array();
        foreach ($insert[0] ?? array() as $slide) {
            $revised[] = $slide;
        }

        foreach ($slides as $position => $slide) {
            $number = $position + 1;
            if (!isset($delete[$number])) {
                $revised[] = $replace[$number] ?? $slide;
            }
            foreach ($insert[$number] ?? array() as $added) {
                $revised[] = $added;
            }
        }

        if (empty($revised)) {
            throw new \Exception('The revision would leave "' . $title . '" with no slides.');
        }

        return $revised;
    }

    /**
     * Keep only the slides a reply offers that are fit to render.
     *
     * @param array $reply The model's decoded reply
     * @param string $title Presentation the reply was for, for the error message
     * @return array Slides with a heading and at least one bullet
     */
    private static function clean_slides($reply, $title) {
        $slides = array();

        foreach ($reply['slides'] ?? array() as $slide) {
            $heading = trim($slide['heading'] ?? '');
            $bullets = array();
            foreach ($slide['bullets'] ?? array() as $bullet) {
                $bullet = trim((string)$bullet);
                if ($bullet !== '') {
                    $bullets[] = $bullet;
                }
            }
            if ($heading !== '' && !empty($bullets)) {
                $slides[] = array('heading' => $heading, 'bullets' => $bullets);
            }
        }

        if (empty($slides)) {
            throw new \Exception('The model returned no usable slides for "' . $title . '".');
        }

        return $slides;
    }

    /**
     * Write multiple choice questions from a presentation's text.
     *
     * @param string $title What the questions are about, used in errors
     * @param string $text Source text
     * @param int $count How many questions to ask for
     * @return array Questions, each with a stem, four options and the correct index
     */
    public static function generate_questions($title, $text, $count) {
        $prompt = "Write $count multiple choice questions from this lecture material.\n\n" .
            "Every question must be answerable from the material alone, and must test " .
            "understanding rather than recall of a stray detail. Write in the same " .
            "language as the material.\n\n" .
            "Give each question exactly four options, of which exactly one is correct. " .
            "The three wrong options must be plausible and about as long as the right " .
            "one, so that the answer cannot be picked out by its shape. Never write " .
            "options such as 'all of the above' or 'none of the above', and do not " .
            "number or letter the options.\n\n" .
            "Return JSON, where 'correct' is the position of the right option counting " .
            "from zero:\n" .
            "{\"questions\":[{\"question\":\"...\",\"options\":[\"...\",\"...\",\"...\",\"...\"],\"correct\":0}]}\n\n" .
            "MATERIAL:\n" . mb_substr($text, 0, 5000, 'UTF-8');

        $reply = self::ask_model($prompt, 3000);

        $questions = array();
        foreach ($reply['questions'] ?? array() as $raw) {
            $stem = trim((string)($raw['question'] ?? ''));

            $options = array();
            foreach ($raw['options'] ?? array() as $option) {
                $option = trim((string)$option);
                if ($option !== '') {
                    $options[] = $option;
                }
            }

            // A question is only usable if exactly one of four options is marked right.
            $correct = (int)($raw['correct'] ?? -1);
            if ($stem === '' || count($options) !== 4 || $correct < 0 || $correct > 3) {
                continue;
            }
            if (count(array_unique($options)) !== 4) {
                continue;
            }

            $questions[] = array('question' => $stem, 'options' => $options, 'correct' => $correct);
        }

        if (empty($questions)) {
            throw new \Exception('No usable questions came back for "' . $title . '".');
        }
        return $questions;
    }

    /**
     * How deep an entry sits relative to its siblings.
     *
     * @param string $title Entry title
     * @return int 0 for a plain heading, 1 for a numbered one, 2 for a subdivision
     */
    private static function entry_rank($title) {
        if (preg_match('/^\d+\.\d/u', $title)) {
            return 2;
        }
        if (preg_match('/^\d{1,3}[\.\)]?\s/u', $title)) {
            return 1;
        }
        return 0;
    }

    /**
     * Total pages in the PDF.
     *
     * @param string $filepath Path to the PDF
     * @return int Page count, or 0 if it could not be read
     */
    private static function pdf_page_count($filepath) {
        // Docling counts the book's pages as it reads it, and the reading is kept,
        // so asking here costs nothing once the book has been read through once.
        return count(self::book_pages($filepath));
    }

    /**
     * Work out how far printed page numbers are shifted from PDF page numbers.
     *
     * Cover pages and front matter push the two apart by an amount that differs per
     * book. Each heading appears both in the contents and at its real location, so
     * every sighting votes for an offset and the true one wins on weight of numbers.
     *
     * @param string $filepath Path to the PDF
     * @param array $entries Contents entries carrying printed page numbers
     * @return int Offset to add to a printed page number
     */
    private static function detect_page_offset($filepath, $entries) {
        $pages = self::book_pages($filepath);

        $normalised = array();
        foreach ($pages as $i => $page) {
            $normalised[$i] = preg_replace('/\s+/u', ' ', mb_strtoupper($page, 'UTF-8'));
        }

        $votes = array();
        foreach ($entries as $entry) {
            if ($entry['page'] < 1) {
                continue;
            }
            $needle = preg_replace('/^\d{1,3}[\.\)]?\s*/u', '', $entry['title']);
            $needle = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($needle), 'UTF-8'));
            if (mb_strlen($needle, 'UTF-8') < 8) {
                continue;
            }
            foreach ($normalised as $i => $text) {
                if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                    $offset = ($i + 1) - $entry['page'];
                    $votes[$offset] = isset($votes[$offset]) ? $votes[$offset] + 1 : 1;
                }
            }
        }

        if (empty($votes)) {
            return 0;
        }
        arsort($votes);
        return (int)key($votes);
    }

    /**
     * Isolate the table of contents from the surrounding front matter.
     *
     * @param string $text Extracted PDF text
     * @return string The table of contents, or '' if there is none
     */
    private static function extract_toc_section($text) {
        $lines = preg_split('/\R/u', $text);

        // Contents entries end in dot leaders followed by a page number. That is a
        // typographic convention, so it holds regardless of the book's language.
        $hits = array();
        foreach ($lines as $i => $line) {
            if (preg_match('/\.{4,}\s*\d{1,4}\s*$/u', $line)) {
                $hits[] = $i;
            }
        }

        if (count($hits) >= 5) {
            $start = $hits[0];
            $end = $hits[count($hits) - 1];

            // The list can open with lines the dot-leader test misses: a heading
            // that carries no page number, or two entries read as one. Where the
            // list's own heading sits just above the first hit, start from there.
            for ($i = $start - 1; $i >= max(0, $start - 10); $i--) {
                if (self::is_contents_heading($lines[$i])) {
                    $start = $i + 1;
                    break;
                }
            }

            return implode("\n", array_slice($lines, $start, $end - $start + 1));
        }

        // No dot leaders, so look for a heading that announces the contents.
        foreach ($lines as $i => $line) {
            if (self::is_contents_heading($line)) {
                return implode("\n", array_slice($lines, $i + 1, 200));
            }
        }

        return '';
    }

    /**
     * Whether a line is the heading of a table of contents.
     *
     * @param string $line A line of text
     * @return bool
     */
    private static function is_contents_heading($line) {
        return (bool)preg_match(
            '/^(содржина|садржај|sodrzina|contents|table of contents|inhalt|índice|sommaire)$/iu',
            trim($line));
    }

    /**
     * Work out which table of contents entries sit at the top of the hierarchy.
     *
     * Numbering and indentation are stated by the book itself, so where they are
     * present they outrank a model's reading of the same page.
     *
     * @param string $toc Table of contents text
     * @return array titles, plus a tier of 1-3 where 1 is the strongest evidence
     *               and 4 means nothing usable was found
     */
    private static function detect_main_chapters($toc) {
        $entries = self::toc_entries($toc);

        if (empty($entries)) {
            return array('titles' => array(), 'tier' => 4);
        }

        // A label that counts: the same words followed by 1, 2, 3 in order ("ТЕМА 1",
        // "МОДУЛАРНА ЕДИНИЦА 2", "PART III") marks a division, whatever words a book
        // uses and however many. A label that only appears once, or whose numbers do
        // not climb, is a title that happens to contain a number. If a book counts
        // more than one kind of division, the one met first is the outer one.
        $groups = array();
        $titles = array();
        foreach ($entries as $entry) {
            $label = self::chapter_label($entry['title']);
            if ($label !== null) {
                $groups[$label['label']][] = $label['number'];
                $titles[$label['label']][] = $entry['title'];
            }
        }
        foreach ($groups as $label => $numbers) {
            $climbs = count($numbers) >= 2 && $numbers[0] <= 2;
            for ($k = 1; $climbs && $k < count($numbers); $k++) {
                $climbs = $numbers[$k] > $numbers[$k - 1];
            }
            if ($climbs) {
                return array('titles' => $titles[$label], 'tier' => 1);
            }
        }

        // Whole-number entries, excluding "1.1" style subdivisions.
        $numbered = array();
        foreach ($entries as $i => $entry) {
            if (preg_match('/^(\d{1,3})[\.\)]?\s+\S/u', $entry['title'], $match)
                && !preg_match('/^\d+\.\d/u', $entry['title'])) {
                $numbered[$i] = (int)$match[1];
            }
        }

        // Numbering that starts again from 1 means the numbered entries sit inside
        // something, and that something is the unnumbered entry just before each
        // fresh start - the way a book's lessons restart in every unit. Front matter
        // and review sections in between are passed over.
        $starts = array_keys($numbered, 1, true);
        if (count($starts) >= 2) {
            $parents = array();
            foreach ($starts as $start) {
                for ($i = $start - 1; $i >= 0; $i--) {
                    if (isset($numbered[$i]) || preg_match('/^\d/u', $entries[$i]['title'])) {
                        break;
                    }
                    if (self::matter_kind($entries[$i]['title']) === null) {
                        $parents[] = $entries[$i]['title'];
                        break;
                    }
                }
            }
            if (count($parents) === count($starts)) {
                return array('titles' => $parents, 'tier' => 2);
            }
        }

        if (count($numbered) >= 2) {
            return array('titles' => array_map(function($i) use ($entries) {
                return $entries[$i]['title'];
            }, array_keys($numbered)), 'tier' => 2);
        }

        // Last resort: whichever entries are indented the least.
        $minindent = $entries[0]['indent'];
        foreach ($entries as $entry) {
            $minindent = min($minindent, $entry['indent']);
        }
        $shallow = array();
        foreach ($entries as $entry) {
            if ($entry['indent'] === $minindent) {
                $shallow[] = $entry['title'];
            }
        }
        return array('titles' => $shallow, 'tier' => 3);
    }

    /**
     * The counting label a contents entry opens with, if any.
     *
     * One to three capitalised words followed by a number, as in "ТЕМА 1:",
     * "МОДУЛАРНА ЕДИНИЦА 2." or "Part IV". Front and back matter never count, so
     * "ПРИЛОГ 1" is not mistaken for a chapter.
     *
     * @param string $title Entry title
     * @return array|null 'label', upper-cased, and 'number'; null if there is none
     */
    private static function chapter_label($title) {
        if (!preg_match('/^(\p{Lu}\p{L}*(?:\s+\p{Lu}\p{L}*){0,2})\s+(\d{1,2}|[IVXLC]{1,5})(?=[\s.:)\-–]|$)/u',
                $title, $match) || self::matter_kind($title) !== null) {
            return null;
        }

        $number = $match[2];
        if (!ctype_digit($number)) {
            $values = array('I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100);
            $total = 0;
            for ($k = 0; $k < strlen($number); $k++) {
                $value = $values[$number[$k]];
                $next = ($k + 1 < strlen($number)) ? $values[$number[$k + 1]] : 0;
                $total += ($value < $next) ? -$value : $value;
            }
            $number = $total;
        }

        return array('label' => mb_strtoupper($match[1], 'UTF-8'), 'number' => (int)$number);
    }

    /**
     * Whether a contents entry is part of the book's apparatus rather than its matter.
     *
     * Front matter comes before the chapters and belongs to none of them. Review
     * sections recur inside chapters and belong to them, but never head one. Back
     * matter - appendices, answers, bibliography, index - ends the last chapter.
     *
     * @param string $title Entry title
     * @return string|null 'front', 'review' or 'back', or null for ordinary matter
     */
    private static function matter_kind($title) {
        $title = trim($title);
        if (preg_match('/^(предговор|предзбор|содржина|predgovor|sodr[zž]ina|preface|foreword|' .
                'table of contents|contents|acknowledg)/iu', $title)) {
            return 'front';
        }
        if (preg_match('/^(задачи за повторување|прашања за повторување|повторување|' .
                'review|revision|exercises)/iu', $title)) {
            return 'review';
        }
        if (preg_match('/^(прилог|прилози|додаток|решенија|одговори|литература|користена литература|' .
                'библиографија|регистар|индекс|речник|appendix|answers|solutions|bibliography|' .
                'references|index|glossary)/iu', $title)) {
            return 'back';
        }
        return null;
    }

    /**
     * Whether a contents line opens an entry of its own.
     *
     * Such a line is never the second half of a title wrapped from the line before.
     *
     * @param string $title Line with any dot leader removed
     * @return bool
     */
    private static function starts_entry($title) {
        return preg_match('/^\d{1,3}(?:\.\d{1,3})*[\.\)]?\s+\p{L}/u', $title)
            || self::chapter_label($title) !== null
            || self::matter_kind($title) !== null;
    }

    /**
     * Separate contents entries that were read together onto one line.
     *
     * Docling sometimes reads two neighbouring entries as one cell, which comes out
     * as "Preface....... 1. Sequences....... 7 9": each title keeps its dot leader
     * and the page numbers gather at the end, in order. Split only where the count
     * of page numbers matches the count of titles, so nothing is guessed.
     *
     * @param string $line A contents line
     * @return array One line per entry
     */
    private static function split_merged_entries($line) {
        $parts = preg_split('/\.{4,}\s*(?=\p{L}|\d{1,3}[\.\)]\s+\p{L})/u', $line);
        if (count($parts) < 2) {
            return array($line);
        }

        $last = array_pop($parts);
        if (!preg_match('/^(.*?)\.{2,}\s*((?:\d{1,4}\s+)+\d{1,4})\s*$/u', $last, $match)) {
            return array($line);
        }
        $pages = preg_split('/\s+/u', trim($match[2]));
        $parts[] = $match[1];
        if (count($pages) !== count($parts)) {
            return array($line);
        }

        $split = array();
        foreach ($parts as $k => $title) {
            $split[] = trim($title) . '.......... ' . $pages[$k];
        }
        return $split;
    }

    /**
     * Rank a chapter's sub-entries for nesting, reading unnumbered ones in context.
     *
     * An unnumbered entry is a part that the lessons sit inside when the chapter
     * opens with one, as "ЛИТОСФЕРА" opens a run of numbered lessons. When the
     * chapter opens with a numbered lesson instead, its unnumbered entries hang
     * under the lessons, as "Линеарна функција" does under lesson 4.
     *
     * @param array $titles Sub-entry titles in book order
     * @return array Rank of each, keyed alike
     */
    private static function rank_subtopics($titles) {
        $ranks = array();
        foreach ($titles as $key => $title) {
            $ranks[$key] = self::entry_rank($title);
        }

        $first = reset($ranks);
        if ($first !== false && $first !== 0) {
            foreach ($ranks as $key => $rank) {
                if ($rank === 0) {
                    $ranks[$key] = 3;
                }
            }
        }

        return $ranks;
    }

    /**
     * Reduce the various shapes an LLM returns into a flat chapter list.
     *
     * @param mixed $data Decoded JSON from the model
     * @return array Chapters with title and summary
     */
    private static function normalise_chapters($data) {
        if (!is_array($data)) {
            return array();
        }

        if (!isset($data[0])) {
            if (isset($data['title']) && is_string($data['title'])) {
                return array(array('title' => trim($data['title']), 'summary' => ''));
            }
            // A wrapper object such as {"chapters": [...]}.
            foreach ($data as $value) {
                if (is_array($value)) {
                    $nested = self::normalise_chapters($value);
                    if (!empty($nested)) {
                        return $nested;
                    }
                }
            }
            return array();
        }

        $chapters = array();
        foreach ($data as $item) {
            if (is_string($item)) {
                $title = $item;
            } else if (is_array($item)) {
                $title = $item['title'] ?? $item['name'] ?? $item['chapter'] ?? '';
            } else {
                continue;
            }
            $title = trim((string)$title);
            if ($title !== '') {
                $chapters[] = array('title' => $title, 'summary' => '');
            }
        }
        return $chapters;
    }

    /**
     * Extract text from DOCX file
     *
     * @param string $filepath Path to DOCX file
     * @return string Extracted text
     */
    private static function extract_docx_text($filepath) {
        $content = '';

        // DOCX is a ZIP archive
        $zip = new \ZipArchive();
        if ($zip->open($filepath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml) {
                // Remove XML tags to get plain text
                $content = strip_tags($xml);
                $content = html_entity_decode($content);
            }
        }

        return $content;
    }

    /**
     * List each chapter's own contents entries beneath it.
     *
     * Moodle 4.0 sections do not nest, so the hierarchy is rendered into the
     * section summary instead.
     *
     * @param array $chapters Accepted chapters, in book order
     * @param string $toc Table of contents
     * @return array Chapters with summaries filled in
     */
    private static function attach_subtopics($chapters, $toc) {
        $entries = self::toc_entries($toc);
        $titles = array_column($chapters, 'title');

        // Find where each chapter sits so the entries between can be attributed to it.
        $marks = array();
        foreach ($entries as $i => $entry) {
            $at = array_search($entry['title'], $titles, true);
            if ($at !== false && !isset($marks[$at])) {
                $marks[$at] = $i;
            }
        }

        foreach ($chapters as $idx => $chapter) {
            if (!isset($marks[$idx])) {
                continue;
            }

            $to = count($entries);
            foreach ($marks as $at) {
                if ($at > $marks[$idx] && $at < $to) {
                    $to = $at;
                }
            }

            $items = array();
            for ($i = $marks[$idx] + 1; $i < $to; $i++) {
                $kind = self::matter_kind($entries[$i]['title']);
                if ($kind === 'back') {
                    break;
                }
                if ($kind !== 'front') {
                    $items[] = array('title' => $entries[$i]['title']);
                }
            }
            foreach (self::rank_subtopics(array_column($items, 'title')) as $k => $rank) {
                $items[$k]['rank'] = $rank;
            }

            $chapters[$idx]['summary'] = self::render_subtopics($items);
        }

        return $chapters;
    }

    /**
     * Split a table of contents into entries, rejoining titles that wrap.
     *
     * @param string $toc Table of contents
     * @return array Entries with a title, an indent and the printed page number
     */
    private static function toc_entries($toc) {
        $lines = array();
        foreach (preg_split('/\R/u', $toc) as $line) {
            // A line holding nothing but a number is the contents page's own page
            // number, which would otherwise be glued onto the entry after it.
            if (preg_match('/^\s*\d{1,4}\s*$/u', $line)) {
                continue;
            }
            foreach (self::split_merged_entries($line) as $part) {
                $lines[] = $part;
            }
        }

        // Rejoining relies on the page number marking where an entry ends, so it only
        // applies to a contents list that actually carries page numbers.
        $filled = 0;
        $withleader = 0;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $filled++;
                if (preg_match('/\.{2,}\s*\d{1,4}\s*$/u', $line)) {
                    $withleader++;
                }
            }
        }
        $rejoin = $filled > 0 && $withleader * 2 >= $filled;

        $entries = array();
        $pending = '';
        $pendingindent = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $indent = mb_strlen($line, 'UTF-8') - mb_strlen(ltrim($line), 'UTF-8');
            $title = self::strip_leader($line);
            $haspage = preg_match('/\.{2,}\s*(\d{1,4})\s*$/u', $line, $match);

            // A line that opens with its own number, label or apparatus heading is a
            // new entry, so whatever was waiting for its second half stands alone.
            if ($pending !== '' && self::starts_entry($title)) {
                if (mb_strlen($pending, 'UTF-8') >= 3) {
                    $entries[] = array('title' => $pending, 'indent' => $pendingindent, 'page' => 0);
                }
                $pending = '';
            }

            if ($rejoin && !$haspage) {
                // No page number, so this is the opening half of a wrapped title.
                if ($pending === '') {
                    $pendingindent = $indent;
                    $pending = $title;
                } else {
                    $pending .= ' ' . $title;
                }
                continue;
            }

            if ($pending !== '') {
                $title = $pending . ' ' . $title;
                $indent = $pendingindent;
                $pending = '';
            }

            if (mb_strlen($title, 'UTF-8') >= 3) {
                $entries[] = array(
                    'title' => $title,
                    'indent' => $indent,
                    'page' => $haspage ? (int)$match[1] : 0,
                );
            }
        }

        if (mb_strlen($pending, 'UTF-8') >= 3) {
            $entries[] = array('title' => $pending, 'indent' => $pendingindent, 'page' => 0);
        }

        return $entries;
    }

    /**
     * Strip dot leaders and the trailing page number from a contents line.
     *
     * @param string $line Raw line
     * @return string Title
     */
    private static function strip_leader($line) {
        return trim(preg_replace('/\.{2,}\s*\d{1,4}\s*$/u', '', $line));
    }

    /**
     * Render contents entries as a nested list.
     *
     * @param array $items Entries with a title and a rank
     * @return string HTML
     */
    private static function render_subtopics($items) {
        if (empty($items)) {
            return '';
        }

        // Ranks are absolute, but a chapter may not use every one of them, so map
        // whichever ranks appear onto consecutive nesting depths.
        $ranks = array_values(array_unique(array_column($items, 'rank')));
        sort($ranks);
        $depthof = array_flip($ranks);

        $html = '';
        $prev = -1;
        foreach ($items as $item) {
            $depth = $depthof[$item['rank']];
            if ($depth > $prev) {
                $html .= str_repeat('<ul>', $depth - $prev);
            } else {
                $html .= '</li>' . str_repeat('</ul></li>', $prev - $depth);
            }
            $html .= '<li>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
            $prev = $depth;
        }

        return $html . '</li>' . str_repeat('</ul></li>', $prev) . '</ul>';
    }

    /**
     * Build the instruction sent to the model.
     *
     * @param string $toc Table of contents
     * @return string Prompt
     */
    private static function build_chapter_prompt($toc, $guidance = '') {
        return "Below is a book's table of contents.\n\n" .
               "It has several levels of heading. Work out which level holds the book's " .
               "main divisions - the broad parts that every other entry sits inside - " .
               "and return every entry at that level.\n\n" .
               "Signals for the top level: a label followed by a number, the least " .
               "indentation, and a wide page range. An entry numbered like 1.1 or 2.3 " .
               "is always a subdivision, never a top-level division.\n\n" .
               "Return every entry at that one level, and nothing from any other level. " .
               "Copy each title verbatim. Return JSON:\n" .
               "{\"chapters\":[{\"title\":\"...\"},{\"title\":\"...\"}]}\n\n" .
               // The teacher has seen the last answer and is correcting it, so this
               // carries more weight than the general rules above.
               ($guidance !== ''
                   ? "THE TEACHER HAS LOOKED AT YOUR LAST ANSWER AND SAYS:\n" .
                     mb_substr($guidance, 0, 600, 'UTF-8') .
                     "\nFollow that over the guidance above where they disagree.\n\n"
                   : '') .
               "TABLE OF CONTENTS:\n" . mb_substr($toc, 0, 12000, 'UTF-8');
    }

    /**
     * Check a model's answer against the table of contents it was given.
     *
     * @param array $chapters Chapters proposed by the model
     * @param string $toc Table of contents
     * @param array $structure Result of detect_main_chapters
     * @return array Chapters in the order they appear in the book
     */
    private static function finalise_chapters($chapters, $toc, $structure) {
        $flat = preg_replace('/\s+/u', ' ', $toc);

        // A title that is not in the contents was invented rather than read.
        $verified = array();
        foreach ($chapters as $chapter) {
            $needle = preg_replace('/\s+/u', ' ', trim($chapter['title']));
            $at = ($needle === '') ? false : mb_strpos($flat, $needle, 0, 'UTF-8');
            if ($at !== false) {
                $chapter['position'] = $at;
                $verified[] = $chapter;
            }
        }

        // Returning "ТЕМА 2" alongside the plain headings sitting under it means two
        // levels have been run together, and the labelled entries are the level asked for.
        $labelled = array();
        foreach ($verified as $chapter) {
            if (self::chapter_label($chapter['title']) !== null) {
                $labelled[] = $chapter;
            }
        }
        if (count($labelled) >= 2 && count($labelled) < count($verified)) {
            $verified = $labelled;
        }

        // Where the book's own numbering is unambiguous the structural scan enumerates
        // that level in full, so a shorter answer means the model skipped entries.
        if ($structure['tier'] <= 2 && count($structure['titles']) > count($verified)) {
            $verified = array();
        }

        if (count($verified) >= 2) {
            usort($verified, function($a, $b) {
                return $a['position'] - $b['position'];
            });
            $ordered = array();
            foreach ($verified as $chapter) {
                $ordered[] = array('title' => $chapter['title'], 'summary' => '');
            }
            return self::attach_subtopics($ordered, $toc);
        }

        $fallback = array();
        foreach ($structure['titles'] as $title) {
            $fallback[] = array('title' => $title, 'summary' => '');
        }
        if (count($fallback) >= 2) {
            return self::attach_subtopics($fallback, $toc);
        }
        return array();
    }

    /**
     * Call AI API to extract chapters
     *
     * @param string $content Book content
     * @param string $toc The book's table of contents
     * @param string $apikey API key (not used for Ollama)
     * @param string $provider AI provider (ollama, claude, openai)
     * @return array Array of chapters
     */
    private static function call_ai_api($content, $toc, $apikey, $provider = 'groq', $guidance = '') {
        if ($provider === 'groq') {
            return self::call_groq_api($toc, $apikey, $guidance);
        } else if ($provider === 'ollama') {
            return self::call_ollama_api($toc);
        } else if ($provider === 'claude') {
            return self::call_claude_api($content, $apikey);
        } else if ($provider === 'openai') {
            return self::call_openai_api($content, $apikey);
        }

        throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'Unknown AI provider');
    }

    /**
     * Call Groq API to extract chapters (free tier, fast, open-source models)
     *
     * @param string $toc The book's table of contents
     * @param string $apikey Groq API key
     * @return array Array of chapters
     */
    private static function call_groq_api($toc, $apikey, $guidance = '') {
        $config = get_config('book');
        $model = $config->groq_model ?? 'openai/gpt-oss-120b';

        $structure = self::detect_main_chapters($toc);

        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => self::build_chapter_prompt($toc, $guidance)
                ]
            ],
            'temperature' => 0,
            'max_tokens' => 4000,
            'response_format' => ['type' => 'json_object']
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            $error_response = substr($response, 0, 500);
            throw new \Exception('Groq API request failed with HTTP ' . $httpcode . '. Response: ' . $error_response);
        }

        $result = json_decode($response, true);

        $text = $result['choices'][0]['message']['content'] ?? '';
        $chapters = self::finalise_chapters(
            self::normalise_chapters(json_decode($text, true)), $toc, $structure);

        if (!empty($chapters)) {
            return $chapters;
        }

        throw new \Exception('Could not identify chapters. Model returned: ' . substr($text, 0, 400));
    }

    /**
     * Call Ollama API to extract chapters (free, open-source, local)
     *
     * @param string $toc The book's table of contents
     * @return array Array of chapters
     */
    private static function call_ollama_api($toc) {
        $config = get_config('book');
        $ollamaurl = $config->ollama_url ?? 'http://host.docker.internal:11434';
        $ollamamodel = $config->ollama_model ?? 'llama3';

        $structure = self::detect_main_chapters($toc);

        $prompt = self::build_chapter_prompt($toc);

        $data = [
            'model' => $ollamamodel,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json'
        ];

        $ch = curl_init($ollamaurl . '/api/generate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout for local processing

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            $error_response = substr($response, 0, 500);
            throw new \Exception('Ollama request failed with HTTP ' . $httpcode . '. Response: ' . $error_response);
        }

        $result = json_decode($response, true);

        $text = $result['response'] ?? '';
        $chapters = self::finalise_chapters(
            self::normalise_chapters(json_decode($text, true)), $toc, $structure);

        if (!empty($chapters)) {
            return $chapters;
        }

        throw new \Exception('Could not identify chapters. Model returned: ' . substr($text, 0, 400));
    }

    /**
     * Call Claude API to extract chapters
     *
     * @param string $content Book content
     * @param string $apikey API key
     * @return array Array of chapters
     */
    private static function call_claude_api($content, $apikey) {
        $prompt = "Analyze the following book content and extract the chapter structure. " .
                  "Return a JSON array of chapters, where each chapter has 'title' and 'summary' fields. " .
                  "Include only main chapters, not subchapters. " .
                  "Format: [{\"title\": \"Chapter 1: Introduction\", \"summary\": \"Brief summary\"}]\n\n" .
                  "Book content:\n" . substr($content, 0, 50000); // Limit content size

        $data = [
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 4000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $apikey,
            'anthropic-version: 2023-06-01'
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'API request failed: ' . $httpcode);
        }

        $result = json_decode($response, true);

        if (isset($result['content'][0]['text'])) {
            $text = $result['content'][0]['text'];

            // Extract JSON from the response
            if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
                $chapters = json_decode($matches[0], true);

                if (is_array($chapters)) {
                    return $chapters;
                }
            }
        }

        throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'Unable to parse AI response');
    }

    /**
     * Call OpenAI API to extract chapters
     *
     * @param string $content Book content
     * @param string $apikey API key
     * @return array Array of chapters
     */
    private static function call_openai_api($content, $apikey) {
        $prompt = "Analyze the following book content and extract the chapter structure. " .
                  "Return a JSON array of chapters, where each chapter has 'title' and 'summary' fields. " .
                  "Include only main chapters, not subchapters. " .
                  "Format: [{\"title\": \"Chapter 1: Introduction\", \"summary\": \"Brief summary\"}]\n\n" .
                  "Book content:\n" . substr($content, 0, 50000);

        $data = [
            'model' => 'gpt-4',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => 4000
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'API request failed: ' . $httpcode);
        }

        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            $text = $result['choices'][0]['message']['content'];

            // Extract JSON from the response
            if (preg_match('/\[[\s\S]*\]/', $text, $matches)) {
                $chapters = json_decode($matches[0], true);

                if (is_array($chapters)) {
                    return $chapters;
                }
            }
        }

        throw new \moodle_exception('aiprocessingerror', 'mod_book', '', 'Unable to parse AI response');
    }
}
