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
 * Contains helper class for the H5P area.
 *
 * @package    core_h5p
 * @copyright  2019 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_h5p;

use context_system;
use core_h5p\local\library\autoloader;

defined('MOODLE_INTERNAL') || die();

/**
 * Helper class for the H5P area.
 *
 * @copyright  2019 Sara Arjona <sara@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Store an H5P file.
     *
     * @param factory $factory The \core_h5p\factory object
     * @param stored_file $file Moodle file instance
     * @param stdClass $config Button options config
     * @param bool $onlyupdatelibs Whether new libraries can be installed or only the existing ones can be updated
     * @param bool $skipcontent Should the content be skipped (so only the libraries will be saved)?
     *
     * @return int|false|null The H5P identifier or null if there is an error when saving or false if it's not a valid H5P package
     */
    public static function save_h5p(factory $factory, \stored_file $file, \stdClass $config, bool $onlyupdatelibs = false,
            bool $skipcontent = false) {
        // This may take a long time.
        \core_php_time_limit::raise();

        $core = $factory->get_core();
        $core->h5pF->set_file($file);
        $path = $core->fs->getTmpPath();
        $core->h5pF->getUploadedH5pFolderPath($path);
        // Add manually the extension to the file to avoid the validation fails.
        $path .= '.h5p';
        $core->h5pF->getUploadedH5pPath($path);

        // Copy the .h5p file to the temporary folder.
        $file->copy_content_to($path);

        // Check if the h5p file is valid before saving it.
        $h5pvalidator = $factory->get_validator();
        if ($h5pvalidator->isValidPackage($skipcontent, $onlyupdatelibs)) {
            $h5pstorage = $factory->get_storage();

            $content = [
                'pathnamehash' => $file->get_pathnamehash(),
                'contenthash' => $file->get_contenthash(),
            ];
            $options = ['disable' => self::get_display_options($core, $config)];

            $h5pstorage->savePackage($content, null, $skipcontent, $options);

            return $h5pstorage->contentId;
        }
        return false;
    }


    /**
     * Get the representation of display options as int.
     *
     * @param core $core The \core_h5p\core object
     * @param stdClass $config Button options config
     *
     * @return int The representation of display options as int
     */
    public static function get_display_options(core $core, \stdClass $config): int {
        $export = isset($config->export) ? $config->export : 0;
        $embed = isset($config->embed) ? $config->embed : 0;
        $copyright = isset($config->copyright) ? $config->copyright : 0;
        $frame = ($export || $embed || $copyright);
        if (!$frame) {
            $frame = isset($config->frame) ? $config->frame : 0;
        }

        $disableoptions = [
            core::DISPLAY_OPTION_FRAME     => $frame,
            core::DISPLAY_OPTION_DOWNLOAD  => $export,
            core::DISPLAY_OPTION_EMBED     => $embed,
            core::DISPLAY_OPTION_COPYRIGHT => $copyright,
        ];

        return $core->getStorableDisplayOptions($disableoptions, 0);
    }

    /**
     * Convert the int representation of display options into stdClass
     *
     * @param core $core The \core_h5p\core object
     * @param int $displayint integer value representing display options
     *
     * @return int The representation of display options as int
     */
    public static function decode_display_options(core $core, int $displayint = null): \stdClass {
        $config = new \stdClass();
        if ($displayint === null) {
            $displayint = self::get_display_options($core, $config);
        }
        $displayarray = $core->getDisplayOptionsForEdit($displayint);
        $config->export = $displayarray[core::DISPLAY_OPTION_DOWNLOAD] ?? 0;
        $config->embed = $displayarray[core::DISPLAY_OPTION_EMBED] ?? 0;
        $config->copyright = $displayarray[core::DISPLAY_OPTION_COPYRIGHT] ?? 0;
        return $config;
    }

    /**
     * Checks if the author of the .h5p file is "trustable". If the file hasn't been uploaded by a user with the
     * required capability, the content won't be deployed.
     *
     * @param  stored_file $file The .h5p file to be deployed
     * @return bool Returns true if the file can be deployed, false otherwise.
     */
    public static function can_deploy_package(\stored_file $file): bool {
        if (null === $file->get_userid()) {
            // If there is no userid, it is owned by the system.
            return true;
        }

        $context = \context::instance_by_id($file->get_contextid());
        if (has_capability('moodle/h5p:deploy', $context, $file->get_userid())) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the content-type libraries can be upgraded.
     * The H5P content-type libraries can only be upgraded if the author of the .h5p file can manage content-types or if all the
     * content-types exist, to avoid users without the required capability to upload malicious content.
     *
     * @param  stored_file $file The .h5p file to be deployed
     * @return bool Returns true if the content-type libraries can be created/updated, false otherwise.
     */
    public static function can_update_library(\stored_file $file): bool {
        if (null === $file->get_userid()) {
            // If there is no userid, it is owned by the system.
            return true;
        }

        // Check if the owner of the .h5p file has the capability to manage content-types.
        $context = \context::instance_by_id($file->get_contextid());
        if (has_capability('moodle/h5p:updatelibraries', $context, $file->get_userid())) {
            return true;
        }

        return false;
    }

    /**
     * Convenience to take a fixture test file and create a stored_file.
     *
     * @param string $filepath The filepath of the file
     * @param  int   $userid  The author of the file
     * @param  \context $context The context where the file will be created
     * @return stored_file The file created
     */
    public static function create_fake_stored_file_from_path(string $filepath, int $userid = 0,
            \context $context = null): \stored_file {
        if (is_null($context)) {
            $context = context_system::instance();
        }
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'core_h5p',
            'filearea'  => 'unittest',
            'itemid'    => rand(),
            'filepath'  => '/',
            'filename'  => basename($filepath),
        ];
        if (!is_null($userid)) {
            $filerecord['userid'] = $userid;
        }

        $fs = get_file_storage();
        return $fs->create_file_from_pathname($filerecord, $filepath);
    }

    /**
     * Get information about different H5P tools and their status.
     *
     * @return array Data to render by the template
     */
    public static function get_h5p_tools_info(): array {
        $tools = array();

        // Getting information from available H5P tools one by one because their enabled/disabled options are totally different.
        // Check the atto button status.
        $link = \editor_atto\plugininfo\atto::get_manage_url();
        $status = strpos(get_config('editor_atto', 'toolbar'), 'h5p') > -1;
        $tools[] = self::convert_info_into_array('atto_h5p', $link, $status);

        // Check the Display H5P filter status.
        $link = \core\plugininfo\filter::get_manage_url();
        $status = filter_get_active_state('displayh5p', context_system::instance()->id);
        $tools[] = self::convert_info_into_array('filter_displayh5p', $link, $status);

        // Check H5P scheduled task.
        $link = '';
        $status = 0;
        $statusaction = '';
        if ($task = \core\task\manager::get_scheduled_task('\core\task\h5p_get_content_types_task')) {
            $status = !$task->get_disabled();
            $link = new \moodle_url(
                '/admin/tool/task/scheduledtasks.php',
                array('action' => 'edit', 'task' => get_class($task))
            );
            if ($status && \tool_task\run_from_cli::is_runnable() && get_config('tool_task', 'enablerunnow')) {
                $statusaction = \html_writer::link(
                    new \moodle_url('/admin/tool/task/schedule_task.php',
                        array('task' => get_class($task))),
                    get_string('runnow', 'tool_task'));
            }
        }
        $tools[] = self::convert_info_into_array('task_h5p', $link, $status, $statusaction);

        return $tools;
    }

    /**
     * Convert information into needed mustache template data array
     * @param string $tool The name of the tool
     * @param \moodle_url $link The URL to management page
     * @param int $status The current status of the tool
     * @param string $statusaction A link to 'Run now' option for the task
     * @return array
     */
    static private function convert_info_into_array(string $tool,
        \moodle_url $link,
        int $status,
        string $statusaction = ''): array {

        $statusclasses = array(
            TEXTFILTER_DISABLED => 'badge badge-danger',
            TEXTFILTER_OFF => 'badge badge-warning',
            0 => 'badge badge-danger',
            TEXTFILTER_ON => 'badge badge-success',
        );

        $statuschoices = array(
            TEXTFILTER_DISABLED => get_string('disabled', 'admin'),
            TEXTFILTER_OFF => get_string('offbutavailable', 'core_filters'),
            0 => get_string('disabled', 'admin'),
            1 => get_string('enabled', 'admin'),
        );

        return [
            'tool' => get_string($tool, 'h5p'),
            'tool_description' => get_string($tool . '_description', 'h5p'),
            'link' => $link,
            'status' => $statuschoices[$status],
            'status_class' => $statusclasses[$status],
            'status_action' => $statusaction,
        ];
    }

    /**
     * Get a query string with the theme revision number to include at the end
     * of URLs. This is used to force the browser to reload the asset when the
     * theme caches are cleared.
     *
     * @return string
     */
    public static function get_cache_buster(): string {
        global $CFG;
        return '?ver=' . $CFG->themerev;
    }

    /**
     * Get the settings needed by the H5P library.
     *
     * @return array The settings.
     */
    public static function get_core_settings(): array {
        global $CFG;

        $basepath = $CFG->wwwroot . '/';
        $systemcontext = context_system::instance();

        // Generate AJAX paths.
        $ajaxpaths = [];
        $ajaxpaths['xAPIResult'] = '';
        $ajaxpaths['contentUserData'] = '';

        $factory = new factory();
        $core = $factory->get_core();

        $settings = array(
            'baseUrl' => $basepath,
            'url' => "{$basepath}pluginfile.php/{$systemcontext->instanceid}/core_h5p",
            'urlLibraries' => "{$basepath}pluginfile.php/{$systemcontext->id}/core_h5p/libraries",
            'postUserStatistics' => false,
            'ajax' => $ajaxpaths,
            'saveFreq' => false,
            'siteUrl' => $CFG->wwwroot,
            'l10n' => array('H5P' => $core->getLocalization()),
            'user' => [],
            'hubIsEnabled' => true,
            'reportingIsEnabled' => false,
            'crossorigin' => null,
            'libraryConfig' => $core->h5pF->getLibraryConfig(),
            'pluginCacheBuster' => self::get_cache_buster(),
            'libraryUrl' => autoloader::get_h5p_core_library_url('core/js')
        );

        return $settings;
    }

    /**
     * Get the core H5P assets, including all core H5P JavaScript and CSS.
     *
     * @return Array core H5P assets.
     */
    public static function get_core_assets(): array {
        global $CFG, $PAGE;

        // Get core settings.
        $settings = self::get_core_settings();
        $settings['core'] = [
            'styles' => [],
            'scripts' => []
        ];
        $settings['loadedJs'] = [];
        $settings['loadedCss'] = [];

        // Make sure files are reloaded for each plugin update.
        $cachebuster = self::get_cache_buster();

        // Use relative URL to support both http and https.
        $liburl = autoloader::get_h5p_core_library_url()->out();
        $relpath = '/' . preg_replace('/^[^:]+:\/\/[^\/]+\//', '', $liburl);

        // Add core stylesheets.
        foreach (core::$styles as $style) {
            $settings['core']['styles'][] = $relpath . $style . $cachebuster;
            $PAGE->requires->css(new \moodle_url($liburl . $style . $cachebuster));
        }
        // Add core JavaScript.
        foreach (core::get_scripts() as $script) {
            $settings['core']['scripts'][] = $script->out(false);
            $PAGE->requires->js($script, true);
        }

        return $settings;
    }

    /**
     * Add required assets for displaying the editor.
     *
     * @param int $id Id of the content being edited. null for creating new content.
     * @param string $mformid Id of Moodle form
     *
     * @return void
     */
    public static function add_editor_assets_to_page(?int $id = null, string $mformid = null): void {
        global $PAGE, $CFG;

        $libeditorpath = 'lib/h5peditor';

        // Require classes from H5P third party library.
        autoloader::register();

        $context = context_system::instance();

        $settings = self::get_core_assets();

        // Use jQuery and styles from core.
        $assets = array(
            'css' => $settings['core']['styles'],
            'js' => $settings['core']['scripts']
        );

        // Use relative URL to support both http and https.
        $url = $CFG->wwwroot . '/'. $libeditorpath . '/';
        $url = '/' . preg_replace('/^[^:]+:\/\/[^\/]+\//', '', $url);

        // Make sure files are reloaded for each plugin update.
        $cachebuster = self::get_cache_buster();

        // Add editor styles.
        foreach (H5peditor::$styles as $style) {
            $assets['css'][] = $url . $style . $cachebuster;
        }

        // Add editor JavaScript.
        foreach (H5peditor::$scripts as $script) {
            // We do not want the creator of the iframe inside the iframe.
            if ($script !== 'scripts/h5peditor-editor.js') {
                $assets['js'][] = $url . $script . $cachebuster;
            }
        }

        // Add JavaScript with library framework integration (editor part).
        $PAGE->requires->js(new moodle_url('/'. $libeditorpath .'/scripts/h5peditor-editor.js' . $cachebuster), true);
        $PAGE->requires->js(new moodle_url('/'. $libeditorpath .'/scripts/h5peditor-init.js' . $cachebuster), true);
        $PAGE->requires->js(new moodle_url('/h5p/editor.js' . $cachebuster), true);

        // Add translations.
        $language = framework::get_language();
        $languagescript = "language/{$language}.js";

        if (!file_exists("{$CFG->dirroot}/" . $libeditorpath . "/{$languagescript}")) {
            $languagescript = 'language/en.js';
        }
        $PAGE->requires->js(new moodle_url('/' . $libeditorpath .'/' . $languagescript . $cachebuster), true);

        // Add JavaScript settings.
        $root = $CFG->wwwroot;
        $filespathbase = "{$root}/pluginfile.php/{$context->id}/core_h5p/";

        $factory = new factory();
        $contentvalidator = $factory->get_content_validator();

        $editorajaxtoken = H5PCore::createToken(editor_ajax::EDITOR_AJAX_TOKEN);
        $settings['editor'] = array(
            'filesPath' => $filespathbase . 'editor',
            'fileIcon' => array(
                'path' => $url . 'images/binary-file.png',
                'width' => 50,
                'height' => 50,
            ),
            'ajaxPath' => $CFG->wwwroot . '/h5p/' . "ajax.php?contextId={$context->id}&token={$editorajaxtoken}&action=",
            'libraryUrl' => $url,
            'copyrightSemantics' => $contentvalidator->getCopyrightSemantics(),
            'metadataSemantics' => $contentvalidator->getMetadataSemantics(),
            'assets' => $assets,
            'apiVersion' => H5PCore::$coreApi,
            'language' => $language,
            'formId' => $mformid,
        );

        if ($id !== null) {
            $settings['editor']['nodeVersionId'] = $id;

            // Override content URL.
            $contenturl = "{$root}/pluginfile.php/{$context->id}/core_h5p/content/{$id}";
            $settings['contents']['cid-' . $id]['contentUrl'] = $contenturl;
        }

        $PAGE->requires->data_for_js('H5PIntegration', $settings, true);
    }
}