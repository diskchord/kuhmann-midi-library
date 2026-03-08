<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Post_Types {

	public const POST_TYPE = 'kml_midi';
	public const TAX_FOLDER = 'kml_folder';

	public static function register(): void {
		self::register_post_type();
		self::register_taxonomy();

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_sorting' ) );
	}

	private static function register_post_type(): void {
		$labels = array(
			'name'               => __( 'MIDI Files', 'kuhmann-midi-library' ),
			'singular_name'      => __( 'MIDI File', 'kuhmann-midi-library' ),
			'add_new_item'       => __( 'Add MIDI File', 'kuhmann-midi-library' ),
			'edit_item'          => __( 'Edit MIDI File', 'kuhmann-midi-library' ),
			'new_item'           => __( 'New MIDI File', 'kuhmann-midi-library' ),
			'view_item'          => __( 'View MIDI File', 'kuhmann-midi-library' ),
			'search_items'       => __( 'Search MIDI Files', 'kuhmann-midi-library' ),
			'not_found'          => __( 'No MIDI Files found', 'kuhmann-midi-library' ),
			'not_found_in_trash' => __( 'No MIDI Files found in Trash', 'kuhmann-midi-library' ),
			'all_items'          => __( 'MIDI Library', 'kuhmann-midi-library' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => $labels,
				'public'             => true,
				'has_archive'        => 'midi',
				'menu_icon'          => 'dashicons-format-audio',
				'show_in_rest'       => true,
				'supports'           => array( 'title', 'editor', 'excerpt' ),
				'rewrite'            => array(
					'slug'       => 'midi',
					'with_front' => false,
				),
				'taxonomies'         => array( self::TAX_FOLDER ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);
	}

	private static function register_taxonomy(): void {
		$labels = array(
			'name'              => __( 'Folders', 'kuhmann-midi-library' ),
			'singular_name'     => __( 'Folder', 'kuhmann-midi-library' ),
			'search_items'      => __( 'Search Folders', 'kuhmann-midi-library' ),
			'all_items'         => __( 'All Folders', 'kuhmann-midi-library' ),
			'parent_item'       => __( 'Parent Folder', 'kuhmann-midi-library' ),
			'parent_item_colon' => __( 'Parent Folder:', 'kuhmann-midi-library' ),
			'edit_item'         => __( 'Edit Folder', 'kuhmann-midi-library' ),
			'update_item'       => __( 'Update Folder', 'kuhmann-midi-library' ),
			'add_new_item'      => __( 'Add New Folder', 'kuhmann-midi-library' ),
			'new_item_name'     => __( 'New Folder Name', 'kuhmann-midi-library' ),
			'menu_name'         => __( 'Folders', 'kuhmann-midi-library' ),
		);

		register_taxonomy(
			self::TAX_FOLDER,
			array( self::POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $labels,
				'public'            => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'         => 'midi-folder',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}

	public static function columns( array $columns ): array {
		$columns['kml_folder']  = __( 'Folder', 'kuhmann-midi-library' );
		$columns['kml_relpath'] = __( 'Relative Path', 'kuhmann-midi-library' );
		$columns['kml_size']    = __( 'Size', 'kuhmann-midi-library' );
		return $columns;
	}

	public static function column_content( string $column, int $post_id ): void {
		if ( 'kml_folder' === $column ) {
			$terms = get_the_terms( $post_id, self::TAX_FOLDER );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				echo esc_html( self::term_path( $terms[0] ) );
			} else {
				echo '—';
			}
			return;
		}

		if ( 'kml_relpath' === $column ) {
			$rel = get_post_meta( $post_id, 'kml_relpath', true );
			echo esc_html( (string) $rel );
			return;
		}

		if ( 'kml_size' === $column ) {
			$size = (int) get_post_meta( $post_id, 'kml_filesize', true );
			echo esc_html( self::human_filesize( $size ) );
			return;
		}
	}

	public static function sortable_columns( array $columns ): array {
		$columns['kml_size'] = 'kml_size';
		return $columns;
	}

	public static function admin_sorting( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( 'kml_size' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', 'kml_filesize' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	public static function term_path( WP_Term $term ): string {
		$parts = array();
		$current = $term;
		while ( $current && ! is_wp_error( $current ) && $current->parent ) {
			$parts[] = $current->slug;
			$current = get_term( $current->parent, self::TAX_FOLDER );
		}
		if ( $current && ! is_wp_error( $current ) ) {
			$parts[] = $current->slug;
		}
		$parts = array_reverse( array_filter( $parts ) );
		return implode( '/', $parts );
	}

	private static function human_filesize( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '0 B';
		}
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$pow   = (int) floor( log( $bytes, 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );
		$val   = $bytes / ( 1024 ** $pow );
		return sprintf( '%s %s', number_format_i18n( $val, 1 ), $units[ $pow ] );
	}
}
