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
 * Writes a slide deck as a PowerPoint file.
 *
 * A .pptx is a zip of Open XML parts, so it is assembled directly rather than
 * through a library. Text is written as real text boxes, which keeps the slides
 * editable instead of shipping a picture of each page.
 *
 * @package    local_course_structure
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_structure;

defined('MOODLE_INTERNAL') || die();

class pptx_deck {

    /** Slide width in English Metric Units: 13.333in, giving 16:9. */
    const WIDTH = 12192000;

    /** Slide height in English Metric Units: 7.5in. */
    const HEIGHT = 6858000;

    /**
     * Write a deck to a .pptx file.
     *
     * @param string $title Deck title
     * @param string $subtitle Line under the title
     * @param array $slides Slides, each with a heading and bullets
     * @param string $outpath Where to write the file
     */
    public static function render($title, $subtitle, $slides, $outpath) {
        $zip = new \ZipArchive();
        if ($zip->open($outpath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception('Could not create ' . $outpath);
        }

        $total = count($slides) + 1;

        $zip->addFromString('[Content_Types].xml', self::content_types($total));
        $zip->addFromString('_rels/.rels', self::root_rels());
        $zip->addFromString('ppt/presentation.xml', self::presentation($total));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', self::presentation_rels($total));
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::slide_master());
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', self::master_rels());
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', self::slide_layout());
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', self::layout_rels());
        $zip->addFromString('ppt/theme/theme1.xml', self::theme());

        $zip->addFromString('ppt/slides/slide1.xml', self::title_slide($title, $subtitle));
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', self::slide_rels());

        foreach ($slides as $i => $slide) {
            $n = $i + 2;
            $zip->addFromString('ppt/slides/slide' . $n . '.xml',
                self::content_slide($slide['heading'], $slide['bullets']));
            $zip->addFromString('ppt/slides/_rels/slide' . $n . '.xml.rels', self::slide_rels());
        }

        $zip->close();
    }

    /**
     * Escape text for XML content.
     *
     * @param string $text Raw text
     * @return string Escaped text
     */
    private static function esc($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * The package part manifest.
     *
     * @param int $slides Number of slides
     * @return string XML
     */
    private static function content_types($slides) {
        $overrides = '';
        for ($i = 1; $i <= $slides; $i++) {
            $overrides .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ' .
                'ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>' .
            '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>' .
            '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>' .
            '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>' .
            $overrides .
            '</Types>';
    }

    /**
     * Package level relationships.
     *
     * @return string XML
     */
    private static function root_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>' .
            '</Relationships>';
    }

    /**
     * The presentation part, listing slides in order.
     *
     * @param int $slides Number of slides
     * @return string XML
     */
    private static function presentation($slides) {
        $list = '';
        for ($i = 1; $i <= $slides; $i++) {
            // Relationship 1 is the master, so slides start at 2.
            $list .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
            '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>' .
            '<p:sldIdLst>' . $list . '</p:sldIdLst>' .
            '<p:sldSz cx="' . self::WIDTH . '" cy="' . self::HEIGHT . '"/>' .
            '<p:notesSz cx="6858000" cy="9144000"/>' .
            '</p:presentation>';
    }

    /**
     * Relationships from the presentation to its master and slides.
     *
     * @param int $slides Number of slides
     * @return string XML
     */
    private static function presentation_rels($slides) {
        $rels = '<Relationship Id="rId1" ' .
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" ' .
            'Target="slideMasters/slideMaster1.xml"/>';
        for ($i = 1; $i <= $slides; $i++) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" ' .
                'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" ' .
                'Target="slides/slide' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($slides + 2) . '" ' .
            'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" ' .
            'Target="theme/theme1.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            $rels . '</Relationships>';
    }

    /**
     * An empty shape tree, used by the master and layout.
     *
     * @param string $name Shape tree name
     * @return string XML
     */
    private static function empty_tree($name) {
        return '<p:cSld name="' . $name . '"><p:spTree>' .
            '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' .
            '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>' .
            '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>' .
            '</p:spTree></p:cSld>';
    }

    /**
     * The slide master.
     *
     * @return string XML
     */
    private static function slide_master() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
            self::empty_tree('Master') .
            '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" ' .
            'accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" ' .
            'accent6="accent6" hlink="hlink" folHlink="folHlink"/>' .
            '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>' .
            '</p:sldMaster>';
    }

    /**
     * Master relationships: its layout and the theme.
     *
     * @return string XML
     */
    private static function master_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>' .
            '</Relationships>';
    }

