# File-based Content (FBC)

Manage WordPress posts and pages as Git-friendly Markdown files. FBC syncs content from a structured directory of Markdown files into WordPress, converting frontmatter and Markdown into native Gutenberg blocks.

## How It Works

Content lives in a companion **content plugin** (a separate, lightweight plugin) that holds your Markdown files and tells FBC where to find them. FBC reads those files, parses the YAML frontmatter and Markdown body, converts everything to Gutenberg blocks, imports local images into the Media Library, and creates or updates the corresponding WordPress posts.

A WP-Cron job runs every 5 minutes. Only files whose content has actually changed are processed — unchanged files are skipped.

After syncing, when a valid content path is available (typically while the content plugin is active), any **published** post in a configured post type whose slug does not appear in the scanned content is moved to **draft** (not only posts that were previously synced by FBC). If there is no content path, published posts are left unchanged.

## Content Structure

```
your-content-plugin/
├── your-content-plugin.php   # registers filters
└── content/
    ├── posts/
    │   ├── my-first-post/
    │   │   ├── index.md
    │   │   ├── hero.jpg
    │   │   └── diagram.png
    │   └── another-post/
    │       └── index.md
    └── pages/
        └── about/
            └── index.md
```

Each post or page is a folder named after the desired slug. The folder contains an `index.md` file and any co-located assets (images, PDFs, etc.) referenced within it.

## Frontmatter

YAML frontmatter at the top of each `index.md` controls post metadata:

```yaml
---
title: "My First Post"
status: publish
date: "2025-06-15"
excerpt: "A short summary."
featured_image: hero.jpg
categories: [Design, Code]
tags: [css, typography]
---
```

| Field            | Description                                     | Default                        |
|------------------|-------------------------------------------------|--------------------------------|
| `title`          | Post title                                      | Derived from slug              |
| `status`         | Post status (`publish`, `draft`, etc.)           | `publish`                      |
| `date`           | Publish date                                    | Current date                   |
| `excerpt`        | Post excerpt                                    | Empty                          |
| `featured_image` | Filename of a co-located image for the thumbnail | None                           |
| `categories`     | Array of category names (posts only)            | None                           |
| `tags`           | Array of tag names (posts only)                 | None                           |

## Markdown Features

- Standard Markdown: headings, paragraphs, bold, italic, inline code, links, images, blockquotes, ordered and unordered lists, fenced code blocks.
- **Local asset references** — images and files referenced by relative path (e.g., `![Alt](diagram.png)`) are automatically imported into the WordPress Media Library. URLs are rewritten to point to the uploaded copies.
- **Gutenberg block passthrough** — you can embed raw Gutenberg block markup directly in Markdown. FBC detects `<!-- wp:* -->` comments and passes them through untouched while converting surrounding Markdown normally.
- **Raw HTML wrapping** — standalone HTML block-level elements (`<div>`, `<section>`, `<table>`, etc.) are wrapped in `<!-- wp:html -->` blocks automatically.

## Configuration

FBC is configured through two WordPress filters, typically registered in your content plugin:

```php
// Tell FBC where the Markdown files live
add_filter('fbcwp_content_path', function () {
    return __DIR__ . '/content';
});

// Which post types to sync
add_filter('fbcwp_post_types', function () {
    return ['post', 'page'];
});
```

If no `fbcwp_post_types` filter is registered, post types can be configured from the admin UI. When the filter is active, the admin setting becomes read-only.

## Admin UI

Navigate to **Settings → FBC** in the WordPress dashboard. The admin page shows:

- **Content path** and a count of discovered Markdown files per post type.
- **Sync status** — last sync time, next scheduled run, and a breakdown of created / updated / skipped / errored items.
- **Sync Now** button for manual sync.
- **Post type settings** (when not locked by a filter).
- **Content plugin generator** (shown when no content plugin is detected) — exports existing WordPress posts as Markdown into a new content plugin, ready to be committed to Git.

## Snapshot / Export

If you already have posts in WordPress and want to migrate to file-based content, use the **Generate & Activate** form in the admin. FBC will:

1. Create a new content plugin in `wp-content/plugins/`.
2. Export each published post as `index.md` with frontmatter.
3. Copy attached media (images, featured images) into each post's folder.
4. Rewrite absolute upload URLs to relative file references.
5. Activate the new content plugin automatically.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [Parsedown](https://github.com/erusev/parsedown) (installed via Composer)

## Installation

```bash
cd wp-content/plugins/file-based-content
composer install
```

Activate the plugin from the WordPress admin. Then either create a content plugin manually or use the built-in generator to export existing content.
