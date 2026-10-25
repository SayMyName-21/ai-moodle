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

        // Call AI API to extract chapters
        $chapters = self::call_ai_api($content, $config->ai_api_key,
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
                $content = self::extract_pdf_text($filepath);
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
     * Extract text from PDF file
     *
     * @param string $filepath Path to PDF file
     * @return string Extracted text
     */
    private static function extract_pdf_text($filepath) {
        // Docling reads a contents table as a table, which is what it is, and the
        // rows come back as lines with the entry at the front - the shape the
        // detection below expects. Read as Markdown instead it arrives wrapped in
        // pipe delimiters, which put a "|" where the chapter label should be and
        // left the detection finding 23 chapters and no sections.
        $pages = array_slice(self::book_pages($filepath), 0, self::FRONT_MATTER_PAGES);
        $text = implode("\n", $pages);

        if (trim($text) === '') {
            throw new \Exception('Docling found no text in the first ' .
                self::FRONT_MATTER_PAGES . ' pages of this PDF.');
        }

        return $text;
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
     * kept: the wait is paid once per book, on upload, and every page view
     * afterwards is served from the cache.
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

        // Docling learns the book's length only by reading it, so chunks are read
        // until one comes back short: that one held the last page.
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

        return $pages;
    }

    /**
     * Read a run of pages through Docling.
     *
     * The conversion is submitted as a task and then checked on, rather than held
     * open as one long request, so a slow chunk is waited for without any single
     * connection having to last minutes.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page
     * @param int $to Last page; may run past the end of the book
     * @return array Text of each page read, in order from $from; empty if $from
     *               lies past the end of the book
     * @throws \Exception if Docling cannot read the pages
     */
    private static function docling_chunk($filepath, $from, $to) {
        $config = get_config('book');
        $url = rtrim(!empty($config->docling_url) ? $config->docling_url
            : 'http://docling:5001', '/');
        $timeout = (int)(!empty($config->docling_timeout) ? $config->docling_timeout : 300);
        $ocrlang = !empty($config->docling_ocrlang) ? $config->docling_ocrlang : 'mkd';
        $range = $from . '-' . $to;

        // Tesseract is the only engine Docling offers with a Macedonian model, so
        // it is asked for by name rather than left to automatic selection, which
        // rejects the language outright. force_ocr stays off: it would overwrite a
        // real text layer with a worse reading of it. The structured form keeps
        // tables as tables and says which page each piece of wording came from.
        $task = self::docling_request($url . '/v1/convert/source/async', array(
            'options' => array(
                'to_formats' => array('json'),
                'do_ocr' => true,
                'ocr_preset' => 'tesserocr',
                'ocr_lang' => explode('+', $ocrlang),
                'page_range' => array((int)$from, (int)$to),
            ),
            'sources' => array(array(
                'kind' => 'file',
                'filename' => 'book.pdf',
                'base64_string' => base64_encode(file_get_contents($filepath)),
            )),
        ));
        if (empty($task['task_id'])) {
            throw new \Exception('Docling at ' . $url . ' did not accept pages ' . $range . '.');
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
            throw new \Exception('Docling could not read pages ' . $range . ': ' . $why);
        }

        $result = self::docling_request($url . '/v1/result/' . $taskid);
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

        throw new \Exception('Docling could not read pages ' . $range . ': ' .
            ($messages ? implode('; ', $messages) : 'no document returned'));
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
        $toc = self::extract_toc_section(self::extract_pdf_text($filepath));
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
        $offset = self::detect_page_offset($filepath, $entries);

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
                    'rank' => self::entry_rank($entry['title']),
                );
            }
        }
        if ($current !== null) {
            $chapters[] = $current;
        }

        return array('offset' => $offset, 'total' => $total, 'chapters' => $chapters);
    }

    /**
     * The parts of the book a presentation can be built from.
     *
     * These are the divisions the book names inside each of its main chapters,
     * which run to roughly one lecture's worth of material each.
     *
     * @param array $outline Result of get_outline
     * @return array Sections in book order, each with its title, chapter and lessons
     */
    public static function list_sections($outline) {
        $sections = array();

        foreach ($outline['chapters'] as $chapterindex => $chapter) {
            $parts = array();
            $current = null;
            foreach ($chapter['subtopics'] as $sub) {
                if ($sub['rank'] === 0) {
                    $current = $sub['title'];
                    $parts[$current] = array();
                } else if ($current !== null) {
                    $parts[$current][] = $sub;
                }
            }

            // A chapter that names no parts still has its lessons.
            if (empty($parts)) {
                $parts[$chapter['title']] = $chapter['subtopics'];
            }

            foreach ($parts as $name => $lessons) {
                if (empty($lessons)) {
                    continue;
                }
                $sections[] = array(
                    'title' => $name,
                    'chapter' => $chapter['title'],
                    'chapterindex' => $chapterindex,
                    'lessons' => $lessons,
                );
            }
        }

        return $sections;
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
     * @return string The table of contents
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
            return implode("\n", array_slice($lines, $start, $end - $start + 1));
        }

        // No dot leaders, so look for a heading that announces the contents.
        foreach ($lines as $i => $line) {
            if (preg_match('/^(содржина|садржај|sodrzina|contents|table of contents|inhalt|índice|sommaire)$/iu', trim($line))) {
                return implode("\n", array_slice($lines, $i + 1, 200));
            }
        }

        return mb_substr($text, 0, 6000, 'UTF-8');
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

        // A capitalised label followed by a number ("ТЕМА 1:", "CHAPTER 1", "PART I")
        // marks the top division, whatever word a given book happens to use for it.
        $labelled = array();
        foreach ($entries as $entry) {
            if (preg_match('/^\p{Lu}[\p{Lu}]+\s+(?:\d{1,2}|[IVXLC]{1,5})\b/u', $entry['title'])) {
                $labelled[] = $entry['title'];
            }
        }
        if (count($labelled) >= 2) {
            return array('titles' => $labelled, 'tier' => 1);
        }

        // Otherwise whole-number entries, excluding "1.1" style subdivisions.
        $numbered = array();
        foreach ($entries as $entry) {
            if (preg_match('/^\d{1,3}[\.\)]?\s+\S/u', $entry['title'])
                && !preg_match('/^\d+\.\d/u', $entry['title'])) {
                $numbered[] = $entry['title'];
            }
        }
        if (count($numbered) >= 2) {
            return array('titles' => $numbered, 'tier' => 2);
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
                $items[] = array(
                    'title' => $entries[$i]['title'],
                    'rank' => self::entry_rank($entries[$i]['title']),
                );
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
        $lines = preg_split('/\R/u', $toc);

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
            if (preg_match('/^\p{Lu}[\p{Lu}]+\s+(?:\d{1,2}|[IVXLC]{1,5})\b/u', $chapter['title'])) {
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
     * @param string $apikey API key (not used for Ollama)
     * @param string $provider AI provider (ollama, claude, openai)
     * @return array Array of chapters
     */
    private static function call_ai_api($content, $apikey, $provider = 'groq', $guidance = '') {
        if ($provider === 'groq') {
            return self::call_groq_api($content, $apikey, $guidance);
        } else if ($provider === 'ollama') {
            return self::call_ollama_api($content);
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
     * @param string $content Book content
     * @param string $apikey Groq API key
     * @return array Array of chapters
     */
    private static function call_groq_api($content, $apikey, $guidance = '') {
        $config = get_config('book');
        $model = $config->groq_model ?? 'openai/gpt-oss-120b';

        $toc = self::extract_toc_section($content);
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
     * @param string $content Book content
     * @return array Array of chapters
     */
    private static function call_ollama_api($content) {
        $config = get_config('book');
        $ollamaurl = $config->ollama_url ?? 'http://host.docker.internal:11434';
        $ollamamodel = $config->ollama_model ?? 'llama3';

        $toc = self::extract_toc_section($content);
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
