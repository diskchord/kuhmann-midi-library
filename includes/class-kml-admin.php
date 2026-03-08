<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KML_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		add_action( 'admin_post_kml_start_index', array( __CLASS__, 'handle_start_index' ) );
	}

	public static function admin_menu(): void {
		add_options_page(
			__( 'Kuhmann MIDI Library', 'kuhmann-midi-library' ),
			__( 'Kuhmann MIDI', 'kuhmann-midi-library' ),
			'manage_options',
			'kuhmann-midi-library',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting( 'kml_settings', 'kml_root_path', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_path' ) ) );
		register_setting( 'kml_settings', 'kml_url_base', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting( 'kml_settings', 'kml_batch_size', array( 'type' => 'integer', 'sanitize_callback' => array( __CLASS__, 'sanitize_batch' ) ) );

		add_settings_section(
			'kml_main',
			__( 'Library Settings', 'kuhmann-midi-library' ),
			function () {
				echo '<p>' . esc_html__( 'Point the plugin at the root folder that contains your MIDI directories. The plugin will index .mid/.midi files into a custom post type for SEO-friendly browsing.', 'kuhmann-midi-library' ) . '</p>';
			},
			'kml_settings'
		);

		add_settings_field(
			'kml_root_path',
			__( 'Root folder (server path)', 'kuhmann-midi-library' ),
			array( __CLASS__, 'field_root_path' ),
			'kml_settings',
			'kml_main'
		);

		add_settings_field(
			'kml_url_base',
			__( 'Public URL base (optional)', 'kuhmann-midi-library' ),
			array( __CLASS__, 'field_url_base' ),
			'kml_settings',
			'kml_main'
		);

		add_settings_field(
			'kml_batch_size',
			__( 'Index batch size', 'kuhmann-midi-library' ),
			array( __CLASS__, 'field_batch_size' ),
			'kml_settings',
			'kml_main'
		);
	}

	public static function sanitize_path( $value ): string {
		$value = (string) $value;
		$value = trim( $value );
		return rtrim( $value, "/\\ \t\n\r\0\x0B" );
	}

	public static function sanitize_batch( $value ): int {
		$val = (int) $value;
		if ( $val < 10 ) {
			$val = 10;
		}
		if ( $val > 2000 ) {
			$val = 2000;
		}
		return $val;
	}

	public static function field_root_path(): void {
		$val = esc_attr( get_option( 'kml_root_path', '' ) );
		echo '<input type="text" class="regular-text" name="kml_root_path" value="' . $val . '" placeholder="/var/www/.../uploads/kuhmann-midi" />';
		echo '<p class="description">' . esc_html__( 'Absolute server path. Must be readable by PHP. Example: /var/www/html/wp-content/uploads/kuhmann-midi', 'kuhmann-midi-library' ) . '</p>';
	}

	public static function field_url_base(): void {
		$val = esc_attr( get_option( 'kml_url_base', '' ) );
		echo '<input type="url" class="regular-text" name="kml_url_base" value="' . $val . '" placeholder="https://example.com/wp-content/uploads/kuhmann-midi" />';
		echo '<p class="description">' . esc_html__( 'Optional: if your MIDI files are publicly accessible via HTTP, set the matching URL base. If empty, the plugin will still offer a download endpoint that streams files.', 'kuhmann-midi-library' ) . '</p>';
	}

	public static function field_batch_size(): void {
		$val = (int) get_option( 'kml_batch_size', 300 );
		echo '<input type="number" min="10" max="2000" name="kml_batch_size" value="' . esc_attr( (string) $val ) . '" />';
		echo '<p class="description">' . esc_html__( 'How many MIDI files to process per index tick (WP-Cron). Increase if indexing is slow; decrease if you hit timeouts.', 'kuhmann-midi-library' ) . '</p>';
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = KML_Indexer::get_status();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kuhmann MIDI Library', 'kuhmann-midi-library' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'kml_settings' );
				do_settings_sections( 'kml_settings' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php echo esc_html__( 'Indexing', 'kuhmann-midi-library' ); ?></h2>
			<p>
				<?php if ( ! empty( $status['root_path'] ) ) : ?>
					<strong><?php echo esc_html__( 'Root:', 'kuhmann-midi-library' ); ?></strong>
					<code><?php echo esc_html( $status['root_path'] ); ?></code>
				<?php else : ?>
					<strong><?php echo esc_html__( 'Root:', 'kuhmann-midi-library' ); ?></strong>
					<?php echo esc_html__( 'Not set', 'kuhmann-midi-library' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php echo esc_html__( 'Status:', 'kuhmann-midi-library' ); ?></strong>
				<?php echo $status['indexing'] ? esc_html__( 'Indexing in progress…', 'kuhmann-midi-library' ) : esc_html__( 'Idle', 'kuhmann-midi-library' ); ?>
				<?php if ( $status['indexing'] ) : ?>
					(<?php echo esc_html( sprintf( __( '%d directories remaining in queue', 'kuhmann-midi-library' ), (int) $status['queue_len'] ) ); ?>)
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="kml_start_index" />
				<?php wp_nonce_field( 'kml_start_index' ); ?>
				<?php submit_button( __( 'Start / Rebuild Index', 'kuhmann-midi-library' ), 'primary' ); ?>
			</form>

			<p class="description">
				<?php echo esc_html__( 'Tip: If you have SSH access, you can run a full index via WP-CLI: wp kml index --batch=800', 'kuhmann-midi-library' ); ?>
			</p>
		</div>
		<?php
	}

	public static function handle_start_index(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kuhmann-midi-library' ) );
		}
		check_admin_referer( 'kml_start_index' );

		KML_Indexer::start_index();

		wp_safe_redirect( admin_url( 'options-general.php?page=kuhmann-midi-library&kml_index_started=1' ) );
		exit;
	}
}
