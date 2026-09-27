<?php
/**
 * Listing/search/sitemap regressions with real files and a recording wpdb fixture.
 * Run: php tests/availability.php
 *
 * SQL assertions cover predicates before pagination and preservation of the query
 * assembled by WordPress. The fixture implements only the SQL used by this suite;
 * checking real WP_Query/MySQL execution still requires a WordPress installation.
 */
define( 'KML_TEST_FIXTURES_ONLY', true );
require __DIR__ . '/seo.php';
require KML_PLUGIN_DIR . 'includes/class-kml-availability.php';
require KML_PLUGIN_DIR . 'includes/class-kml-sitemaps.php';

/** Return integer IDs from a SQL IN list; fixture queries contain no subqueries. */
function fixture_sql_ids( $sql, $column, $operator = 'IN' ) {
	$pattern = '/\b' . preg_quote( $column, '/' ) . '\s+' . preg_quote( $operator, '/' ) . '\s*\(([\d,\s]+)\)/i';
	if ( ! preg_match( $pattern, $sql, $match ) ) {
		return array();
	}
	return array_map( 'intval', explode( ',', $match[1] ) );
}

class KML_Test_DB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $queries = array();
	public function prepare( $sql, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$index = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $match ) use ( $args, &$index ) {
				$value = $args[ $index++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			},
			$sql
		);
	}
	public function get_results( $sql ) {
		$this->queries[] = $sql;
		if ( contains( 'FROM wp_postmeta', $sql ) ) {
			$ids = fixture_sql_ids( $sql, 'post_id' );
			return array_values( array_map( static function ( $row ) { return (object) $row; }, array_filter( $GLOBALS['db_meta_rows'], static function ( $row ) use ( $ids ) { return in_array( $row['post_id'], $ids, true ); } ) ) );
		}
		if ( ! contains( 'FROM wp_posts', $sql ) ) {
			throw new RuntimeException( 'Unexpected database operation: ' . $sql );
		}
		$rows = array_values( $GLOBALS['fixture']['posts_by_id'] );
		$included = fixture_sql_ids( $sql, 'wp_posts.ID' );
		$excluded = fixture_sql_ids( $sql, 'wp_posts.ID', 'NOT IN' );
		$terms = fixture_sql_ids( $sql, 'term_taxonomy_id' );
		preg_match( '/wp_posts\.ID > (\d+)/', $sql, $minimum );
		preg_match( '/LIMIT (\d+)\s*$/', $sql, $limit );
		preg_match( "/wp_posts\\.post_title LIKE '%([^']+)%'/", $sql, $search );
		$rows = array_filter(
			$rows,
			static function ( $post ) use ( $sql, $included, $excluded, $terms, $minimum, $search ) {
				if ( 'kml_midi' !== $post->post_type || $post->ID <= (int) ( $minimum[1] ?? 0 ) ) {
					return false;
				}
				if ( contains( "wp_posts.post_status = 'publish'", $sql ) && 'publish' !== $post->post_status ) {
					return false;
				}
				if ( contains( "wp_posts.post_password = ''", $sql ) && '' !== $post->post_password ) {
					return false;
				}
				if ( ( $included && ! in_array( $post->ID, $included, true ) ) || in_array( $post->ID, $excluded, true ) ) {
					return false;
				}
				if ( $terms && ! array_intersect( $terms, $GLOBALS['db_terms'][ $post->ID ] ?? array() ) ) {
					return false;
				}
				return ! $search || false !== stripos( $post->post_title, $search[1] );
			}
		);
		usort( $rows, static function ( $left, $right ) { return $left->ID <=> $right->ID; } );
		return array_slice( $rows, 0, (int) ( $limit[1] ?? count( $rows ) ) );
	}
}

