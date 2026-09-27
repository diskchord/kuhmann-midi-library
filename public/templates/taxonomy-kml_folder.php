<?php
/**
 * Template: Folder archive (taxonomy kml_folder)
 *
 * You can override this template by copying it to your theme:
 * taxonomy-kml_folder.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$term = get_queried_object();
if ( ! ( $term instanceof WP_Term ) ) {
	get_footer();
	exit;
}

?>
<main id="primary" class="site-main kml-folder">
	<header class="kml-folder-header">
		<h1><?php echo esc_html( single_term_title( '', false ) ); ?></h1>

		<nav class="kml-breadcrumbs" aria-label="<?php echo esc_attr__( 'Breadcrumbs', 'kuhmann-midi-library' ); ?>">
			<?php
			$crumbs = array();
			$current = $term;
			while ( $current && ! is_wp_error( $current ) ) {
				$link = get_term_link( $current );
				if ( is_wp_error( $link ) ) {
					break;
				}
				$crumbs[] = array( 'name' => $current->name, 'url' => $link );
				if ( ! $current->parent ) {
					break;
				}
				$current = get_term( (int) $current->parent, KML_Post_Types::TAX_FOLDER );
			}
			$crumbs = array_reverse( $crumbs );
			?>
			<a href="<?php echo esc_url( get_post_type_archive_link( KML_Post_Types::POST_TYPE ) ); ?>"><?php echo esc_html__( 'MIDI Library', 'kuhmann-midi-library' ); ?></a>
			<?php foreach ( $crumbs as $c ) : ?>
				<span class="kml-sep">/</span>
				<a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		$desc = term_description( $term );
		if ( $desc ) {
			echo '<div class="kml-term-description">' . wp_kses_post( $desc ) . '</div>';
		} else {
			echo '<p class="kml-term-description">' . esc_html( KML_Public::get_folder_page_summary( $term ) ) . '</p>';
		}
		?>
	</header>

	<section class="kml-folder-search">
		<?php
		$search = isset( $_GET['kml_q'] ) ? sanitize_text_field( wp_unslash( $_GET['kml_q'] ) ) : '';
		?>
		<form class="kml-search" method="get">
			<input type="search" name="kml_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search in this folder…', 'kuhmann-midi-library' ); ?>" />
			<button type="submit"><?php echo esc_html__( 'Search', 'kuhmann-midi-library' ); ?></button>
		</form>
	</section>

	<section class="kml-subfolders">
		<?php
		$children = get_terms(
			array(
				'taxonomy'   => KML_Post_Types::TAX_FOLDER,
				'parent'     => (int) $term->term_id,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 1000,
			)
		);
		if ( ! is_wp_error( $children ) && ! empty( $children ) ) :
		?>
			<h2><?php echo esc_html__( 'Subfolders', 'kuhmann-midi-library' ); ?></h2>
			<ul class="kml-folders">
				<?php foreach ( $children as $child ) : ?>
					<li>
						<a href="<?php echo esc_url( get_term_link( $child ) ); ?>"><?php echo esc_html( $child->name ); ?></a>
						<span class="kml-count">(<?php echo esc_html( (string) (int) $child->count ); ?>)</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="kml-files-list">
		<h2><?php echo esc_html__( 'MIDI Files', 'kuhmann-midi-library' ); ?></h2>
		<?php
		$paged = max( 1, (int) get_query_var( 'paged' ) );

		$args = array(
			'post_type'      => KML_Post_Types::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'paged'          => $paged,
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

		$show_details = '' !== $search;
		$q = new WP_Query( $args );

		if ( $q->have_posts() ) :
		?>
			<ul class="kml-files<?php echo $show_details ? ' kml-files-detailed' : ''; ?>">
				<?php while ( $q->have_posts() ) : $q->the_post(); ?>
					<?php KML_Public::render_file_list_item( (int) get_the_ID(), $show_details ); ?>
				<?php endwhile; ?>
			</ul>

			<?php
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
			?>

		<?php else : ?>
			<p><?php echo esc_html__( 'No MIDI files found in this folder.', 'kuhmann-midi-library' ); ?></p>
		<?php endif; ?>
	</section>

</main>
<?php
get_footer();
