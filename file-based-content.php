<?php
/**
 * Plugin Name: File-based Content (FBC)
 * Description: Manage post content via Git-friendly Markdown files.
 * Version: 1.1.0
 * Author: Velzani
 * License: GPL-2.0-or-later
 * Text Domain: file-based-content
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FBCWP_VERSION', '1.1.0');
define('FBCWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FBCWP_PLUGIN_FILE', __FILE__);
define('FBCWP_META_KEY', '_fbcwp_source_md');

require_once FBCWP_PLUGIN_DIR . 'vendor/autoload.php';

require_once FBCWP_PLUGIN_DIR . 'includes/sync.php';
require_once FBCWP_PLUGIN_DIR . 'includes/snapshot.php';
require_once FBCWP_PLUGIN_DIR . 'includes/admin.php';


register_activation_hook(__FILE__, 'fbcwp_activate');
register_deactivation_hook(__FILE__, 'fbcwp_deactivate');

function fbcwp_activate() {
    fbcwp_schedule_cron();
}

function fbcwp_deactivate() {
    fbcwp_unschedule_cron();
}
