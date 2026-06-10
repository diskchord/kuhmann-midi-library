<?php
/**
 * Template: MIDI Library archive
 *
 * You can override this template by copying it to your theme:
 * archive-kml_midi.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kml_term_count_including_children( WP_Term $term, string $taxonomy ): int {
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

get_header();

$search = isset( $_GET['kml_q'] ) ? sanitize_text_field( wp_unslash( $_GET['kml_q'] ) ) : '';

?>
<main id="primary" class="site-main kml-archive">
	<header class="kml-archive-header">
		<h1><?php echo esc_html__( 'Kuhmann / Disklavier World MIDI Mirror', 'kuhmann-midi-library' ); ?></h1>

		<div class="kml-term-description"><b>This is a curated, Disklavier-ready mirror of the famous Kuhmann / Disklavier World MIDI directory, reorganized and relabeled for Yamaha Disklavier systems.</b><br /><br />You can read more about this <a href="https://www.alexanderpeppe.com/kuhmann-disklavier-world/">famous directory on my Kuhmann Directory (Disklavier World) page</a>. I have modified this archive slightly: All titles have been standardized. In the original collection, many pieces were untitled. No other modifications have been made. You can download the <a href="https://cloud.alexanderpeppe.com/s/cxMBwp3pndize7m">original, unmodified archive here</a>.<br /><br />Although I do not profit from this archive directly and I am sharing it in good faith as a preservation and compatibility resource, I do not know what permissions were obtained for the underlying copyrighted works in the original collection. If you are a copyright holder and would like anything removed, please don't hesitate to contact me. My <a href="https://www.alexanderpeppe.com/dmca-policy/">DMCA Policy is available here</a>.<br /><br />Please also see my other <a href="https://www.alexanderpeppe.com/midi-sources/">recommendations for free MIDI files</a>, along with sources where you can <a href="https://www.alexanderpeppe.com/premium-songs-for-your-disklavier">purchase MIDI songs for your Disklavier</a>. You can also download the <a href="https://cloud.alexanderpeppe.com/s/F2sYD2bmMFsw7HE">entire Kuhmann Directory at once from my file server</a>. <i>If using a Disklavier ENSPIRE, the collection should be separated into multiple smaller USB sticks, with generally no more than 1000 songs to a USB stick.</i></div>
	</header>

	<section class="kml-archive-search">
		<form class="kml-search" method="get">
			<input type="search" name="kml_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search all MIDI files…', 'kuhmann-midi-library' ); ?>" />
			<button type="submit"><?php echo esc_html__( 'Search', 'kuhmann-midi-library' ); ?></button>
		</form>
	</section>

	<section class="kml-archive-folders">
		<h2><?php echo esc_html__( 'Browse by Folder', 'kuhmann-midi-library' ); ?></h2>
		<?php
		$top = get_terms(
			array(
				'taxonomy'   => KML_Post_Types::TAX_FOLDER,
				'parent'     => 0,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_wp_error( $top ) && ! empty( $top ) ) :
		?>
			<ul class="kml-folders">
				<?php foreach ( $top as $t ) : ?>
					<li>
						<a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?></a>

<?php $cnt = kml_term_count_including_children( $t, KML_Post_Types::TAX_FOLDER ); ?>
<span class="kml-count">(<?php echo esc_html( (string) $cnt ); ?>)</span>

					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php echo esc_html__( 'No folders found yet. Configure the root path and run indexing.', 'kuhmann-midi-library' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="kml-archive-results">
		<?php
		$args = array(
			'post_type'      => KML_Post_Types::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $search ) {
			$args['s'] = $search;
			echo '<h2>' . esc_html__( 'Search Results', 'kuhmann-midi-library' ) . '</h2>';
		/*} else {
			echo '<h2>' . esc_html__( 'Newest MIDI Files', 'kuhmann-midi-library' ) . '</h2>';
			}*/

		$q = new WP_Query( $args );
		if ( $q->have_posts() ) :
		?>
			<ul class="kml-files kml-files-detailed">
				<?php while ( $q->have_posts() ) : $q->the_post(); ?>
					<?php KML_Public::render_file_list_item( (int) get_the_ID(), true ); ?>
				<?php endwhile; ?>
			</ul>
			<?php wp_reset_postdata(); ?>
		<?php else : ?>
			<p><?php echo esc_html__( 'No MIDI files found.', 'kuhmann-midi-library' ); ?></p>
		<?php endif; } ?>
	</section>

</main>
<?php
get_footer();
