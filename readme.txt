=== File-based Content (FBC) ===
Contributors: velzani
Tags: markdown, git, content, sync, headless
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage WordPress posts and pages as Git-friendly Markdown files with automatic sync.

== Description ==

File-based Content (FBC) lets you manage your WordPress content as Markdown files stored in a separate content plugin. Write posts in your favorite editor, commit them to Git, and let FBC sync them into WordPress automatically.

**Key Features:**

* Write content in Markdown with YAML frontmatter
* Co-locate images and assets with your posts
* Automatic import of local images into Media Library
* Converts Markdown to native Gutenberg blocks
* Supports categories, tags, featured images, and excerpts
* Embed raw Gutenberg blocks directly in Markdown
* WP-Cron sync every 5 minutes (only changed files are processed)
* Export existing WordPress content to Markdown

**How It Works:**

1. Content lives in a companion "content plugin" — a lightweight plugin holding your Markdown files
2. FBC reads those files, parses frontmatter and Markdown, and syncs to WordPress
3. Local images are imported into the Media Library automatically
4. A cron job keeps everything in sync

**Content Structure:**

Each post is a folder containing an `index.md` file and any co-located assets:

    content/
    ├── posts/
    │   └── my-post/
    │       ├── index.md
    │       └── hero.jpg
    └── pages/
        └── about/
            └── index.md

**Status Folders (New in 1.1.0):**

Organize content by status using subdirectories:

    content/posts/
    ├── published/
    │   └── live-post/
    ├── draft/
    │   └── work-in-progress/
    ├── pending/
    └── private/

**Multi-Site Support (New in 1.1.0):**

Manage content for multiple sites by using domain-specific directories:

    content/
    ├── example.com/
    │   └── posts/
    └── staging.example.com/
        └── posts/

FBC auto-detects the site domain and uses matching content.

**Frontmatter Example:**

    ---
    title: "My Post"
    status: publish
    date: "2025-06-15"
    excerpt: "A summary."
    featured_image: hero.jpg
    categories: [Design, Code]
    tags: [css, typography]
    ---

== Installation ==

1. Upload the `file-based-content` folder to `/wp-content/plugins/`
2. Run `composer install` inside the plugin folder
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings → FBC to configure or generate a content plugin

== Frequently Asked Questions ==

= How do I set up my content plugin? =

You can either:

1. Use the built-in generator (Settings → FBC) to export existing posts as Markdown
2. Create a plugin manually with two filters:

    add_filter('fbcwp_content_path', function() {
        return __DIR__ . '/content';
    });

    add_filter('fbcwp_post_types', function() {
        return ['post', 'page'];
    });

= How often does it sync? =

A WP-Cron job runs every 5 minutes. Only files that have changed since the last sync are processed. You can also trigger a manual sync from the admin page.

= Can I use Gutenberg blocks in my Markdown? =

Yes. FBC detects `<!-- wp:* -->` block comments and passes them through unchanged. Surrounding Markdown is converted normally.

= What happens to my images? =

Local images referenced by relative path (e.g., `![Alt](photo.jpg)`) are imported into the WordPress Media Library. The URLs in your content are rewritten automatically.

= What post types are supported? =

Any public post type. Configure via the `fbcwp_post_types` filter or the admin UI.

= What happens to published posts that are not in the Markdown tree? =

After each sync, when a content path is configured (e.g. the content plugin is active), any published post in a configured post type whose slug is missing from the content scan is moved to draft — including posts created only in WordPress, not only posts previously synced from files. If no content path is available, published posts are not changed this way.

== Changelog ==

= 1.1.0 =
* Status folder organization — content can be organized into `published/`, `draft/`, `pending/`, and `private/` subdirectories
* Multi-site support — domain-specific content directories (e.g., `content/example.com/posts/`)
* Enhanced export — now exports all post statuses (not just published) into status-organized folders
* Bedrock compatibility — dynamic uploads path detection for non-standard directory structures

= 1.0.0 =
* Initial release
* Markdown parsing with YAML frontmatter
* Gutenberg block conversion
* Local asset import
* WP-Cron sync
* Content plugin generator