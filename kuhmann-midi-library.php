<?php
/**
 * Plugin Name: Kuhmann MIDI Library
 * Description: Index and display a large MIDI directory as an SEO-friendly, navigable library (custom post type + folder taxonomy).
 * Version: 0.1.10
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: alexanderpeppe.com
 * License: GPLv2 or later
 * Text Domain: kuhmann-midi-library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KML_VERSION', '0.1.10' );
define( 'KML_PLUGIN_FILE', __FILE__ );
define( 'KML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once KML_PLUGIN_DIR . 'includes/class-kml-post-types.php';
require_once KML_PLUGIN_DIR . 'includes/class-kml-indexer.php';
require_once KML_PLUGIN_DIR . 'includes/class-kml-admin.php';
require_once KML_PLUGIN_DIR . 'includes/class-kml-shortcodes.php';
require_once KML_PLUGIN_DIR . 'includes/class-kml-public.php';

final class KML_Library_Plugin {

	/** @var KML_Library_Plugin|null */
	private static $instance = null;

	public static function instance(): KML_Library_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Core.
		add_action( 'init', array( 'KML_Post_Types', 'register' ) );

		// Admin.
		if ( is_admin() ) {
			KML_Admin::init();
		}

		// Public.
		KML_Public::init();

		// Shortcodes.
		KML_Shortcodes::init();

		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 20 );
		add_action( 'init', array( 'KML_Public', 'schedule_player_upload_purge' ), 30 );

		// Activation / deactivation.
		register_activation_hook( KML_PLUGIN_FILE, array( __CLASS__, 'on_activate' ) );
		register_deactivation_hook( KML_PLUGIN_FILE, array( __CLASS__, 'on_deactivate' ) );
	}

	public static function on_activate(): void {
		// Ensure CPT & taxonomy exist before flushing.
		KML_Post_Types::register();
		KML_Public::add_rewrite_rules();

		flush_rewrite_rules();
		update_option( 'kml_rewrite_version', KML_VERSION, false );
		KML_Public::schedule_player_upload_purge();

		// Schedule a lightweight daily scan (optional).
		if ( ! wp_next_scheduled( 'kml_daily_scan' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'kml_daily_scan' );
		}
	}

	public static function on_deactivate(): void {
		flush_rewrite_rules();
		delete_option( 'kml_rewrite_version' );

		$timestamp = wp_next_scheduled( 'kml_daily_scan' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'kml_daily_scan' );
		}

		$timestamp2 = wp_next_scheduled( 'kml_index_tick' );
		if ( $timestamp2 ) {
			wp_unschedule_event( $timestamp2, 'kml_index_tick' );
		}

		$timestamp3 = wp_next_scheduled( 'kml_purge_player_uploads' );
		if ( $timestamp3 ) {
			wp_unschedule_event( $timestamp3, 'kml_purge_player_uploads' );
		}
	}

	public static function maybe_flush_rewrite_rules(): void {
		if ( (string) get_option( 'kml_rewrite_version', '' ) === KML_VERSION ) {
			return;
		}

		KML_Post_Types::register();
		KML_Public::add_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( 'kml_rewrite_version', KML_VERSION, false );
	}
}

KML_Library_Plugin::instance();
