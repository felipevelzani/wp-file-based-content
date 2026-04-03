<?php
/**
 * FBCWP Snapshot Functions
 * 
 * Takes a snapshot of current WordPress content and generates a content plugin
 * with Markdown files. Used for initial setup or migrating existing content.
 */

if (!defined('ABSPATH')) {
    exit;
}

function fbcwp_generate_content_plugin($plugin_name, $post_types) {
    global $wp_filesystem;
    
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    
    $plugins_dir = WP_PLUGIN_DIR;
    $plugin_slug = sanitize_title($plugin_name);
    $plugin_path = $plugins_dir . '/' . $plugin_slug;
    
    if (is_dir($plugin_path)) {
        return ['success' => false, 'error' => __('Plugin folder already exists', 'file-based-content')];
    }
    
    wp_mkdir_p($plugin_path);
    wp_mkdir_p($plugin_path . '/content');
    
    $post_types_php = fbcwp_array_to_php($post_types);
    
    $php_content = "<?php\n";
    $php_content .= "/**\n";
    $php_content .= " * Plugin Name: " . esc_html($plugin_name) . "\n";
    $php_content .= " * Description: Content files for File-based Content (FBC)\n";
    $php_content .= " * Version: 1.0.0\n";
    $php_content .= " */\n\n";
    $php_content .= "if (!defined('ABSPATH')) {\n";
    $php_content .= "    exit;\n";
    $php_content .= "}\n\n";
    $php_content .= "add_filter('fbcwp_content_path', function() {\n";
    $php_content .= "    return __DIR__ . '/content';\n";
    $php_content .= "});\n\n";
    $php_content .= "add_filter('fbcwp_post_types', function() {\n";
    $php_content .= "    return " . $post_types_php . ";\n";
    $php_content .= "});\n";
    
    $wp_filesystem->put_contents($plugin_path . '/' . $plugin_slug . '.php', $php_content, FS_CHMOD_FILE);
    
    $exported = 0;
    
    foreach ($post_types as $post_type) {
        $folder = $post_type === 'post' ? 'posts' : $post_type . 's';
        if ($post_type === 'page') $folder = 'pages';
        
        $type_path = $plugin_path . '/content/' . $folder;
        wp_mkdir_p($type_path);
        
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
        ]);
        
        foreach ($posts as $post) {
            $status_folder = fbcwp_status_to_folder($post->post_status);
            $status_path = $type_path . '/' . $status_folder;
            wp_mkdir_p($status_path);
            
            $post_path = $status_path . '/' . $post->post_name;
            wp_mkdir_p($post_path);
            
            $attachment_map = fbcwp_export_attachments($post->post_content, $post_path);
            $md = fbcwp_post_to_markdown($post, $attachment_map);
            $wp_filesystem->put_contents($post_path . '/index.md', $md, FS_CHMOD_FILE);
            
            if (has_post_thumbnail($post->ID)) {
                $thumb_id = get_post_thumbnail_id($post->ID);
                $thumb_path = get_attached_file($thumb_id);
                if ($thumb_path && file_exists($thumb_path)) {
                    $thumb_name = basename($thumb_path);
                    $wp_filesystem->copy($thumb_path, $post_path . '/' . $thumb_name);
                }
            }
            
            $exported++;
        }
    }
    
    activate_plugin($plugin_slug . '/' . $plugin_slug . '.php');
    
    return ['success' => true, 'exported' => $exported, 'path' => $plugin_path];
}

function fbcwp_array_to_php($array) {
    $items = array_map(function($item) {
        return "'" . addslashes($item) . "'";
    }, $array);
    return '[' . implode(', ', $items) . ']';
}

function fbcwp_export_attachments($content, $post_path) {
    global $wp_filesystem;
    
    $attachment_map = [];
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    $site_url = home_url();
    
    $urls = fbcwp_extract_attachment_urls($content, $site_url);
    
    foreach ($urls as $url) {
        if (isset($attachment_map[$url])) {
            continue;
        }
        
        $attachment_id = attachment_url_to_postid($url);
        if (!$attachment_id) {
            $attachment_id = fbcwp_url_to_attachment_id($url);
        }
        
        if ($attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if ($file_path && file_exists($file_path)) {
                $filename = fbcwp_get_unique_filename($post_path, basename($file_path));
                $wp_filesystem->copy($file_path, $post_path . '/' . $filename);
                $attachment_map[$url] = $filename;
                
                $sized_urls = fbcwp_get_sized_image_urls($attachment_id, $url);
                foreach ($sized_urls as $sized_url) {
                    $attachment_map[$sized_url] = $filename;
                }
            }
        }
    }
    
    return $attachment_map;
}

function fbcwp_get_uploads_path_segment() {
    $upload_dir = wp_upload_dir();
    $baseurl = $upload_dir['baseurl'];
    $parsed = wp_parse_url($baseurl);
    return isset($parsed['path']) ? $parsed['path'] : '/wp-content/uploads';
}

