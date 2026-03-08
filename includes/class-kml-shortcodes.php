<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Shortcodes {

	public static function init(): void {
		add_shortcode( 'kml_library', array( __CLASS__, 'shortcode_library' ) );
	}

	public static function shortcode_library( array $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'folder'   => '', // folder term slug path, e.g. "classical/beethoven"
				'per_page' => 50,
			),
			$atts,
			'kml_library'
		);

		$folder = trim( (string) $atts['folder'] );
		$per_page = max( 10, min( 200, (int) $atts['per_page'] ) );

		ob_start();

		if ( $folder ) {
			// Support hierarchical term paths: "a/b/c"
			$term = self::get_term_by_path( $folder );
			if ( $term ) {
				self::render_folder( $term, $per_page );
			} else {
				echo '<p>' . esc_html__( 'Folder not found.', 'kuhmann-midi-library' ) . '</p>';
			}
		} else {
			self::render_root( $per_page );
		}

		return (string) ob_get_clean();
	}

	private static function render_root( int $per_page ): void {
		echo '<div class="kml-library">';
		echo '<h2>' . esc_html__( 'MIDI Library', 'kuhmann-midi-library' ) . '</h2>';

		self::render_search_box();

		$top = get_terms(
			array(
				'taxonomy'   => KML_Post_Types::TAX_FOLDER,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $top ) || empty( $top ) ) {
			echo '<p>' . esc_html__( 'No folders found yet. Configure the root path and run indexing.', 'kuhmann-midi-library' ) . '</p>';
		} else {
			echo '<ul class="kml-folders">';
			foreach ( $top as $t ) {
				$link = get_term_link( $t );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				echo '<li><a href="' . esc_url( $link ) . '">' . esc_html( $t->name ) . '</a>';
				echo ' <span class="kml-count">(' . esc_html( (string) (int) $t->count ) . ')</span></li>';
			}
			echo '</ul>';
		}

		// Optionally show newest MIDI files.
		$q = new WP_Query(
			array(
				'post_type'      => KML_Post_Types::POST_TYPE,
				'posts_per_page' => $per_page,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		if ( $q->have_posts() ) {
			echo '<h3>' . esc_html__( 'Newest MIDI Files', 'kuhmann-midi-library' ) . '</h3>';
			echo '<ul class="kml-files">';
			while ( $q->have_posts() ) {
				$q->the_post();
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
			}
			echo '</ul>';
			wp_reset_postdata();
		}

		echo '</div>';
	}

	private static function render_folder( WP_Term $term, int $per_page ): void {
		echo '<div class="kml-library">';
		echo '<h2>' . esc_html( $term->name ) . '</h2>';

		self::render_breadcrumbs( $term );
		self::render_search_box();

		$children = get_terms(
			array(
				'taxonomy'   => KML_Post_Types::TAX_FOLDER,
				'parent'     => (int) $term->term_id,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
			echo '<h3>' . esc_html__( 'Subfolders', 'kuhmann-midi-library' ) . '</h3>';
			echo '<ul class="kml-folders">';
			foreach ( $children as $child ) {
				$link = get_term_link( $child );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				echo '<li><a href="' . esc_url( $link ) . '">' . esc_html( $child->name ) . '</a>';
				echo ' <span class="kml-count">(' . esc_html( (string) (int) $child->count ) . ')</span></li>';
			}
			echo '</ul>';
		}

		// Files in this folder.
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$search = isset( $_GET['kml_q'] ) ? sanitize_text_field( wp_unslash( $_GET['kml_q'] ) ) : '';

		$args = array(
			'post_type'      => KML_Post_Types::POST_TYPE,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'post_status'    => 'publish',
			'tax_query'      => array(
				array(
					'taxonomy' => KML_Post_Types::TAX_FOLDER,
					'field'    => 'term_id',
					'terms'    => (int) $term->term_id,
				),
			),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		$q = new WP_Query( $args );

		echo '<h3>' . esc_html__( 'MIDI Files', 'kuhmann-midi-library' ) . '</h3>';
		if ( $q->have_posts() ) {
			echo '<ul class="kml-files">';
			while ( $q->have_posts() ) {
				$q->the_post();
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
			}
			echo '</ul>';

			// Pagination.
			$big = 999999999;
			echo '<div class="kml-pagination">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
						'format'    => '?paged=%#%',
						'current'   => $paged,
						'total'     => (int) $q->max_num_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			) . '</div>';

			wp_reset_postdata();
		} else {
			echo '<p>' . esc_html__( 'No MIDI files found in this folder.', 'kuhmann-midi-library' ) . '</p>';
		}

		echo '</div>';
	}

	private static function render_search_box(): void {
		$search = isset( $_GET['kml_q'] ) ? sanitize_text_field( wp_unslash( $_GET['kml_q'] ) ) : '';
		echo '<form class="kml-search" method="get">';
		// Keep existing query vars.
		foreach ( $_GET as $k => $v ) {
			if ( 'kml_q' === $k ) {
				continue;
			}
			if ( is_array( $v ) ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<input type="search" name="kml_q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search MIDI files…', 'kuhmann-midi-library' ) . '" />';
		echo '<button type="submit">' . esc_html__( 'Search', 'kuhmann-midi-library' ) . '</button>';
		echo '</form>';
	}

	private static function render_breadcrumbs( WP_Term $term ): void {
		$crumbs = array();
		$current = $term;

		while ( $current && ! is_wp_error( $current ) ) {
			$link = get_term_link( $current );
			if ( is_wp_error( $link ) ) {
				break;
			}
			$crumbs[] = array(
				'name' => $current->name,
				'url'  => $link,
			);

			if ( ! $current->parent ) {
				break;
			}
			$current = get_term( (int) $current->parent, KML_Post_Types::TAX_FOLDER );
		}

		$crumbs = array_reverse( $crumbs );
		array_unshift(
			$crumbs,
			array(
				'name' => __( 'MIDI Library', 'kuhmann-midi-library' ),
				'url'  => get_post_type_archive_link( KML_Post_Types::POST_TYPE ),
			)
		);

		echo '<nav class="kml-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'kuhmann-midi-library' ) . '">';
		$parts = array();
		foreach ( $crumbs as $c ) {
			$parts[] = '<a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['name'] ) . '</a>';
		}
		echo wp_kses_post( implode( ' <span class="kml-sep">/</span> ', $parts ) );
		echo '</nav>';
	}

	private static function get_term_by_path( string $path ): ?WP_Term {
		$path = trim( $path, "/ \t\n\r\0\x0B" );
		if ( '' === $path ) {
			return null;
		}

		$parts = array_filter( explode( '/', $path ) );
		$parent = 0;
		$term = null;

		foreach ( $parts as $slug ) {
			$slug = sanitize_title( $slug );
			$found = get_terms(
				array(
					'taxonomy'   => KML_Post_Types::TAX_FOLDER,
					'hide_empty' => false,
					'parent'     => $parent,
					'slug'       => $slug,
					'number'     => 1,
				)
			);
			if ( is_wp_error( $found ) || empty( $found ) ) {
				return null;
			}
			$term = $found[0];
			$parent = (int) $term->term_id;
		}

		return $term;
	}
}
