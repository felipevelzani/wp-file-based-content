<?php
/**
 * FBCWP Admin UI
 * 
 * Handles settings and admin page interface.
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// SETTINGS FUNCTIONS
// =============================================================================

function fbcwp_get_content_path() {
    $path = apply_filters('fbcwp_content_path', null);
    return $path && is_dir($path) ? $path : null;
}

function fbcwp_get_post_types() {
    $hook_value = apply_filters('fbcwp_post_types', null);
    if ($hook_value !== null) {
        return (array) $hook_value;
    }
    return get_option('fbcwp_post_types', ['post']);
}

function fbcwp_post_types_from_hook() {
    $hook_value = apply_filters('fbcwp_post_types', null);
    return $hook_value !== null;
}

// =============================================================================
// ADMIN MENU
// =============================================================================

add_action('admin_menu', 'fbcwp_admin_menu');

function fbcwp_admin_menu() {
    add_options_page(
        __('File-based Content', 'file-based-content'),
        __('FBC', 'file-based-content'),
        'manage_options',
        'file-based-content',
        'fbcwp_admin_page'
    );
}

add_action('admin_init', 'fbcwp_admin_init');

function fbcwp_admin_init() {
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if ($page !== 'file-based-content') {
        return;
    }
    
    $export_nonce = isset($_GET['fbcwp_export_nonce']) ? sanitize_text_field(wp_unslash($_GET['fbcwp_export_nonce'])) : '';
    if (isset($_GET['fbcwp_export']) && wp_verify_nonce($export_nonce, 'fbcwp_export')) {
        fbcwp_handle_export();
        exit;
    }
    
    $post_nonce = isset($_POST['fbcwp_nonce']) ? sanitize_text_field(wp_unslash($_POST['fbcwp_nonce'])) : '';
    
    if (isset($_POST['fbcwp_save_settings']) && wp_verify_nonce($post_nonce, 'fbcwp_settings')) {
        if (!fbcwp_post_types_from_hook()) {
            $post_types = isset($_POST['fbcwp_post_types']) ? array_map('sanitize_key', wp_unslash((array) $_POST['fbcwp_post_types'])) : ['post'];
            update_option('fbcwp_post_types', $post_types);
        }
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'file-based-content') . '</p></div>';
        });
    }
    
    if (isset($_POST['fbcwp_sync_now']) && wp_verify_nonce($post_nonce, 'fbcwp_sync_now')) {
        $results = fbcwp_run_sync();
        update_option('fbcwp_last_sync', [
            'time' => current_time('timestamp'),
            'results' => $results,
        ]);
        
        $total = count($results['created']) + count($results['updated']);
        $drafted = count($results['drafted'] ?? []);
        add_action('admin_notices', function() use ($total, $drafted) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            /* translators: %d: number of items processed */
            printf(esc_html__('Sync complete. %d items processed.', 'file-based-content'), absint($total));
            if ($drafted > 0) {
                echo ' ';
                /* translators: %d: number of posts moved to draft */
                printf(esc_html__('%d posts moved to draft.', 'file-based-content'), absint($drafted));
            }
            echo '</p></div>';
        });
    }
    
    if (isset($_POST['fbcwp_generate']) && wp_verify_nonce($post_nonce, 'fbcwp_generate')) {
        $plugin_name = isset($_POST['fbcwp_plugin_name']) ? sanitize_text_field(wp_unslash($_POST['fbcwp_plugin_name'])) : 'fbcwp-content';
        $post_types = isset($_POST['fbcwp_gen_post_types']) ? array_map('sanitize_key', wp_unslash((array) $_POST['fbcwp_gen_post_types'])) : ['post'];
        
        $result = fbcwp_generate_content_plugin($plugin_name, $post_types);
        
        if ($result['success']) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-success is-dismissible"><p>';
                /* translators: %d: number of exported items */
                printf(esc_html__('Content plugin generated! Exported %d items.', 'file-based-content'), absint($result['exported']));
                echo '</p></div>';
            });
        } else {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html($result['error']);
                echo '</p></div>';
            });
        }
    }
}