function fbcwp_extract_attachment_urls($content, $site_url) {
    $uploads_path = fbcwp_get_uploads_path_segment();
    $urls = [];
    
    preg_match_all('/src=["\']([^"\']+)["\']/', $content, $src_matches);
    foreach ($src_matches[1] as $url) {
        if (strpos($url, $site_url) !== false || strpos($url, $uploads_path) !== false) {
            $urls[] = $url;
        }
    }
    
    preg_match_all('/href=["\']([^"\']+)["\']/', $content, $href_matches);
    foreach ($href_matches[1] as $url) {
        if (strpos($url, $uploads_path) !== false) {
            $urls[] = $url;
        }
    }
    
    preg_match_all('/<!-- wp:\w+[^>]*(\{[^}]+\})[^>]*-->/', $content, $block_matches);
    foreach ($block_matches[1] as $json_str) {
        $attrs = json_decode($json_str, true);
        if ($attrs) {
            $urls = array_merge($urls, fbcwp_extract_urls_from_attrs($attrs, $site_url, $uploads_path));
        }
    }
    
    preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $css_matches);
    foreach ($css_matches[1] as $url) {
        if (strpos($url, $site_url) !== false || strpos($url, $uploads_path) !== false) {
            $urls[] = $url;
        }
    }
    
    return array_unique($urls);
}

function fbcwp_extract_urls_from_attrs($attrs, $site_url, $uploads_path = null) {
    if ($uploads_path === null) {
        $uploads_path = fbcwp_get_uploads_path_segment();
    }
    $urls = [];
    
    foreach ($attrs as $key => $value) {
        if (is_string($value)) {
            if (strpos($value, $site_url) !== false || strpos($value, $uploads_path) !== false) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|mp4|webm|ogg|mp3|wav|doc|docx|xls|xlsx|ppt|pptx|zip)$/i', $value)) {
                    $urls[] = $value;
                }
            }
        } elseif (is_array($value)) {
            $urls = array_merge($urls, fbcwp_extract_urls_from_attrs($value, $site_url, $uploads_path));
        }
    }
    
    return $urls;
}

function fbcwp_get_unique_filename($post_path, $filename) {
    if (!file_exists($post_path . '/' . $filename)) {
        return $filename;
    }
    
    $info = pathinfo($filename);
    $base = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $counter = 1;
    
    while (file_exists($post_path . '/' . $base . '-' . $counter . $ext)) {
        $counter++;
    }
    
    return $base . '-' . $counter . $ext;
}

function fbcwp_get_sized_image_urls($attachment_id, $original_url) {
    $urls = [];
    $metadata = wp_get_attachment_metadata($attachment_id);
    
    if (!empty($metadata['sizes'])) {
        $base_url = dirname($original_url);
        foreach ($metadata['sizes'] as $size => $data) {
            $urls[] = $base_url . '/' . $data['file'];
        }
    }
    
    return $urls;
}

function fbcwp_url_to_attachment_id($url) {
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];
    
    if (strpos($url, $base_url) !== 0) {
        return 0;
    }
    
    $relative_path = str_replace($base_url . '/', '', $url);
    $relative_path = preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $relative_path);
    
    $cache_key = 'fbcwp_attachment_' . md5($relative_path);
    $attachment_id = wp_cache_get($cache_key, 'fbcwp');
    
    if (false === $attachment_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No WP API for this lookup; result is cached.
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
            $relative_path
        ));
        $attachment_id = $attachment_id ? (int) $attachment_id : 0;
        wp_cache_set($cache_key, $attachment_id, 'fbcwp', HOUR_IN_SECONDS);
    }
    
    return $attachment_id;
}

function fbcwp_post_to_markdown($post, $attachment_map = []) {
    $frontmatter = [
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'date' => $post->post_date,
    ];
    
    if (!empty($post->post_excerpt)) {
        $frontmatter['excerpt'] = $post->post_excerpt;
    }
    
    if (has_post_thumbnail($post->ID)) {
        $thumb_id = get_post_thumbnail_id($post->ID);
        $thumb_path = get_attached_file($thumb_id);
        if ($thumb_path) {
            $frontmatter['featured_image'] = basename($thumb_path);
        }
    }
    
    if ($post->post_type === 'post') {
        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        if (!empty($categories)) {
            $frontmatter['categories'] = $categories;
        }
        
        $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        if (!empty($tags)) {
            $frontmatter['tags'] = $tags;
        }
    }
    
    $yaml = "---\n";
    foreach ($frontmatter as $key => $value) {
        if (is_array($value)) {
            $yaml .= "$key:\n";
            foreach ($value as $item) {
                $yaml .= "  - \"" . addslashes($item) . "\"\n";
            }
        } else {
            $yaml .= "$key: \"" . addslashes($value) . "\"\n";
        }
    }
    $yaml .= "---\n\n";
    
    $content = fbcwp_rewrite_attachment_urls($post->post_content, $attachment_map);
    
    return $yaml . $content;
}

function fbcwp_rewrite_attachment_urls($content, $attachment_map) {
    foreach ($attachment_map as $url => $local_file) {
        $content = str_replace($url, $local_file, $content);
        
        $escaped_url = str_replace('/', '\\/', $url);
        $content = str_replace($escaped_url, $local_file, $content);
    }
    
    return $content;
}
