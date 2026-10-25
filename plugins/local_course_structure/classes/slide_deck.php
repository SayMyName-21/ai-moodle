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
 * Renders lecture slides as a PDF.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

class slide_deck {

    /**
     * Read a deck back out of a PDF this class wrote.
     *
     * Recovering the slides from the file avoids asking the model to write them a
     * second time, which the daily token allowance would not stand.
     *
     * @param string $pdfpath Path to the PDF
     * @return array Slides, each with a heading and bullets
     */
    public static function parse($pdfpath) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/classes/ai_service.php');

        // Read the same careful way as the book. Docling marks a bullet with "-"
        // rather than the "•" the deck was drawn with, and a heading may arrive
        // prefixed with hashes; both spellings are accepted below.
        $pages = \mod_book\ai_service::book_pages($pdfpath);

        $bullet = "\xe2\x80\xa2";
        $slides = array();

        // The first page is the title, which the caller already knows.
        foreach (array_slice($pages, 1) as $page) {
            $lines = array();
            foreach (preg_split('/\R/u', $page) as $line) {
                $line = trim($line);
                // Markdown emphasis and image placeholders are not wording.
                $line = preg_replace('/^#{1,6}\s*/u', '', $line);
                $line = str_replace(array('**', '<!-- image -->'), '', $line);
                $line = trim($line);

                if ($line !== '' && strpos($line, 'Powered by TCPDF') === false) {
                    $lines[] = $line;
                }
            }

            // Drop the slide number printed in the corner.
            while (!empty($lines) && preg_match('/^\d{1,3}$/', end($lines))) {
                array_pop($lines);
            }
            if (empty($lines)) {
                continue;
            }

            $heading = '';
            $bullets = array();
            foreach ($lines as $line) {
                if (mb_strpos($line, $bullet, 0, 'UTF-8') === 0) {
                    $bullets[] = trim(mb_substr($line, 1, null, 'UTF-8'));
                } else if (preg_match('/^[-*]\s+(.*)$/u', $line, $m)) {
                    $bullets[] = trim($m[1]);
                } else if (empty($bullets)) {
                    $heading = ($heading === '') ? $line : $heading . ' ' . $line;
                } else {
                    // A bullet long enough to have wrapped onto the next line.
                    $bullets[count($bullets) - 1] .= ' ' . $line;
                }
            }

            if ($heading !== '') {
                $slides[] = array('heading' => $heading, 'bullets' => $bullets);
            }
        }

        return $slides;
    }

    /**
     * Write a slide deck to a PDF file.
     *
     * @param string $title Deck title
     * @param string $subtitle Line under the title, usually the chapter
     * @param array $slides Slides, each with a heading and bullets
     * @param string $outpath Where to write the PDF
     */
    public static function render($title, $subtitle, $slides, $outpath) {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf('L', 'mm', 'A4');
        $pdf->SetCreator('Moodle');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);

        // freesans is the bundled font with Cyrillic coverage.
        $pdf->AddPage();
        $pdf->SetY(65);
        $pdf->SetFont('freesans', 'B', 26);
        $pdf->MultiCell(0, 13, $title, 0, 'C');
        if (trim($subtitle) !== '') {
            $pdf->Ln(5);
            $pdf->SetFont('freesans', '', 14);
            $pdf->MultiCell(0, 9, $subtitle, 0, 'C');
        }

        $number = 0;
        foreach ($slides as $slide) {
            $number++;
            $pdf->AddPage();

            $pdf->SetFont('freesans', 'B', 21);
            $pdf->MultiCell(0, 11, $slide['heading'], 0, 'L');
            $pdf->Ln(4);

            $pdf->SetFont('freesans', '', 13);
            foreach ($slide['bullets'] as $bullet) {
                $pdf->MultiCell(0, 7.5, "\xe2\x80\xa2  " . $bullet, 0, 'L');
                $pdf->Ln(2);
            }

            // Placing the number near the foot would otherwise trip the page break.
            $pdf->SetAutoPageBreak(false);
            $pdf->SetY(-16);
            $pdf->SetFont('freesans', '', 9);
            $pdf->Cell(0, 6, (string)$number, 0, 0, 'R');
            $pdf->SetAutoPageBreak(true, 20);
        }

        $pdf->Output($outpath, 'F');
    }
}
