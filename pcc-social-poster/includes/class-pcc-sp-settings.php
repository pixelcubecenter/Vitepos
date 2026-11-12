<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Settings {

	const OPTION_KEY = 'pcc_sp_settings';

	public static function defaults() {
		return array(
			// Captions.
			'website_text'            => 'pixelcubecenter.com',
			'phone_text'              => '',
			'currency_label'          => 'IQD',
			'caption_template'        => "{title}\n\nPrice: {price} {currency}\nAvailable now at {website}",

			// Template image settings.
			'template_base_attachment_id' => 0,
			'tpl_image_box'           => '507,395,566,486',
			'tpl_category_box'        => '320,132,567,113',
			'tpl_title_box'           => '99,250,1001,141',
			'tpl_price_box'           => '118,501,252,74',
			'tpl_text_color'          => '#123F3B',
			'tpl_price_color'         => '#FFFFFF',
			'tpl_bg_tolerance'        => 30,

			// Stories (Instagram + Facebook Page). Template is 1206x2160 (9:16).
			'story_template_attachment_id' => 0,
			'story_image_box'         => '149,724,931,798',
			'story_category_box'      => '240,305,733,146',
			'story_title_box'         => '42,466,1113,182',
			'story_price_box'         => '452,1811,325,96',
			'story_text_color'        => '#123F3B',
			'story_price_color'       => '#FFFFFF',
			'story_platform_instagram' => '1',
			'story_platform_facebook' => '1',
			'story_auto_pos'          => '1',

			// Instagram.
			'ig_access_token'         => '',
			'ig_business_account_id'  => '',
			'ig_app_id'               => '',
			'ig_app_secret'           => '',
			'graph_host'              => 'graph.instagram.com',
			'fb_graph_version'        => 'v21.0',

			// Facebook Page.
			'fb_app_id'               => '',
			'fb_app_secret'           => '',
			'fb_config_id'            => '',
			'fb_page_id'              => '',
			'fb_page_name'            => '',
			'fb_page_access_token'    => '',

			// TikTok.
			'tiktok_client_key'       => '',
			'tiktok_client_secret'    => '',
			'tiktok_access_token'     => '',
			'tiktok_refresh_token'    => '',
			'tiktok_open_id'          => '',
			'tiktok_username'         => '',
			'tiktok_post_mode'        => 'draft',

			// Threads.
			'threads_app_id'          => '',
			'threads_app_secret'      => '',
			'threads_access_token'    => '',
			'threads_user_id'         => '',
			'threads_graph_version'   => 'v1.0',

			// LinkedIn Company Page.
			'linkedin_client_id'      => '',
			'linkedin_client_secret'  => '',
			'linkedin_access_token'   => '',
			'linkedin_expires_at'     => '',
			'linkedin_org_id'         => '',
			'linkedin_api_version'    => '202609',

			// Telegram channel.
			'telegram_bot_token'      => '',
			'telegram_chat_id'        => '',
			'telegram_chat_title'     => '',

			// Telegram Private (images only, to a group).
			'telegram_private_bot_token'  => '',
			'telegram_private_chat_id'    => '',
			'telegram_private_chat_title' => '',

			// Tumblr.
			'tumblr_client_id'        => '',
			'tumblr_client_secret'    => '',
			'tumblr_blog'             => '',
			'tumblr_state'            => 'published',
			'tumblr_tags'             => '',
			'tumblr_access_token'     => '',
			'tumblr_refresh_token'    => '',
			'tumblr_expires_at'       => '',

			// Pinterest.
			'pinterest_client_id'     => '',
			'pinterest_client_secret' => '',
			'pinterest_environment'   => 'sandbox',
			'pinterest_sandbox_token' => '',
			'pinterest_board_id'      => '',
			'pinterest_access_token'  => '',
			'pinterest_refresh_token' => '',
			'pinterest_expires_at'    => '',

			// Bluesky.
			'bluesky_identifier'      => '',
			'bluesky_app_password'    => '',

			// Mastodon.
			'mastodon_instance'       => '',
			'mastodon_access_token'   => '',
			'mastodon_visibility'     => 'public',

			// Reddit.
			'reddit_client_id'        => '',
			'reddit_client_secret'    => '',
			'reddit_access_token'     => '',
			'reddit_refresh_token'    => '',
			'reddit_expires_at'       => '',
			'reddit_username'         => '',
			'reddit_subreddit'        => '',

			// Posting pace.
			'post_interval_minutes'   => 15,
			'max_posts_per_day'       => 10,
		);
	}

	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::get_all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save( array $data ) {
		$defaults  = self::defaults();
		$current   = self::get_all();
		$sanitized = $current;

		$int_fields      = array( 'template_base_attachment_id', 'story_template_attachment_id', 'post_interval_minutes', 'max_posts_per_day', 'tpl_bg_tolerance' );
		$textarea_fields = array( 'caption_template' );

		foreach ( $defaults as $key => $default_value ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, $int_fields, true ) ) {
				$sanitized[ $key ] = absint( $data[ $key ] );
			} elseif ( in_array( $key, $textarea_fields, true ) ) {
				$sanitized[ $key ] = sanitize_textarea_field( wp_unslash( $data[ $key ] ) );
			} else {
				$sanitized[ $key ] = trim( sanitize_text_field( wp_unslash( $data[ $key ] ) ) );
			}
		}

		update_option( self::OPTION_KEY, $sanitized );
		return $sanitized;
	}

	public static function is_template_configured() {
		$s = self::get_all();
		return ! empty( $s['template_base_attachment_id'] );
	}

	public static function is_instagram_configured() {
		$s = self::get_all();
		return ! empty( $s['ig_access_token'] ) && ! empty( $s['ig_business_account_id'] );
	}

	public static function is_facebook_configured() {
		$s = self::get_all();
		return ! empty( $s['fb_page_access_token'] ) && ! empty( $s['fb_page_id'] );
	}

	public static function is_threads_configured() {
		$s = self::get_all();
		return ! empty( $s['threads_access_token'] ) && ! empty( $s['threads_user_id'] );
	}

	public static function is_linkedin_configured() {
		$s = self::get_all();
		return ! empty( $s['linkedin_access_token'] ) && ! empty( $s['linkedin_org_id'] );
	}

	public static function is_telegram_configured() {
		$s = self::get_all();
		return ! empty( $s['telegram_bot_token'] ) && ! empty( $s['telegram_chat_id'] );
	}

	/**
	 * Needs a group ID, and a bot token (its own, or the main Telegram bot's).
	 */
	public static function is_telegram_private_configured() {
		$s = self::get_all();
		return ! empty( $s['telegram_private_chat_id'] ) && ( ! empty( $s['telegram_private_bot_token'] ) || ! empty( $s['telegram_bot_token'] ) );
	}

	public static function is_tumblr_configured() {
		$s = self::get_all();
		return ( ! empty( $s['tumblr_refresh_token'] ) || ! empty( $s['tumblr_access_token'] ) ) && ! empty( $s['tumblr_blog'] );
	}

	public static function is_pinterest_configured() {
		$s = self::get_all();
		$has_login = ! empty( $s['pinterest_refresh_token'] ) || ! empty( $s['pinterest_access_token'] ) || ( 'production' !== $s['pinterest_environment'] && ! empty( $s['pinterest_sandbox_token'] ) );
		return $has_login && ! empty( $s['pinterest_board_id'] );
	}

	public static function is_bluesky_configured() {
		$s = self::get_all();
		return ! empty( $s['bluesky_identifier'] ) && ! empty( $s['bluesky_app_password'] );
	}

	public static function is_mastodon_configured() {
		$s = self::get_all();
		return ! empty( $s['mastodon_instance'] ) && ! empty( $s['mastodon_access_token'] );
	}

	public static function is_reddit_configured() {
		$s = self::get_all();
		return ! empty( $s['reddit_refresh_token'] );
	}

	public static function is_tiktok_configured() {
		$s = self::get_all();
		return ! empty( $s['tiktok_access_token'] ) && ! empty( $s['tiktok_open_id'] );
	}

	/**
	 * Parse a "x,y,w,h" box string into an array. Falls back to the default
	 * box for that key if the stored value is malformed.
	 */
	public static function get_box( $key ) {
		$s      = self::get_all();
		$raw    = isset( $s[ $key ] ) ? $s[ $key ] : '';
		$parts  = array_map( 'trim', explode( ',', $raw ) );
		if ( count( $parts ) !== 4 ) {
			$parts = array_map( 'trim', explode( ',', self::defaults()[ $key ] ) );
		}
		list( $x, $y, $w, $h ) = array_map( 'intval', $parts );
		return array( 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h );
	}
}
