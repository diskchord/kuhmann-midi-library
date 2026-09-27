<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Indexer {

	// Option keys.
	private const OPT_ROOT_PATH   = 'kml_root_path';
	private const OPT_URL_BASE    = 'kml_url_base';
	private const OPT_BATCH_SIZE  = 'kml_batch_size';
	private const OPT_SCAN_QUEUE  = 'kml_scan_queue';
	private const OPT_INDEXING    = 'kml_indexing';
	private const OPT_SCAN_RUN_ID = 'kml_scan_run_id';

	/** Allowed extensions (lowercase). */
	private const EXTENSIONS = array( 'mid', 'midi' );

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		add_action( 'kml_daily_scan', array( __CLASS__, 'maybe_start_daily_scan' ) );
		add_action( 'kml_index_tick', array( __CLASS__, 'index_tick' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::register_wp_cli();
		}
	}
private static function force_valid_utf8( string $s ): string {
	// Remove invalid UTF-8 sequences that can break DB inserts.
	$s = wp_check_invalid_utf8( $s, true );

	// As a belt-and-suspenders fallback, strip non-printing control chars.
	$s = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $s );

	return trim( (string) $s );
}
	public static function cron_schedules( array $schedules ): array {
		// A short-interval schedule for initial indexing bursts.
		if ( ! isset( $schedules['kml_minutely'] ) ) {
			$schedules['kml_minutely'] = array(
				'interval' => 60,
				'display'  => __( 'Every Minute (KML)', 'kuhmann-midi-library' ),
			);
		}
		return $schedules;
	}

	public static function get_root_path(): string {
		$val = (string) get_option( self::OPT_ROOT_PATH, '' );
		return rtrim( $val, "/\\ \t\n\r\0\x0B" );
	}

	public static function get_url_base(): string {
		$val = (string) get_option( self::OPT_URL_BASE, '' );
		return rtrim( $val, "/\\ \t\n\r\0\x0B" );
	}

	public static function get_batch_size(): int {
		$val = (int) get_option( self::OPT_BATCH_SIZE, 300 );
		if ( $val < 10 ) {
			$val = 10;
		}
		if ( $val > 2000 ) {
			$val = 2000;
		}
		return $val;
	}

	public static function is_indexing(): bool {
		return (bool) get_option( self::OPT_INDEXING, false );
	}

	public static function get_status(): array {
		$queue = get_option( self::OPT_SCAN_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		return array(
			'indexing'   => self::is_indexing(),
			'queue_len'  => count( $queue ),
			'root_path'  => self::get_root_path(),
			'url_base'   => self::get_url_base(),
			'batch_size' => self::get_batch_size(),
			'run_id'     => (int) get_option( self::OPT_SCAN_RUN_ID, 0 ),
		);
	}

	/**
	 * Starts (or restarts) indexing the library.
	 */
	public static function start_index(): void {
		$root = self::get_root_path();
		if ( empty( $root ) || ! is_dir( $root ) || ! is_readable( $root ) ) {
			return;
		}

		update_option( self::OPT_SCAN_QUEUE, array( '' ), false );
		update_option( self::OPT_INDEXING, true, false );
		update_option( self::OPT_SCAN_RUN_ID, time(), false );

		// Schedule frequent ticks until the queue is empty.
		if ( ! wp_next_scheduled( 'kml_index_tick' ) ) {
			wp_schedule_event( time() + 10, 'kml_minutely', 'kml_index_tick' );
		}
	}

	/**
	 * A daily scan: only start if not already indexing.
	 */
	public static function maybe_start_daily_scan(): void {
		if ( self::is_indexing() ) {
			return;
		}
		self::start_index();
	}

	/**
	 * Indexer tick (runs on WP Cron; processes a batch of files/dirs).
	 */
	public static function index_tick(): void {
		if ( ! self::is_indexing() ) {
			return;
		}

		$root = self::get_root_path();
		if ( empty( $root ) || ! is_dir( $root ) || ! is_readable( $root ) ) {
			update_option( self::OPT_INDEXING, false, false );
			return;
		}

		$queue = get_option( self::OPT_SCAN_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$max_files = self::get_batch_size();
		$max_dirs  = max( 50, (int) ceil( $max_files / 2 ) );

		$files_processed = 0;
		$dirs_scanned    = 0;

		while ( ! empty( $queue ) && ( $files_processed < $max_files ) && ( $dirs_scanned < $max_dirs ) ) {
			$rel_dir = array_shift( $queue );
			$abs_dir = self::join_paths( $root, $rel_dir );

			if ( ! is_dir( $abs_dir ) || ! is_readable( $abs_dir ) ) {
				continue;
			}

			$dirs_scanned++;

			$entries = @scandir( $abs_dir );
			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$abs_path = self::join_paths( $abs_dir, $entry );
				$rel_path = ltrim( self::join_paths( $rel_dir, $entry ), '/\\' );

				if ( is_dir( $abs_path ) ) {
					$queue[] = $rel_path;
					continue;
				}

				$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, self::EXTENSIONS, true ) ) {
					continue;
				}

				self::index_file( $abs_path, $rel_path );

				$files_processed++;
				if ( $files_processed >= $max_files ) {
					break;
				}
			}
		}

		update_option( self::OPT_SCAN_QUEUE, array_values( $queue ), false );

		// Stop if done.
		if ( empty( $queue ) ) {
			update_option( self::OPT_INDEXING, false, false );

			// Clear the frequent tick schedule.
			$timestamp = wp_next_scheduled( 'kml_index_tick' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'kml_index_tick' );
			}
		}
	}

	/**
	 * Index (create/update) a single MIDI file as a CPT post.
	 *
	 * @param string $abs_file Absolute path
	 * @param string $rel_file Relative path from root
	 */
	private static function index_file( string $abs_file, string $rel_file ): void {
		$root = self::get_root_path();
		if ( empty( $root ) ) {
			return;
		}

		// Safety: ensure file is inside the configured root folder.
		$real_root = realpath( $root );
		$real_file = realpath( $abs_file );
		if ( ! $real_root || ! is_dir( $real_root ) || ! $real_file
			|| ! is_file( $real_file ) || ! is_readable( $real_file )
			|| ! in_array( strtolower( pathinfo( $real_file, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true )
			|| 0 !== strpos( $real_file, rtrim( $real_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return;
		}

		$filesize = (int) @filesize( $real_file );
		$mtime    = (int) @filemtime( $real_file );

		$folder_rel = trim( str_replace( '\\', '/', dirname( $rel_file ) ), '/' );
		if ( '.' === $folder_rel ) {
			$folder_rel = '';
		}

		$folder_term_id = self::ensure_folder_terms( $folder_rel );

		// Find existing post by relpath meta (unique within root).
		$existing = get_posts(
			array(
				'post_type'      => KML_Post_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'         => 'ids',
				'meta_key'       => 'kml_relpath',
				'meta_value'     => $rel_file,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		$post_id = ( is_array( $existing ) && ! empty( $existing ) ) ? (int) $existing[0] : 0;

		$title   = self::force_valid_utf8( self::nice_title_from_filename( basename( $rel_file ) ) );
		$slug    = self::unique_slug_for_relpath( $title, $rel_file );
		$summary = self::summary_from_file( $title, $rel_file, $folder_rel, $filesize, $mtime );

		$post_data = array(
			'post_type'   => KML_Post_Types::POST_TYPE,
			'post_title'  => $title,
			'post_name'   => $slug,
			'post_status' => 'publish',
		);

		$author_id = self::default_author_id();
		if ( $author_id > 0 ) {
			$post_data['post_author'] = $author_id;
		}

		$current_excerpt = $post_id ? trim( (string) get_post_field( 'post_excerpt', $post_id ) ) : '';
		if ( ! $post_id || '' === $current_excerpt || self::is_legacy_summary( $current_excerpt ) ) {
			$post_data['post_excerpt'] = $summary;
		}

		if ( $post_id ) {
			$post_data['ID'] = $post_id;

			$updated = wp_update_post( wp_slash( $post_data ), true );
			if ( is_wp_error( $updated ) ) {
				// Optional: log for debugging.
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					WP_CLI::warning( sprintf( 'Update failed for %s: %s', $rel_file, $updated->get_error_message() ) );
				} else {
					error_log( sprintf( '[KML] Update failed for %s: %s', $rel_file, $updated->get_error_message() ) );
				}
				return;
			}

			$post_id = (int) $updated;
		} else {
			$inserted = wp_insert_post( wp_slash( $post_data ), true );
			if ( is_wp_error( $inserted ) ) {
				// Optional: log for debugging.
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					WP_CLI::warning( sprintf( 'Insert failed for %s: %s', $rel_file, $inserted->get_error_message() ) );
				} else {
					error_log( sprintf( '[KML] Insert failed for %s: %s', $rel_file, $inserted->get_error_message() ) );
				}
				return;
			}

			$post_id = (int) $inserted;
		}
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, 'kml_relpath', $rel_file );
		update_post_meta( $post_id, 'kml_abspath', $real_file );
		update_post_meta( $post_id, 'kml_filesize', $filesize );
		update_post_meta( $post_id, 'kml_mtime', $mtime );
		update_post_meta( $post_id, 'kml_filename', basename( $rel_file ) );
		update_post_meta( $post_id, 'kml_folder_rel', $folder_rel );
		add_post_meta( $post_id, KML_Post_Types::META_VIEW_COUNT, 0, true );
		add_post_meta( $post_id, KML_Post_Types::META_DOWNLOAD_COUNT, 0, true );

		$url = self::file_url_from_relpath( $rel_file );
		if ( $url ) {
			update_post_meta( $post_id, 'kml_file_url', $url );
		} else {
			delete_post_meta( $post_id, 'kml_file_url' );
		}

		if ( $folder_term_id ) {
			wp_set_object_terms( $post_id, array( $folder_term_id ), KML_Post_Types::TAX_FOLDER, false );
		}
	}

	/**
	 * Ensures that a relative folder path exists as hierarchical taxonomy terms.
	 * Returns the term ID of the deepest folder.
	 */
	private static function ensure_folder_terms( string $folder_rel ): int {
		$folder_rel = trim( str_replace( '\\', '/', $folder_rel ), '/' );
		if ( '' === $folder_rel ) {
			return 0;
		}

		$parts = array_filter( array_map( 'sanitize_title', explode( '/', $folder_rel ) ) );
		if ( empty( $parts ) ) {
			return 0;
		}

		$parent = 0;
		$built  = array();
		foreach ( $parts as $slug ) {
			$built[] = $slug;
			$path_key = implode( '/', $built );

			$term = term_exists( $slug, KML_Post_Types::TAX_FOLDER, $parent );
			if ( ! $term ) {
				// Use the original (unslugged) segment as a nicer name if possible.
				$name = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
				$term = wp_insert_term(
					$name,
					KML_Post_Types::TAX_FOLDER,
					array(
						'slug'   => $slug,
						'parent' => $parent,
					)
				);
			}

			if ( is_wp_error( $term ) ) {
				return 0;
			}

			$parent = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		return $parent;
	}

	private static function nice_title_from_filename( string $filename ): string {
		$name = preg_replace( '/\.(mid|midi)$/i', '', $filename );
		$name = str_replace( array( '_', '-' ), ' ', $name );
		$name = preg_replace( '/\s+/', ' ', (string) $name );
		$name = trim( (string) $name );
		if ( '' === $name ) {
			$name = $filename;
		}
		return $name;
	}

	private static function summary_from_file( string $title, string $rel_file, string $folder_rel, int $filesize, int $mtime ): string {
		$summary = sprintf(
			/* translators: %s: MIDI file title. */
			__( 'Download the %s MIDI file from the Kuhmann / Disklavier World mirror.', 'kuhmann-midi-library' ),
			$title
		);

		$folder_label = trim( str_replace( '/', ' / ', str_replace( '\\', '/', $folder_rel ) ) );
		if ( '' !== $folder_label ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: folder path. */
				__( 'This Yamaha Disklavier-ready MIDI download is filed under %s.', 'kuhmann-midi-library' ),
				$folder_label
			);
		}

		$filename = basename( $rel_file );
		if ( '' !== $filename ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: filename. */
				__( 'Filename: %s.', 'kuhmann-midi-library' ),
				$filename
			);
		}

		if ( $filesize > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: formatted file size. */
				__( 'File size: %s.', 'kuhmann-midi-library' ),
				size_format( $filesize )
			);
		}

		if ( $mtime > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: localized modified date. */
				__( 'Last updated: %s.', 'kuhmann-midi-library' ),
				date_i18n( get_option( 'date_format' ), $mtime )
			);
		}

		return self::force_valid_utf8( $summary );
	}

	private static function default_author_id(): int {
		$author_id = (int) apply_filters( 'kml_default_author_user_id', 0 );
		if ( $author_id > 0 && get_userdata( $author_id ) ) {
			return $author_id;
		}

		foreach ( array( 'peppe', 'alexanderpeppe', 'alexander-peppe', 'alexander_peppe' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				return (int) $user->ID;
			}
		}

		$user = get_user_by( 'slug', 'peppe' );
		if ( $user ) {
			return (int) $user->ID;
		}

		$users = get_users(
			array(
				'search'         => 'Alexander Peppe',
				'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
				'number'         => 5,
			)
		);

		foreach ( $users as $user ) {
			if ( isset( $user->display_name ) && 0 === strcasecmp( 'Alexander Peppe', (string) $user->display_name ) ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	private static function is_legacy_summary( string $summary ): bool {
		$summary = strtolower( wp_strip_all_tags( $summary ) );
		return false !== strpos( $summary, 'is a downloadable file resource' )
			&& false !== strpos( $summary, 'related instructions or context available on the site' );
	}

	/**
	 * Build a stable, unique slug: {title}-{short-hash}
	 */
	private static function unique_slug_for_relpath( string $title, string $rel_file ): string {
		$base = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'midi';
		}
		$hash = substr( md5( $rel_file ), 0, 8 );
		return $base . '-' . $hash;
	}

	public static function file_url_from_relpath( string $rel_file ): string {
		$base = self::get_url_base();
		if ( empty( $base ) ) {
			return '';
		}

		$rel_file = str_replace( '\\', '/', $rel_file );
		$rel_file = ltrim( $rel_file, '/' );
		$rel_file = implode( '/', array_map( 'rawurlencode', explode( '/', $rel_file ) ) );

		// Note: if files are not directly web-accessible, leave URL base empty and use the download endpoint.
		return esc_url_raw( trailingslashit( $base ) . $rel_file );
	}

	private static function join_paths( string $a, string $b ): string {
		if ( '' === $b ) {
			return rtrim( $a, "/\\ \t\n\r\0\x0B" );
		}
		return rtrim( $a, "/\\ \t\n\r\0\x0B" ) . '/' . ltrim( $b, "/\\ \t\n\r\0\x0B" );
	}

	private static function register_wp_cli(): void {
		WP_CLI::add_command(
			'kml index',
			function ( $args, $assoc_args ) {
				$batch = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 500;
				update_option( self::OPT_BATCH_SIZE, $batch, false );
				self::start_index();

				WP_CLI::log( 'Indexing started. Running ticks until done...' );
				while ( self::is_indexing() ) {
					self::index_tick();
					$status = self::get_status();
					WP_CLI::log( sprintf( 'Queue: %d dirs remaining', (int) $status['queue_len'] ) );
				}
				WP_CLI::success( 'Indexing complete.' );
			},
			array(
				'shortdesc' => 'Index the configured Kuhmann MIDI directory into WordPress.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Max MIDI files processed per tick.',
						'optional'    => true,
					),
				),
			)
		);

		WP_CLI::add_command(
			'kml status',
			function () {
				$status = self::get_status();
				WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			},
			array(
				'shortdesc' => 'Show Kuhmann MIDI indexer status.',
			)
		);
	}
}

KML_Indexer::init();