function availability_fixture() {
	reset_fixture( 'archive' );
	KML_Availability::reset_cache();
	$GLOBALS['wpdb'] = new KML_Test_DB();
	$GLOBALS['fixture']['posts_by_id'] = array();
	$GLOBALS['db_meta_rows'] = array();
	$GLOBALS['db_terms'] = array();
	$records = array(
		7  => array( 'Clair de lune', $GLOBALS['midi_fixture_path'], 11 ),
		8  => array( 'Absent étude', $GLOBALS['midi_fixture_root'] . '/absent.mid', 11 ),
		9  => array( 'Nocturne one', $GLOBALS['midi_fixture_path'], 12 ),
		10 => array( 'Nocturne missing', $GLOBALS['midi_fixture_root'] . '/missing.mid', 12 ),
		11 => array( 'Nocturne without metadata', null, 11 ),
		12 => array( 'Nocturne password protected', $GLOBALS['midi_fixture_path'], 11 ),
		13 => array( 'Nocturne outside root', $GLOBALS['midi_fixture_dir'] . '/library-other/outside.mid', 12 ),
		14 => array( 'Nocturne directory', $GLOBALS['midi_fixture_root'], 11 ),
		15 => array( 'Nocturne two', $GLOBALS['midi_fixture_path'], 11 ),
		16 => array( 'Private MIDI', $GLOBALS['midi_fixture_path'], 11 ),
		17 => array( 'Unrelated blog post', null, 11 ),
		18 => array( 'Draft MIDI', null, 11 ),
	);
	foreach ( $records as $id => $record ) {
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_title = $record[0];
		$post->post_password = 12 === $id ? 'secret' : '';
		$post->post_status = 16 === $id ? 'private' : ( 18 === $id ? 'draft' : 'publish' );
		$post->post_type = 17 === $id ? 'post' : 'kml_midi';
		$GLOBALS['fixture']['posts_by_id'][ $id ] = $post;
		$GLOBALS['db_terms'][ $id ] = array( $record[2] );
		if ( null !== $record[1] ) {
			$GLOBALS['fixture']['meta'][ $id ]['kml_abspath'] = $record[1];
			$GLOBALS['db_meta_rows'][] = array( 'post_id' => $id, 'meta_value' => $record[1] );
		}
	}
}

function listing_clauses() {
	return array(
		'fields' => 'wp_posts.*',
		'join' => '',
		'where' => " AND wp_posts.post_status = 'publish'",
		'groupby' => '',
		'orderby' => 'wp_posts.ID ASC',
		'distinct' => '',
		'limits' => 'LIMIT 0, 2',
	);
}

function filtered_query_ids( array $clauses, array $candidates ) {
	$excluded = fixture_sql_ids( $clauses['where'], 'wp_posts.ID', 'NOT IN' );
	return array_values( array_filter( $candidates, static function ( $id ) use ( $excluded ) {
		$post = $GLOBALS['fixture']['posts_by_id'][ $id ];
		return ! in_array( $id, $excluded, true ) && ( 'kml_midi' !== $post->post_type || ( 'publish' === $post->post_status && '' === $post->post_password ) );
	} ) );
}

availability_fixture();
KML_Availability::init();
KML_Sitemaps::init();
check( has_filter( 'posts_clauses', array( 'KML_Availability', 'filter_posts_clauses' ) ), 'Availability filtering must run on SQL clauses before pagination.' );
check( ! has_filter( 'the_posts' ) && ! has_filter( 'posts_results' ), 'Unavailable posts must not be removed only after pagination.' );

// Published candidates are checked against their real filesystem paths in batches.
$expected_unavailable = array( 8, 10, 11, 12, 13, 14 );
check( $expected_unavailable === KML_Availability::get_unavailable_ids(), 'Missing, protected, pathless, outside-root and directory entries must be excluded.' );
check( 2 === count( $GLOBALS['wpdb']->queries ), 'A small candidate set should use one post query and one path-metadata query.' );
$query_count = count( $GLOBALS['wpdb']->queries );
check( $expected_unavailable === KML_Availability::get_unavailable_ids() && $query_count === count( $GLOBALS['wpdb']->queries ), 'Repeated checks in one request should reuse the same candidate results.' );
check( ! contains( 'wp_posts.*', $GLOBALS['wpdb']->queries[0] ), 'Availability scans must avoid loading full post bodies.' );
check( contains( "meta_key = 'kml_abspath'", $GLOBALS['wpdb']->queries[1] ), 'Availability scans should load only file-path metadata.' );

