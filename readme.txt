=== Copia Estática Local ===
Contributors: blogpocket
Tags: static, export, archive, html, backup
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.8
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates a static copy of your blog as flat HTML: posts, pages, local images and internal links rewritten to relative paths.

== Description ==

**Copia Estática Local** exports your WordPress site to a self-contained set of HTML, CSS and image files that can be served from any flat hosting (even from a USB stick), without PHP or a database.

Unlike most exporters, this plugin:

* Generates **one .html file per post and per page**, organized in `year/month/slug.html` and `pages/slug.html` folders.
* **Downloads images** referenced inside content to a local `/img/` folder, renamed with MD5 hashes to avoid collisions.
* **Rewrites internal links** between posts and pages so they point to local files, not to the live blog URLs.
* **Detects historical domains** via the `CEL_EXTRA_INTERNAL_HOSTS` constant: if your blog has changed domain in the past, hardcoded links to the previous domain are also rewritten correctly.
* **Resilient to errors**: each post is processed inside a `try/catch`. If one fails for any reason, the rest keep going and the failed one is reported.
* **Streaming downloads** to avoid memory exhaustion with large images (no practical size limit).

= Use cases =

* Archive a blog before migrating or shutting it down.
* Create a "frozen" copy of the site to be served from static hosting (GitHub Pages, Netlify, Cloudflare Pages...) or a subdomain of your own cPanel.
* Self-contained backup independent of the database.
* Offline browsable documentation of a blog.

= Optional constants (in wp-config.php) =

* `CEL_MAX_IMG_SIZE`: maximum size per image in bytes (default 30 MB). Images above are skipped.
* `CEL_EXTRA_INTERNAL_HOSTS`: historical domains of the blog, comma-separated. Example: `define( 'CEL_EXTRA_INTERNAL_HOSTS', 'old-domain.com,another-historical.net' );`

== Installation ==

1. Upload the `copia-estatica` folder to the `/wp-content/plugins/` directory of your WordPress installation.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Access the "Copia Estática" sidebar menu in the admin panel.
4. Select year and months to export, or click "Generate All Pages" for static pages.
5. The copy is placed in `/wp-content/uploads/copia-estatica-html/`.

= Recommendations =

* Before generating a large year, enable `WP_DEBUG_LOG` in `wp-config.php` to monitor progress and diagnose potential errors.
* If your blog has changed domain, declare the previous ones in `CEL_EXTRA_INTERNAL_HOSTS` before generating.
* For hosting with strict memory/time limits, export month by month instead of an entire year.

== Frequently Asked Questions ==

= Where is the generated copy saved? =

In `/wp-content/uploads/copia-estatica-html/` on your own server. You can access that folder directly from a browser (it is public) or download it via FTP/cPanel to serve it from somewhere else.

= How does internal link rewriting work? =

The plugin uses a dual strategy: it first tries to resolve each link with WordPress's `url_to_postid()` (normalizing the domain to the current one). If that does not work, it falls back to a precomputed `slug → local_path` index of all published posts and pages. If the last segment of the URL path matches any slug in the index, it gets rewritten to the corresponding local file.

= What happens with very large images? =

Before downloading, the plugin checks the size with a `HEAD` request. If it exceeds `CEL_MAX_IMG_SIZE` (30 MB by default), it is skipped and logged. This prevents a forgotten 200 MB file from crashing the process due to memory issues.

= Does it process custom post types (CPTs)? =

No, only standard WordPress posts and pages.

= What about categories, tags, date archives or feeds? =

They are not exported as static files. Links pointing to them from inside content remain pointing to the original blog (which keeps working).

= How do I optimize the final size? =

After generating, JPG/PNG images can be converted to WebP to reduce weight between 40% and 70% with no visible loss of quality. External scripts and tools automate that conversion.

= Can I customize the CSS of the copy? =

The plugin generates a minimalist `style.css` at the root of the copy. You can edit it manually after generation, or replace it with your own CSS. Each HTML references it with relative paths.

== Changelog ==

= 1.8 =
* WordPress.org Plugin Check compliance.
* Header with License and License URI (GPL v2+).
* Input validation and sanitization with `absint`, `wp_unslash`.
* Output escaping with `esc_*` and `wp_kses_post`.
* Replacement of discouraged functions: `parse_url` → `wp_parse_url`, `date` → `gmdate`, `unlink` → `wp_delete_file`, `rename` → `copy + wp_delete_file`.
* `error_log()` wrapped in `cel_log()`: only writes if `WP_DEBUG` and `WP_DEBUG_LOG` are enabled.
* Transient caching for direct SQL queries.

= 1.7 =
* Robust internal link rewriting: detects historical domains via `CEL_EXTRA_INTERNAL_HOSTS` constant.
* Dual resolution strategy: `url_to_postid` first, fallback by slug index.
* Explicit skipping of non-content URLs (categories, tags, authors, feeds, `wp-*`).

= 1.6 =
* Image downloads in streaming directly to disk to avoid fatal memory errors with very large images.
* Pre-download size check with `HEAD` request: images exceeding `CEL_MAX_IMG_SIZE` are skipped.
* Downloaded files are first written to `.part` and only renamed if the download finishes OK.

= 1.5 =
* Defensive per-post processing (try/catch): if one fails, the others keep going.
* Detailed logging with the post being processed to diagnose fatal errors.
* Posts loaded by ID instead of full object (more memory efficient).

= 1.4 =
* Robust image URL detection: absolute, protocol-relative and relative. Support for lazy-loading attributes.
* Local path replacement only happens if the download succeeded.

== Upgrade Notice ==

= 1.8 =
WordPress.org standards compliance. Functionality unchanged from 1.7. Recommended update.
