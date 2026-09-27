<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep missing MIDI files out of core and Yoast sitemaps before pagination. */
final class KML_Sitemaps {

	public static function init(): void {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_core_query_args' ), 20, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'filter_yoast_excluded_ids' ), 20 );
		add_filter( 'wpseo_posts_where', array( __CLASS__, 'filter_yoast_where' ), 20, 2 );
		add_filter( 'wpseo_typecount_where', array( __CLASS__, 'filter_yoast_where' ), 20, 2 );
		add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'filter_yoast_entry' ), 20, 3 );
		add_filter( 'wpseo_enable_xml_sitemap_transient_caching', array( __CLASS__, 'filter_yoast_cache' ), 20 );
	}

	/** Core uses the same query arguments for URL pages and the sitemap page count. */
	public static function filter_core_query_args( array $args, string $post_type ): array {
		if ( KML_Post_Types::POST_TYPE !== $post_type ) {
			return $args;
		}

		$excluded = self::merge_ids(
			isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(),
			KML_Availability::get_unavailable_ids()
		);
		$args['post__not_in'] = $excluded;
		$args['has_password'] = false;

		// WP_Query prioritizes post__in over post__not_in. Respect a caller's
		// allowlist while still removing unavailable or explicitly excluded IDs.
		if ( ! empty( $args['post__in'] ) ) {
			$included = array_values( array_diff( array_map( 'absint', (array) $args['post__in'] ), $excluded ) );
			$args['post__in'] = $included ?: array( 0 );
		}

		return $args;
	}

	public static function filter_yoast_excluded_ids( $excluded ): array {
		return self::merge_ids( (array) $excluded, KML_Availability::get_unavailable_ids() );
	}

	/** Yoast's ID exclusion hook runs after LIMIT, so filter its SQL and count too. */
	public static function filter_yoast_where( $where, string $post_type ) {
		if ( KML_Post_Types::POST_TYPE !== $post_type ) {
			return $where;
		}

		$excluded = self::merge_ids( array(), KML_Availability::get_unavailable_ids() );
		if ( ! $excluded ) {
			return $where;
		}

		global $wpdb;
		// IDs are normalized integers; the table identifier comes from WordPress.
		return (string) $where . ' AND ' . $wpdb->posts . '.ID NOT IN (' . implode( ',', $excluded ) . ')';
	}

	/** Final guard if a file disappears while a sitemap is being generated. */
	public static function filter_yoast_entry( $url, string $type, $object ) {
		if ( ! empty( $url ) && 'post' === $type && is_object( $object )
			&& isset( $object->ID, $object->post_type ) && KML_Post_Types::POST_TYPE === $object->post_type
			&& '' === KML_Public::get_downloadable_file( (int) $object->ID ) ) {
			return false;
		}
		return $url;
	}

	/**
	 * Files can change without a WordPress save event. Rebuild only the MIDI
	 * sitemap and its index; other Yoast sitemap caches retain their setting.
	 * Yoast also calls this filter at init, before a sitemap request is known,
	 * and calls it again before reading its XML cache after parsing the query.
	 */
	public static function filter_yoast_cache( $enabled ): bool {
		global $wp_query;
		if ( ! ( $wp_query instanceof WP_Query ) ) {
			return (bool) $enabled;
		}

		$type = (string) get_query_var( 'sitemap', '' );
		if ( in_array( $type, array( '1', KML_Post_Types::POST_TYPE ), true ) ) {
			return false;
		}
		return (bool) $enabled;
	}

	private static function merge_ids( array $existing, array $additional ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', array_merge( $existing, $additional ) ) ) ) );
	}
}
