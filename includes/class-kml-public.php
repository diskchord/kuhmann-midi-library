<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Public {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_download' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_count_file_view' ), 20 );

		add_filter( 'template_include', array( __CLASS__, 'template_loader' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_meta_description' ), 1 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_file_excerpt' ), 10, 2 );
		add_filter( 'get_the_author_display_name', array( __CLASS__, 'filter_file_author_display_name' ), 10, 3 );
		add_filter( 'the_author', array( __CLASS__, 'filter_file_author_display_name' ), 10, 1 );
		add_filter( 'author_link', array( __CLASS__, 'filter_file_author_link' ), 10, 3 );
	}

	public static function add_rewrite_rules(): void {
		// /midi-download/{post_id}/
		add_rewrite_rule( '^midi-download/([0-9]+)/?$', 'index.php?kml_download=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'kml_download';
		return $vars;
	}

	public static function enqueue_assets(): void {
		$enqueue = false;

		if ( is_singular( KML_Post_Types::POST_TYPE ) || is_post_type_archive( KML_Post_Types::POST_TYPE ) || is_tax( KML_Post_Types::TAX_FOLDER ) ) {
			$enqueue = true;
		} else {
			global $post;
			if ( $post && isset( $post->post_content ) && has_shortcode( (string) $post->post_content, 'kml_library' ) ) {
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
			KML_VERSION
		);

		wp_enqueue_script(
			'kml-public',
			KML_PLUGIN_URL . 'public/assets/js/kml-public.js',
			array(),
			KML_VERSION,
			true
		);

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

	public static function template_loader( string $template ): string {
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
		if ( '' !== $excerpt && ! self::is_legacy_file_excerpt( $excerpt ) ) {
			if ( false === stripos( $excerpt, 'midi' ) || false === stripos( $excerpt, 'download' ) ) {
				$excerpt = sprintf(
					/* translators: %s: custom MIDI page excerpt. */
					__( 'MIDI file download: %s', 'kuhmann-midi-library' ),
					$excerpt
				);
			}
			return self::trim_summary( $excerpt, 260 );
		}

		$title    = get_the_title( $post_id );
		$folder   = self::get_post_folder_label( $post_id );
		$filesize = (int) get_post_meta( $post_id, 'kml_filesize', true );
		$mtime    = (int) get_post_meta( $post_id, 'kml_mtime', true );

		$summary = sprintf(
			/* translators: %s: MIDI file title. */
			__( 'Download the %s MIDI file from the Kuhmann / Disklavier World mirror.', 'kuhmann-midi-library' ),
			$title
		);

		if ( '' !== $folder ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: folder path. */
				__( 'This Yamaha Disklavier-ready MIDI download is filed under %s.', 'kuhmann-midi-library' ),
				$folder
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

		return self::trim_summary( $summary, 260 );
	}

	public static function get_folder_page_summary( WP_Term $term ): string {
		$count = self::term_count_including_children( $term, KML_Post_Types::TAX_FOLDER );
		$path  = self::get_term_name_path( $term );

		if ( $count > 0 ) {
			return sprintf(
				/* translators: 1: folder path, 2: number of MIDI files. */
				__( 'Browse and download MIDI files in the %1$s folder from the Kuhmann / Disklavier World mirror. Includes %2$s Yamaha Disklavier-ready MIDI downloads.', 'kuhmann-midi-library' ),
				$path,
				number_format_i18n( $count )
			);
		}

		return sprintf(
			/* translators: %s: folder path. */
			__( 'Browse MIDI file downloads in the %s folder from the Kuhmann / Disklavier World mirror.', 'kuhmann-midi-library' ),
			$path
		);
	}

	public static function filter_file_excerpt( string $excerpt, $post ): string {
		if ( ! ( $post instanceof WP_Post ) || KML_Post_Types::POST_TYPE !== $post->post_type ) {
			return $excerpt;
		}

		$excerpt = trim( $excerpt );
		if ( '' !== $excerpt && ! self::is_legacy_file_excerpt( $excerpt ) ) {
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

		$post = get_post( $post_id );
		if ( ! $post || KML_Post_Types::POST_TYPE !== $post->post_type ) {
			status_header( 404 );
			exit;
		}

		$abs = (string) get_post_meta( $post_id, 'kml_abspath', true );
		$rel = (string) get_post_meta( $post_id, 'kml_relpath', true );

		if ( empty( $abs ) || ! file_exists( $abs ) || ! is_readable( $abs ) ) {
			status_header( 404 );
			exit;
		}

		// Safety: ensure requested file is inside configured root.
		$root = KML_Indexer::get_root_path();
		$real_root = $root ? realpath( $root ) : '';
		$real_file = realpath( $abs );
		if ( ! $real_root || ! $real_file || 0 !== strpos( $real_file, $real_root ) ) {
			status_header( 403 );
			exit;
		}

		$filename = basename( $rel ? $rel : $abs );
		$filesize = (int) filesize( $real_file );

		nocache_headers();

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: audio/midi' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . $filesize );

		// Stream file.
		$fp = fopen( $real_file, 'rb' );
		if ( false === $fp ) {
			status_header( 500 );
			exit;
		}

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

	private static function get_post_folder_label( int $post_id ): string {
		$terms = get_the_terms( $post_id, KML_Post_Types::TAX_FOLDER );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return self::get_term_name_path( $terms[0] );
		}

		$folder_rel = trim( str_replace( '\\', '/', (string) get_post_meta( $post_id, 'kml_folder_rel', true ) ), '/' );
		return str_replace( '/', ' / ', $folder_rel );
	}

	private static function get_term_name_path( WP_Term $term ): string {
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

	private static function is_legacy_file_excerpt( string $excerpt ): bool {
		$excerpt = strtolower( wp_strip_all_tags( $excerpt ) );
		return false !== strpos( $excerpt, 'is a downloadable file resource' )
			&& false !== strpos( $excerpt, 'related instructions or context available on the site' );
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

	private static function trim_summary( string $summary, int $max_length ): string {
		$summary = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $summary ) ) );
		if ( '' === $summary || strlen( $summary ) <= $max_length ) {
			return $summary;
		}

		$trimmed = substr( $summary, 0, $max_length );
		$trimmed = preg_replace( '/\s+\S*$/', '', $trimmed );

		return rtrim( (string) $trimmed, " \t\n\r\0\x0B.,;:" ) . '.';
	}
}
