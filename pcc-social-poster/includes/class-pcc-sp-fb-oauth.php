<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_FB_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-fb-callback';
	const PAGES_TRANSIENT = 'pcc_sp_fb_pages_choice';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_fb', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_select_fb_page', array( $this, 'select_page' ) );
		add_action( 'admin_post_pcc_sp_disconnect_fb', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_fb' ), 'pcc_sp_connect_fb' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_fb' ), 'pcc_sp_disconnect_fb' );
	}

	public static function select_page_url( $page_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_select_fb_page&page_id=' . rawurlencode( $page_id ) ), 'pcc_sp_select_fb_page' );
	}

	public function maybe_handle_callback() {
		$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		if ( untrailingslashit( $path ) === self::CALLBACK_PATH ) {
			$this->callback();
			exit;
		}
	}

	public function connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_connect_fb' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['fb_app_id'] ) || empty( $settings['fb_app_secret'] ) ) {
			wp_die( 'Set your Facebook App ID and App Secret first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_fb_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['fb_app_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'state'         => $state,
		);

		if ( ! empty( $settings['fb_config_id'] ) ) {
			// Newer "Facebook Login for Business" apps require a pre-made
			// Configuration (which bundles the asset type + permissions)
			// instead of a plain scope list.
			$params['config_id'] = $settings['fb_config_id'];
		} else {
			$params['scope'] = 'pages_show_list,pages_manage_posts,pages_read_engagement';
		}

		$version = ! empty( $settings['fb_graph_version'] ) ? $settings['fb_graph_version'] : 'v21.0';
		wp_redirect( "https://www.facebook.com/{$version}/dialog/oauth?" . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$this->fail( isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.' );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_fb_oauth_state' );
		delete_transient( 'pcc_sp_fb_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$settings = PCC_SP_Settings::get_all();
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$version  = ! empty( $settings['fb_graph_version'] ) ? $settings['fb_graph_version'] : 'v21.0';

		// Exchange the code for a short-lived user access token.
		$token_url = add_query_arg( array(
			'client_id'     => $settings['fb_app_id'],
			'redirect_uri'  => self::redirect_uri(),
			'client_secret' => $settings['fb_app_secret'],
			'code'          => $code,
		), "https://graph.facebook.com/{$version}/oauth/access_token" );

		$token_resp = wp_remote_get( $token_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $token_resp ) ) {
			$this->fail( $token_resp->get_error_message() );
		}
		$token_payload = json_decode( wp_remote_retrieve_body( $token_resp ), true );
		if ( empty( $token_payload['access_token'] ) ) {
			$msg = isset( $token_payload['error']['message'] ) ? $token_payload['error']['message'] : 'No access token returned by Facebook.';
			$this->fail( $msg );
		}
		$short_user_token = $token_payload['access_token'];

		// Exchange for a long-lived user token (~60 days) so the Page tokens
		// we derive from it below are long-lived too.
		$exchange_url = add_query_arg( array(
			'grant_type'        => 'fb_exchange_token',
			'client_id'         => $settings['fb_app_id'],
			'client_secret'     => $settings['fb_app_secret'],
			'fb_exchange_token' => $short_user_token,
		), "https://graph.facebook.com/{$version}/oauth/access_token" );

		$exchange_resp = wp_remote_get( $exchange_url, array( 'timeout' => 60 ) );
		$long_user_token = $short_user_token;
		if ( ! is_wp_error( $exchange_resp ) ) {
			$exchange_payload = json_decode( wp_remote_retrieve_body( $exchange_resp ), true );
			if ( ! empty( $exchange_payload['access_token'] ) ) {
				$long_user_token = $exchange_payload['access_token'];
			}
		}

		// List the Pages this user manages, with their (long-lived) Page tokens.
		$pages_url = add_query_arg( array(
			'access_token' => $long_user_token,
			'fields'       => 'id,name,access_token',
		), "https://graph.facebook.com/{$version}/me/accounts" );

		$pages_resp = wp_remote_get( $pages_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $pages_resp ) ) {
			$this->fail( $pages_resp->get_error_message() );
		}
		$pages_payload = json_decode( wp_remote_retrieve_body( $pages_resp ), true );
		if ( empty( $pages_payload['data'] ) ) {
			$this->fail( 'No Facebook Pages found for this account. Make sure you are an admin of at least one Page and approved all requested permissions.' );
		}

		$pages = array();
		foreach ( $pages_payload['data'] as $p ) {
			if ( ! empty( $p['id'] ) && ! empty( $p['access_token'] ) ) {
				$pages[] = array( 'id' => $p['id'], 'name' => isset( $p['name'] ) ? $p['name'] : $p['id'], 'access_token' => $p['access_token'] );
			}
		}

		if ( 1 === count( $pages ) ) {
			// Only one Page — connect it directly, no need to ask.
			PCC_SP_Settings::save( array(
				'fb_page_id'           => $pages[0]['id'],
				'fb_page_name'         => $pages[0]['name'],
				'fb_page_access_token' => $pages[0]['access_token'],
			) );
			wp_safe_redirect( add_query_arg( 'pcc_sp_fb_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
			exit;
		}

		// Multiple Pages — let the admin pick one on the Settings page.
		set_transient( self::PAGES_TRANSIENT, $pages, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'pcc_sp_fb_choose', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function select_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_select_fb_page' );

		$page_id = isset( $_GET['page_id'] ) ? sanitize_text_field( wp_unslash( $_GET['page_id'] ) ) : '';
		$pages   = get_transient( self::PAGES_TRANSIENT );

		if ( ! $pages || ! $page_id ) {
			wp_die( 'That Page selection has expired — please click Connect again. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$chosen = null;
		foreach ( $pages as $p ) {
			if ( $p['id'] === $page_id ) {
				$chosen = $p;
				break;
			}
		}
		if ( ! $chosen ) {
			wp_die( 'Page not found in the list — please try connecting again.' );
		}

		PCC_SP_Settings::save( array(
			'fb_page_id'           => $chosen['id'],
			'fb_page_name'         => $chosen['name'],
			'fb_page_access_token' => $chosen['access_token'],
		) );
		delete_transient( self::PAGES_TRANSIENT );

		wp_safe_redirect( add_query_arg( 'pcc_sp_fb_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_fb' );
		PCC_SP_Settings::save( array(
			'fb_page_id'           => '',
			'fb_page_name'         => '',
			'fb_page_access_token' => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'Facebook connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
