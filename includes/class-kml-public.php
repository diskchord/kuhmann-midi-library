<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Public {

	private const PLAYER_UPLOAD_SUBDIR = 'kml-midi-player-uploads';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404_missing_file' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_download' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_count_file_view' ), 20 );
		add_action( 'kml_purge_player_uploads', array( __CLASS__, 'purge_player_uploads' ) );

		add_filter( 'template_include', array( __CLASS__, 'template_loader' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_file_excerpt' ), 10, 2 );
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'filter_file_author_display_name' ), 10, 3 );
		add_filter( 'the_author', array( __CLASS__, 'filter_file_author_display_name' ), 10, 1 );
		add_filter( 'author_link', array( __CLASS__, 'filter_file_author_link' ), 10, 3 );
	}

	public static function add_rewrite_rules(): void {
		// /midi-download/{post_id}/
		add_rewrite_rule( '^midi-download/([0-9]+)/?$', 'index.php?kml_download=$matches[1]', 'top' );

		// /midi-player/{path-relative-to-web-root}
		add_rewrite_rule( '^midi-player/(.+?)/?$', 'index.php?kml_player_path=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'kml_download';
		$vars[] = 'kml_player_path';
		return $vars;
	}

	public static function enqueue_assets(): void {
		$enqueue = false;
		$enqueue_pianoroll = false;
		$style_ver = self::asset_version( 'public/assets/css/kml-public.css' );
		$script_ver = self::asset_version( 'public/assets/js/kml-public.js' );

		if ( is_singular( KML_Post_Types::POST_TYPE ) || is_post_type_archive( KML_Post_Types::POST_TYPE ) || is_tax( KML_Post_Types::TAX_FOLDER ) ) {
			$enqueue = true;
		} else {
			global $post;
			$post_content = $post && isset( $post->post_content ) ? (string) $post->post_content : '';
			$enqueue_pianoroll = $post_content && (
				has_shortcode( $post_content, 'kml_midi_player' ) ||
				has_shortcode( $post_content, 'kml_player' )
			);
			if (
				$post_content &&
				(
					has_shortcode( $post_content, 'kml_library' ) ||
					$enqueue_pianoroll
				)
			) {
				$enqueue = true;
			}
		}

		if ( ! $enqueue ) {
			return;
		}

		wp_enqueue_style(
			'kml-public',
			KML_PLUGIN_URL . 'public/assets/css/kml-public.css',
			array(),
			$style_ver
		);

		wp_enqueue_script(
			'kml-public',
			KML_PLUGIN_URL . 'public/assets/js/kml-public.js',
			array(),
			$script_ver,
			true
		);

		if ( $enqueue_pianoroll ) {
			self::enqueue_pianoroll_assets();
		}

		// Optional browser MIDI player: only on single pages AND only if a public URL is available.
		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			$post_id = get_the_ID();
			$file_url = $post_id ? self::get_public_file_url( (int) $post_id ) : '';
			if ( ! empty( $file_url ) ) {
				wp_enqueue_script(
					'kml-html-midi-player',
					'https://cdn.jsdelivr.net/combine/npm/tone@14.7.58,npm/@magenta/music@1.23.1/es6/core.js,npm/html-midi-player@1.5.0',
					array(),
					null,
					true
				);
			}
		}
	}

	public static function enqueue_pianoroll_assets(): void {
		$style_ver = self::asset_version( 'public/assets/css/kml-public.css' );
		$script_ver = self::asset_version( 'public/assets/js/kml-public.js' );

		wp_enqueue_style(
			'kml-public',
			KML_PLUGIN_URL . 'public/assets/css/kml-public.css',
			array(),
			$style_ver
		);

		wp_enqueue_script(
			'kml-public',
			KML_PLUGIN_URL . 'public/assets/js/kml-public.js',
			array(),
			$script_ver,
			true
		);

		wp_enqueue_script(
			'tonejs',
			'https://cdn.jsdelivr.net/npm/tone@14.8.49/build/Tone.js',
			array(),
			null,
			true
		);

		wp_enqueue_script(
			'tonejs-midi',
			plugins_url( 'public/assets/Midi.js', KML_PLUGIN_FILE ),
			array(),
			'2.0.28',
			true
		);

		wp_enqueue_script(
			'soundfont-player',
			'https://cdn.jsdelivr.net/npm/soundfont-player@0.12.0/dist/soundfont-player.min.js',
			array(),
			'0.12.0',
			true
		);

		$pianoroll_path = KML_PLUGIN_DIR . 'public/assets/kml-pianoroll.js';
		$pianoroll_ver  = file_exists( $pianoroll_path ) ? (string) filemtime( $pianoroll_path ) : KML_VERSION;

		wp_enqueue_script(
			'kml-pianoroll',
			plugins_url( 'public/assets/kml-pianoroll.js', KML_PLUGIN_FILE ),
			array( 'tonejs', 'tonejs-midi', 'soundfont-player' ),
			$pianoroll_ver,
			true
		);
	}

	private static function asset_version( string $relative_path ): string {
		$path = KML_PLUGIN_DIR . ltrim( $relative_path, '/' );

		return file_exists( $path ) ? (string) filemtime( $path ) : KML_VERSION;
	}

	public static function template_loader( string $template ): string {
		if ( self::is_direct_player_request() ) {
			$theme = locate_template( array( 'kml-midi-player.php', 'midi-player.php' ) );
			if ( $theme ) {
				return $theme;
			}
			return KML_PLUGIN_DIR . 'public/templates/midi-player.php';
		}

		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			$theme = locate_template( array( 'single-' . KML_Post_Types::POST_TYPE . '.php' ) );
			if ( $theme ) {
				return $theme;
			}
			return KML_PLUGIN_DIR . 'public/templates/single-kml_midi.php';
		}

		if ( is_post_type_archive( KML_Post_Types::POST_TYPE ) ) {
			$theme = locate_template( array( 'archive-' . KML_Post_Types::POST_TYPE . '.php' ) );
			if ( $theme ) {
				return $theme;
			}
			return KML_PLUGIN_DIR . 'public/templates/archive-kml_midi.php';
		}

		if ( is_tax( KML_Post_Types::TAX_FOLDER ) ) {
			$theme = locate_template( array( 'taxonomy-' . KML_Post_Types::TAX_FOLDER . '.php' ) );
			if ( $theme ) {
				return $theme;
			}
			return KML_PLUGIN_DIR . 'public/templates/taxonomy-kml_folder.php';
		}

		return $template;
	}

	public static function get_public_file_url( int $post_id ): string {
		$relpath = (string) get_post_meta( $post_id, 'kml_relpath', true );
		if ( '' !== $relpath ) {
			return KML_Indexer::file_url_from_relpath( $relpath );
		}

		return esc_url_raw( (string) get_post_meta( $post_id, 'kml_file_url', true ) );
	}

	public static function get_download_url( int $post_id ): string {
		return esc_url_raw( home_url( user_trailingslashit( 'midi-download/' . $post_id ) ) );
	}

	/** Return the real file the public download endpoint can serve, or an empty string. */
	public static function get_downloadable_file( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || KML_Post_Types::POST_TYPE !== $post->post_type
			|| 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return '';
		}

		$abs = (string) get_post_meta( $post_id, 'kml_abspath', true );
		return self::resolve_library_file( $abs );
	}

	/** Validate an indexed path without loading a post (also used by listing queries). */
	public static function resolve_library_file( string $abs ): string {
		$root = KML_Indexer::get_root_path();
		if ( '' === $abs || '' === $root || false !== strpos( $abs . $root, "\0" ) ) {
			return '';
		}

		$real_root = realpath( $root );
		$real_file = realpath( $abs );
		if ( ! $real_root || ! is_dir( $real_root ) || ! $real_file
			|| ! self::path_is_inside( $real_file, $real_root )
			|| ! is_file( $real_file ) || ! is_readable( $real_file )
			|| ! in_array( strtolower( pathinfo( $real_file, PATHINFO_EXTENSION ) ), array( 'mid', 'midi' ), true ) ) {
			return '';
		}

		return $real_file;
	}

	/** Reject stale public permalinks before redirects, view counts, SEO, or templates run. */
	public static function maybe_404_missing_file(): void {
		if ( is_admin() || ! is_singular( KML_Post_Types::POST_TYPE ) ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ( is_preview() && current_user_can( 'edit_post', $post_id ) )
			|| '' !== self::get_downloadable_file( $post_id ) ) {
			return;
		}

		global $wp_query, $post;
		$wp_query->posts = array();
		$wp_query->post = null;
		$wp_query->post_count = 0;
		$wp_query->found_posts = 0;
		$wp_query->max_num_pages = 0;
		$wp_query->queried_object = null;
		$wp_query->queried_object_id = 0;
		$post = null;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		// Do not redirect a missing-file 404 back to the stale post's permalink.
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'old_slug_redirect_url', '__return_false' );
	}

	public static function is_direct_player_request(): bool {
		return '' !== (string) get_query_var( 'kml_player_path', '' );
	}

	public static function get_direct_player_file(): array {
		$request_path = (string) get_query_var( 'kml_player_path', '' );
		$request_path = rawurldecode( $request_path );
		$request_path = str_replace( '\\', '/', $request_path );
		$request_path = preg_replace( '#/+#', '/', $request_path );
		$request_path = ltrim( trim( (string) $request_path ), '/' );

		if ( '' === $request_path || false !== strpos( $request_path, "\0" ) ) {
			return self::direct_player_error( __( 'Missing MIDI file path.', 'kuhmann-midi-library' ) );
		}

		$parts = explode( '/', $request_path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return self::direct_player_error( __( 'Invalid MIDI file path.', 'kuhmann-midi-library' ) );
			}
		}

		$ext = strtolower( pathinfo( $request_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'mid', 'midi' ), true ) ) {
			return self::direct_player_error( __( 'Only .mid and .midi files can be loaded by this player.', 'kuhmann-midi-library' ) );
		}

		$document_root = self::get_document_root();
		$real_root     = $document_root ? realpath( $document_root ) : '';
		if ( ! $real_root || ! is_dir( $real_root ) ) {
			return self::direct_player_error( __( 'The web root could not be resolved.', 'kuhmann-midi-library' ) );
		}

		$abs_path  = rtrim( $real_root, "/\\" ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $request_path );
		$real_file = realpath( $abs_path );

		if ( ! $real_file || ! is_file( $real_file ) || ! is_readable( $real_file ) || ! self::path_is_inside( $real_file, $real_root ) ) {
			return self::direct_player_error( __( 'The requested MIDI file could not be found.', 'kuhmann-midi-library' ) );
		}

		$url_path = implode( '/', array_map( 'rawurlencode', explode( '/', $request_path ) ) );

		return array(
			'found'    => true,
			'error'    => '',
			'relpath'  => $request_path,
			'abs_path' => $real_file,
			'file_url' => esc_url_raw( home_url( '/' . $url_path ) ),
			'filename' => basename( $request_path ),
			'filesize' => (int) @filesize( $real_file ),
			'mtime'    => (int) @filemtime( $real_file ),
		);
	}

	public static function get_uploaded_player_file( string $filename ): array {
		$filename = sanitize_file_name( wp_basename( rawurldecode( $filename ) ) );
		if ( '' === $filename ) {
			return self::direct_player_error( __( 'Missing uploaded MIDI file.', 'kuhmann-midi-library' ) );
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'mid', 'midi' ), true ) ) {
			return self::direct_player_error( __( 'Only .mid and .midi files can be loaded by this player.', 'kuhmann-midi-library' ) );
		}

		$upload_dir = self::get_player_upload_dir( false );
		if ( empty( $upload_dir['dir'] ) || empty( $upload_dir['url'] ) ) {
			return self::direct_player_error( __( 'The player upload directory could not be resolved.', 'kuhmann-midi-library' ) );
		}

		$abs_path  = trailingslashit( $upload_dir['dir'] ) . $filename;
		$real_root = realpath( $upload_dir['dir'] );
		$real_file = realpath( $abs_path );

		if ( ! $real_root || ! $real_file || ! is_file( $real_file ) || ! is_readable( $real_file ) || ! self::path_is_inside( $real_file, $real_root ) ) {
			return self::direct_player_error( __( 'The uploaded MIDI file could not be found.', 'kuhmann-midi-library' ) );
		}

		return array(
			'found'    => true,
			'error'    => '',
			'relpath'  => self::PLAYER_UPLOAD_SUBDIR . '/' . $filename,
			'abs_path' => $real_file,
			'file_url' => esc_url_raw( trailingslashit( $upload_dir['url'] ) . rawurlencode( $filename ) ),
			'filename' => $filename,
			'filesize' => (int) @filesize( $real_file ),
			'mtime'    => (int) @filemtime( $real_file ),
		);
	}

	public static function save_player_upload( array $file ): array {
		self::purge_player_uploads( false );

		$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error_code ) {
			return self::direct_player_error( self::upload_error_message( $error_code ) );
		}

		$original_name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$tmp_name      = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size          = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'mid', 'midi' ), true ) ) {
			return self::direct_player_error( __( 'Please upload a .mid or .midi file.', 'kuhmann-midi-library' ) );
		}

		$max_size = (int) apply_filters( 'kml_midi_player_upload_max_size', 20 * 1024 * 1024 );
		if ( $max_size > 0 && $size > $max_size ) {
			return self::direct_player_error(
				sprintf(
					/* translators: %s: formatted maximum upload size. */
					__( 'The MIDI file is larger than the %s upload limit.', 'kuhmann-midi-library' ),
					size_format( $max_size )
				)
			);
		}

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return self::direct_player_error( __( 'The uploaded file could not be read.', 'kuhmann-midi-library' ) );
		}

		$upload_dir = self::get_player_upload_dir( true );
		if ( empty( $upload_dir['dir'] ) || ! is_dir( $upload_dir['dir'] ) || ! is_writable( $upload_dir['dir'] ) ) {
			return self::direct_player_error( __( 'The player upload directory is not writable.', 'kuhmann-midi-library' ) );
		}

		$base_name = sanitize_file_name( pathinfo( $original_name, PATHINFO_FILENAME ) );
		if ( '' === $base_name ) {
			$base_name = 'midi';
		}

		$filename = wp_unique_filename(
			$upload_dir['dir'],
			$base_name . '-' . wp_generate_password( 8, false, false ) . '.' . $ext
		);
		$target = trailingslashit( $upload_dir['dir'] ) . $filename;

		if ( ! @move_uploaded_file( $tmp_name, $target ) ) {
			return self::direct_player_error( __( 'The uploaded MIDI file could not be saved.', 'kuhmann-midi-library' ) );
		}

		@chmod( $target, 0644 );

		return self::get_uploaded_player_file( $filename );
	}

	public static function schedule_player_upload_purge(): void {
		if ( wp_next_scheduled( 'kml_purge_player_uploads' ) ) {
			return;
		}

		wp_schedule_event( self::next_midnight_timestamp(), 'daily', 'kml_purge_player_uploads' );
	}

	public static function purge_player_uploads( bool $purge_all = true ): void {
		$upload_dir = self::get_player_upload_dir( false );
		$dir        = ! empty( $upload_dir['dir'] ) ? (string) $upload_dir['dir'] : '';

		if ( '' === $dir || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return;
		}

		$today_start = null;
		if ( ! $purge_all ) {
			$today_start = new DateTimeImmutable( 'today 00:00:00', wp_timezone() );
		}

		foreach ( new DirectoryIterator( $dir ) as $item ) {
			if ( $item->isDot() || ! $item->isFile() ) {
				continue;
			}

			$filename = $item->getFilename();
			if ( 'index.html' === $filename ) {
				continue;
			}

			$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'mid', 'midi' ), true ) ) {
				continue;
			}

			if ( $today_start && $item->getMTime() >= $today_start->getTimestamp() ) {
				continue;
			}

			@unlink( $item->getPathname() );
		}
	}

	public static function maybe_count_file_view(): void {
		if ( ! is_singular( KML_Post_Types::POST_TYPE ) || is_preview() || is_feed() || wp_doing_ajax() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		self::increment_counter( $post_id, KML_Post_Types::META_VIEW_COUNT );
	}

	public static function print_meta_description(): void {
		$description = self::trim_summary( self::get_current_page_summary(), 180 );
		if ( '' === $description ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	public static function get_current_page_summary(): string {
		if ( self::is_direct_player_request() ) {
			$file = self::get_direct_player_file();
			if ( ! empty( $file['found'] ) ) {
				return sprintf(
					/* translators: %s: MIDI file name. */
					__( 'Browser piano-roll MIDI player for %s.', 'kuhmann-midi-library' ),
					(string) $file['filename']
				);
			}
			return '';
		}

		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			return self::get_file_page_summary( (int) get_queried_object_id() );
		}

		if ( is_post_type_archive( KML_Post_Types::POST_TYPE ) ) {
			return self::get_archive_page_summary();
		}

		if ( is_tax( KML_Post_Types::TAX_FOLDER ) ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				return self::get_folder_page_summary( $term );
			}
		}

		return '';
	}

	public static function get_archive_page_summary(): string {
		return __( 'Browse and download Yamaha Disklavier-ready MIDI files from the Kuhmann / Disklavier World mirror, organized by folder with search and playback previews where available.', 'kuhmann-midi-library' );
	}

	public static function get_file_page_summary( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || KML_Post_Types::POST_TYPE !== $post->post_type ) {
			return '';
		}

		$excerpt = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $post_id ) ) );
		if ( '' !== $excerpt && ! self::is_generated_file_excerpt( $excerpt ) ) {
			return self::trim_summary( $excerpt, 260 );
		}

		$title  = get_the_title( $post_id );
		$folder = self::get_post_folder_label( $post_id );

		if ( '' !== $folder ) {
			$summary = sprintf(
				/* translators: 1: MIDI file title, 2: folder path. */
				__( 'Download %1$s MIDI from %2$s for Yamaha Disklavier. Part of the Kuhmann / Disklavier World collection.', 'kuhmann-midi-library' ),
				$title,
				$folder
			);
		} else {
			$summary = sprintf(
				/* translators: %s: MIDI file title. */
				__( 'Download %s MIDI for Yamaha Disklavier from the Kuhmann / Disklavier World collection.', 'kuhmann-midi-library' ),
				$title
			);
		}

		return self::trim_summary( $summary, 260 );
	}

	public static function get_folder_page_summary( WP_Term $term ): string {
		$description = trim( wp_strip_all_tags( (string) $term->description ) );
		if ( '' !== $description ) {
			return self::trim_summary( $description, 260 );
		}

		$count = self::term_count_including_children( $term, KML_Post_Types::TAX_FOLDER );
		$path  = self::get_term_name_path( $term );

		if ( $count > 0 ) {
			$summary = sprintf(
				/* translators: 1: folder path, 2: number of MIDI files. */
				_n(
					'Browse %1$s: %2$s MIDI file to download for Yamaha Disklavier. Explore the Kuhmann / Disklavier World collection.',
					'Browse %1$s: %2$s MIDI files to download for Yamaha Disklavier. Explore the Kuhmann / Disklavier World collection.',
					$count,
					'kuhmann-midi-library'
				),
				$path,
				number_format_i18n( $count )
			);
		} else {
			$summary = sprintf(
				/* translators: %s: folder path. */
				__( 'Browse the %s MIDI folder in the Kuhmann / Disklavier World collection for Yamaha Disklavier.', 'kuhmann-midi-library' ),
				$path
			);
		}

		return self::trim_summary( $summary, 260 );
	}

	public static function filter_file_excerpt( string $excerpt, $post ): string {
		if ( ! ( $post instanceof WP_Post ) || KML_Post_Types::POST_TYPE !== $post->post_type ) {
			return $excerpt;
		}

		$excerpt = trim( $excerpt );
		if ( '' !== $excerpt && ! self::is_generated_file_excerpt( $excerpt ) ) {
			return $excerpt;
		}

		return self::get_file_page_summary( (int) $post->ID );
	}

	public static function get_file_author_label( int $post_id ): string {
		$user = self::get_default_author_user();
		if ( $user ) {
			return (string) $user->display_name;
		}

		return self::get_default_author_fallback_label();
	}

	public static function filter_file_author_display_name( string $display_name, $user_id = null, $original_user_id = null ): string {
		if ( ! self::is_midi_author_context() ) {
			return $display_name;
		}

		$user = self::get_default_author_user();
		if ( $user ) {
			return (string) $user->display_name;
		}

		return self::get_default_author_fallback_label();
	}

	public static function filter_file_author_link( string $link, int $author_id, string $author_nicename ): string {
		if ( ! self::is_midi_author_context() ) {
			return $link;
		}

		$user = self::get_default_author_user();
		if ( ! $user ) {
			return $link;
		}

		return get_author_posts_url( (int) $user->ID, (string) $user->user_nicename );
	}

	public static function render_file_list_item( int $post_id, bool $include_details = false ): void {
		// Recheck at output time in case a file disappeared after the listing query.
		if ( '' === self::get_downloadable_file( $post_id ) ) {
			return;
		}

		$classes = array( 'kml-file-result' );
		if ( $include_details ) {
			$classes[] = 'kml-file-result-detailed';
		}
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<a class="kml-file-title" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
			<?php if ( $include_details ) : ?>
				<?php $author = self::get_file_author_label( $post_id ); ?>
				<?php if ( $author ) : ?>
					<div class="kml-file-author"><?php echo esc_html( sprintf( __( 'By %s', 'kuhmann-midi-library' ), $author ) ); ?></div>
				<?php endif; ?>

				<?php $summary = self::get_file_page_summary( $post_id ); ?>
				<?php if ( $summary ) : ?>
					<div class="kml-file-summary"><?php echo esc_html( $summary ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</li>
		<?php
	}

	public static function maybe_serve_download(): void {
		$post_id = (int) get_query_var( 'kml_download' );
		if ( ! $post_id ) {
			return;
		}

		$real_file = self::get_downloadable_file( $post_id );
		$rel = (string) get_post_meta( $post_id, 'kml_relpath', true );
		if ( '' === $real_file ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		// Open before sending attachment headers; the file may disappear after validation.
		$fp = @fopen( $real_file, 'rb' );
		if ( false === $fp ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$filename = basename( $rel ? $rel : $real_file );
		$stat = fstat( $fp );
		if ( false === $stat ) {
			fclose( $fp );
			status_header( 500 );
			nocache_headers();
			exit;
		}
		$filesize = (int) $stat['size'];

		nocache_headers();

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: audio/midi' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . $filesize );

		self::increment_counter( $post_id, KML_Post_Types::META_DOWNLOAD_COUNT );

		while ( ! feof( $fp ) ) {
			echo fread( $fp, 8192 );
			@flush();
		}
		fclose( $fp );
		exit;
	}

	private static function increment_counter( int $post_id, string $meta_key ): int {
		global $wpdb;

		$allowed = array(
			KML_Post_Types::META_VIEW_COUNT,
			KML_Post_Types::META_DOWNLOAD_COUNT,
		);

		if ( ! in_array( $meta_key, $allowed, true ) ) {
			return 0;
		}

		if ( ! metadata_exists( 'post', $post_id, $meta_key ) && add_post_meta( $post_id, $meta_key, 1, true ) ) {
			return 1;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
				$post_id,
				$meta_key
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );

		if ( false === $updated || 0 === $updated ) {
			$count = (int) get_post_meta( $post_id, $meta_key, true );
			$count++;
			update_post_meta( $post_id, $meta_key, $count );
			return $count;
		}

		return (int) get_post_meta( $post_id, $meta_key, true );
	}

	private static function get_document_root(): string {
		$root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
		$root = rtrim( $root, "/\\ \t\n\r\0\x0B" );

		/**
		 * Allows hosts with unusual WordPress/web-root layouts to override the
		 * filesystem root used by /midi-player/{path}.
		 */
		$root = (string) apply_filters( 'kml_midi_player_document_root', $root );
		$root = rtrim( $root, "/\\ \t\n\r\0\x0B" );

		if ( '' !== $root && is_dir( $root ) ) {
			return $root;
		}

		return rtrim( ABSPATH, "/\\ \t\n\r\0\x0B" );
	}

	private static function get_player_upload_dir( bool $create ): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array(
				'dir' => '',
				'url' => '',
			);
		}

		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::PLAYER_UPLOAD_SUBDIR;
		$url = trailingslashit( (string) $uploads['baseurl'] ) . self::PLAYER_UPLOAD_SUBDIR;

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( $create && is_dir( $dir ) ) {
			$index_file = trailingslashit( $dir ) . 'index.html';
			if ( ! file_exists( $index_file ) ) {
				@file_put_contents( $index_file, '' );
			}
		}

		return array(
			'dir' => $dir,
			'url' => $url,
		);
	}

	private static function next_midnight_timestamp(): int {
		$midnight = new DateTimeImmutable( 'tomorrow 00:00:00', wp_timezone() );
		return $midnight->getTimestamp();
	}

	private static function upload_error_message( int $error_code ): string {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded MIDI file is too large.', 'kuhmann-midi-library' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The MIDI file upload did not finish.', 'kuhmann-midi-library' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Please choose a MIDI file to upload.', 'kuhmann-midi-library' );
			default:
				return __( 'The MIDI file could not be uploaded.', 'kuhmann-midi-library' );
		}
	}

	private static function path_is_inside( string $path, string $root ): bool {
		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';

		return 0 === strpos( $path, $root );
	}

	private static function direct_player_error( string $message ): array {
		return array(
			'found'    => false,
			'error'    => $message,
			'relpath'  => '',
			'abs_path' => '',
			'file_url' => '',
			'filename' => '',
			'filesize' => 0,
			'mtime'    => 0,
		);
	}

	public static function get_post_folder_label( int $post_id ): string {
		$terms = get_the_terms( $post_id, KML_Post_Types::TAX_FOLDER );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return self::get_term_name_path( $terms[0] );
		}

		$folder_rel = trim( str_replace( '\\', '/', (string) get_post_meta( $post_id, 'kml_folder_rel', true ) ), '/' );
		return str_replace( '/', ' / ', $folder_rel );
	}

	public static function get_term_name_path( WP_Term $term ): string {
		$parts   = array();
		$current = $term;

		while ( $current && ! is_wp_error( $current ) ) {
			$parts[] = $current->name;
			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, KML_Post_Types::TAX_FOLDER );
		}

		$parts = array_reverse( array_filter( $parts ) );
		return implode( ' / ', $parts );
	}

	private static function is_midi_author_context(): bool {
		$post = get_post();
		return $post instanceof WP_Post && KML_Post_Types::POST_TYPE === $post->post_type;
	}

	private static function get_default_author_user(): ?WP_User {
		$author_id = (int) apply_filters( 'kml_default_author_user_id', 0 );
		if ( $author_id > 0 ) {
			$user = get_userdata( $author_id );
			if ( $user ) {
				return $user;
			}
		}

		foreach ( array( 'peppe', 'alexanderpeppe', 'alexander-peppe', 'alexander_peppe' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'slug', 'peppe' );
		if ( $user ) {
			return $user;
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
				return $user;
			}
		}

		return null;
	}

	private static function get_default_author_fallback_label(): string {
		return (string) apply_filters( 'kml_default_author_label', 'Alexander Peppe' );
	}

	private static function is_generated_file_excerpt( string $excerpt ): bool {
		$excerpt = trim( wp_strip_all_tags( $excerpt ) );
		$lower   = strtolower( $excerpt );
		if ( false !== strpos( $lower, 'is a downloadable file resource' )
			&& false !== strpos( $lower, 'related instructions or context available on the site' ) ) {
			return true;
		}

		// Existing imports stored this generated text. Refresh it at display time
		// without rewriting edited excerpts or requiring another library scan.
		return 1 === preg_match(
			'~^Download the .+ MIDI file from the Kuhmann / Disklavier World mirror\.(?: This Yamaha Disklavier-ready MIDI download is filed under .+\.)?(?: Filename: .+\.)?(?: File size: [\d.,]+\s*\w+\.)?(?: Last updated: .+\.)?$~i',
			$excerpt
		);
	}

	private static function term_count_including_children( WP_Term $term, string $taxonomy ): int {
		$total = (int) $term->count;

		$children = get_term_children( $term->term_id, $taxonomy );
		if ( is_wp_error( $children ) || empty( $children ) ) {
			return $total;
		}

		foreach ( $children as $child_id ) {
			$child = get_term( (int) $child_id, $taxonomy );
			if ( $child && ! is_wp_error( $child ) ) {
				$total += (int) $child->count;
			}
		}

		return $total;
	}

	public static function trim_summary( string $summary, int $max_length ): string {
		if ( $max_length < 1 ) {
			return '';
		}

		$summary = html_entity_decode( wp_strip_all_tags( $summary ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$summary = trim( (string) preg_replace( '/\s+/u', ' ', wp_check_invalid_utf8( $summary ) ) );
		$chars   = preg_split( '//u', $summary, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars || count( $chars ) <= $max_length ) {
			return $summary;
		}

		// Reserve one character for punctuation; splitting UTF-8 characters also
		// works on hosts without the optional mbstring PHP extension.
		$trimmed = implode( '', array_slice( $chars, 0, $max_length - 1 ) );
		$trimmed = preg_replace( '/\s+\S*$/u', '', $trimmed );

		return rtrim( (string) $trimmed, " \t\n\r\0\x0B.,;:" ) . '.';
	}
}
