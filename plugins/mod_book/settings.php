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
 * Book plugin settings
 *
 * @package    mod_book
 * @copyright  2004-2011 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once(__DIR__.'/lib.php');

    // General settings

    $options = book_get_numbering_types();

    $settings->add(new admin_setting_configmultiselect('book/numberingoptions',
        get_string('numberingoptions', 'mod_book'), get_string('numberingoptions_desc', 'mod_book'),
        array_keys($options), $options));

    // Modedit defaults.

    $settings->add(new admin_setting_heading('bookmodeditdefaults',
        get_string('modeditdefaults', 'admin'), get_string('condifmodeditdefaults', 'admin')));

    $settings->add(new admin_setting_configselect('book/numbering',
        get_string('numbering', 'mod_book'), '', BOOK_NUM_NUMBERS, $options));

    // AI Settings
    $settings->add(new admin_setting_heading('bookaisettings',
        get_string('aisettings', 'mod_book'), get_string('aisettings_desc', 'mod_book')));

    $aiproviders = array(
        'groq' => 'Groq (Free, Fast, Open Source)',
        'ollama' => 'Ollama (Free, Open Source, Local)',
        'claude' => 'Claude (Anthropic)',
        'openai' => 'OpenAI GPT-4'
    );

    $settings->add(new admin_setting_configselect('book/ai_provider',
        get_string('aiprovider', 'mod_book'),
        get_string('aiprovider_desc', 'mod_book'),
        'groq',
        $aiproviders));

    $groqmodels = array(
        'openai/gpt-oss-120b' => 'GPT-OSS 120B (Recommended)',
        'openai/gpt-oss-20b' => 'GPT-OSS 20B (Faster)',
        'qwen/qwen3.8-27b' => 'Qwen 3.8 27B',
        'qwen/qwen3.6-27b' => 'Qwen 3.6 27B'
    );

    $settings->add(new admin_setting_configselect('book/groq_model',
        get_string('groqmodel', 'mod_book'),
        get_string('groqmodel_desc', 'mod_book'),
        'openai/gpt-oss-120b',
        $groqmodels));

    $settings->add(new admin_setting_configtext('book/ollama_url',
        get_string('ollamaurl', 'mod_book'),
        get_string('ollamaurl_desc', 'mod_book'),
        'http://host.docker.internal:11434',
        PARAM_TEXT,
        60));

    $settings->add(new admin_setting_configtext('book/ollama_model',
        get_string('ollamamodel', 'mod_book'),
        get_string('ollamamodel_desc', 'mod_book'),
        'llama3',
        PARAM_TEXT,
        30));

    $settings->add(new admin_setting_configtext('book/ai_api_key',
        get_string('aiapikey', 'mod_book'),
        get_string('aiapikey_desc', 'mod_book'),
        '',
        PARAM_TEXT,
        60));

    // Reading text out of pictures.

    $settings->add(new admin_setting_heading('bookdoclingsettings',
        get_string('doclingsettings', 'mod_book'),
        get_string('doclingsettings_desc', 'mod_book')));

    $settings->add(new admin_setting_configcheckbox('book/docling_enabled',
        get_string('doclingenabled', 'mod_book'),
        get_string('doclingenabled_desc', 'mod_book'),
        0));

    $settings->add(new admin_setting_configtext('book/docling_url',
        get_string('doclingurl', 'mod_book'),
        get_string('doclingurl_desc', 'mod_book'),
        'http://docling:5001',
        PARAM_TEXT,
        60));

    $settings->add(new admin_setting_configtext('book/docling_ocrlang',
        get_string('doclingocrlang', 'mod_book'),
        get_string('doclingocrlang_desc', 'mod_book'),
        'mkd',
        PARAM_TEXT,
        30));

    $settings->add(new admin_setting_configtext('book/docling_timeout',
        get_string('doclingtimeout', 'mod_book'),
        get_string('doclingtimeout_desc', 'mod_book'),
        300,
        PARAM_INT,
        10));

}
