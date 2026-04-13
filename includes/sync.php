<?php
/**
 * FBCWP Sync Engine
 * 
 * Handles markdown parsing, image imports, content syncing, and cron scheduling.
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// MARKDOWN PARSING
// =============================================================================

function fbcwp_parse_markdown($content) {
    $result = [
        'frontmatter' => [],
        'body' => '',
        'raw' => $content,
    ];

    if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
        $yaml_str = $matches[1];
        $result['body'] = trim($matches[2]);
        
        foreach (explode("\n", $yaml_str) as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, ':') === false) continue;
            
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $kv)) {
                $key = $kv[1];
                $value = trim($kv[2], '"\'');
                
                if ($value === '') continue;
                
                if (preg_match('/^\[.*\]$/', $value)) {
                    $value = array_map('trim', explode(',', trim($value, '[]')));
                    $value = array_map(function($v) { return trim($v, '"\''); }, $value);
                }
                
                $result['frontmatter'][$key] = $value;
            }
        }
        
        if (!empty($result['frontmatter']['categories']) && is_string($result['frontmatter']['categories'])) {
            $result['frontmatter']['categories'] = [$result['frontmatter']['categories']];
        }
        if (!empty($result['frontmatter']['tags']) && is_string($result['frontmatter']['tags'])) {
            $result['frontmatter']['tags'] = [$result['frontmatter']['tags']];
        }
    } else {
        $result['body'] = $content;
    }

    return $result;
}

function fbcwp_markdown_to_html($markdown) {
    static $parsedown = null;
    if ($parsedown === null) {
        $parsedown = new Parsedown();
    }
    return $parsedown->text($markdown);
}

// =============================================================================
// CONTENT SEGMENT SPLITTING (Block Passthrough)
// =============================================================================

function fbcwp_split_content_segments($content) {
    $segments = [];
    $pattern = '/(<!-- wp:[\w\/-]+ (?:\{[^}]*\} )?-->.*?<!-- \/wp:[\w\/-]+ -->)/s';
    
    $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if (empty($trimmed)) {
            continue;
        }
        
        if (preg_match('/^<!-- wp:[\w\/-]+/', $trimmed)) {
            $segments[] = [
                'type' => 'block',
                'content' => $trimmed,
            ];
        } else {
            $segments[] = [
                'type' => 'markdown',
                'content' => $trimmed,
            ];
        }
    }
    
    return $segments;
}

function fbcwp_has_gutenberg_blocks($content) {
    return preg_match('/<!-- wp:[\w\/-]+/', $content) === 1;
}

// =============================================================================
// GUTENBERG BLOCK CONVERSION
// =============================================================================

function fbcwp_process_attachments($content_path, $content, $post_id) {
    $attachment_map = [];
    $local_files = fbcwp_extract_local_file_references($content);
    
    foreach ($local_files as $src) {
        if (isset($attachment_map[$src])) {
            continue;
        }
        
        $file_path = $content_path . '/' . $src;
        if (!file_exists($file_path)) {
            continue;
        }
        
        $attachment_id = fbcwp_import_attachment($file_path, $post_id);
        if ($attachment_id) {
            $attachment_map[$src] = [
                'id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
            ];
        }
    }
    
    return $attachment_map;
}

function fbcwp_extract_local_file_references($content) {
    $files = [];
    
    preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $md_matches);
    foreach ($md_matches[2] as $src) {
        if (!preg_match('/^https?:\/\//', $src)) {
            $files[] = $src;
        }
    }
    
    preg_match_all('/src=["\']([^"\']+)["\']/', $content, $src_matches);
    foreach ($src_matches[1] as $src) {
        if (!preg_match('/^https?:\/\//', $src) && !preg_match('/^\//', $src)) {
            $files[] = $src;
        }
    }
    
    preg_match_all('/href=["\']([^"\']+)["\']/', $content, $href_matches);
    foreach ($href_matches[1] as $src) {
        if (!preg_match('/^https?:\/\//', $src) && !preg_match('/^\//', $src)) {
            if (preg_match('/\.(pdf|doc|docx|xls|xlsx|ppt|pptx|zip|mp3|mp4|webm|ogg|wav)$/i', $src)) {
                $files[] = $src;
            }
        }
    }
    
    preg_match_all('/<!-- wp:\w+[^>]*(\{[^}]+\})[^>]*-->/', $content, $block_matches);
    foreach ($block_matches[1] as $json_str) {
        $attrs = json_decode($json_str, true);
        if ($attrs) {
            $files = array_merge($files, fbcwp_extract_local_files_from_attrs($attrs));
        }
    }
    
    preg_match_all('/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $css_matches);
    foreach ($css_matches[1] as $src) {
        if (!preg_match('/^https?:\/\//', $src) && !preg_match('/^\//', $src)) {
            $files[] = $src;
        }
    }
    
    return array_unique($files);
}

function fbcwp_extract_local_files_from_attrs($attrs) {
    $files = [];
    
    foreach ($attrs as $key => $value) {
        if (is_string($value)) {
            if (!preg_match('/^https?:\/\//', $value) && !preg_match('/^\//', $value)) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|mp4|webm|ogg|mp3|wav|doc|docx|xls|xlsx|ppt|pptx|zip)$/i', $value)) {
                    $files[] = $value;
                }
            }
        } elseif (is_array($value)) {
            $files = array_merge($files, fbcwp_extract_local_files_from_attrs($value));
        }
    }
    
    return $files;
}

function fbcwp_rewrite_local_urls($content, $attachment_map) {
    foreach ($attachment_map as $local_file => $data) {
        $content = str_replace(
            ['"' . $local_file . '"', "'" . $local_file . "'", '(' . $local_file . ')'],
            ['"' . $data['url'] . '"', "'" . $data['url'] . "'", '(' . $data['url'] . ')'],
            $content
        );
    }
    
    return $content;
}

function fbcwp_markdown_to_blocks($content, $attachment_map = []) {
    if (fbcwp_has_gutenberg_blocks($content)) {
        return fbcwp_process_mixed_content($content, $attachment_map);
    }
    
    return fbcwp_convert_markdown_to_blocks($content, $attachment_map);
}

function fbcwp_process_mixed_content($content, $attachment_map = []) {
    $segments = fbcwp_split_content_segments($content);
    $output = [];
    
    foreach ($segments as $segment) {
        if ($segment['type'] === 'block') {
            $output[] = $segment['content'];
        } else {
            $converted = fbcwp_convert_markdown_to_blocks($segment['content'], $attachment_map);
            if (!empty(trim($converted))) {
                $output[] = $converted;
            }
        }
    }
    
    return implode("\n\n", $output);
}

function fbcwp_wrap_raw_html_blocks($content) {
    $block_tags = 'div|section|article|aside|header|footer|nav|main|figure|table|form|fieldset|details|summary|iframe|video|audio|canvas|svg';
    
    $pattern = '/(<(' . $block_tags . ')[\s>].*?<\/\2>)/is';
    
    $content = preg_replace_callback($pattern, function($matches) {
        $html = trim($matches[1]);
        if (strpos($html, '<!-- wp:') !== false) {
            return $html;
        }
        return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
    }, $content);
    
    $self_closing_pattern = '/(<(?:hr|br|img|input|meta|link|embed|source|track|wbr)(?:\s[^>]*)?\s*\/?>)/i';
    $content = preg_replace_callback($self_closing_pattern, function($matches) {
        $tag = trim($matches[1]);
        if (preg_match('/^<(img|br)[\s>]/i', $tag)) {
            return $tag;
        }
        if (strpos($tag, '<!-- wp:') !== false) {
            return $tag;
        }
        return "<!-- wp:html -->\n" . $tag . "\n<!-- /wp:html -->";
    }, $content);
    
    return $content;
}

function fbcwp_convert_markdown_to_blocks($markdown, $attachment_map = []) {
    $markdown = fbcwp_wrap_raw_html_blocks($markdown);
    
    $blocks = [];
    $lines = explode("\n", $markdown);
    $current_block = '';
    $in_code_block = false;
    $code_language = '';
    $code_content = '';
    $in_list = false;
    $list_items = [];
    $list_ordered = false;
    
    foreach ($lines as $line) {
        if (preg_match('/^```(\w*)/', $line, $matches)) {
            if ($in_code_block) {
                $blocks[] = fbcwp_make_code_block($code_content, $code_language);
                $in_code_block = false;
                $code_content = '';
                $code_language = '';
            } else {
                if ($in_list) {
                    $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                    $in_list = false;
                    $list_items = [];
                }
                if (trim($current_block) !== '') {
                    $blocks[] = fbcwp_make_paragraph_block($current_block);
                    $current_block = '';
                }
                $in_code_block = true;
                $code_language = $matches[1] ?? '';
            }
            continue;
        }
        
        if ($in_code_block) {
            $code_content .= ($code_content ? "\n" : '') . $line;
            continue;
        }
        
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
            if ($in_list) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $in_list = false;
                $list_items = [];
            }
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            $level = strlen($matches[1]);
            $blocks[] = fbcwp_make_heading_block($matches[2], $level);
            continue;
        }
        
        if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)\s*$/', $line, $matches)) {
            if ($in_list) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $in_list = false;
                $list_items = [];
            }
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            $alt = $matches[1];
            $src = $matches[2];
            $blocks[] = fbcwp_make_image_block($src, $alt, $attachment_map);
            continue;
        }
        
        if (preg_match('/^>\s*(.*)$/', $line, $matches)) {
            if ($in_list) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $in_list = false;
                $list_items = [];
            }
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            $blocks[] = fbcwp_make_quote_block($matches[1]);
            continue;
        }
        
        if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            if ($in_list && $list_ordered) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $list_items = [];
            }
            $in_list = true;
            $list_ordered = false;
            $list_items[] = $matches[1];
            continue;
        }
        
        if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            if ($in_list && !$list_ordered) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $list_items = [];
            }
            $in_list = true;
            $list_ordered = true;
            $list_items[] = $matches[1];
            continue;
        }
        
        if (trim($line) === '') {
            if ($in_list) {
                $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
                $in_list = false;
                $list_items = [];
            }
            if (trim($current_block) !== '') {
                $blocks[] = fbcwp_make_paragraph_block($current_block);
                $current_block = '';
            }
            continue;
        }
        
        $current_block .= ($current_block ? ' ' : '') . $line;
    }
    
    if ($in_list) {
        $blocks[] = fbcwp_make_list_block($list_items, $list_ordered);
    }
    if (trim($current_block) !== '') {
        $blocks[] = fbcwp_make_paragraph_block($current_block);
    }
    
    return implode("\n\n", $blocks);
}

function fbcwp_inline_markdown($text) {
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
    return $text;
}

function fbcwp_make_paragraph_block($text) {
    $html = fbcwp_inline_markdown(trim($text));
    return "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->";
}

function fbcwp_make_heading_block($text, $level) {
    $html = fbcwp_inline_markdown(trim($text));
    $attrs = $level !== 2 ? ' {"level":' . $level . '}' : '';
    return "<!-- wp:heading$attrs -->\n<h$level>$html</h$level>\n<!-- /wp:heading -->";
}

function fbcwp_make_image_block($src, $alt, $image_map) {
    if (isset($image_map[$src])) {
        $id = $image_map[$src]['id'];
        $url = $image_map[$src]['url'];
        $attrs = json_encode(['id' => $id, 'sizeSlug' => 'full', 'linkDestination' => 'none']);
        return "<!-- wp:image $attrs -->\n<figure class=\"wp-block-image size-full\"><img src=\"$url\" alt=\"" . esc_attr($alt) . "\" class=\"wp-image-$id\"/></figure>\n<!-- /wp:image -->";
    }
    
    if (preg_match('/^https?:\/\//', $src)) {
        $attrs = json_encode(['sizeSlug' => 'full', 'linkDestination' => 'none']);
        return "<!-- wp:image $attrs -->\n<figure class=\"wp-block-image size-full\"><img src=\"$src\" alt=\"" . esc_attr($alt) . "\"/></figure>\n<!-- /wp:image -->";
    }
    
    return "<!-- wp:paragraph -->\n<p>![" . esc_html($alt) . "]($src)</p>\n<!-- /wp:paragraph -->";
}

function fbcwp_make_list_block($items, $ordered = false) {
    $tag = $ordered ? 'ol' : 'ul';
    $attrs = $ordered ? ' {"ordered":true}' : '';
    $html_items = array_map(function($item) {
        return '<li>' . fbcwp_inline_markdown(trim($item)) . '</li>';
    }, $items);
    $list_html = "<$tag>" . implode('', $html_items) . "</$tag>";
    return "<!-- wp:list$attrs -->\n$list_html\n<!-- /wp:list -->";
}

function fbcwp_make_code_block($code, $language = '') {
    $escaped = esc_html($code);
    return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>$escaped</code></pre>\n<!-- /wp:code -->";
}

function fbcwp_make_quote_block($text) {
    $html = fbcwp_inline_markdown(trim($text));
    return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>$html</p></blockquote>\n<!-- /wp:quote -->";
}

// =============================================================================
// DOMAIN DETECTION
// =============================================================================

function fbcwp_get_site_domain() {
    $site_url = get_site_url();
    $host = wp_parse_url($site_url, PHP_URL_HOST);
    
    if (!$host) {
        return null;
    }
    
    if (strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    
    return $host;
}

// =============================================================================
// STATUS FOLDER MAPPING
// =============================================================================

function fbcwp_get_valid_statuses() {
    return [
        'published' => 'publish',
        'publish' => 'publish',
        'draft' => 'draft',
        'private' => 'private',
    ];
}

function fbcwp_status_to_folder($wp_status) {
    $statuses = fbcwp_get_valid_statuses();
    $folder = array_search($wp_status, $statuses, true);
    return $folder !== false ? $folder : 'published';
}

// =============================================================================
// CONTENT SCANNING
// =============================================================================

function fbcwp_scan_content() {
    $content_path = fbcwp_get_content_path();
    if (!$content_path) {
        return [];
    }

    $domain = fbcwp_get_site_domain();
    $domain_path = $content_path . '/' . $domain;
    
    if ($domain && is_dir($domain_path)) {
        $base_path = $domain_path;
    } else {
        $base_path = $content_path;
    }

    $post_types = fbcwp_get_post_types();
    $valid_statuses = fbcwp_get_valid_statuses();
    $items = [];

    foreach ($post_types as $post_type) {
        $folder = $post_type === 'post' ? 'posts' : $post_type . 's';
        if ($post_type === 'page') $folder = 'pages';
        
        $type_path = $base_path . '/' . $folder;
        if (!is_dir($type_path)) continue;

        $dirs = glob($type_path . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $dir_name = basename($dir);
            
            if (isset($valid_statuses[$dir_name])) {
                $status_folder = $dir_name;
                $wp_status = $valid_statuses[$dir_name];
                $post_dirs = glob($dir . '/*', GLOB_ONLYDIR);
                
                foreach ($post_dirs as $post_dir) {
                    $md_file = $post_dir . '/index.md';
                    if (!file_exists($md_file)) continue;

                    $items[] = [
                        'post_type' => $post_type,
                        'slug' => basename($post_dir),
                        'path' => $post_dir,
                        'md_file' => $md_file,
                        'folder_status' => $wp_status,
                    ];
                }
            } else {
                $md_file = $dir . '/index.md';
                if (!file_exists($md_file)) continue;

                $items[] = [
                    'post_type' => $post_type,
                    'slug' => $dir_name,
                    'path' => $dir,
                    'md_file' => $md_file,
                ];
            }
        }
    }

    return $items;
}

// =============================================================================
// CHANGE DETECTION
// =============================================================================

function fbcwp_content_changed($post_id, $new_content) {
    $stored = get_post_meta($post_id, FBCWP_META_KEY, true);
    return $stored !== $new_content;
}

function fbcwp_find_post_by_slug($slug, $post_type) {
    $posts = get_posts([
        'name' => $slug,
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'numberposts' => 1,
    ]);
    return !empty($posts) ? $posts[0] : null;
}

// =============================================================================
// ATTACHMENT HANDLING
// =============================================================================

function fbcwp_import_attachment($file_path, $post_id) {
    if (!function_exists('wp_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $filename = basename($file_path);
    $current_hash = md5_file($file_path);
    
    $cache_key = 'fbcwp_src_' . md5($file_path);
    $existing_id = wp_cache_get($cache_key, 'fbcwp');
    
    if (false === $existing_id) {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Cached lookup, necessary for deduplication.
        $existing = get_posts([
            'post_type' => 'attachment',
            'meta_key' => '_fbcwp_source_file',
            'meta_value' => $file_path,
            'numberposts' => 1,
        ]);
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $existing_id = !empty($existing) ? $existing[0]->ID : 0;
        wp_cache_set($cache_key, $existing_id, 'fbcwp', HOUR_IN_SECONDS);
    }
    
    if ($existing_id) {
        $stored_hash = get_post_meta($existing_id, '_fbcwp_source_hash', true);
        
        if ($stored_hash === $current_hash) {
            return $existing_id;
        }
        
        wp_delete_attachment($existing_id, true);
        wp_cache_delete($cache_key, 'fbcwp');
    }

    $tmp_file = wp_tempnam($filename);
    copy($file_path, $tmp_file);

    $file_array = [
        'name' => $filename,
        'tmp_name' => $tmp_file,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id);
    
    if (is_wp_error($attachment_id)) {
        wp_delete_file($tmp_file);
        return false;
    }

    update_post_meta($attachment_id, '_fbcwp_source_file', $file_path);
    update_post_meta($attachment_id, '_fbcwp_source_hash', $current_hash);
    
    return $attachment_id;
}

function fbcwp_handle_featured_image($image_name, $content_path, $post_id) {
    if (empty($image_name)) return;
    
    $image_path = $content_path . '/' . $image_name;
    if (!file_exists($image_path)) return;
    
    $attachment_id = fbcwp_import_attachment($image_path, $post_id);
    if ($attachment_id) {
        set_post_thumbnail($post_id, $attachment_id);
    }
}

// =============================================================================
// SYNC ENGINE
// =============================================================================

function fbcwp_sync_post($item) {
    $md_content = file_get_contents($item['md_file']);
    $parsed = fbcwp_parse_markdown($md_content);
    
    $existing = fbcwp_find_post_by_slug($item['slug'], $item['post_type']);
    
    if ($existing && !fbcwp_content_changed($existing->ID, $md_content)) {
        return ['status' => 'skipped', 'title' => $existing->post_title];
    }
    
    $post_id_for_attachments = $existing ? $existing->ID : 0;
    $attachment_map = fbcwp_process_attachments($item['path'], $parsed['body'], $post_id_for_attachments);
    
    $block_content = fbcwp_markdown_to_blocks($parsed['body'], $attachment_map);
    $block_content = fbcwp_rewrite_local_urls($block_content, $attachment_map);
    
    $post_data = [
        'post_type' => $item['post_type'],
        'post_name' => $item['slug'],
        'post_content' => $block_content,
        'post_title' => $parsed['frontmatter']['title'] ?? ucwords(str_replace('-', ' ', $item['slug'])),
        'post_status' => $item['folder_status'] ?? $parsed['frontmatter']['status'] ?? 'publish',
        'post_excerpt' => $parsed['frontmatter']['excerpt'] ?? '',
    ];
    
    if (!empty($parsed['frontmatter']['date'])) {
        $post_data['post_date'] = gmdate('Y-m-d H:i:s', strtotime($parsed['frontmatter']['date']));
    }
    
    if ($existing) {
        $post_data['ID'] = $existing->ID;
        wp_update_post($post_data);
        $post_id = $existing->ID;
        $status = 'updated';
    } else {
        $post_id = wp_insert_post($post_data);
        $status = 'created';
    }
    
    if (is_wp_error($post_id)) {
        return ['status' => 'error', 'title' => $post_data['post_title'], 'error' => $post_id->get_error_message()];
    }
    
    update_post_meta($post_id, FBCWP_META_KEY, $md_content);
    
    if (!empty($parsed['frontmatter']['featured_image'])) {
        fbcwp_handle_featured_image($parsed['frontmatter']['featured_image'], $item['path'], $post_id);
    }
    
    if (!empty($parsed['frontmatter']['categories']) && $item['post_type'] === 'post') {
        $cat_ids = [];
        foreach ((array) $parsed['frontmatter']['categories'] as $cat_name) {
            $cat = get_term_by('name', $cat_name, 'category');
            if (!$cat) {
                $result = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($result)) {
                    $cat_ids[] = $result['term_id'];
                }
            } else {
                $cat_ids[] = $cat->term_id;
            }
        }
        if (!empty($cat_ids)) {
            wp_set_post_categories($post_id, $cat_ids);
        }
    }
    
    if (!empty($parsed['frontmatter']['tags']) && $item['post_type'] === 'post') {
        wp_set_post_tags($post_id, (array) $parsed['frontmatter']['tags']);
    }
    
    return ['status' => $status, 'title' => $post_data['post_title']];
}

function fbcwp_run_sync() {
    $items = fbcwp_scan_content();
    $results = [
        'created' => [],
        'updated' => [],
        'skipped' => [],
        'drafted' => [],
        'errors' => [],
    ];
    
    // Build a map of source slugs by post type
    $source_slugs = [];
    foreach ($items as $item) {
        if (!isset($source_slugs[$item['post_type']])) {
            $source_slugs[$item['post_type']] = [];
        }
        $source_slugs[$item['post_type']][] = $item['slug'];
    }
    
    // Sync posts from source
    foreach ($items as $item) {
        $result = fbcwp_sync_post($item);
        $results[$result['status'] . ($result['status'] === 'error' ? 's' : '')][] = $result['title'];
    }
    
    // Move posts without matching slugs to draft (only when a content path exists — e.g. content plugin active)
    if (fbcwp_get_content_path()) {
        $post_types = fbcwp_get_post_types();
        foreach ($post_types as $post_type) {
            $published_posts = get_posts([
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => -1,
            ]);
            
            $type_slugs = $source_slugs[$post_type] ?? [];
            
            foreach ($published_posts as $post) {
                if (!in_array($post->post_name, $type_slugs, true)) {
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_status' => 'draft',
                    ]);
                    $results['drafted'][] = $post->post_title;
                }
            }
        }
    }
    
    return $results;
}

// =============================================================================
// CRON SCHEDULING
// =============================================================================

add_filter('cron_schedules', 'fbcwp_add_cron_interval');

function fbcwp_add_cron_interval($schedules) {
    $schedules['fbcwp_five_minutes'] = [
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'file-based-content'),
    ];
    return $schedules;
}

add_action('fbcwp_sync_cron', 'fbcwp_cron_sync');

function fbcwp_cron_sync() {
    $results = fbcwp_run_sync();
    
    update_option('fbcwp_last_sync', [
        'time' => current_time('timestamp'),
        'results' => $results,
    ]);
}

add_action('init', 'fbcwp_schedule_cron');

function fbcwp_schedule_cron() {
    if (!wp_next_scheduled('fbcwp_sync_cron')) {
        wp_schedule_event(time(), 'fbcwp_five_minutes', 'fbcwp_sync_cron');
    }
}

function fbcwp_unschedule_cron() {
    $timestamp = wp_next_scheduled('fbcwp_sync_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fbcwp_sync_cron');
    }
}
