# Kuhmann MIDI Library

Kuhmann MIDI Library is a WordPress plugin for indexing and browsing a large MIDI directory as an SEO-friendly library. It turns `.mid` and `.midi` files into a public `kml_midi` custom post type, mirrors the directory tree into a hierarchical `kml_folder` taxonomy, and provides archive, folder, single-file, search, download, and playback views.

The plugin was built for publishing a navigable Kuhmann / Disklavier World MIDI mirror, but it can index any readable server-side MIDI folder.

Source: [https://github.com/diskchord/kuhmann-midi-library](https://github.com/diskchord/kuhmann-midi-library)

## Features

- Indexes `.mid` and `.midi` files from a configured server folder.
- Creates one WordPress post per MIDI file.
- Preserves folder structure through hierarchical taxonomy terms.
- Adds public archive, folder, and single MIDI templates.
- Supports search across the archive and within folders.
- Stores relative path, absolute path, filename, file size, modified time, and optional public URL metadata.
- Provides a protected download endpoint for MIDI downloads.
- Omits unavailable files from public library listings, search results, and WordPress/Yoast post sitemaps before pagination.
- Tracks internal per-file view and download counts in the WordPress admin.
- Adds meaningful meta descriptions and generated page summaries for archive, folder, and single MIDI download pages.
- Integrates descriptions, MIDI-specific titles, and linked file/breadcrumb structured data with Yoast SEO, with standalone metadata when no SEO plugin is active.
- Adds a beta browser playback / piano-roll view when a public file URL is configured.
- Includes `[kml_library]` for embedding a browsable library and `[kml_midi_player]` for a standalone upload/playback tool.
- Supports temporary public MIDI uploads for the standalone player, purged nightly at midnight.
- Supports WP-Cron indexing and WP-CLI indexing/status commands.

## Requirements

- WordPress 6.0 or newer.
- PHP 7.4 or newer.
- A server folder containing MIDI files that PHP can read.
- Optional: WP-CLI for faster command-line indexing.
- Optional: a public URL base for direct file downloads and browser playback.

## Installation

1. Copy this folder into `wp-content/plugins/kuhmann-midi-library`.
2. Activate **Kuhmann MIDI Library** in the WordPress admin.
3. Go to **Settings > Kuhmann MIDI**.
4. Set **Root folder (server path)** to the absolute path of the MIDI library.
5. Optionally set **Public URL base** if the same folder is available over HTTP.
6. Save settings.
7. Click **Start / Rebuild Index**.

After activation, WordPress rewrite rules are flushed. If routes do not resolve, visit **Settings > Permalinks** and save once.

## Configuration

### Root Folder

The root folder must be an absolute server path, for example:

```text
/var/www/html/wp-content/uploads/kuhmann-midi
```

The plugin recursively scans this folder and indexes files with `.mid` and `.midi` extensions.

### Public URL Base

If MIDI files are publicly accessible, set the matching URL base:

```text
https://example.com/wp-content/uploads/kuhmann-midi
```

When this is configured, single MIDI pages can load the beta browser player from the public file URL. Downloads still go through the plugin's `/midi-download/{post_id}/` endpoint so download counts can be tracked, as long as the underlying file remains readable by PHP.

Public MIDI entry pages return HTTP 404 and use the theme's not-found template when the indexed file is missing, unreadable, not a regular MIDI file, or outside the configured root folder. The indexer also skips files that fail these filesystem checks. The same validation protects downloads and file metadata. Unpublished and password-protected entries are not available through the public download endpoint. Previews remain available to users who can edit the entry.

The check uses the local file served by the download endpoint; a public URL base is optional and is not probed over HTTP. Unavailable files are excluded from public MIDI listings, WordPress search results that include MIDI files, and WordPress/Yoast post sitemaps. Exclusions are applied before counting and pagination, so missing entries do not leave empty result pages or consume slots that belong to available files. File links are checked again as the plugin renders them.

Indexed posts are retained in the admin. Restoring a file at its indexed path makes it eligible for listings again on the next request without a reimport. Validation reads matching indexed paths in database batches and caches results only within the current request; it does not walk the directory tree. Clear full-page/CDN caches when removing or restoring files, since a cached response can bypass PHP. The 404 guard remains for old external links and files removed after a listing was generated.

### Batch Size

The indexer processes files in batches through WP-Cron. Increase the batch size for faster indexing on capable servers, or lower it if the server hits timeouts.

## Usage

### Public Routes

- MIDI archive: `/midi/`
- Folder archive: `/midi-folder/{folder}/`
- Download endpoint: `/midi-download/{post_id}/`
- Direct player endpoint: `/midi-player/{path-relative-to-web-root}`

For example, if a MIDI file is publicly available at `/uploads/midifile.mid`, open:

```text
https://example.com/midi-player/uploads/midifile.mid
```

### Shortcode

Render the root library:

```text
[kml_library]
```

Render a specific folder:

```text
[kml_library folder="classical/beethoven"]
```

Set the number of files shown per page:

```text
[kml_library per_page="100"]
```

Render a standalone MIDI player with upload support:

```text
[kml_midi_player]
```

When no MIDI source or upload is provided, the player loads a bundled piano chromatic scale (C4 to C5 and back), ready to play. A supplied `src` or uploaded MIDI file takes precedence over this default.

Uploaded files are stored in a public temporary uploads folder and are purged every night at midnight. The upload option is only rendered by this standalone player shortcode; individual indexed MIDI pages do not show it.

Render a standalone player for a specific public MIDI URL or root-relative path:

```text
[kml_midi_player src="/uploads/midifile.mid" upload="0"]
```

### WP-CLI

Start and run indexing until complete:

```bash
wp kml index --batch=800
```

Show indexer status:

```bash
wp kml status
```

## Search Indexing and Snippets

Published MIDI entries, the `/midi/` archive, and folder archives are public WordPress pages. The plugin does not set or override `noindex`. Remove any library-specific `noindex` rules in site snippets, and check **Settings > Reading > Discourage search engines from indexing this site** and your SEO plugin's visibility settings for **MIDI Files** and **Folders**.

Each file has a visible summary describing its title and folder. Folders use their edited description or a generated summary. Edit a MIDI post's **Excerpt** or a folder's **Description** to supply more specific information. Existing generated excerpts are refreshed at display time; no library rebuild is needed.

With **Yoast SEO**, the plugin supplies missing meta descriptions, adds MIDI context to default file/folder titles, and extends Yoast's existing schema graph with folder breadcrumbs and MIDI `AudioObject` metadata. Explicit per-entry Yoast titles and nonempty Yoast descriptions are retained. Yoast continues to own canonical URLs, robots directives, and sitemap generation; unavailable MIDI entries are removed from sitemap queries.

Yoast's XML cache is bypassed for the MIDI post sitemap and sitemap index, so filesystem changes are reflected in generated XML and sitemap page counts. Other Yoast sitemap caches keep their existing settings. Full-page/CDN caches still need to be purged separately.

Without an SEO plugin, the plugin supplies descriptions, archive/folder canonical URLs (including pagination), and linked `WebPage`/`CollectionPage`, `BreadcrumbList`, and `AudioObject` JSON-LD. WordPress supplies singular canonicals and its XML sitemap. With Rank Math, AIOSEO, SEOPress, or The SEO Framework active, head metadata is left to that plugin; the Yoast-specific integration does not apply.

These metadata changes target permanent public library pages. They do not change indexing policy for internal searches, previews, protected entries, or player URLs. Structured data describes the file and navigation; it does not claim ratings, composers, performers, or a Google music rich result.

After installing the update:

1. Remove the external snippet's `noindex` rule and clear WordPress, server, and CDN page caches.
2. Inspect a file and folder URL's response headers and page source. Check for remaining `X-Robots-Tag: noindex` or robots meta restrictions, one description, and the correct canonical URL. An `index` tag cannot cancel another `noindex` directive.
3. Confirm published files and folders appear in the active XML sitemap (`/sitemap_index.xml` with Yoast, or `/wp-sitemap.xml` with WordPress core). Enable their sitemap inclusion in the SEO plugin if excluded.
4. Validate representative pages with [Google's Rich Results Test](https://search.google.com/test/rich-results) and inspect/request recrawling in Search Console. Submit the active sitemap.

Google chooses whether to index a page and which snippet to display; meta descriptions and valid structured data are signals, not guarantees. See [Google's snippet guidance](https://developers.google.com/search/docs/appearance/snippet) and [breadcrumb documentation](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb).

## Theme Overrides

The plugin ships default templates in `public/templates/`. Override them by copying matching files into the active theme:

- `single-kml_midi.php`
- `archive-kml_midi.php`
- `taxonomy-kml_folder.php`

## Development Notes

- Main plugin bootstrap: `kuhmann-midi-library.php`
- Post type and taxonomy registration: `includes/class-kml-post-types.php`
- Indexing and WP-CLI commands: `includes/class-kml-indexer.php`
- Admin settings page: `includes/class-kml-admin.php`
- Shortcode rendering: `includes/class-kml-shortcodes.php`
- Public routing, templates, downloads, and assets: `includes/class-kml-public.php`
- Search metadata and Yoast integration: `includes/class-kml-seo.php`
- Availability filtering before listing pagination: `includes/class-kml-availability.php`
- WordPress and Yoast sitemap exclusions: `includes/class-kml-sitemaps.php`

Run the dependency-free SEO regression checks with `php tests/seo.php`, `php tests/seo.php yoast`, and `php tests/seo.php rank-math`. These use WordPress stubs; also inspect rendered pages in the target WordPress installation after deployment.

Run listing and sitemap exclusion checks with `php tests/availability.php`.

This repository intentionally excludes MIDI libraries and other large/generated artifacts. Keep MIDI source folders outside the plugin directory, or ensure they remain ignored by git.

## Changelog

### 0.1.13

- Excluded unavailable MIDI entries from public listings, searches, and WordPress/Yoast sitemaps before pagination, with automatic reappearance when files return.
- Added an early 404 guard for unavailable MIDI entries, shared with downloads and SEO metadata.
- Validated regular readable MIDI files within the configured library root, including resolved symlink boundaries.
- Improved search titles, visible summaries, descriptions, canonical URLs, and Yoast-compatible structured data.
- Added a bundled chromatic scale as the standalone player's default MIDI source.

### 0.1.12

- Aligned Play/Pause, Stop, and WAV creation in one responsive action row.
- Improved player loading, cancellation, and accessibility states.
- Added Apache License 2.0 release metadata and source attribution on individual MIDI entries.
- Expanded repository publishing metadata and ignore rules.

## License

Kuhmann MIDI Library is licensed under the [Apache License 2.0](LICENSE).

Third-party components retain their respective licenses; see [Third-Party Notices](THIRD_PARTY_NOTICES.md).
