<?php
/**
 * Dependency-free SEO and file-availability regressions against the real classes.
 *
 * Run each integration in a fresh PHP process:
 *   php tests/seo.php
 *   php tests/seo.php yoast
 *   php tests/seo.php rank-math
 *
 * These fixtures model the WordPress APIs used by the SEO integration. They do
 * not replace checks of the rendered HTML on a real WordPress installation.
 */

error_reporting( E_ALL );
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

define( 'ABSPATH', __DIR__ . '/' );
define( 'KML_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
$midi_fixture_dir = tempnam( sys_get_temp_dir(), 'kml-seo-' );
unlink( $midi_fixture_dir );
mkdir( $midi_fixture_dir );
$midi_fixture_root = $midi_fixture_dir . '/library';
mkdir( $midi_fixture_root );
mkdir( $midi_fixture_dir . '/library-other' );
$midi_fixture_path = $midi_fixture_root . '/Clair de lune.mid';
$midi_fixture_bytes = hex2bin( '4d546864000000060000000100604d54726b0000000400ff2f00' );
file_put_contents( $midi_fixture_path, $midi_fixture_bytes );
file_put_contents( $midi_fixture_dir . '/library-other/outside.mid', $midi_fixture_bytes );
register_shutdown_function(
	static function () use ( $midi_fixture_dir ) {
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $midi_fixture_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $midi_fixture_dir );
	}
);
$integration = $argv[1] ?? 'native';
if ( ! in_array( $integration, array( 'native', 'yoast', 'rank-math', 'download-probe' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/seo.php [native|yoast|rank-math]\n" );
	exit( 1 );
}
if ( 'yoast' === $integration ) {
	define( 'WPSEO_VERSION', 'test' );
	class WPSEO_Taxonomy_Meta {
		public static function get_term_meta( $term, $taxonomy, $key ) {
			$id = is_object( $term ) ? $term->term_id : $term;
			return $GLOBALS['fixture']['term_meta'][ $id ][ $key ] ?? '';
		}
	}
} elseif ( 'rank-math' === $integration ) {
	define( 'RANK_MATH_VERSION', 'test' );
}

class WP_Post {
	public $ID = 7;
	public $post_type = 'kml_midi';
	public $post_status = 'publish';
	public $post_password = '';
	public $post_title = 'Clair de lune';
	public $post_excerpt = '';
	public $post_content = '';
	public $post_name = 'clair-de-lune';
	public $post_date = '2025-05-01 12:00:00';
	public $post_modified = '2025-05-02 12:00:00';
	public $post_date_gmt = '2025-05-01 12:00:00';
	public $post_modified_gmt = '2025-05-02 12:00:00';
}
class WP_Term {
	public $term_id;
	public $taxonomy = 'kml_folder';
	public $name;
	public $slug;
	public $parent;
	public $count;
	public $description = '';
	public function __construct( $id, $name, $slug, $parent = 0, $count = 0 ) {
		$this->term_id = $id;
		$this->name = $name;
		$this->slug = $slug;
		$this->parent = $parent;
		$this->count = $count;
	}
}
class WP_Error {}
class WP_Query {
	public $query_vars = array();
	public $posts = array();
	public $post;
	public $post_count = 1;
	public $found_posts = 5;
	public $max_num_pages = 3;
	public $queried_object;
	public $queried_object_id;
	public $is_404 = false;
	public $is_singular = false;
	public $is_archive = false;
	public $is_post_type_archive = false;
	public $is_tax = false;
	public $is_search = false;
	public $is_feed = false;
	public $is_home = false;
	public $is_admin = false;
	public function __construct( array $args = array() ) { $this->query_vars = $args; }
	public function get( $key, $default = '' ) { return $this->query_vars[ $key ] ?? $default; }
	public function set( $key, $value ) { $this->query_vars[ $key ] = $value; }
	public function is_main_query() { return $this === $GLOBALS['wp_query']; }
	public function is_singular( $types = '' ) { return $this->is_singular && ( '' === $types || array_intersect( (array) $types, (array) $this->get( 'post_type' ) ) ); }
	public function is_archive() { return $this->is_archive; }
	public function is_post_type_archive( $types = '' ) { return $this->is_post_type_archive && ( '' === $types || array_intersect( (array) $types, (array) $this->get( 'post_type' ) ) ); }
	public function is_tax( $taxonomy = '' ) { return $this->is_tax && ( '' === $taxonomy || $taxonomy === $this->get( 'taxonomy' ) ); }
	public function is_search() { return $this->is_search; }
	public function is_feed() { return $this->is_feed; }
	public function is_home() { return $this->is_home; }
	public function set_404() {
		$this->is_404 = true;
		$this->is_singular = false;
		$GLOBALS['fixture']['flags']['404'] = true;
		$GLOBALS['fixture']['type'] = 'missing';
	}
}
class KML_Test_Rewrite {
	public $permalink_structure = '/%postname%/';
	public $pagination_base = 'page';
	public $index = 'index.php';
	public function using_permalinks() { return '' !== $this->permalink_structure; }
	public function using_index_permalinks() { return false !== strpos( $this->permalink_structure, 'index.php' ); }
}

function reset_fixture( $type = 'single' ) {
	$post = new WP_Post();
	$parent = new WP_Term( 10, 'Classical', 'classical', 0, 2 );
	$child = new WP_Term( 11, 'Debussy', 'debussy', 10, 3 );
	$GLOBALS['fixture'] = array(
		'type' => $type,
		'flags' => array(),
		'vars' => array(),
		'post' => $post,
		'terms' => array( 10 => $parent, 11 => $child ),
		'post_terms' => array( $child ),
		'meta' => array( 7 => array( 'kml_relpath' => 'Classical/Debussy/Clair de lune.mid', 'kml_abspath' => $GLOBALS['midi_fixture_path'], 'kml_filesize' => 4096 ) ),
		'term_meta' => array(),
		'options' => array( 'blogname' => 'Piano Library', 'blog_charset' => 'UTF-8', 'date_format' => 'F j, Y', 'kml_url_base' => 'https://example.test/files', 'kml_root_path' => $GLOBALS['midi_fixture_root'], 'blog_public' => '1' ),
		'canonical' => 'https://example.test/midi/clair-de-lune/',
		'archive_url' => 'https://example.test/midi/',
		'status' => 200,
		'nocache' => false,
	);
	$GLOBALS['post'] = $post;
	$GLOBALS['wp_rewrite'] = new KML_Test_Rewrite();
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->is_singular = 'single' === $type;
	$GLOBALS['wp_query']->is_archive = in_array( $type, array( 'archive', 'folder' ), true );
	$GLOBALS['wp_query']->is_post_type_archive = 'archive' === $type;
	$GLOBALS['wp_query']->is_tax = 'folder' === $type;
	$GLOBALS['wp_query']->query_vars = array( 'post_type' => 'kml_midi', 'taxonomy' => 'folder' === $type ? 'kml_folder' : '' );
	$GLOBALS['wp_query']->posts = array( $post );
	$GLOBALS['wp_query']->post = $post;
	$GLOBALS['wp_query']->queried_object = 'single' === $type ? $post : ( 'folder' === $type ? $child : (object) array( 'name' => 'kml_midi' ) );
	$GLOBALS['wp_query']->queried_object_id = 'single' === $type ? 7 : ( 'folder' === $type ? 11 : 0 );
	unset( $GLOBALS['hooks']['redirect_canonical'], $GLOBALS['hooks']['old_slug_redirect_url'], $GLOBALS['hook_priorities']['redirect_canonical'], $GLOBALS['hook_priorities']['old_slug_redirect_url'] );
	$_GET = array();
}
function __( $text, $domain = '' ) { return $text; }
function _x( $text, $context, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; }
function is_admin() { return ! empty( $GLOBALS['fixture']['flags']['admin'] ); }
function is_search() { return ! empty( $GLOBALS['fixture']['flags']['search'] ); }
function is_preview() { return ! empty( $GLOBALS['fixture']['flags']['preview'] ); }
function is_404() { return ! empty( $GLOBALS['fixture']['flags']['404'] ); }
function is_feed() { return ! empty( $GLOBALS['fixture']['flags']['feed'] ); }
function is_embed() { return ! empty( $GLOBALS['fixture']['flags']['embed'] ); }
function is_singular( $type = '' ) { return 'single' === $GLOBALS['fixture']['type'] && ( '' === $type || in_array( $GLOBALS['fixture']['post']->post_type, (array) $type, true ) ); }
function is_post_type_archive( $type = '' ) { return 'archive' === $GLOBALS['fixture']['type'] && ( '' === $type || 'kml_midi' === $type ); }
function is_tax( $taxonomy = '' ) { return 'folder' === $GLOBALS['fixture']['type'] && ( '' === $taxonomy || 'kml_folder' === $taxonomy ); }
function get_queried_object() { return $GLOBALS['wp_query']->queried_object ?? null; }
function get_queried_object_id() { $object = get_queried_object(); return $object->ID ?? $object->term_id ?? 0; }
function get_query_var( $key, $default = '' ) { return $GLOBALS['fixture']['vars'][ $key ] ?? $default; }
function get_post( $id = null ) { if ( ! is_object( $id ) && isset( $GLOBALS['fixture']['posts_by_id'][ $id ] ) ) { return $GLOBALS['fixture']['posts_by_id'][ $id ]; } return $id instanceof WP_Post ? $id : ( null === $id || (int) $id === $GLOBALS['fixture']['post']->ID ? $GLOBALS['fixture']['post'] : null ); }
function get_post_type( $post = null ) { $post = get_post( $post ); return $post ? $post->post_type : false; }
function get_post_status( $post = null ) { $post = get_post( $post ); return $post ? $post->post_status : false; }
function is_post_publicly_viewable( $post = null ) { $post = get_post( $post ); return $post && 'publish' === $post->post_status; }
function post_password_required( $post = null ) { $post = get_post( $post ); return $post && '' !== $post->post_password; }
function get_post_field( $field, $id = null, $context = 'display' ) { $post = get_post( $id ); return $post ? $post->$field : ''; }
function get_the_title( $id = null ) { return get_post_field( 'post_title', $id ); }
function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['fixture']['meta'][ $id ][ $key ] ?? ( $single ? '' : array() ); }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['fixture']['meta'][ $id ][ $key ] ); }
function add_post_meta( $id, $key, $value, $unique = false ) { if ( $unique && metadata_exists( 'post', $id, $key ) ) { return false; } $GLOBALS['fixture']['meta'][ $id ][ $key ] = $value; return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['fixture']['meta'][ $id ][ $key ] = $value; return true; }
function get_term_meta( $id, $key = '', $single = false ) { return $GLOBALS['fixture']['term_meta'][ $id ][ $key ] ?? ( $single ? '' : array() ); }
function get_term( $id, $taxonomy = '' ) { return $GLOBALS['fixture']['terms'][ $id ] ?? new WP_Error(); }
function get_the_terms( $id, $taxonomy ) { return $GLOBALS['fixture']['post_terms']; }
function get_term_children( $id, $taxonomy ) { return 10 === (int) $id ? array( 11 ) : array(); }
function get_ancestors( $id, $taxonomy, $type = '' ) { return 11 === (int) $id ? array( 10 ) : array(); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function term_description( $id = 0, $taxonomy = '' ) { return get_term( $id ?: get_queried_object_id() )->description; }
function get_option( $name, $default = false ) { return $GLOBALS['fixture']['options'][ $name ] ?? $default; }
function get_bloginfo( $name = '', $filter = 'raw' ) { return 'language' === $name ? 'en-US' : get_option( 'charset' === $name ? 'blog_charset' : 'blogname', '' ); }
function get_locale() { return 'en_US'; }
function home_url( $path = '' ) { return 'https://example.test' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function user_trailingslashit( $value, $type = '' ) { return trailingslashit( $value ); }
function get_permalink( $post = null ) { return 'https://example.test/midi/clair-de-lune/'; }
function wp_get_canonical_url( $post = null ) { return $GLOBALS['fixture']['canonical']; }
function get_post_type_archive_link( $type ) { return $GLOBALS['fixture']['archive_url']; }
function get_term_link( $term, $taxonomy = '' ) { $term = $term instanceof WP_Term ? $term : get_term( $term ); return $term instanceof WP_Error ? $term : 'https://example.test/midi-folder/' . ( $term->parent ? 'classical/' : '' ) . $term->slug . '/'; }
function add_query_arg( $key, $value = null, $url = null ) {
	$args = is_array( $key ) ? $key : array( $key => $value );
	$url = is_array( $key ) ? $value : $url;
	$parts = parse_url( $url );
	parse_str( $parts['query'] ?? '', $query );
	$query = array_merge( $query, $args );
	return preg_replace( '/[?#].*$/', '', $url ) . '?' . http_build_query( $query ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( $text );
	return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
}
function wp_check_invalid_utf8( $text, $strip = false ) { return preg_match( '//u', $text ) ? $text : ( $strip ? iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) : '' ); }
function strip_shortcodes( $text ) { return preg_replace( '/\[\/?[a-zA-Z_][^\]]*\]/', '', $text ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function sanitize_text_field( $value ) { return trim( wp_strip_all_tags( $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_url_raw( $url ) { return $url; }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( $number, $decimals ); }
function size_format( $bytes, $decimals = 0 ) { return number_format( $bytes / 1024, $decimals ) . ' KB'; }
function date_i18n( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function get_post_time( $format = 'U', $gmt = false, $post = null, $translate = false ) { return gmdate( $format, strtotime( get_post_field( 'post_date_gmt', $post ) ) ); }
function get_post_modified_time( $format = 'U', $gmt = false, $post = null, $translate = false ) { return gmdate( $format, strtotime( get_post_field( 'post_modified_gmt', $post ) ) ); }
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; $GLOBALS['hook_priorities'][ $hook ][ $priority ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { add_action( $hook, $callback, $priority, $accepted_args ); }
function has_action( $hook, $callback = false ) { return ! empty( $GLOBALS['hooks'][ $hook ] ) && ( false === $callback || in_array( $callback, $GLOBALS['hooks'][ $hook ], true ) ); }
function has_filter( $hook, $callback = false ) { return has_action( $hook, $callback ); }
function apply_filters( $hook, $value, ...$args ) { foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $callback ) { $value = $callback( $value, ...$args ); } return $value; }
function __return_false() { return false; }
function current_user_can( $capability, ...$args ) { return 'edit_post' === $capability && ! empty( $GLOBALS['fixture']['flags']['can_edit'] ); }
function status_header( $status, $description = '' ) { $GLOBALS['fixture']['status'] = $status; }
function nocache_headers() { $GLOBALS['fixture']['nocache'] = true; }
function wp_doing_ajax() { return false; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function locate_template( $templates ) { $GLOBALS['fixture']['located_templates'] = $templates; return ''; }

require KML_PLUGIN_DIR . 'includes/class-kml-post-types.php';
require KML_PLUGIN_DIR . 'includes/class-kml-indexer.php';
require KML_PLUGIN_DIR . 'includes/class-kml-public.php';
require KML_PLUGIN_DIR . 'includes/class-kml-seo.php';

$checks = 0;
function check( $condition, $message ) {
	++$GLOBALS['checks'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function contains( $needle, $text ) { return false !== strpos( $text, $needle ); }
function char_length( $text ) { return preg_match_all( '/./us', $text ); }
function capture_metadata() { ob_start(); KML_SEO::print_metadata(); return ob_get_clean(); }
function node_of_type( array $graph, $type ) {
	foreach ( $graph as $node ) {
		if ( in_array( $type, (array) ( $node['@type'] ?? array() ), true ) ) {
			return $node;
		}
	}
	return null;
}
function count_nodes_of_type( array $graph, $type ) {
	return count( array_filter( $graph, static function ( $node ) use ( $type ) { return in_array( $type, (array) ( $node['@type'] ?? array() ), true ); } ) );
}
function download_probe( $scenario ) {
	$process = proc_open( array( PHP_BINARY, __FILE__, 'download-probe', $scenario ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not launch download endpoint probe.' );
	}
	fclose( $pipes[0] );
	$body = stream_get_contents( $pipes[1] );
	$state = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( 'exit' => proc_close( $process ), 'body' => $body, 'state' => json_decode( $state, true ), 'stderr' => $state );
}

// Share only fixtures and helpers with the listing/sitemap regression suite.
if ( defined( 'KML_TEST_FIXTURES_ONLY' ) && KML_TEST_FIXTURES_ONLY ) {
	return;
}

// Exercise the real endpoint in its own process because it intentionally exits.
if ( 'download-probe' === $integration ) {
	reset_fixture();
	$GLOBALS['fixture']['vars']['kml_download'] = 7;
	$scenario = $argv[2] ?? 'valid';
	if ( 'missing' === $scenario ) {
		$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $midi_fixture_root . '/deleted.mid';
	} elseif ( 'private' === $scenario ) {
		$GLOBALS['fixture']['post']->post_status = 'private';
	} elseif ( 'outside-root' === $scenario ) {
		$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $midi_fixture_dir . '/library-other/outside.mid';
	} elseif ( 'directory' === $scenario ) {
		$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $midi_fixture_root;
	} elseif ( 'valid-without-public-url' === $scenario ) {
		$GLOBALS['fixture']['options']['kml_url_base'] = '';
	}
	register_shutdown_function(
		static function () {
			fwrite( STDERR, json_encode( array( 'status' => $GLOBALS['fixture']['status'], 'nocache' => $GLOBALS['fixture']['nocache'], 'downloads' => get_post_meta( 7, KML_Post_Types::META_DOWNLOAD_COUNT, true ) ) ) );
		}
	);
	KML_Public::maybe_serve_download();
	throw new RuntimeException( 'The download endpoint unexpectedly returned.' );
}

reset_fixture();
KML_Public::init();
KML_SEO::init();
check( ! has_action( 'wp_head', array( 'KML_Public', 'print_meta_description' ) ), 'The old public description must not duplicate the SEO integration.' );
check( has_action( 'wp_head', array( 'KML_SEO', 'print_metadata' ) ), 'SEO metadata must be connected to the document head.' );
check( ! has_filter( 'wp_robots' ) && ! has_filter( 'wpseo_robots' ), 'Metadata integration must preserve the site’s existing robots policy.' );

// Landing pages and the download endpoint must agree about file availability.
check( in_array( array( 'KML_Public', 'maybe_404_missing_file' ), $GLOBALS['hook_priorities']['template_redirect'][0] ?? array(), true ), 'Missing-file validation must run before redirects, downloads and view counting.' );
check( realpath( $midi_fixture_path ) === KML_Public::get_downloadable_file( 7 ), 'A readable MIDI file in the configured library must resolve.' );
check( '' === KML_Public::get_downloadable_file( 999 ), 'Unknown posts must not resolve to a download.' );
$other_midi_path = $midi_fixture_root . '/alternate.MIDI';
file_put_contents( $other_midi_path, $midi_fixture_bytes );
$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $other_midi_path;
check( realpath( $other_midi_path ) === KML_Public::get_downloadable_file( 7 ), 'Both .mid and .midi extensions must work regardless of case.' );
$directory_path = $midi_fixture_root . '/directory.mid';
mkdir( $directory_path );
$text_path = $midi_fixture_root . '/not-midi.txt';
file_put_contents( $text_path, 'not a MIDI download' );
$outside_path = $midi_fixture_dir . '/library-other/outside.mid';
$outside_symlink = $midi_fixture_root . '/outside-link.mid';
symlink( $outside_path, $outside_symlink );
$text_symlink = $midi_fixture_root . '/text-link.mid';
symlink( $text_path, $text_symlink );
$inside_symlink = $midi_fixture_root . '/inside-link.mid';
symlink( $midi_fixture_path, $inside_symlink );
$index_file = new ReflectionMethod( 'KML_Indexer', 'index_file' );
if ( PHP_VERSION_ID < 80100 ) {
	$index_file->setAccessible( true );
}
foreach ( array( 'missing metadata' => '', 'missing file' => $midi_fixture_root . '/absent.mid', 'directory' => $directory_path, 'non-MIDI file' => $text_path, 'sibling with shared path prefix' => $outside_path, 'symlink outside root' => $outside_symlink, 'MIDI symlink to a non-MIDI file' => $text_symlink ) as $reason => $path ) {
	reset_fixture();
	$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $path;
	check( '' === KML_Public::get_downloadable_file( 7 ), "The resolver must reject $reason." );
	check( ! KML_SEO::is_library_page() && array() === KML_SEO::get_schema_graph(), "The SEO layer must not describe a download with $reason." );
	$metadata_before = $GLOBALS['fixture']['meta'];
	$index_file->invoke( null, $path, 'invalid.mid' );
	check( $metadata_before === $GLOBALS['fixture']['meta'], "The indexer must reject $reason before modifying post metadata." );
}
foreach ( array( '', $midi_fixture_dir . '/absent-root', $midi_fixture_path ) as $invalid_root ) {
	reset_fixture();
	$GLOBALS['fixture']['options']['kml_root_path'] = $invalid_root;
	check( '' === KML_Public::get_downloadable_file( 7 ), 'An absent or non-directory library root must disable file delivery.' );
	$metadata_before = $GLOBALS['fixture']['meta'];
	$index_file->invoke( null, $midi_fixture_path, 'Clair de lune.mid' );
	check( $metadata_before === $GLOBALS['fixture']['meta'], 'An invalid root must prevent the indexer from modifying posts.' );
}
reset_fixture();
$GLOBALS['fixture']['meta'][7]['kml_abspath'] = $inside_symlink;
check( realpath( $midi_fixture_path ) === KML_Public::get_downloadable_file( 7 ), 'A symlink resolving to a MIDI inside the library must remain available.' );
$root_symlink = $midi_fixture_dir . '/library-alias';
symlink( $midi_fixture_root, $root_symlink );
$GLOBALS['fixture']['options']['kml_root_path'] = $root_symlink . '/';
check( realpath( $midi_fixture_path ) === KML_Public::get_downloadable_file( 7 ), 'A configured root symlink and trailing slash must resolve consistently.' );
foreach ( array( 'draft', 'private', 'future', 'trash' ) as $status ) {
	reset_fixture();
	$GLOBALS['fixture']['post']->post_status = $status;
	check( '' === KML_Public::get_downloadable_file( 7 ), "$status MIDI posts must not expose their file through the shared resolver." );
}
reset_fixture();
$GLOBALS['fixture']['post']->post_password = 'protected';
check( '' === KML_Public::get_downloadable_file( 7 ), 'Password-protected posts must not expose unprotected file downloads.' );
$GLOBALS['fixture']['post']->post_password = '';
$GLOBALS['fixture']['post']->post_type = 'post';
check( '' === KML_Public::get_downloadable_file( 7 ), 'Unrelated post types must not expose files stored in MIDI metadata.' );

reset_fixture();
unlink( $midi_fixture_path );
clearstatcache();
check( '' === KML_Public::get_downloadable_file( 7 ), 'Deleting a file must immediately invalidate its indexed download.' );
KML_Public::maybe_404_missing_file();
check( is_404() && 404 === $GLOBALS['fixture']['status'] && $GLOBALS['fixture']['nocache'], 'An unavailable landing page must send an uncached HTTP 404.' );
check( false === apply_filters( 'redirect_canonical', 'https://example.test/stale/' ) && false === apply_filters( 'old_slug_redirect_url', 'https://example.test/stale/' ), 'Rejected pages must not be redirected back to stale canonical or old-slug destinations.' );
check( array() === $GLOBALS['wp_query']->posts && 0 === $GLOBALS['wp_query']->post_count && 0 === $GLOBALS['wp_query']->found_posts, 'A missing file must be removed from the main query before the theme loop.' );
check( null === $GLOBALS['post'] && null === get_queried_object() && 0 === get_queried_object_id(), 'A missing file must not leave the post or queried object available to the theme.' );
KML_Public::maybe_count_file_view();
check( '' === get_post_meta( 7, KML_Post_Types::META_VIEW_COUNT, true ), 'Unavailable landing pages must not count as file views.' );
check( '/theme/404.php' === KML_Public::template_loader( '/theme/404.php' ), 'An unavailable file must use the theme’s 404 template.' );
check( '' === capture_metadata() && array() === KML_SEO::get_schema_graph() && '' === KML_SEO::get_canonical_url(), 'Unavailable landing pages must not publish library metadata or canonical URLs.' );
file_put_contents( $midi_fixture_path, $midi_fixture_bytes );
clearstatcache();
reset_fixture();
check( realpath( $midi_fixture_path ) === KML_Public::get_downloadable_file( 7 ), 'Restoring a file must restore its indexed page without a reimport.' );
$GLOBALS['fixture']['options']['kml_url_base'] = '';
KML_Public::maybe_404_missing_file();
check( ! is_404() && 200 === $GLOBALS['fixture']['status'] && ! $GLOBALS['fixture']['nocache'], 'A valid local download must keep its landing page even without a public file URL.' );
check( ! has_filter( 'redirect_canonical' ) && ! has_filter( 'old_slug_redirect_url' ), 'Available landing pages must retain normal redirect behavior.' );
check( KML_PLUGIN_DIR . 'public/templates/single-kml_midi.php' === KML_Public::template_loader( '/theme/single.php' ), 'Available files must retain the MIDI landing-page template.' );
KML_Public::maybe_count_file_view();
check( 1 === get_post_meta( 7, KML_Post_Types::META_VIEW_COUNT, true ), 'Available landing pages must still count views.' );
check( KML_SEO::is_library_page() && 'https://example.test/midi-download/7/' === node_of_type( KML_SEO::get_schema_graph(), 'AudioObject' )['contentUrl'], 'A valid local-only file must retain SEO metadata and its download URL.' );

chmod( $midi_fixture_path, 0000 );
clearstatcache();
if ( ! is_readable( $midi_fixture_path ) ) {
	check( '' === KML_Public::get_downloadable_file( 7 ), 'Unreadable local files must not be offered as available downloads.' );
	$metadata_before = $GLOBALS['fixture']['meta'];
	$index_file->invoke( null, $midi_fixture_path, 'Clair de lune.mid' );
	check( $metadata_before === $GLOBALS['fixture']['meta'], 'Unreadable files must not be indexed.' );
}
chmod( $midi_fixture_path, 0600 );
clearstatcache();
foreach ( array( 'admin', 'preview' ) as $flag ) {
	reset_fixture();
	$GLOBALS['fixture']['flags'][ $flag ] = true;
	$GLOBALS['fixture']['flags']['can_edit'] = true;
	$GLOBALS['fixture']['meta'][7]['kml_abspath'] = '';
	KML_Public::maybe_404_missing_file();
	check( ! is_404() && 200 === $GLOBALS['fixture']['status'] && $GLOBALS['post'] instanceof WP_Post, "The availability guard must preserve $flag requests." );
	check( ! has_filter( 'redirect_canonical' ) && ! has_filter( 'old_slug_redirect_url' ), "Excluded $flag requests must retain normal redirect behavior." );
}
reset_fixture();
$GLOBALS['fixture']['flags']['preview'] = true;
$GLOBALS['fixture']['meta'][7]['kml_abspath'] = '';
KML_Public::maybe_404_missing_file();
check( is_404() && 404 === $GLOBALS['fixture']['status'], 'An anonymous preview query parameter must not bypass missing-file validation.' );
foreach ( array( 'archive', 'folder', 'unrelated' ) as $type ) {
	reset_fixture( $type );
	$GLOBALS['fixture']['meta'][7]['kml_abspath'] = '';
	KML_Public::maybe_404_missing_file();
	check( ! is_404() && 200 === $GLOBALS['fixture']['status'], "The availability guard must leave $type pages unchanged." );
}
foreach ( array( 'valid', 'valid-without-public-url', 'missing', 'private', 'outside-root', 'directory' ) as $scenario ) {
	$probe = download_probe( $scenario );
	check( 0 === $probe['exit'] && is_array( $probe['state'] ), "Download probe $scenario must finish cleanly: " . $probe['stderr'] );
	if ( 0 === strpos( $scenario, 'valid' ) ) {
		check( $midi_fixture_bytes === $probe['body'] && 200 === $probe['state']['status'] && 1 === $probe['state']['downloads'], "$scenario downloads must stream the actual MIDI file and count one download." );
	} else {
		check( '' === $probe['body'] && 404 === $probe['state']['status'] && '' === $probe['state']['downloads'], "$scenario downloads must return HTTP 404 without file bytes or download counts." );
	}
}

// The visible summary and the search description must identify the actual file.
reset_fixture();
check( KML_SEO::is_library_page(), 'A public published MIDI page should receive metadata.' );
$summary = KML_Public::get_file_page_summary( 7 );
check( contains( 'Clair de lune', $summary ) && contains( 'Debussy', $summary ), 'Generated summaries must include the file and its folder.' );
$description = KML_SEO::filter_description( '' );
check( contains( 'Clair de lune', $description ) && contains( 'Debussy', $description ), 'Search snippets must retain the distinguishing file and folder.' );
check( char_length( $description ) <= 180, 'Generated descriptions must fit the configured length.' );
check( contains( 'MIDI', KML_SEO::get_title() ) && contains( 'Clair de lune', KML_SEO::get_title() ), 'The title must identify both the piece and its MIDI format.' );
$title_parts = KML_SEO::filter_document_title_parts( array( 'title' => 'Old title', 'site' => 'Piano Library', 'page' => 'Page 2' ) );
check( 'Piano Library' === $title_parts['site'] && 'Page 2' === $title_parts['page'] && contains( 'Clair de lune', $title_parts['title'] ), 'Native title changes must preserve the site and pagination parts.' );
check( 'My editorial search description.' === KML_SEO::filter_description( 'My editorial search description.' ), 'An existing SEO description must be preserved.' );
$GLOBALS['fixture']['post']->post_excerpt = '<p>A gentle piano arrangement by Claude Debussy.</p>';
check( contains( 'A gentle piano arrangement by Claude Debussy.', KML_SEO::filter_description( '' ) ), 'An editorial excerpt must supply the fallback description.' );
check( ! contains( '<p>', KML_SEO::filter_description( '' ) ), 'Descriptions must be plain text.' );
$GLOBALS['fixture']['post']->post_excerpt = 'Download the Clair de lune MIDI file from the Kuhmann / Disklavier World mirror. File size: 4 KB.';
check( contains( 'Debussy', KML_SEO::filter_description( '' ) ), 'Previously generated excerpts must refresh with the identifying folder without a reimport.' );

// Multibyte names and text without spaces must not be cut inside a character.
$unicode = str_repeat( '夜想曲é🎹', 60 );
$trimmed = KML_Public::trim_summary( $unicode, 180 );
check( 1 === preg_match( '//u', $trimmed ), 'Truncation must produce valid UTF-8.' );
check( char_length( $trimmed ) <= 180 && char_length( $trimmed ) > 100, 'Truncation must measure characters, including the ending punctuation.' );
check( 'Bach & Mozart' === KML_Public::trim_summary( '<b>Bach &amp; Mozart</b>', 180 ), 'HTML entities should become readable text before output escaping.' );
check( '' === KML_Public::trim_summary( 'Some text', 0 ), 'A zero character limit must return an empty summary.' );

reset_fixture( 'folder' );
$GLOBALS['fixture']['terms'][11]->description = '<p>Piano arrangements of Debussy’s best-known compositions.</p>';
check( contains( 'Piano arrangements of Debussy', KML_Public::get_folder_page_summary( $GLOBALS['fixture']['terms'][11] ) ), 'An editorial folder description should appear in the visible summary.' );
check( contains( 'Piano arrangements of Debussy', KML_SEO::filter_description( '' ) ), 'An editorial folder description should supply the search snippet.' );
$GLOBALS['fixture']['terms'][11]->description = '';
$GLOBALS['fixture']['terms'][11]->count = 1;
check( contains( '1 MIDI file to download', KML_SEO::filter_description( '' ) ), 'Folder descriptions must use a singular label for one file.' );
check( contains( '3 MIDI files', KML_Public::get_folder_page_summary( $GLOBALS['fixture']['terms'][10] ) ), 'Folder counts must include child folders.' );

// Restricted and utility contexts must never emit new metadata or overwrite SEO.
foreach ( array( 'admin', 'search', 'preview', '404', 'feed', 'embed' ) as $flag ) {
	reset_fixture();
	$GLOBALS['fixture']['flags'][ $flag ] = true;
	check( ! KML_SEO::is_library_page(), "$flag requests must be excluded." );
	check( '' === capture_metadata(), "$flag requests must not emit metadata." );
	check( 'Original description' === KML_SEO::filter_description( 'Original description' ), "$flag requests must preserve existing descriptions." );
	check( array() === KML_SEO::get_schema_graph(), "$flag requests must not expose schema via SEO plugin filters." );
}
foreach ( array( 'draft', 'private', 'future', 'trash' ) as $status ) {
	reset_fixture();
	$GLOBALS['fixture']['post']->post_status = $status;
	check( ! KML_SEO::is_library_page() && '' === capture_metadata(), "$status posts must not expose metadata." );
}
reset_fixture();
$GLOBALS['fixture']['post']->post_password = 'secret';
check( ! KML_SEO::is_library_page() && '' === capture_metadata(), 'Password-protected MIDI content must not leak into metadata.' );
foreach ( array( 'kml_download' => 7, 'kml_player_path' => 'private.mid', 'kml_q' => 'Debussy' ) as $key => $value ) {
	reset_fixture( 'archive' );
	$GLOBALS['fixture']['vars'][ $key ] = $value;
	$_GET[ $key ] = $value;
	check( ! KML_SEO::is_library_page() && '' === capture_metadata(), "$key requests must not generate a second indexable document." );
}
reset_fixture( 'archive' );
$_GET['kml_q'] = '0';
check( ! KML_SEO::is_library_page(), 'A search for the string zero must still count as a filtered request.' );
$_GET['kml_q'] = array( 'unexpected' );
check( ! KML_SEO::is_library_page(), 'Malformed search parameters must be safely excluded.' );
reset_fixture( 'unrelated' );
check( ! KML_SEO::is_library_page(), 'Unrelated pages must retain their existing SEO.' );
check( array( 'title' => 'About us' ) === KML_SEO::filter_document_title_parts( array( 'title' => 'About us' ) ), 'Unrelated document titles must be untouched.' );

// Canonicals must be independent of arbitrary query parameters and honor paging.
reset_fixture();
$GLOBALS['fixture']['canonical'] = 'https://example.test/midi/clair-de-lune/2/';
check( $GLOBALS['fixture']['canonical'] === KML_SEO::get_canonical_url(), 'Singular canonicals must use WordPress canonical resolution.' );
reset_fixture( 'archive' );
$_GET['utm_source'] = 'test';
check( 'https://example.test/midi/' === KML_SEO::get_canonical_url(), 'Archive canonicals must omit tracking parameters.' );
$GLOBALS['fixture']['vars']['paged'] = 3;
check( 'https://example.test/midi/page/3/' === KML_SEO::get_canonical_url(), 'Archive page three must have its own canonical.' );
$GLOBALS['wp_rewrite']->pagination_base = 'seite';
check( 'https://example.test/midi/seite/3/' === KML_SEO::get_canonical_url(), 'Canonical pagination must honor the configured pagination base.' );
$GLOBALS['wp_rewrite']->permalink_structure = '';
$GLOBALS['fixture']['archive_url'] = 'https://example.test/?post_type=kml_midi';
check( 'https://example.test/?post_type=kml_midi&paged=3' === KML_SEO::get_canonical_url(), 'Plain permalinks must preserve the post-type query while adding pagination.' );
reset_fixture( 'folder' );
$GLOBALS['fixture']['vars']['paged'] = 2;
check( 'https://example.test/midi-folder/classical/debussy/page/2/' === KML_SEO::get_canonical_url(), 'Folder pagination must have distinct canonical URLs.' );

// Schema describes only real files and supplies usable breadcrumb destinations.
reset_fixture();
$graph = KML_SEO::get_schema_graph();
$page = node_of_type( $graph, 'WebPage' );
$audio = node_of_type( $graph, 'AudioObject' );
$breadcrumbs = node_of_type( $graph, 'BreadcrumbList' );
check( null !== $page && null !== $audio && null !== $breadcrumbs, 'MIDI pages need linked page, audio and breadcrumb schema.' );
check( 'audio/midi' === $audio['encodingFormat'], 'MIDI files must declare their actual encoding.' );
check( 'https://example.test/midi-download/7/' === $audio['contentUrl'], 'Audio contentUrl must point to the working download endpoint for a readable indexed file.' );
check( ( $audio['mainEntityOfPage']['@id'] ?? '' ) === $page['@id'], 'The audio object must link back to its page.' );
check( count( $breadcrumbs['itemListElement'] ) >= 2, 'Breadcrumbs must provide at least two items.' );
foreach ( $breadcrumbs['itemListElement'] as $index => $item ) {
	check( $index + 1 === $item['position'] && ! empty( $item['name'] ) && ! empty( $item['item'] ), 'Each breadcrumb must have a consecutive position, visible name and destination.' );
}
check( contains( 'Debussy', json_encode( $breadcrumbs ) ), 'File breadcrumbs must include the folder hierarchy.' );
check( ! isset( $audio['duration'] ) && ! isset( $audio['aggregateRating'] ), 'Schema must not invent duration or ratings.' );
$GLOBALS['fixture']['post']->post_title = 'Für Elise &#8217; &amp; friends';
$graph = KML_SEO::get_schema_graph();
check( 'Für Elise ’ & friends' === node_of_type( $graph, 'AudioObject' )['name'], 'Audio names must decode WordPress title entities inside JSON-LD.' );
check( contains( 'Für Elise ’ & friends', node_of_type( $graph, 'WebPage' )['name'] ), 'Page names must decode WordPress title entities inside JSON-LD.' );
$crumbs = node_of_type( $graph, 'BreadcrumbList' )['itemListElement'];
check( 'Für Elise ’ & friends' === end( $crumbs )['name'], 'Breadcrumb names must decode WordPress title entities inside JSON-LD.' );

foreach ( array( 'archive', 'folder' ) as $type ) {
	reset_fixture( $type );
	$graph = KML_SEO::get_schema_graph();
	check( null !== node_of_type( $graph, 'CollectionPage' ), "$type needs collection page schema." );
	check( null === node_of_type( $graph, 'AudioObject' ), "$type is a collection, not an individual audio file." );
	$breadcrumbs = node_of_type( $graph, 'BreadcrumbList' );
	check( ( 'archive' === $type && null === $breadcrumbs ) || ( null !== $breadcrumbs && count( $breadcrumbs['itemListElement'] ) >= 2 ), "$type must omit one-item breadcrumbs or provide at least two destinations." );
}

reset_fixture();
$GLOBALS['fixture']['options']['kml_url_base'] = '';
$GLOBALS['fixture']['meta'][7]['kml_abspath'] = '';
check( array() === KML_SEO::get_schema_graph(), 'Unavailable files must not generate landing-page or audio schema.' );

// The metadata output must be valid and cannot let text terminate its script.
reset_fixture();
$GLOBALS['fixture']['post']->post_title = 'Nocturne "é" </script><script>alert(1)</script>';
$metadata = capture_metadata();
if ( 'native' === $integration ) {
	check( 1 === substr_count( $metadata, 'name="description"' ), 'Native output must have exactly one description.' );
	check( ! contains( '<script>alert(1)', $metadata ), 'User content must not introduce executable script markup.' );
	check( 1 === preg_match( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/s', $metadata, $matches ), 'Native output must contain one JSON-LD script.' );
	$decoded = json_decode( $matches[1], true );
	check( JSON_ERROR_NONE === json_last_error() && ! empty( $decoded['@graph'] ), 'JSON-LD must remain valid for punctuation and non-ASCII text.' );
	reset_fixture( 'archive' );
	$metadata = capture_metadata();
	check( 1 === substr_count( $metadata, 'rel="canonical"' ), 'Archives need exactly one native canonical.' );
} else {
	check( '' === $metadata, 'An active SEO plugin must own document-head metadata without duplication.' );
}

if ( 'yoast' === $integration ) {
	reset_fixture();
	$GLOBALS['fixture']['meta'][7]['_yoast_wpseo_title'] = 'My custom Yoast title';
	check( 'Resolved custom title | Piano Library' === KML_SEO::filter_yoast_title( 'Resolved custom title | Piano Library' ), 'Explicit Yoast post titles must be preserved.' );
	unset( $GLOBALS['fixture']['meta'][7]['_yoast_wpseo_title'] );
	check( contains( 'Clair de lune', KML_SEO::filter_yoast_title( 'Generic title' ) ), 'Default Yoast titles should identify the MIDI file.' );
	reset_fixture( 'folder' );
	$GLOBALS['fixture']['term_meta'][11]['title'] = 'Custom folder title';
	check( 'Custom folder title | Piano Library' === KML_SEO::filter_yoast_title( 'Custom folder title | Piano Library' ), 'Explicit Yoast folder titles must be preserved.' );
	reset_fixture();
	$site = array( '@type' => 'WebSite', '@id' => 'https://example.test/#website', 'name' => 'Piano Library' );
	$organization = array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'name' => 'Publisher' );
	$existing_page = array( '@type' => 'WebPage', '@id' => 'https://example.test/midi/clair-de-lune/#yoast-page', 'url' => 'https://example.test/midi/clair-de-lune/', 'name' => 'Editorial title', 'description' => 'Editorial description', 'isPartOf' => array( '@id' => $site['@id'] ), 'breadcrumb' => array( '@id' => 'https://example.test/#yoast-breadcrumb' ) );
	$existing_breadcrumb = array( '@type' => 'BreadcrumbList', '@id' => 'https://example.test/#yoast-breadcrumb', 'itemListElement' => array() );
	$merged = KML_SEO::filter_yoast_schema( array( $site, $organization, $existing_page, $existing_breadcrumb ) );
	check( $site === node_of_type( $merged, 'WebSite' ) && $organization === node_of_type( $merged, 'Organization' ), 'Yoast schema integration must preserve site and publisher nodes.' );
	check( 1 === count_nodes_of_type( $merged, 'WebPage' ) && 1 === count_nodes_of_type( $merged, 'BreadcrumbList' ) && 1 === count_nodes_of_type( $merged, 'AudioObject' ), 'Yoast graphs must merge page and breadcrumb nodes without duplicates.' );
	$merged_page = node_of_type( $merged, 'WebPage' );
	$merged_audio = node_of_type( $merged, 'AudioObject' );
	check( $existing_page['@id'] === $merged_page['@id'] && $existing_page['isPartOf'] === $merged_page['isPartOf'], 'Existing Yoast page identity and site links must survive.' );
	check( $existing_page['@id'] === $merged_audio['mainEntityOfPage']['@id'], 'Audio schema must link to the actual existing Yoast page identifier.' );
	check( 'Editorial description' === $merged_page['description'], 'Existing editorial schema descriptions must survive.' );
	check( $merged_page['breadcrumb']['@id'] === node_of_type( $merged, 'BreadcrumbList' )['@id'], 'Merged breadcrumb references must resolve.' );
	check( array() === KML_SEO::filter_yoast_schema( array() ), 'An intentionally disabled Yoast graph must remain disabled.' );
	check( array( $site ) === KML_SEO::filter_yoast_schema( array( $site ) ), 'A graph without a page must not receive dangling page references.' );
	$existing_page['description'] = '';
	$merged = KML_SEO::filter_yoast_schema( array( $site, $existing_page ) );
	check( contains( 'Clair de lune', node_of_type( $merged, 'WebPage' )['description'] ), 'Missing Yoast schema descriptions should receive the generated summary.' );
	$custom_context = (object) array( 'canonical' => 'https://example.test/custom-canonical/' );
	$merged = KML_SEO::filter_yoast_schema( array( $site, $existing_page ), $custom_context );
	check( 'https://example.test/custom-canonical/#midi' === node_of_type( $merged, 'AudioObject' )['@id'], 'Yoast custom canonicals must be used for new schema identifiers.' );
}

fwrite( STDOUT, "SEO regression checks passed ($integration): $checks\n" );
