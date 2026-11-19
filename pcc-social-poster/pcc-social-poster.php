<?php
/**
 * Plugin Name: PCC Social Poster
 * Description: Pick a WooCommerce product category and auto-generate branded social media images with a fast no-AI template, then post them to Instagram, Facebook, TikTok, Threads, LinkedIn, Telegram (channel + private group), Tumblr, Pinterest, Bluesky, Mastodon and Reddit — combining multiple selected products into a single carousel post.
 * Version: 2.15.1
 * Author: PixelCubeCenter
 * Text Domain: pcc-sp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCC_SP_VERSION', '2.15.1' );
define( 'PCC_SP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PCC_SP_URL', plugin_dir_url( __FILE__ ) );
define( 'PCC_SP_TABLE_POSTS', 'pcc_sp_posts' );
define( 'PCC_SP_TABLE_JOBS', 'pcc_sp_jobs' );

require_once PCC_SP_PATH . 'includes/class-pcc-sp-db.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-settings.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-image-utils.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-template-renderer.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-instagram.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-facebook.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-fb-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-tiktok.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-tiktok-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-threads.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-threads-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-linkedin.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-linkedin-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-telegram.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-tumblr.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-tumblr-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-pinterest.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-pinterest-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-bluesky.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-mastodon.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-reddit.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-reddit-oauth.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-token-refresh.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-story.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-queue.php';
require_once PCC_SP_PATH . 'includes/class-pcc-sp-admin.php';

/**
 * The queue check used to run every single minute. It is now every 5
 * minutes, and queuing a post schedules an extra one-off check a few
 * seconds later (the 'pcc_sp_kick' hook), so new posts still start
 * promptly while an idle site does 5x less background work.
 */
function pcc_sp_schedule_tick() {
	if ( ! wp_next_scheduled( 'pcc_sp_tick' ) ) {
		wp_schedule_event( time() + 120, 'pcc_sp_five_min', 'pcc_sp_tick' );
	}
}

function pcc_sp_activate() {
	PCC_SP_DB::create_tables();
	pcc_sp_schedule_tick();
}
register_activation_hook( __FILE__, 'pcc_sp_activate' );

function pcc_sp_deactivate() {
	wp_clear_scheduled_hook( 'pcc_sp_tick' );
	wp_clear_scheduled_hook( 'pcc_sp_kick' );
}
register_deactivation_hook( __FILE__, 'pcc_sp_deactivate' );

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['pcc_sp_five_min'] = array(
		'interval' => 300,
		'display'  => __( 'Every 5 Minutes (PCC Social Poster)', 'pcc-sp' ),
	);
	return $schedules;
} );

add_action( 'admin_notices', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="notice notice-error"><p><strong>PCC Social Poster</strong> requires WooCommerce to be active.</p></div>';
	}
} );

/**
 * One-time cleanup when upgrading from a version that had the AI methods
 * or the old every-minute schedule.
 */
function pcc_sp_upgrade_from_legacy() {
	// Old every-minute event -> new 5-minute event.
	wp_clear_scheduled_hook( 'pcc_sp_tick' );
	pcc_sp_schedule_tick();

	// Drop the AI settings (including stored API keys) that no longer exist.
	$saved = get_option( PCC_SP_Settings::OPTION_KEY, array() );
	if ( is_array( $saved ) ) {
		$legacy = array( 'image_provider', 'gemini_api_key', 'gemini_model', 'openai_api_key', 'openai_model', 'logo_attachment_id', 'style_ref_attachment_id', 'extra_style_notes' );
		$dirty  = false;
		foreach ( $legacy as $key ) {
			if ( array_key_exists( $key, $saved ) ) {
				unset( $saved[ $key ] );
				$dirty = true;
			}
		}
		if ( $dirty ) {
			update_option( PCC_SP_Settings::OPTION_KEY, $saved );
		}
	}
}

add_action( 'plugins_loaded', function () {
	// dbDelta and the legacy cleanup only run when our version changed (e.g.
	// after updating the plugin files), not on every single page load.
	if ( get_option( 'pcc_sp_db_version' ) !== PCC_SP_VERSION ) {
		PCC_SP_DB::create_tables();
		pcc_sp_upgrade_from_legacy();
		update_option( 'pcc_sp_db_version', PCC_SP_VERSION );
	}

	PCC_SP_Queue::instance();
	PCC_SP_Story::init();

	// OAuth callbacks listen on clean-path URLs (cheap path check on init);
	// the admin UI is only built in wp-admin / admin-ajax, so normal page
	// views on the shop don't pay for it.
	PCC_SP_OAuth::instance();
	PCC_SP_FB_OAuth::instance();
	PCC_SP_TikTok_OAuth::instance();
	PCC_SP_Threads_OAuth::instance();
	PCC_SP_LinkedIn_OAuth::instance();
	PCC_SP_Tumblr_OAuth::instance();
	PCC_SP_Pinterest_OAuth::instance();
	PCC_SP_Reddit_OAuth::instance();

	if ( is_admin() ) {
		PCC_SP_Admin::instance();
	}
} );
