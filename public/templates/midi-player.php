<?php
/**
 * Template: Direct MIDI Player
 *
 * Handles /midi-player/{path-relative-to-web-root}
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$player_file = KML_Public::get_direct_player_file();

if ( empty( $player_file['found'] ) ) {
	global $wp_query;
	if ( $wp_query && method_exists( $wp_query, 'set_404' ) ) {
		$wp_query->set_404();
	}
	status_header( 404 );
	nocache_headers();
}

$file_url = ! empty( $player_file['file_url'] ) ? (string) $player_file['file_url'] : '';
$filename = ! empty( $player_file['filename'] ) ? (string) $player_file['filename'] : __( 'MIDI file', 'kuhmann-midi-library' );
$relpath  = ! empty( $player_file['relpath'] ) ? (string) $player_file['relpath'] : '';
$filesize = ! empty( $player_file['filesize'] ) ? (int) $player_file['filesize'] : 0;
$mtime    = ! empty( $player_file['mtime'] ) ? (int) $player_file['mtime'] : 0;

wp_enqueue_style(
	'kml-public',
	KML_PLUGIN_URL . 'public/assets/css/kml-public.css',
	array(),
	KML_VERSION
);

wp_enqueue_script(
	'tonejs',
	'https://cdn.jsdelivr.net/npm/tone@14.8.49/build/Tone.js',
	array(),
	null,
	true
);

wp_enqueue_script(
	'tonejs-midi',
	plugins_url( 'public/assets/Midi.js', KML_PLUGIN_FILE ),
	array(),
	'2.0.28',
	true
);

$pianoroll_path = KML_PLUGIN_DIR . 'public/assets/kml-pianoroll.js';
$pianoroll_ver  = file_exists( $pianoroll_path ) ? (string) filemtime( $pianoroll_path ) : KML_VERSION;

wp_enqueue_script(
	'kml-pianoroll',
	plugins_url( 'public/assets/kml-pianoroll.js', KML_PLUGIN_FILE ),
	array( 'tonejs', 'tonejs-midi' ),
	$pianoroll_ver,
	true
);

get_header();
?>

<style>
.kml-roll{
  border: 1px solid rgba(0,0,0,.12);
  border-radius: 14px;
  padding: 12px;
  background: #fff;
  box-shadow: 0 6px 22px rgba(0,0,0,.06);
}

.kml-roll-controls{
  display:flex;
  flex-wrap: wrap;
  gap: 10px 14px;
  align-items: center;
  margin-bottom: 10px;
}

.kml-btn{
  border: 1px solid rgba(0,0,0,.16);
  background: #fff;
  border-radius: 999px;
  padding: 8px 12px;
  color: #000;
  cursor: pointer;
}

.kml-label{
  display:flex;
  align-items:center;
  gap: 8px;
  font-size: 13px;
  opacity: .9;
}

.kml-label input[type="range"]{
  width: 160px;
}

.kml-time{
  margin-left:auto;
  font-variant-numeric: tabular-nums;
  font-size: 13px;
  opacity: .85;
}

.kml-canvas{
  display:block;
  width:100%;
  height: 560px;
  border-radius: 12px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.02);
}
@media (max-width: 600px){
  .kml-label input[type="range"]{ width: 120px; }
  .kml-canvas{ height: 440px; }
}
</style>

<main id="primary" class="site-main kml-single kml-midi-player-single">
	<article class="kml-midi-player-page">
		<header class="entry-header">
			<h1 class="entry-title kml-player-title"><?php echo esc_html( sprintf( __( 'MIDI Player: %s', 'kuhmann-midi-library' ), $filename ) ); ?></h1>
		</header>

		<div class="entry-content">
			<?php if ( $file_url ) : ?>
				<section class="kml-player">
					<div class="kml-roll" data-midi-url="<?php echo esc_url( $file_url ); ?>" id="kml_direct_player_roll">
						<div class="kml-roll-controls">
							<button type="button" class="kml-btn kml-play"><?php echo esc_html__( 'Play', 'kuhmann-midi-library' ); ?></button>
							<button type="button" class="kml-btn kml-stop"><?php echo esc_html__( 'Stop', 'kuhmann-midi-library' ); ?></button>

							<label class="kml-label"><?php echo esc_html__( 'Tempo', 'kuhmann-midi-library' ); ?>
								<input class="kml-tempo" type="range" min="50" max="160" value="100">
								<span class="kml-tempo-val">100%</span>
							</label>

							<label class="kml-label"><?php echo esc_html__( 'Volume', 'kuhmann-midi-library' ); ?>
								<input class="kml-volume" type="range" min="0" max="200" value="100">
								<span class="kml-volume-val">100%</span>
							</label>

							<label class="kml-label"><?php echo esc_html__( 'Zoom', 'kuhmann-midi-library' ); ?>
								<input class="kml-zoom" type="range" min="20" max="220" value="90">
								<span class="kml-zoom-val">90</span>
							</label>

							<span class="kml-time">0:00 / --:--</span>
						</div>

						<canvas class="kml-canvas" height="560"></canvas>
					</div>

					<ul class="kml-meta">
						<?php if ( $relpath ) : ?>
							<li><strong><?php echo esc_html__( 'Path:', 'kuhmann-midi-library' ); ?></strong> <code><?php echo esc_html( '/' . $relpath ); ?></code></li>
						<?php endif; ?>
						<?php if ( $filesize ) : ?>
							<li><strong><?php echo esc_html__( 'Size:', 'kuhmann-midi-library' ); ?></strong> <?php echo esc_html( size_format( $filesize ) ); ?></li>
						<?php endif; ?>
						<?php if ( $mtime ) : ?>
							<li><strong><?php echo esc_html__( 'Updated:', 'kuhmann-midi-library' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), $mtime ) ); ?></li>
						<?php endif; ?>
					</ul>

					<p><a class="kml-download" href="<?php echo esc_url( $file_url ); ?>"><?php echo esc_html__( 'Open MIDI file', 'kuhmann-midi-library' ); ?></a></p>
				</section>
			<?php else : ?>
				<p><?php echo esc_html__( 'The requested MIDI file could not be found or is not readable.', 'kuhmann-midi-library' ); ?></p>
			<?php endif; ?>
		</div>
	</article>
</main>

<?php
get_footer();