// Preserve existing search/tax/order clauses while removing IDs before LIMIT.
$query = new WP_Query( array( 'post_type' => 'kml_midi', 'posts_per_page' => 2 ) );
$query->is_post_type_archive = true;
$original = listing_clauses();
$filtered = KML_Availability::filter_posts_clauses( $original, $query );
foreach ( array( 'fields', 'join', 'groupby', 'orderby', 'distinct', 'limits' ) as $key ) {
	check( $original[ $key ] === $filtered[ $key ], "Filtering must preserve the query's $key clause." );
}
check( 0 === strpos( $filtered['where'], $original['where'] ), 'Existing WHERE conditions must be retained.' );
check( array( 8, 10, 11, 13, 14 ) === fixture_sql_ids( $filtered['where'], 'wp_posts.ID', 'NOT IN' ), 'Unavailable IDs must be added to WHERE before WordPress applies LIMIT.' );
$visible = filtered_query_ids( $filtered, array( 7, 8, 9, 10, 11, 12, 13, 14, 15 ) );
check( array( 7, 9, 15 ) === $visible && array( 7, 9 ) === array_slice( $visible, 0, 2 ) && array( 15 ) === array_slice( $visible, 2, 2 ), 'Removing unavailable rows before pagination must fill page one and retain the available file on page two.' );
check( 3 === count( $visible ) && 2 === (int) ceil( count( $visible ) / 2 ), 'The pre-LIMIT predicate must leave three results and two pages rather than counting missing files.' );

$scoped = listing_clauses();
$scoped['join'] = ' INNER JOIN wp_term_relationships ON (wp_posts.ID = wp_term_relationships.object_id)';
$scoped['where'] .= " AND wp_posts.post_title LIKE '%Nocturne%' AND wp_term_relationships.term_taxonomy_id IN (11)";
$scoped['groupby'] = 'wp_posts.ID';
$scoped['orderby'] = 'wp_posts.post_title ASC';
$query = new WP_Query( array( 'post_type' => '', 'taxonomy' => 'kml_folder', 's' => 'Nocturne' ) );
$query->is_tax = true;
$query->is_search = true;
$filtered = KML_Availability::filter_posts_clauses( $scoped, $query );
check( array( 11, 14 ) === fixture_sql_ids( $filtered['where'], 'wp_posts.ID', 'NOT IN' ), 'A folder search should inspect and exclude only matching candidates.' );
check( $scoped['join'] === $filtered['join'] && $scoped['groupby'] === $filtered['groupby'] && $scoped['orderby'] === $filtered['orderby'], 'Folder searches must retain their joins, grouping and order.' );
check( 0 === strpos( $filtered['where'], $scoped['where'] ), 'Folder and search terms must survive the availability predicate.' );

foreach ( array( 'explicit MIDI' => array( 'kml_midi', false ), 'mixed post types' => array( array( 'post', 'kml_midi' ), false ), 'any post type' => array( 'any', false ), 'site search' => array( '', true ) ) as $label => $args ) {
	$query = new WP_Query( array( 'post_type' => $args[0] ) );
	$query->is_search = $args[1];
	$filtered = KML_Availability::filter_posts_clauses( listing_clauses(), $query );
	check( contains( 'wp_posts.ID NOT IN', $filtered['where'] ), "$label queries must filter unavailable MIDI entries." );
	check( in_array( 17, filtered_query_ids( $filtered, array( 7, 8, 17 ) ), true ), "$label queries must preserve unrelated posts with no MIDI metadata." );
}
$query = new WP_Query( array( 'post_type' => 'kml_midi', 'feed' => 'rss2' ) );
$query->is_feed = true;
check( contains( 'wp_posts.ID NOT IN', KML_Availability::filter_posts_clauses( listing_clauses(), $query )['where'] ), 'MIDI feeds must exclude unavailable files too.' );

foreach ( array( 'admin', 'singular', 'unrelated' ) as $context ) {
	$query = new WP_Query( array( 'post_type' => 'unrelated' === $context ? 'post' : 'kml_midi' ) );
	$query->is_singular = 'singular' === $context;
	$GLOBALS['fixture']['flags']['admin'] = 'admin' === $context;
	$before = count( $GLOBALS['wpdb']->queries );
	check( listing_clauses() === KML_Availability::filter_posts_clauses( listing_clauses(), $query ), "$context queries must retain their clauses." );
	check( $before === count( $GLOBALS['wpdb']->queries ), "$context queries must not trigger filesystem candidate scans." );
}
$GLOBALS['fixture']['flags']['admin'] = false;