function fbcwp_handle_export() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized', 'file-based-content'));
    }
    
    $content_path = fbcwp_get_content_path();
    if (!$content_path || !is_dir($content_path)) {
        wp_die(esc_html__('No content path available', 'file-based-content'));
    }
    
    $plugin_dir = dirname($content_path);
    $plugin_name = basename($plugin_dir);
    
    if (!class_exists('ZipArchive')) {
        wp_die(esc_html__('ZipArchive extension is required for export', 'file-based-content'));
    }
    
    $tmp_file = wp_tempnam($plugin_name . '.zip');
    $zip = new ZipArchive();
    
    if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die(esc_html__('Could not create ZIP file', 'file-based-content'));
    }
    
    fbcwp_add_folder_to_zip($zip, $plugin_dir, $plugin_name);
    $zip->close();
    
    global $wp_filesystem;
    
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $plugin_name . '.zip"');
    header('Content-Length: ' . filesize($tmp_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP file output, escaping would corrupt data.
    echo $wp_filesystem->get_contents($tmp_file);
    wp_delete_file($tmp_file);
    exit;
}

function fbcwp_add_folder_to_zip($zip, $folder, $base_name) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $file_path = $file->getRealPath();
            $relative_path = $base_name . '/' . substr($file_path, strlen($folder) + 1);
            $zip->addFile($file_path, $relative_path);
        }
    }
}

