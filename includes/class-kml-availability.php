<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exclude unavailable MIDI entries before SQL counting and pagination. */
final class KML_Availability {

	/** Request-only caches: filesystem changes must be observed on the next request. */
	private static $available = array();
	private static $exclusions = array();

	public static function init(): void {
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_posts_clauses' ), 20, 2 );
	}

	public static function reset_cache(): void {
		self::$available = array();
		self::$exclusions = array();
	}

	public static function filter_posts_clauses( array $clauses, WP_Query $query ): array {
		if ( is_admin() || $query->is_singular() ) {
			return $clauses;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( KML_Post_Types::POST_TYPE, $types, true ) && ! in_array( 'any', $types, true )
			&& ! ( empty( $query->get( 'post_type' ) ) && ( $query->is_search() || $query->is_tax( KML_Post_Types::TAX_FOLDER ) ) ) ) {
			return $clauses;
		}

		global $wpdb;
		// Other post types in a mixed search retain their original visibility rules.
		$clauses['where'] .= $wpdb->prepare(
			" AND ({$wpdb->posts}.post_type <> %s OR ({$wpdb->posts}.post_status = 'publish' AND {$wpdb->posts}.post_password = ''))",
			KML_Post_Types::POST_TYPE
		);
		$ids = self::get_unavailable_ids( $clauses['join'], $clauses['where'] );
		if ( $ids ) {
			$clauses['where'] .= " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		return $clauses;
	}

	/**
	 * Validate only indexed entries matching a query, using bounded database reads.
	 * The optional clauses come from WP_Query, never directly from request input.
	 * With no clauses, return exclusions for all published MIDI sitemap candidates.
	 */
	public static function get_unavailable_ids( string $join = '', string $where = '' ): array {
		global $wpdb;
		if ( '' === $join && '' === $where ) {
			$where = " AND {$wpdb->posts}.post_status = 'publish'";
		}
		$key = md5( $join . "\n" . $where );
		if ( isset( self::$exclusions[ $key ] ) ) {
			return self::$exclusions[ $key ];
		}

		$unavailable = array();
		$last_id = 0;
		$batch_size = 500;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT {$wpdb->posts}.ID, {$wpdb->posts}.post_status, {$wpdb->posts}.post_password
					FROM {$wpdb->posts} {$join}
					WHERE 1=1 {$where} AND {$wpdb->posts}.post_type = %s AND {$wpdb->posts}.ID > %d
					ORDER BY {$wpdb->posts}.ID ASC LIMIT %d",
					KML_Post_Types::POST_TYPE,
					$last_id,
					$batch_size
				)
			);
			if ( ! $rows ) {
				break;
			}

			$unchecked = array();
			foreach ( $rows as $row ) {
				$id = (int) $row->ID;
				$last_id = $id;
				if ( 'publish' !== $row->post_status || '' !== $row->post_password ) {
					self::$available[ $id ] = false;
				} elseif ( ! isset( self::$available[ $id ] ) ) {
					$unchecked[] = $id;
				}
			}

			if ( $unchecked ) {
				// Load only path metadata, without hydrating hundreds of full posts.
				$metadata = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_value FROM {$wpdb->postmeta}
						WHERE meta_key = %s AND post_id IN (" . implode( ',', $unchecked ) . ') ORDER BY meta_id ASC',
						'kml_abspath'
					)
				);
				$paths = array();
				foreach ( (array) $metadata as $meta ) {
					// Match get_post_meta(..., true) if legacy duplicate rows exist.
					if ( ! isset( $paths[ (int) $meta->post_id ] ) ) {
						$paths[ (int) $meta->post_id ] = (string) $meta->meta_value;
					}
				}
				foreach ( $unchecked as $id ) {
					self::$available[ $id ] = '' !== KML_Public::resolve_library_file( $paths[ $id ] ?? '' );
				}
			}

			foreach ( $rows as $row ) {
				if ( ! self::$available[ (int) $row->ID ] ) {
					$unavailable[] = (int) $row->ID;
				}
			}
		} while ( count( $rows ) === $batch_size );

		self::$exclusions[ $key ] = $unavailable;
		return $unavailable;
	}
}