// Honor legacy duplicate metadata rows in the same order as get_post_meta(...,true).
availability_fixture();
$GLOBALS['db_meta_rows'][] = array( 'post_id' => 8, 'meta_value' => $midi_fixture_path );
$GLOBALS['db_meta_rows'][] = array( 'post_id' => 15, 'meta_value' => $midi_fixture_root . '/absent.mid' );
check( $expected_unavailable === KML_Availability::get_unavailable_ids(), 'Duplicate path metadata must use the first stored path consistently.' );

// Multiple bounded batches must not skip unavailable entries after the first 500.
availability_fixture();
for ( $id = 1000; $id < 1505; ++$id ) {
	$post = new WP_Post();
	$post->ID = $id;
	$GLOBALS['fixture']['posts_by_id'][ $id ] = $post;
	$GLOBALS['db_meta_rows'][] = array( 'post_id' => $id, 'meta_value' => 1504 === $id ? $midi_fixture_root . '/last-missing.mid' : $midi_fixture_path );
}
$batched_ids = KML_Availability::get_unavailable_ids();
check( in_array( 1504, $batched_ids, true ), 'Candidate scans must continue beyond the first 500 rows.' );
check( 4 === count( $GLOBALS['wpdb']->queries ), 'More than 500 candidates should use bounded post and metadata batches.' );
check( count( $batched_ids ) === count( array_unique( $batched_ids ) ), 'Batch boundaries must not duplicate unavailable IDs.' );

// A fresh request sees restored/deleted files; the final renderer checks races.
availability_fixture();
KML_Availability::get_unavailable_ids();
ob_start();
KML_Public::render_file_list_item( 7 );
$rendered = ob_get_clean();
check( contains( '<a ', $rendered ) && contains( 'Clair de lune', $rendered ), 'Available files must retain their linked list item.' );
foreach ( array( false, true ) as $details ) {
	ob_start();
	KML_Public::render_file_list_item( 8, $details );
	check( '' === ob_get_clean(), 'Unavailable files must not render a list item or permalink.' );
}
unlink( $midi_fixture_path );
clearstatcache();
ob_start();
KML_Public::render_file_list_item( 7 );
check( '' === ob_get_clean(), 'A file removed after the SQL scan must not leave a link in the final renderer.' );
KML_Availability::reset_cache();
check( in_array( 7, KML_Availability::get_unavailable_ids(), true ), 'A fresh request must observe a file deleted since the previous request.' );
file_put_contents( $midi_fixture_path, $midi_fixture_bytes );
clearstatcache();
KML_Availability::reset_cache();
check( ! in_array( 7, KML_Availability::get_unavailable_ids(), true ), 'A restored file must reappear on the next request without reindexing.' );

