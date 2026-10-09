<?php
/** Dependency-free checks for the local-file player shortcode. Run: php tests/player-shortcode.php */
define( 'ABSPATH', __DIR__ . '/' );
define( 'KML_PLUGIN_FILE', dirname( __DIR__ ) . '/kuhmann-midi-library.php' );
define( 'KML_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'KML_PLUGIN_URL', 'https://example.test/wp-content/plugins/kuhmann-midi-library/' );
define( 'KML_VERSION', 'test' );

function shortcode_atts( $defaults, $atts, $tag ) { return array_merge( $defaults, array_intersect_key( $atts, $defaults ) ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain ) { return esc_html( $value ); }
function esc_attr__( $value, $domain ) { return esc_attr( $value ); }
function esc_url_raw( $value ) { return preg_match( '/^https?:\/\//i', $value ) ? $value : ''; }
function esc_url( $value ) { return esc_attr( esc_url_raw( $value ) ); }
function plugins_url( $path, $plugin ) { return KML_PLUGIN_URL . $path; }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function wp_basename( $path ) { return basename( $path ); }
function wp_generate_uuid4() { static $id = 0; return 'test-player-' . ++$id; }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}

require_once KML_PLUGIN_DIR . 'includes/class-kml-public.php';
require_once KML_PLUGIN_DIR . 'includes/class-kml-shortcodes.php';

$checks = 0;
function check( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, "FAILED: $message\n" );
		exit( 1 );
	}
}
function contains( $needle, $haystack ) { return false !== strpos( $haystack, $needle ); }

$html = KML_Shortcodes::shortcode_midi_player();
check( contains( 'class="kml-upload-input" type="file"', $html ), 'The standalone player must retain an accessible native file picker.' );
check( ! contains( '<form', $html ) && ! contains( 'type="submit"', $html ), 'Choosing a local file must not create a server submission path or a separate Load step.' );
check( ! contains( 'name="kml_midi_file"', $html ) && ! contains( 'multipart/form-data', $html ), 'Local file bytes must not become a successful form field, even inside an enclosing form.' );
check( contains( 'Your MIDI file stays on your device.', $html ) && contains( 'drag one onto the player', $html ), 'The local picker should explain file handling and dropping.' );
check( contains( 'data-midi-url=""', $html ), 'An empty player must wait for file selection or Demo, without auto-loading the old chromatic scale.' );
check( contains( 'class="kml-btn kml-demo"', $html ) && contains( 'data-demo-url="' . KML_PLUGIN_URL . 'public/assets/demo-the-man-that-got-away.mid"', $html ), 'Try a demo must target the original archive performance.' );
check( contains( 'data-demo-title="The Man That Got Away"', $html ), 'The demo must display the actual archive song title.' );
check( is_readable( KML_PLUGIN_DIR . 'public/assets/demo-the-man-that-got-away.mid' ), 'The configured demo asset must be present.' );
check( 'e693d173f1e509876f11bdb847cbecb291637ee9ce66b2abddaa7be30a727d6b' === hash_file( 'sha256', KML_PLUGIN_DIR . 'public/assets/demo-the-man-that-got-away.mid' ), 'The demo must preserve the complete original archive MIDI byte-for-byte.' );
check( ! method_exists( 'KML_Public', 'save_player_upload' ) && ! method_exists( 'KML_Public', 'get_uploaded_player_file' ), 'Public player methods must no longer accept or retrieve server uploads.' );

// An old cached form or bookmarked upload URL must not revive server processing.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'kml_midi_player_upload' => '1', 'kml_midi_player_nonce' => 'legacy' );
$_FILES = array( 'kml_midi_file' => array( 'name' => 'private.mid', 'tmp_name' => '/tmp/private.mid', 'error' => 0 ) );
$_GET = array( 'kml_uploaded_midi' => 'private.mid' );
$legacy = KML_Shortcodes::shortcode_midi_player();
check( contains( 'data-midi-url=""', $legacy ) && ! contains( 'private.mid', $legacy ), 'Legacy POST and GET upload parameters must be ignored.' );

$explicit = KML_Shortcodes::shortcode_midi_player( array( 'src' => '/archive/performance.mid', 'upload' => '0' ) );
check( contains( 'data-midi-url="https://example.test/archive/performance.mid"', $explicit ), 'Existing explicit root-relative MIDI sources must still load.' );
check( contains( 'data-midi-title="performance.mid"', $explicit ), 'The initial source filename must reach the dynamic player summary.' );
check( ! contains( 'type="file"', $explicit ) && contains( 'data-local-files="0"', $explicit ), 'The legacy upload=0 option must disable the local picker and drop handling.' );
check( ! contains( 'kml-player-title', $explicit ), 'The initial title must not leave a stale second heading after the active file changes.' );

foreach ( array( 'false', 'no', 'off' ) as $value ) {
	$disabled = KML_Shortcodes::shortcode_midi_player( array( 'upload' => $value ) );
	check( ! contains( 'type="file"', $disabled ) && contains( 'kml-demo', $disabled ), 'False upload options must retain a working demo for an otherwise empty player.' );
}
$multiple = $html . KML_Shortcodes::shortcode_midi_player();
preg_match_all( '/\bid="([^"]+)"/', $multiple, $ids );
check( count( $ids[1] ) === count( array_unique( $ids[1] ) ), 'Multiple shortcode players must use distinct picker and help IDs.' );
$quoted = KML_Shortcodes::shortcode_midi_player( array( 'src' => 'https://example.test/song" onmouseover="bad.mid' ) );
check( ! contains( ' onmouseover="', $quoted ), 'Source titles and attributes must escape untrusted URL text.' );

fwrite( STDOUT, "Local player shortcode checks passed: $checks\n" );
