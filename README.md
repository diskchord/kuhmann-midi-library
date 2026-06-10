# Kuhmann MIDI Library

Kuhmann MIDI Library is a WordPress plugin for indexing and browsing a large MIDI directory as an SEO-friendly library. It turns `.mid` and `.midi` files into a public `kml_midi` custom post type, mirrors the directory tree into a hierarchical `kml_folder` taxonomy, and provides archive, folder, single-file, search, download, and playback views.

The plugin was built for publishing a navigable Kuhmann / Disklavier World MIDI mirror, but it can index any readable server-side MIDI folder.

## Features

- Indexes `.mid` and `.midi` files from a configured server folder.
- Creates one WordPress post per MIDI file.
- Preserves folder structure through hierarchical taxonomy terms.
- Adds public archive, folder, and single MIDI templates.
- Supports search across the archive and within folders.
- Stores relative path, absolute path, filename, file size, modified time, and optional public URL metadata.
- Provides a protected download endpoint for MIDI downloads.
- Tracks internal per-file view and download counts in the WordPress admin.
- Adds meaningful meta descriptions and generated page summaries for archive, folder, and single MIDI download pages.
- Adds a beta browser playback / piano-roll view when a public file URL is configured.
- Includes a `[kml_library]` shortcode for embedding a browsable library.
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

### Batch Size

The indexer processes files in batches through WP-Cron. Increase the batch size for faster indexing on capable servers, or lower it if the server hits timeouts.

## Usage

### Public Routes

- MIDI archive: `/midi/`
- Folder archive: `/midi-folder/{folder}/`
- Download endpoint: `/midi-download/{post_id}/`

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

### WP-CLI

Start and run indexing until complete:

```bash
wp kml index --batch=800
```

Show indexer status:

```bash
wp kml status
```

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

This repository intentionally excludes MIDI libraries and other large/generated artifacts. Keep MIDI source folders outside the plugin directory, or ensure they remain ignored by git.

## License

GPLv2 or later.