// Core sitemap URL lists and page counts share the same pre-pagination arguments.
availability_fixture();
check( has_filter( 'wp_sitemaps_posts_query_args', array( 'KML_Sitemaps', 'filter_core_query_args' ) ), 'Core sitemap query filtering must be registered.' );
$args = array( 'post_type' => 'kml_midi', 'post__not_in' => array( 99, 8 ), 'posts_per_page' => 2, 'paged' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' );
$filtered_args = KML_Sitemaps::filter_core_query_args( $args, 'kml_midi' );
check( array( 99, 8, 10, 11, 12, 13, 14 ) === $filtered_args['post__not_in'], 'Core sitemap exclusions must merge and deduplicate existing excluded IDs.' );
check( false === $filtered_args['has_password'], 'Core MIDI sitemaps must exclude protected entries.' );
foreach ( array( 'posts_per_page', 'paged', 'orderby', 'order', 'fields' ) as $key ) {
	check( $args[ $key ] === $filtered_args[ $key ], "Core sitemap filtering must preserve $key." );
}
check( $args === KML_Sitemaps::filter_core_query_args( $args, 'post' ), 'Other post types must retain their sitemap query arguments.' );
$allowlist = KML_Sitemaps::filter_core_query_args( array( 'post__in' => array( 7, 8, 9 ), 'post__not_in' => array( 9 ) ), 'kml_midi' );
check( array( 7 ) === $allowlist['post__in'], 'An explicit core sitemap allowlist must still exclude unavailable and explicitly excluded posts.' );
$empty_allowlist = KML_Sitemaps::filter_core_query_args( array( 'post__in' => array( 8, 11 ) ), 'kml_midi' );
check( array( 0 ) === $empty_allowlist['post__in'], 'An exhausted allowlist must not become an empty array that expands to all posts.' );

// Yoast needs matching SQL predicates for URL pagination and its sitemap index count.
check( has_filter( 'wpseo_posts_where', array( 'KML_Sitemaps', 'filter_yoast_where' ) ) && has_filter( 'wpseo_typecount_where', array( 'KML_Sitemaps', 'filter_yoast_where' ) ), 'Yoast URL and count queries must both receive the availability predicate.' );
$where = " AND wp_posts.post_status = 'publish' AND wp_posts.ID > 5";
$yoast_where = KML_Sitemaps::filter_yoast_where( $where, 'kml_midi' );
check( 0 === strpos( $yoast_where, $where ) && $expected_unavailable === fixture_sql_ids( $yoast_where, 'wp_posts.ID', 'NOT IN' ), 'Yoast must exclude unavailable files before its SQL LIMIT and retain existing conditions.' );
check( $where === KML_Sitemaps::filter_yoast_where( $where, 'post' ), 'Other Yoast post-type sitemaps must remain unchanged.' );
check( array( 99, 8, 10, 11, 12, 13, 14 ) === KML_Sitemaps::filter_yoast_excluded_ids( array( 99, 8, 0 ) ), 'Yoast exclusion IDs must merge existing values without duplicates or zero.' );
$url = array( 'loc' => 'https://example.test/midi/clair-de-lune/' );
check( $url === KML_Sitemaps::filter_yoast_entry( $url, 'post', get_post( 7 ) ), 'Available MIDI entries must remain in Yoast sitemaps.' );
check( false === KML_Sitemaps::filter_yoast_entry( $url, 'post', get_post( 8 ) ), 'Missing MIDI entries must also be blocked by the final Yoast entry guard.' );
check( $url === KML_Sitemaps::filter_yoast_entry( $url, 'post', get_post( 17 ) ) && $url === KML_Sitemaps::filter_yoast_entry( $url, 'term', $GLOBALS['fixture']['terms'][11] ), 'Other post types and taxonomy sitemap entries must remain unchanged.' );
check( false === KML_Sitemaps::filter_yoast_entry( false, 'post', get_post( 7 ) ), 'A sitemap entry excluded by another integration must stay excluded.' );
foreach ( array( '1', 'kml_midi' ) as $sitemap ) {
	$GLOBALS['fixture']['vars']['sitemap'] = $sitemap;
	check( false === KML_Sitemaps::filter_yoast_cache( true ), 'MIDI and index sitemaps must observe changes made only on disk.' );
}
foreach ( array( '', 'post', 'page', 'kml_folder' ) as $sitemap ) {
	$GLOBALS['fixture']['vars']['sitemap'] = $sitemap;
	check( true === KML_Sitemaps::filter_yoast_cache( true ) && false === KML_Sitemaps::filter_yoast_cache( false ), 'Unrelated sitemap cache settings must be preserved.' );
}

$restored_path = $midi_fixture_root . '/absent.mid';
file_put_contents( $restored_path, $midi_fixture_bytes );
clearstatcache();
KML_Availability::reset_cache();
$core_after_restore = KML_Sitemaps::filter_core_query_args( array(), 'kml_midi' );
check( ! in_array( 8, $core_after_restore['post__not_in'], true ), 'Restored files must return to core sitemap queries on a new request.' );
check( ! in_array( 8, fixture_sql_ids( KML_Sitemaps::filter_yoast_where( '', 'kml_midi' ), 'wp_posts.ID', 'NOT IN' ), true ), 'Restored files must return to Yoast sitemap URL and count queries.' );
unlink( $restored_path );
clearstatcache();

fwrite( STDOUT, "Listing/search/sitemap regression checks passed: $checks\n" );