function fbcwp_admin_page() {
    $content_path = fbcwp_get_content_path();
    $post_types = fbcwp_get_post_types();
    $from_hook = fbcwp_post_types_from_hook();
    $last_sync = get_option('fbcwp_last_sync');
    
    $all_post_types = get_post_types(['public' => true], 'objects');
    unset($all_post_types['attachment']);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('File-based Content for WordPress', 'file-based-content'); ?></h1>
        
        <?php if ($content_path): ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Content Path', 'file-based-content'); ?></h2>
                <p><code><?php echo esc_html($content_path); ?></code></p>
                
                <?php
                $items = fbcwp_scan_content();
                $counts = [];
                foreach ($items as $item) {
                    $counts[$item['post_type']] = ($counts[$item['post_type']] ?? 0) + 1;
                }
                if (!empty($counts)):
                ?>
                <ul>
                    <?php foreach ($counts as $type => $count): ?>
                        <li>
                            <?php
                            /* translators: %1$s: post type name, %2$d: number of items */
                            printf(esc_html__('%1$s: %2$d items', 'file-based-content'), esc_html($type), absint($count));
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Sync Status', 'file-based-content'); ?></h2>
                
                <?php
                $next_run = wp_next_scheduled('fbcwp_sync_cron');
                if ($next_run):
                ?>
                <p>
                    <strong><?php esc_html_e('Cron:', 'file-based-content'); ?></strong>
                    <?php esc_html_e('Running every 5 minutes', 'file-based-content'); ?>
                    <br>
                    <strong><?php esc_html_e('Next run:', 'file-based-content'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_run)); ?>
                </p>
                <?php else: ?>
                <p style="color: orange;">
                    <?php esc_html_e('Cron not scheduled. Try deactivating and reactivating the plugin.', 'file-based-content'); ?>
                </p>
                <?php endif; ?>
                
                <?php if ($last_sync): ?>
                <p>
                    <strong><?php esc_html_e('Last sync:', 'file-based-content'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync['time'])); ?>
                </p>
                <ul>
                    <?php if (!empty($last_sync['results']['created'])): ?>
                        <li style="color: green;">
                            <?php
                            /* translators: %d: number of posts created */
                            printf(esc_html__('Created: %d', 'file-based-content'), count($last_sync['results']['created']));
                            ?>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($last_sync['results']['updated'])): ?>
                        <li style="color: blue;">
                            <?php
                            /* translators: %d: number of posts updated */
                            printf(esc_html__('Updated: %d', 'file-based-content'), count($last_sync['results']['updated']));
                            ?>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($last_sync['results']['skipped'])): ?>
                        <li>
                            <?php
                            /* translators: %d: number of posts skipped */
                            printf(esc_html__('Skipped: %d unchanged', 'file-based-content'), count($last_sync['results']['skipped']));
                            ?>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($last_sync['results']['drafted'])): ?>
                        <li style="color: orange;">
                            <?php
                            /* translators: %d: number of posts drafted */
                            printf(esc_html__('Drafted: %d (no matching source)', 'file-based-content'), count($last_sync['results']['drafted']));
                            ?>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($last_sync['results']['errors'])): ?>
                        <li style="color: red;">
                            <?php
                            /* translators: %d: number of errors */
                            printf(esc_html__('Errors: %d', 'file-based-content'), count($last_sync['results']['errors']));
                            ?>
                        </li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
                
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('fbcwp_sync_now', 'fbcwp_nonce'); ?>
                    <input type="submit" name="fbcwp_sync_now" class="button button-primary" 
                        value="<?php esc_attr_e('Sync Now', 'file-based-content'); ?>">
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Export Content', 'file-based-content'); ?></h2>
                <p><?php esc_html_e('Download your content plugin as a ZIP file. You can use this for backup, version control with Git, or to import on another WordPress installation.', 'file-based-content'); ?></p>
                
                <?php
                $plugin_dir = dirname($content_path);
                $plugin_name = basename($plugin_dir);
                $export_url = add_query_arg([
                    'page' => 'file-based-content',
                    'fbcwp_export' => '1',
                    'fbcwp_export_nonce' => wp_create_nonce('fbcwp_export'),
                ], admin_url('options-general.php'));
                ?>
                
                <p>
                    <strong><?php esc_html_e('Plugin:', 'file-based-content'); ?></strong> 
                    <code><?php echo esc_html($plugin_name); ?></code>
                </p>
                
                <p style="margin-top: 15px;">
                    <a href="<?php echo esc_url($export_url); ?>" class="button button-secondary">
                        <?php esc_html_e('Download ZIP', 'file-based-content'); ?>
                    </a>
                </p>
                
                <p class="description" style="margin-top: 10px;">
                    <?php esc_html_e('Tip: Extract the ZIP to your plugins folder and track with Git for version control.', 'file-based-content'); ?>
                </p>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('Enabled Post Types', 'file-based-content'); ?></h2>
                
                <?php if ($from_hook): ?>
                    <p><em><?php esc_html_e('Set via fbcwp_post_types hook (read-only)', 'file-based-content'); ?></em></p>
                    <ul>
                        <?php foreach ($post_types as $pt): ?>
                            <li><?php echo esc_html($pt); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <form method="post">
                        <?php wp_nonce_field('fbcwp_settings', 'fbcwp_nonce'); ?>
                        
                        <?php foreach ($all_post_types as $pt): ?>
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" name="fbcwp_post_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                    <?php checked(in_array($pt->name, $post_types)); ?>>
                                <?php echo esc_html($pt->label); ?>
                            </label>
                        <?php endforeach; ?>
                        
                        <p style="margin-top: 15px;">
                            <input type="submit" name="fbcwp_save_settings" class="button button-primary" 
                                value="<?php esc_attr_e('Save Settings', 'file-based-content'); ?>">
                        </p>
                    </form>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2><?php esc_html_e('No content plugin detected', 'file-based-content'); ?></h2>
                <p><?php esc_html_e('Generate a content plugin to export your existing posts as Markdown files.', 'file-based-content'); ?></p>
                
                <form method="post">
                    <?php wp_nonce_field('fbcwp_generate', 'fbcwp_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="fbcwp_plugin_name"><?php esc_html_e('Plugin Name', 'file-based-content'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="fbcwp_plugin_name" name="fbcwp_plugin_name" 
                                    value="fbcwp-content" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Post Types to Export', 'file-based-content'); ?></th>
                            <td>
                                <?php foreach ($all_post_types as $pt): 
                                    $count = wp_count_posts($pt->name);
                                    $total = isset($count->publish) ? $count->publish : 0;
                                ?>
                                    <label style="display: block; margin-bottom: 8px;">
                                        <input type="checkbox" name="fbcwp_gen_post_types[]" value="<?php echo esc_attr($pt->name); ?>"
                                            <?php checked($pt->name === 'post'); ?>>
                                        <?php
                                        /* translators: %1$s: post type label, %2$d: number of items */
                                        printf(esc_html__('%1$s (%2$d items)', 'file-based-content'), esc_html($pt->label), absint($total));
                                        ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <p>
                        <input type="submit" name="fbcwp_generate" class="button button-primary" 
                            value="<?php esc_attr_e('Generate & Activate', 'file-based-content'); ?>">
                    </p>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
