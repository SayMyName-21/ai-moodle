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

    /** Seconds to wait for Docling to answer at all, as opposed to to finish. */
    const DOCLING_CONNECT_TIMEOUT = 5;

    /** Consecutive Docling failures after which a run stops asking it. */
    const DOCLING_GIVE_UP = 2;

    /**
     * Extract chapters from a book file using AI
     *
     * @param string $filepath Path to the uploaded book file
     * @param string $filetype Type of file (pdf, docx, txt)
     * @return array Array of chapters with title and content
     * @throws \moodle_exception
     */
    public static function extract_chapters($filepath, $filetype) {
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
        $chapters = self::call_ai_api($content, $config->ai_api_key, $config->ai_provider ?? 'claude');

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
        $output = array();
        $return_var = 0;

        // -layout preserves indentation and dot leaders, which is how a table of
        // contents encodes its heading hierarchy. Only the front matter is needed.
        exec('pdftotext -layout -f 1 -l 30 ' . escapeshellarg($filepath) . ' - 2>/dev/null',
             $output, $return_var);

        if ($return_var !== 0 || empty($output)) {
            throw new \Exception('pdftotext could not read this PDF. It may be a scanned image with no text layer.');
        }

        return implode("\n", $output);
    }

    /**
     * Read the book's structure along with the page range each entry covers.
     *
     * @param string $filepath Path to the PDF
     * @return array offset, total pages, and chapters each holding their subtopics
     */
    public static function get_outline($filepath) {
        $toc = self::extract_toc_section(self::extract_pdf_text($filepath));
        $entries = self::toc_entries($toc);
        $structure = self::detect_main_chapters($toc);
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
        $output = array();
        exec('pdftotext -layout -f ' . (int)$from . ' -l ' . (int)$to . ' ' .
             escapeshellarg($filepath) . ' - 2>/dev/null', $output);
        $text = implode("\n", $output);

        // Docling reads the same pages more carefully. It rejoins the words a book
        // breaks across a line end, which pdftotext leaves in halves, and it reads
        // a page that is a picture rather than text. Whichever reading carries the
        // more wording is the one kept, so a failed or empty answer changes nothing.
        $ocr = self::docling_pages($filepath, $from, $to);
        if (mb_strlen(self::squash($ocr)) > mb_strlen(self::squash($text))) {
            return $ocr;
        }

        return $text;
    }

    /**
     * Strip whitespace so two extractions can be compared on wording alone.
     *
     * @param string $text Text to reduce
     * @return string The text with all whitespace removed
     */
    private static function squash($text) {
        return preg_replace('/\s+/u', '', (string)$text);
    }

    /**
     * Read a page range through Docling, picking up text that sits inside pictures.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page, as a PDF page number
     * @param int $to Last page, as a PDF page number
     * @return string The pages as Markdown, or an empty string if Docling was no help
     */
    private static function docling_pages($filepath, $from, $to) {
        $config = get_config('book');
        if (empty($config->docling_enabled)) {
            return '';
        }

        // A run works through one page range after another. Once Docling has
        // stopped answering, waiting out the timeout on every remaining range
        // would cost far more than the reading is worth, so it is dropped for the
        // rest of the run and pdftotext carries on alone.
        static $failures = 0;
        if ($failures >= self::DOCLING_GIVE_UP) {
            return '';
        }

        // Only the wanted pages are sent, which keeps a slow converter bearable.
        $slice = self::slice_pdf($filepath, $from, $to);
        if ($slice === '') {
            return '';
        }

        $url = rtrim(!empty($config->docling_url) ? $config->docling_url
            : 'http://docling:5001', '/');

        // Tesseract is the only engine Docling offers with a Macedonian model, so
        // it is asked for by name rather than left to automatic selection, which
        // rejects the language outright. force_ocr stays off: it would overwrite a
        // real text layer with a worse reading of it.
        $post = array(
            'files' => new \CURLFile($slice, 'application/pdf', 'slice.pdf'),
            'to_formats' => 'md',
            'do_ocr' => 'true',
            'ocr_preset' => 'tesserocr',
            'ocr_lang' => !empty($config->docling_ocrlang) ? $config->docling_ocrlang : 'mkd',
        );

        $ch = curl_init($url . '/v1/convert/file');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT,
            (int)(!empty($config->docling_timeout) ? $config->docling_timeout : 300));
        // Reading a long range legitimately takes minutes, but opening the
        // connection should be instant. Separating the two means a service that is
        // down is noticed in seconds instead of holding up the whole timeout.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::DOCLING_CONNECT_TIMEOUT);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        remove_dir(dirname($slice));

        $result = ($httpcode === 200) ? json_decode($response, true) : null;
        if (!isset($result['document']['md_content'])) {
            $failures++;
            return '';
        }

        $failures = 0;

        return (string)$result['document']['md_content'];
    }

    /**
     * Copy a page range into a PDF of its own.
     *
     * The pages are copied rather than re-rendered, so the text layer the book
     * already carries arrives intact.
     *
     * @param string $filepath Path to the PDF
     * @param int $from First page
     * @param int $to Last page
     * @return string Path to the slice, or an empty string if it could not be made
     */
    private static function slice_pdf($filepath, $from, $to) {
        $dir = make_temp_directory('mod_book/slice-' . uniqid());

        exec('pdfseparate -f ' . (int)$from . ' -l ' . (int)$to . ' ' .
             escapeshellarg($filepath) . ' ' .
             escapeshellarg($dir . '/p-%d.pdf') . ' 2>/dev/null');

        $pages = glob($dir . '/p-*.pdf');
        if (empty($pages)) {
            remove_dir($dir);
            return '';
        }

        // glob sorts as text, which would put page 10 before page 2.
        natsort($pages);
        $pages = array_values($pages);

        $merged = $dir . '/range.pdf';
        if (count($pages) === 1) {
            rename($pages[0], $merged);
        } else {
            $args = '';
            foreach ($pages as $page) {
                $args .= escapeshellarg($page) . ' ';
            }
            exec('pdfunite ' . $args . escapeshellarg($merged) . ' 2>/dev/null');
        }

        if (!file_exists($merged)) {
            remove_dir($dir);
            return '';
        }

        return $merged;
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
        $output = array();
        exec('pdfinfo ' . escapeshellarg($filepath) . ' 2>/dev/null', $output);
        foreach ($output as $line) {
            if (preg_match('/^Pages:\s*(\d+)/', $line, $match)) {
                return (int)$match[1];
            }
        }
        return 0;
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
        $output = array();
        exec('pdftotext -layout ' . escapeshellarg($filepath) . ' - 2>/dev/null', $output);
        $pages = explode("\f", implode("\n", $output));

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
    private static function build_chapter_prompt($toc) {
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
    private static function call_ai_api($content, $apikey, $provider = 'groq') {
        if ($provider === 'groq') {
            return self::call_groq_api($content, $apikey);
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
    private static function call_groq_api($content, $apikey) {
        $config = get_config('book');
        $model = $config->groq_model ?? 'openai/gpt-oss-120b';

        $toc = self::extract_toc_section($content);
        $structure = self::detect_main_chapters($toc);

        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => self::build_chapter_prompt($toc)
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
