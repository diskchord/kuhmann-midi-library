<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Search metadata for permanent library pages; robots policy belongs to the site. */
final class KML_SEO {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_metadata' ), 5 );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title_parts' ) );
		add_filter( 'wpseo_title', array( __CLASS__, 'filter_yoast_title' ) );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'filter_description' ) );
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'filter_yoast_schema' ), 10, 2 );
	}

	public static function is_library_page(): bool {
		if ( is_admin() || is_404() || is_search() || is_preview() || is_feed() || is_embed()
			|| KML_Public::is_direct_player_request() || get_query_var( 'kml_download' )
			|| ( isset( $_GET['kml_q'] ) && ( ! is_string( $_GET['kml_q'] ) || '' !== trim( $_GET['kml_q'] ) ) ) ) {
			return false;
		}

		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			$post = get_queried_object();
			return $post instanceof WP_Post && '' !== KML_Public::get_downloadable_file( (int) $post->ID );
		}

		return is_post_type_archive( KML_Post_Types::POST_TYPE ) || is_tax( KML_Post_Types::TAX_FOLDER );
	}

	public static function get_title(): string {
		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			/* translators: %s: MIDI file title. */
			return sprintf( __( '%s — MIDI download', 'kuhmann-midi-library' ), get_the_title( get_queried_object_id() ) );
		}
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			/* translators: %s: folder name. */
			return sprintf( __( '%s — MIDI files', 'kuhmann-midi-library' ), $term->name );
		}
		return __( 'Kuhmann / Disklavier World MIDI Library', 'kuhmann-midi-library' );
	}

	public static function filter_document_title_parts( array $parts ): array {
		if ( self::is_library_page() ) {
			$parts['title'] = self::get_title();
		}
		return $parts;
	}

	public static function filter_yoast_title( string $title ): string {
		if ( ! self::is_library_page() ) {
			return $title;
		}
		// Keep titles explicitly edited in Yoast. Archive templates remain Yoast-owned.
		if ( is_post_type_archive( KML_Post_Types::POST_TYPE )
			|| ( is_singular( KML_Post_Types::POST_TYPE ) && get_post_meta( get_queried_object_id(), '_yoast_wpseo_title', true ) ) ) {
			return $title;
		}
		$term = get_queried_object();
		if ( $term instanceof WP_Term && class_exists( 'WPSEO_Taxonomy_Meta' )
			&& WPSEO_Taxonomy_Meta::get_term_meta( $term, KML_Post_Types::TAX_FOLDER, 'title' ) ) {
			return $title;
		}
		$title = self::get_title();
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			/* translators: %s: page number. */
			$title .= ' — ' . sprintf( __( 'Page %s', 'kuhmann-midi-library' ), number_format_i18n( $paged ) );
		}
		$site = get_bloginfo( 'name' );
		return $site ? $title . ' | ' . $site : $title;
	}

	public static function filter_description( $description ): string {
		if ( ! self::is_library_page() || '' !== trim( (string) $description ) ) {
			return (string) $description;
		}
		return KML_Public::trim_summary( KML_Public::get_current_page_summary(), 180 );
	}

	public static function get_canonical_url(): string {
		if ( ! self::is_library_page() ) {
			return '';
		}
		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			return (string) wp_get_canonical_url( get_queried_object_id() );
		}
		$url = is_tax( KML_Post_Types::TAX_FOLDER )
			? get_term_link( get_queried_object() )
			: get_post_type_archive_link( KML_Post_Types::POST_TYPE );
		if ( ! $url || is_wp_error( $url ) ) {
			return '';
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			global $wp_rewrite;
			$url = $wp_rewrite->using_permalinks()
				? trailingslashit( $url ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' )
				: add_query_arg( 'paged', $paged, $url );
		}
		return $url;
	}

	/** Defer head tags to SEO plugins so descriptions and canonicals are not duplicated. */
	public static function has_seo_plugin(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
	}

	public static function print_metadata(): void {
		if ( self::has_seo_plugin() ) {
			return;
		}
		if ( ! self::is_library_page() ) {
			// Retain the direct player's existing description, without library schema.
			if ( KML_Public::is_direct_player_request() ) {
				KML_Public::print_meta_description();
			}
			return;
		}
		$description = self::filter_description( '' );
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		// WordPress already outputs singular canonical URLs through rel_canonical().
		$url = self::get_canonical_url();
		if ( $url && ! is_singular() ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		}
		$graph = self::get_schema_graph( $url );
		if ( $graph ) {
			echo '<script type="application/ld+json">' . wp_json_encode(
				array( '@context' => 'https://schema.org', '@graph' => $graph ),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . '</script>' . "\n";
		}
	}

	public static function get_schema_graph( string $canonical = '' ): array {
		if ( ! self::is_library_page() ) {
			return array();
		}
		$url = $canonical ?: self::get_canonical_url();
		if ( ! $url ) {
			return array();
		}
		$single = is_singular( KML_Post_Types::POST_TYPE );
		$page = array(
			'@type'       => $single ? 'WebPage' : 'CollectionPage',
			'@id'         => $url . '#webpage',
			'url'         => $url,
			'name'        => self::schema_text( self::get_title() ),
			'description' => self::filter_description( '' ),
		);
		$graph = array();
		$crumbs = self::get_breadcrumbs();
		if ( count( $crumbs ) >= 2 ) {
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $crumbs,
			);
		}
		if ( $single ) {
			$post_id = (int) get_queried_object_id();
			$audio = array(
				'@type'               => 'AudioObject',
				'@id'                 => $url . '#midi',
				'url'                 => $url,
				'name'                => self::schema_text( get_the_title( $post_id ) ),
				'description'         => $page['description'],
				'encodingFormat'      => 'audio/midi',
				'isAccessibleForFree' => true,
				'mainEntityOfPage'    => array( '@id' => $page['@id'] ),
			);
			if ( '' !== KML_Public::get_downloadable_file( $post_id ) ) {
				$audio['contentUrl'] = KML_Public::get_download_url( $post_id );
			}
			$size = (int) get_post_meta( $post_id, 'kml_filesize', true );
			if ( $size > 0 ) {
				$audio['contentSize'] = size_format( $size );
			}
			$page['mainEntity'] = array( '@id' => $audio['@id'] );
			$graph[] = $audio;
		}
		array_unshift( $graph, $page );
		return $graph;
	}

	/** Extend Yoast's existing page graph instead of publishing competing page entities. */
	public static function filter_yoast_schema( array $graph, $context = null ): array {
		$canonical = isset( $context->canonical ) ? (string) $context->canonical : '';
		$library = self::get_schema_graph( $canonical );
		if ( ! $library ) {
			return $graph;
		}
		$page = array_shift( $library );
		$page_id = '';
		$breadcrumb_id = isset( $page['breadcrumb']['@id'] ) ? $page['breadcrumb']['@id'] : '';
		foreach ( $graph as &$piece ) {
			$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
			if ( array_intersect( array( 'WebPage', 'CollectionPage' ), $types ) && ! empty( $piece['@id'] ) ) {
				$page_id = $piece['@id'];
				if ( $breadcrumb_id && ! empty( $piece['breadcrumb']['@id'] ) ) {
					$breadcrumb_id = $piece['breadcrumb']['@id'];
					$page['breadcrumb']['@id'] = $breadcrumb_id;
				}
				if ( empty( $piece['description'] ) ) {
					$piece['description'] = $page['description'];
				}
				foreach ( array( 'mainEntity', 'breadcrumb' ) as $key ) {
					if ( isset( $page[ $key ] ) ) {
						$piece[ $key ] = $page[ $key ];
					}
				}
				break;
			}
		}
		unset( $piece );
		// Do not restore an intentionally disabled graph or reference a missing page.
		if ( '' === $page_id ) {
			return $graph;
		}
		foreach ( $library as $piece ) {
			if ( 'BreadcrumbList' === $piece['@type'] ) {
				$piece['@id'] = $breadcrumb_id;
			}
			if ( isset( $piece['mainEntityOfPage'] ) ) {
				$piece['mainEntityOfPage'] = array( '@id' => $page_id );
			}
			foreach ( $graph as $key => $existing ) {
				if ( isset( $existing['@id'] ) && $existing['@id'] === $piece['@id'] ) {
					unset( $graph[ $key ] );
				}
			}
			$graph[] = $piece;
		}
		return array_values( $graph );
	}

	private static function get_breadcrumbs(): array {
		$items = array();
		$archive = get_post_type_archive_link( KML_Post_Types::POST_TYPE );
		if ( $archive ) {
			$items[] = array( 'name' => __( 'MIDI Library', 'kuhmann-midi-library' ), 'item' => $archive );
		}
		$term = get_queried_object();
		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			$terms = get_the_terms( get_queried_object_id(), KML_Post_Types::TAX_FOLDER );
			$term = is_array( $terms ) && $terms ? $terms[0] : null;
		}
		if ( $term instanceof WP_Term ) {
			$ids = array_reverse( get_ancestors( $term->term_id, KML_Post_Types::TAX_FOLDER, 'taxonomy' ) );
			$ids[] = $term->term_id;
			foreach ( $ids as $id ) {
				$folder = get_term( $id, KML_Post_Types::TAX_FOLDER );
				if ( ! $folder || is_wp_error( $folder ) ) {
					continue;
				}
				$link = get_term_link( $folder );
				if ( ! is_wp_error( $link ) ) {
					$items[] = array( 'name' => $folder->name, 'item' => $link );
				}
			}
		}
		if ( is_singular( KML_Post_Types::POST_TYPE ) ) {
			$items[] = array( 'name' => get_the_title( get_queried_object_id() ), 'item' => get_permalink( get_queried_object_id() ) );
		}
		foreach ( $items as $index => &$item ) {
			$item['name'] = self::schema_text( $item['name'] );
			$item['@type'] = 'ListItem';
			$item['position'] = $index + 1;
		}
		unset( $item );
		return $items;
	}

	private static function schema_text( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
