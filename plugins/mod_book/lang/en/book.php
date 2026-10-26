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
 * Book module language strings
 *
 * @package    mod_book
 * @copyright  2004-2012 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['modulename'] = 'Book';
$string['modulename_help'] = 'The book module enables a teacher to create a multi-page resource in a book-like format, with chapters and subchapters. Books can contain media files as well as text and are useful for displaying lengthy passages of information which can be broken down into sections.

A book may be used

* To display reading material for individual modules of study
* As a staff departmental handbook
* As a showcase portfolio of student work';
$string['modulename_link'] = 'mod/book/view';
$string['modulenameplural'] = 'Books';
$string['pluginname'] = 'Book';
$string['pluginadministration'] = 'Book administration';

$string['toc'] = 'Table of contents';
$string['chapterandsubchaptersdeleted'] = 'Chapter "{$a->title}" and its {$a->subchapters} subchapters were deleted';
$string['chapterdeleted'] = 'Chapter "{$a->title}" was deleted';
$string['customtitles'] = 'Custom titles';
$string['customtitles_help'] = 'Normally the chapter title is displayed in the table of contents (TOC) AND as a heading above the content.

If the custom titles checkbox is ticked, the chapter title is NOT displayed as a heading above the content. A different title (perhaps longer than the chapter title) may be entered as part of the content.';
$string['chapters'] = 'Chapters';
$string['chaptertitle'] = 'Chapter title';
$string['content'] = 'Content';
$string['deletechapter'] = 'Delete chapter "{$a}"';
$string['editingchapter'] = 'Editing chapter';
$string['eventchaptercreated'] = 'Chapter created';
$string['eventchapterdeleted'] = 'Chapter deleted';
$string['eventchapterupdated'] = 'Chapter updated';
$string['eventchapterviewed'] = 'Chapter viewed';
$string['editchapter'] = 'Edit chapter "{$a}"';
$string['hidechapter'] = 'Hide chapter "{$a}"';
$string['indicator:cognitivedepth'] = 'Book cognitive';
$string['indicator:cognitivedepth_help'] = 'This indicator is based on the cognitive depth reached by the student in a Book resource.';
$string['indicator:cognitivedepthdef'] = 'Book cognitive';
$string['indicator:cognitivedepthdef_help'] = 'The participant has reached this percentage of the cognitive engagement offered by the Book activities during this analysis interval (Levels = No view, View)';
$string['indicator:cognitivedepthdef_link'] = 'Learning_analytics_indicators#Cognitive_depth';
$string['indicator:socialbreadth'] = 'Book social';
$string['indicator:socialbreadth_help'] = 'This indicator is based on the social breadth reached by the student in a Book resource.';
$string['indicator:socialbreadthdef'] = 'Book social';
$string['indicator:socialbreadthdef_help'] = 'The participant has reached this percentage of the social engagement offered by the Book activities during this analysis interval (Levels = No participation, Participant alone)';
$string['indicator:socialbreadthdef_link'] = 'Learning_analytics_indicators#Social_breadth';
$string['movechapterup'] = 'Move chapter up "{$a}"';
$string['movechapterdown'] = 'Move chapter down "{$a}"';
$string['privacy:metadata'] = 'The book activity module does not store any personal data.';
$string['search:activity'] = 'Book - resource information';
$string['search:chapter'] = 'Book - chapters';
$string['showchapter'] = 'Show chapter "{$a}"';
$string['subchapter'] = 'Subchapter';
$string['navoptions'] = 'Available options for navigational links';
$string['navoptions_desc'] = 'Options for displaying navigation on the book pages';
$string['navstyle'] = 'Style of navigation';
$string['navstyle_help'] = '* Images - Icons are used for navigation
* Text - Chapter titles are used for navigation';
$string['nocontent'] = 'No content has been added to this book yet.';
$string['numbering'] = 'Chapter formatting';
$string['numbering_help'] = '* None - Chapter and subchapter titles have no formatting
* Numbers - Chapters and subchapter titles are numbered 1, 1.1, 1.2, 2, ...
* Bullets - Subchapters are indented and displayed with bullets in the table of contents
* Indented - Subchapters are indented in the table of contents';
$string['numbering0'] = 'None';
$string['numbering1'] = 'Numbers';
$string['numbering2'] = 'Bullets';
$string['numbering3'] = 'Indented';
$string['numberingoptions'] = 'Available options for chapter formatting';
$string['numberingoptions_desc'] = 'Options for displaying chapters and subchapters in the table of contents';
$string['addafter'] = 'Add new chapter';
$string['addafterchapter'] = 'Add new chapter after "{$a->title}"';
$string['previouschapter'] = 'Previous chapter';
$string['confchapterdelete'] = 'Do you really want to delete this chapter?';
$string['confchapterdeleteall'] = 'Do you really want to delete this chapter and all its subchapters?';
$string['top'] = 'top';
$string['navprev'] = 'Previous';
$string['navprevtitle'] = 'Previous: {$a}';
$string['navnext'] = 'Next';
$string['navnexttitle'] = 'Next: {$a}';
$string['navexit'] = 'Exit book';
$string['book:addinstance'] = 'Add a new book';
$string['book:read'] = 'View book';
$string['book:edit'] = 'Edit book chapters';
$string['book:viewhiddenchapters'] = 'View hidden book chapters';
$string['errorchapter'] = 'Error reading chapter of book.';