    /**
     * A blank slide layout. Slides carry their own text boxes.
     *
     * @return string XML
     */
    private static function slide_layout() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank">' .
            self::empty_tree('Blank') .
            '</p:sldLayout>';
    }

    /**
     * Layout relationships: back to the master.
     *
     * @return string XML
     */
    private static function layout_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>' .
            '</Relationships>';
    }

    /**
     * Slide relationships: back to the layout.
     *
     * @return string XML
     */
    private static function slide_rels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>' .
            '</Relationships>';
    }

    /**
     * A text box shape.
     *
     * @param int $id Shape id, unique within the slide
     * @param string $name Shape name
     * @param array $box Position and size in EMU: x, y, cx, cy
     * @param string $paragraphs Paragraph XML
     * @return string XML
     */
    private static function textbox($id, $name, $box, $paragraphs) {
        return '<p:sp><p:nvSpPr>' .
            '<p:cNvPr id="' . $id . '" name="' . $name . '"/>' .
            '<p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>' .
            '<p:spPr><a:xfrm>' .
            '<a:off x="' . $box['x'] . '" y="' . $box['y'] . '"/>' .
            '<a:ext cx="' . $box['cx'] . '" cy="' . $box['cy'] . '"/>' .
            '</a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>' .
            '<p:txBody><a:bodyPr wrap="square" lIns="91440" tIns="45720" rIns="91440" ' .
            'bIns="45720"><a:normAutofit/></a:bodyPr><a:lstStyle/>' .
            $paragraphs .
            '</p:txBody></p:sp>';
    }

    /**
     * Wrap a shape tree around the slide's shapes.
     *
     * @param string $shapes Shape XML
     * @return string XML
     */
    private static function slide_xml($shapes) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">' .
            '<p:cSld><p:spTree>' .
            '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>' .
            '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>' .
            '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>' .
            $shapes .
            '</p:spTree></p:cSld>' .
            '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>' .
            '</p:sld>';
    }

    /**
     * The opening slide.
     *
     * @param string $title Deck title
     * @param string $subtitle Line under the title
     * @return string XML
     */
    private static function title_slide($title, $subtitle) {
        $paragraphs = '<a:p><a:pPr algn="ctr"/><a:r>' .
            '<a:rPr lang="mk-MK" sz="4000" b="1" dirty="0"/>' .
            '<a:t>' . self::esc($title) . '</a:t></a:r></a:p>';

        if (trim((string)$subtitle) !== '') {
            $paragraphs .= '<a:p><a:pPr algn="ctr"/><a:r>' .
                '<a:rPr lang="mk-MK" sz="2000" dirty="0"/>' .
                '<a:t>' . self::esc($subtitle) . '</a:t></a:r></a:p>';
        }

        return self::slide_xml(self::textbox(2, 'Title', array(
            'x' => 838200, 'y' => 2286000, 'cx' => 10515600, 'cy' => 2286000,
        ), $paragraphs));
    }

    /**
     * A content slide: heading above a bulleted list.
     *
     * @param string $heading Slide heading
     * @param array $bullets Bullet lines
     * @return string XML
     */
    private static function content_slide($heading, $bullets) {
        $headingxml = '<a:p><a:r><a:rPr lang="mk-MK" sz="2800" b="1" dirty="0"/>' .
            '<a:t>' . self::esc($heading) . '</a:t></a:r></a:p>';

        $body = '';
        foreach ($bullets as $bullet) {
            $body .= '<a:p><a:pPr marL="285750" indent="-285750">' .
                '<a:buFont typeface="Arial"/><a:buChar char="' . "\xe2\x80\xa2" . '"/></a:pPr>' .
                '<a:r><a:rPr lang="mk-MK" sz="1800" dirty="0"/>' .
                '<a:t>' . self::esc($bullet) . '</a:t></a:r></a:p>';
        }
        if ($body === '') {
            $body = '<a:p><a:endParaRPr lang="mk-MK"/></a:p>';
        }

        $shapes = self::textbox(2, 'Heading', array(
            'x' => 685800, 'y' => 457200, 'cx' => 10820400, 'cy' => 1143000,
        ), $headingxml);

        $shapes .= self::textbox(3, 'Body', array(
            'x' => 685800, 'y' => 1752600, 'cx' => 10820400, 'cy' => 4351338,
        ), $body);

        return self::slide_xml($shapes);
    }

    /**
     * A minimal theme, which the master requires.
     *
     * @return string XML
     */
    private static function theme() {
        $colours = array(
            'dk1' => '000000', 'lt1' => 'FFFFFF', 'dk2' => '44546A', 'lt2' => 'E7E6E6',
            'accent1' => '4472C4', 'accent2' => 'ED7D31', 'accent3' => 'A5A5A5',
            'accent4' => 'FFC000', 'accent5' => '5B9BD5', 'accent6' => '70AD47',
            'hlink' => '0563C1', 'folHlink' => '954F72',
        );

        $scheme = '';
        foreach ($colours as $name => $hex) {
            if ($name === 'dk1' || $name === 'lt1') {
                $scheme .= '<a:' . $name . '><a:sysClr val="' .
                    ($name === 'dk1' ? 'windowText' : 'window') .
                    '" lastClr="' . $hex . '"/></a:' . $name . '>';
            } else {
                $scheme .= '<a:' . $name . '><a:srgbClr val="' . $hex . '"/></a:' . $name . '>';
            }
        }

        $fill = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $line = '<a:ln w="6350" cap="flat" cmpd="sng" algn="ctr">' . $fill .
            '<a:prstDash val="solid"/></a:ln>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office">' .
            '<a:themeElements>' .
            '<a:clrScheme name="Office">' . $scheme . '</a:clrScheme>' .
            '<a:fontScheme name="Office">' .
            '<a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>' .
            '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>' .
            '</a:fontScheme>' .
            '<a:fmtScheme name="Office">' .
            '<a:fillStyleLst>' . $fill . $fill . $fill . '</a:fillStyleLst>' .
            '<a:lnStyleLst>' . $line . $line . $line . '</a:lnStyleLst>' .
            '<a:effectStyleLst>' .
            '<a:effectStyle><a:effectLst/></a:effectStyle>' .
            '<a:effectStyle><a:effectLst/></a:effectStyle>' .
            '<a:effectStyle><a:effectLst/></a:effectStyle>' .
            '</a:effectStyleLst>' .
            '<a:bgFillStyleLst>' . $fill . $fill . $fill . '</a:bgFillStyleLst>' .
            '</a:fmtScheme>' .
            '</a:themeElements>' .
            '<a:objectDefaults/><a:extraClrSchemeLst/>' .
            '</a:theme>';
    }
}
