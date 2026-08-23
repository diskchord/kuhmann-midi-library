<?php
/**
 * Template: Single MIDI File
 *
 * You can override this template by copying it to your theme:
 * single-kml_midi.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

the_post();

$post_id  = get_the_ID();
$file_url = KML_Public::get_public_file_url( (int) $post_id );
$abs_path = (string) get_post_meta( $post_id, 'kml_abspath', true );
$relpath  = (string) get_post_meta( $post_id, 'kml_relpath', true );
$filesize = (int) get_post_meta( $post_id, 'kml_filesize', true );
$mtime    = (int) get_post_meta( $post_id, 'kml_mtime', true );

$has_readable_file = ( '' !== $abs_path ) && file_exists( $abs_path ) && is_readable( $abs_path );
$download_url      = $has_readable_file ? KML_Public::get_download_url( (int) $post_id ) : '';

$terms = get_the_terms( $post_id, KML_Post_Types::TAX_FOLDER );
$folder_term = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;

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
<?php
wp_enqueue_script(
  'tonejs',
  'https://cdn.jsdelivr.net/npm/tone@14.8.49/build/Tone.js',
  [],
  null,
  true
);

wp_enqueue_script(
  'tonejs-midi',
  plugins_url('public/assets/Midi.js', KML_PLUGIN_FILE),
  [],
  '2.0.28',
  true
);

wp_enqueue_script(
  'soundfont-player',
  'https://cdn.jsdelivr.net/npm/soundfont-player@0.12.0/dist/soundfont-player.min.js',
  [],
  '0.12.0',
  true
);

$pianoroll_path = KML_PLUGIN_DIR . 'public/assets/kml-pianoroll.js';
$pianoroll_ver  = file_exists( $pianoroll_path ) ? (string) filemtime( $pianoroll_path ) : KML_VERSION;

wp_enqueue_script(
  'kml-pianoroll',
  plugins_url('public/assets/kml-pianoroll.js', KML_PLUGIN_FILE),
  ['tonejs', 'tonejs-midi', 'soundfont-player'],
  $pianoroll_ver,
  true
);
?>
<main id="primary" class="site-main kml-single">

	<article <?php post_class( 'kml-midisingle' ); ?>>
		<header class="entry-header">
			<h1 class="entry-title"><?php the_title(); ?></h1>

			<?php if ( $folder_term ) : ?>
				<nav class="kml-breadcrumbs" aria-label="<?php echo esc_attr__( 'Breadcrumbs', 'kuhmann-midi-library' ); ?>">
					<a href="<?php echo esc_url( get_post_type_archive_link( KML_Post_Types::POST_TYPE ) ); ?>"><?php echo esc_html__( 'MIDI Library', 'kuhmann-midi-library' ); ?></a>
					<span class="kml-sep">/</span>
					<a href="<?php echo esc_url( get_term_link( $folder_term ) ); ?>"><?php echo esc_html( $folder_term->name ); ?></a>
				</nav>
			<?php endif; ?>
		</header>

		<div class="entry-content">
<?php
if (function_exists('do_shortcode')) {
  echo do_shortcode('[aps_midi_meta]');
}
?>
					<?php if ( $download_url ) : ?>
						<div class="kml-actions">
							<a class="kml-download" href="<?php echo esc_url( $download_url ); ?>" rel="nofollow">
								<?php echo esc_html__( 'Download MIDI', 'kuhmann-midi-library' ); ?>
							</a>
						</div>
					<?php endif; ?>

			<ul class="kml-meta">
				<?php if ( $relpath ) : ?>
					<li><strong><?php echo esc_html__( 'Path:', 'kuhmann-midi-library' ); ?></strong> <code><?php echo esc_html( $relpath ); ?></code></li>
				<?php endif; ?>
				<?php if ( $filesize ) : ?>
					<li><strong><?php echo esc_html__( 'Size:', 'kuhmann-midi-library' ); ?></strong> <?php echo esc_html( size_format( $filesize ) ); ?></li>
				<?php endif; ?>
				<?php if ( $mtime ) : ?>
					<li><strong><?php echo esc_html__( 'Updated:', 'kuhmann-midi-library' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), $mtime ) ); ?></li>
				<?php endif; ?>
			</ul>

			<?php
			// Optional: browser playback (requires public file URL).
			if ( $file_url ) :
			?>
				<section class="kml-player">

<?php if ( $file_url ) :
  $uid = 'kml_' . get_the_ID();
?>
  <section class="kml-player">

<?php if ( $file_url ) :
  $uid = 'kml_' . get_the_ID();
?>
  <section class="kml-player">
    <h2><?php echo esc_html__( 'Play (beta)', 'kuhmann-midi-library' ); ?></h2>

    <div class="kml-roll" data-midi-url="<?php echo esc_url( $file_url ); ?>" id="<?php echo esc_attr($uid); ?>_roll">
      <div class="kml-roll-controls">
        <button type="button" class="kml-btn kml-play">Play</button>
        <button type="button" class="kml-btn kml-stop">Stop</button>

        <label class="kml-label">Tempo
          <input class="kml-tempo" type="range" min="50" max="160" value="100">
          <span class="kml-tempo-val">100%</span>
        </label>

        <label class="kml-label">Volume
          <input class="kml-volume" type="range" min="0" max="200" value="100">
          <span class="kml-volume-val">100%</span>
        </label>

        <label class="kml-label">Zoom
          <input class="kml-zoom" type="range" min="20" max="220" value="90">
          <span class="kml-zoom-val">90</span>
        </label>

        <span class="kml-time">0:00 / --:--</span>
      </div>

      <canvas class="kml-canvas" height="560"></canvas>
    </div>
<p><i>Please note that this player is in Beta, and that it often does not play Yamaha XG format files correctly.</i></p>
  </section>
<?php endif; ?><!--
    <h2><?php echo esc_html__( 'Play', 'kuhmann-midi-library' ); ?></h2>

    <div class="kml-midi-card" style="margin-bottom: 20px;">
      <midi-player
        id="<?php echo esc_attr( $uid ); ?>_player"
        src="<?php echo esc_url( $file_url ); ?>"
        sound-font
        visualizer="#<?php echo esc_attr( $uid ); ?>_viz">
      </midi-player>
    </div>

  <!--  <h2><?php echo esc_html__( 'Visualization (beta)', 'kuhmann-midi-library' ); ?></h2>
<p>As you play the piece, you can watch the notes play in the song.</p>

    <div class="kml-midi-card">
      <midi-visualizer
        style="height: 420px; overflow: hidden: border-radius: 12px;"
        id="<?php echo esc_attr( $uid ); ?>_viz"
	type="piano-roll"
        for="<?php echo esc_attr( $uid ); ?>_player">
      </midi-visualizer>

    </div>
  </section>-->
<?php endif; ?>

				</section>
			<?php endif; ?>
    
<h2><?php echo esc_html__( 'Instructions', 'kuhmann-midi-library' ); ?></h2>

			<?php
			// Post content (optional SEO description).
			if ( trim( get_the_content() ) ) {
				the_content();
			} else {
				echo '<p>If your piano has a Bluetooth-MIDI adapter, you can send this file directly from your phone or tablet. <a href="https://www.alexanderpeppe.com/aps-notecast-beta-sign-up-send-midi-files-using-bluetooth-midi/">APS NoteCast</a> supports Android devices; <a href="https://www.alexanderpeppe.com/pianostream-for-disklaviers/">PianoStream</a>, Sweet MIDI, and other Bluetooth-MIDI apps are also available depending on your device.<br /><br />Otherwise, you can put them on a USB stick. Files will be playable natively on newer Disklaviers, such as the Mark IV, E3, and ENSPIRE. You can see my <a href="https://www.alexanderpeppe.com/disklavier-compatibility-table/">Disklavier compatibility table</a> to see which instruments support USB.<br /><br />For older generations of Disklavier using floppy disks or Nalbantov USB emulators, see my article on <a href="https://www.alexanderpeppe.com/eseq-and-pianodir-fil/">converting MIDI files to E-SEQ and creating PIANODIR.FIL</a>.<br /><br />Read more about <a href="https://www.alexanderpeppe.com/kuhmann-disklavier-world/">the former Kuhmann Directory (Disklavier World)</a>.</p>';
				}
				?>

			<p class="kml-entry-source">
				<em>
					<?php echo esc_html__( 'Source:', 'kuhmann-midi-library' ); ?>
					<a href="<?php echo esc_url( 'https://github.com/diskchord/kuhmann-midi-library' ); ?>" rel="external">https://github.com/diskchord/kuhmann-midi-library</a>
				</em>
			</p>

			</div>
	</article>

</main>

<?php
// Lightweight JSON-LD for SEO.
$schema = array(
	'@context'       => 'https://schema.org',
	'@type'          => 'MusicRecording',
	'name'           => get_the_title(),
	'encodingFormat' => 'audio/midi',
	'isAccessibleForFree' => true,
);
if ( $download_url ) {
	$schema['contentUrl'] = $download_url;
}
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema ); ?></script>
<script type="text/javascript">(async function () {
  // Wait until the custom elements are registered
  if (window.customElements?.whenDefined) {
    await customElements.whenDefined("midi-player");
    await customElements.whenDefined("midi-visualizer");
  }

  document.querySelectorAll("midi-player").forEach(player => {
    const sel = player.getAttribute("visualizer");
    if (!sel) return;

    // visualizer attribute can contain comma-separated selectors
    const firstSel = sel.split(",")[0].trim();
    const viz = document.querySelector(firstSel);
    if (!viz) return;

    // Bulletproof: bind programmatically
    if (typeof player.addVisualizer === "function") {
      player.addVisualizer(viz);
    }

    // If either src is missing, copy it from the other
    const pSrc = player.getAttribute("src");
    const vSrc = viz.getAttribute("src");
    if (pSrc && !vSrc) viz.setAttribute("src", pSrc);
    if (vSrc && !pSrc) player.setAttribute("src", vSrc);
  });
})();

document.addEventListener("DOMContentLoaded", async () => {
  await customElements.whenDefined("midi-player");

  document.querySelectorAll(".kml-player").forEach(section => {
    const player = section.querySelector("midi-player");
    const viz = section.querySelector("midi-visualizer");
    if (!player || !viz) return;

    player.addEventListener("load", () => {
      const ns = player.noteSequence;
      if (!ns?.notes?.length) return;

      // For each pitch, ensure notes do not overlap: if a new note starts,
      // end the previous one at that start time.
      const lastByPitch = new Map();

      // Notes are usually in time order, but sort to be safe
      ns.notes.sort((a,b) => a.startTime - b.startTime);

      for (const n of ns.notes) {
        const key = n.pitch; // you can also include instrument/program if you want
        const prev = lastByPitch.get(key);
        if (prev && prev.endTime > n.startTime) {
          prev.endTime = n.startTime;
        }
        lastByPitch.set(key, n);
      }

      // Feed the visualizer from the same sequence
      viz.noteSequence = ns;
    });
  });
});
</script>

<?php
get_footer();