$string['page-mod-book-x'] = 'Any book module page';
$string['subchapternotice'] = '(Only available once the first chapter has been created)';
$string['subplugintype_booktool'] = 'Book tool';
$string['subplugintype_booktool_plural'] = 'Book tools';

$string['removeallbooktags'] = 'All book tags';
$string['tagarea_book_chapters'] = 'Book chapters';

// Deprecated since Moodle 4.4.
$string['navimages'] = 'Images';
$string['navtext'] = 'Text';
$string['navtoc'] = 'TOC Only';

// Deprecated since Moodle 4.5.
$string['tagsdeleted'] = 'Book tags have been deleted';

// AI Chapter Extraction.
$string['aichapterextraction'] = 'AI Chapter Extraction';
$string['enableaichapters'] = 'Enable AI chapter extraction';
$string['enableaichapters_help'] = 'When enabled, this will use AI to automatically analyze the uploaded book file and create course sections based on the chapters found in the book.';
$string['bookfile'] = 'Upload book file';
$string['bookfile_help'] = 'Upload a book file (PDF, DOCX, or TXT) that will be analyzed by AI to extract chapters and create course sections automatically.';
$string['aiprocessing'] = 'AI is processing your book...';
$string['aiprocessingcomplete'] = 'AI processing complete. {$a} course sections created from book chapters.';
$string['aiprocessingerror'] = 'Error processing book with AI: {$a}';
$string['aiapikeynotset'] = 'AI API key not configured. Please contact your site administrator.';
$string['aichapternumber'] = 'Chapter {$a}';

// AI Settings.
$string['aisettings'] = 'AI Settings';
$string['aisettings_desc'] = 'Configure AI provider for automatic chapter extraction from book files. Groq is recommended (free tier, fast, open-source models).';
$string['aiprovider'] = 'AI Provider';
$string['aiprovider_desc'] = 'Groq: Free tier with fast inference and open-source models (recommended). Ollama: Free, runs locally. Claude/OpenAI: Paid API services.';
$string['groqmodel'] = 'Groq Model';
$string['groqmodel_desc'] = 'Select the Groq model for chapter extraction. Llama 3.1 70B is recommended for best quality.';
$string['ollamaurl'] = 'Ollama URL';
$string['ollamaurl_desc'] = 'URL where Ollama is running. Default: http://host.docker.internal:11434 (for Docker). Use http://localhost:11434 for local installation.';
$string['ollamamodel'] = 'Ollama Model';
$string['ollamamodel_desc'] = 'Ollama model to use for chapter extraction. Recommended: llama3, mistral, or llama3.1. Install models with: ollama pull llama3';
$string['aiapikey'] = 'AI API Key';
$string['aiapikey_desc'] = 'Enter your API key. Required for Groq, Claude, and OpenAI. Get a free Groq API key at: https://console.groq.com';
$string['doclingsettings'] = 'Reading the book';
$string['doclingsettings_desc'] = 'Every page of the book is read by Docling, the only reader the plugin uses. It reads tables as tables, rejoins words the book breaks across a line end, and reads pages that are pictures or scans. The book is read once, ten pages at a time, and the reading is kept, so only the first use of a book waits for it; a reading that is interrupted resumes from the last finished ten pages. If Docling cannot be reached or cannot read a page, the work stops with Docling\'s reason rather than carrying on with a worse reading.';
$string['doclingurl'] = 'Docling URL';
$string['doclingurl_desc'] = 'Address of the Docling server. Default: http://docling:5001 (the service name inside Docker).';
$string['doclingocrlang'] = 'Language on the page';
$string['doclingocrlang_desc'] = 'Tesseract language code, such as mkd for Macedonian or eng for English; join several with a plus sign, as in mkd+eng. Tesseract is used because it is the only engine Docling ships with a Macedonian model, and its language files are supplied by the tessdata folder mounted in docker-compose.';
$string['doclingtimeout'] = 'Timeout per ten pages (seconds)';
$string['doclingtimeout_desc'] = 'How long to let Docling work on one run of ten pages before the reading stops with an error. Ten text pages take around forty seconds and scanned ones longer, so 300 leaves plenty of room. Waiting for the connection itself is capped separately at a few seconds, so a server that is switched off is noticed straight away.';
