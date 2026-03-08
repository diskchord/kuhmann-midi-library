<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Public {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_download' ) );

		add_filter( 'template_include', array( __CLASS__, 'template_loader' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
			$file_url = $post_id ? get_post_meta( $post_id, 'kml_file_url', true ) : '';
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

		while ( ! feof( $fp ) ) {
			echo fread( $fp, 8192 );
			@flush();
		}
		fclose( $fp );
		exit;
	}
}
