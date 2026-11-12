<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-ig-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_ig', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_ig', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	/**
	 * The redirect URI must have NO query string: Instagram replaces the
	 * entire query string of the registered redirect_uri with
	 * ?code=...&state=... instead of appending to it. We match on a clean
	 * path very early, before WP's normal routing/rewrite logic runs.
	 */
	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_ig' ), 'pcc_sp_connect_ig' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_ig' ), 'pcc_sp_disconnect_ig' );
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
		check_admin_referer( 'pcc_sp_connect_ig' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['ig_app_id'] ) || empty( $settings['ig_app_secret'] ) ) {
			wp_die( 'Set your Instagram App ID and App Secret first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['ig_app_id'],
			'redirect_uri'  => self::redirect_uri(),
			'scope'         => 'instagram_business_basic,instagram_business_content_publish',
			'response_type' => 'code',
			'state'         => $state,
		);

		wp_redirect( 'https://www.instagram.com/oauth/authorize?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$this->fail( isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.' );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_oauth_state' );
		delete_transient( 'pcc_sp_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$settings = PCC_SP_Settings::get_all();
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$code     = rtrim( $code, '#_' );

		$token_resp = wp_remote_post( 'https://api.instagram.com/oauth/access_token', array(
			'timeout' => 60,
			'body'    => array(
				'client_id'     => $settings['ig_app_id'],
				'client_secret' => $settings['ig_app_secret'],
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
			),
		) );

		if ( is_wp_error( $token_resp ) ) {
			$this->fail( $token_resp->get_error_message() );
		}

		$token_payload = json_decode( wp_remote_retrieve_body( $token_resp ), true );
		if ( empty( $token_payload['access_token'] ) ) {
			$msg = isset( $token_payload['error_message'] ) ? $token_payload['error_message'] : 'No access token returned by Instagram.';
			$this->fail( $msg );
		}

		$short_token = $token_payload['access_token'];
		$user_id     = isset( $token_payload['user_id'] ) ? $token_payload['user_id'] : '';

		$exchange_url = add_query_arg( array(
			'grant_type'    => 'ig_exchange_token',
			'client_secret' => $settings['ig_app_secret'],
			'access_token'  => $short_token,
		), 'https://graph.instagram.com/access_token' );

		$exchange_resp = wp_remote_get( $exchange_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $exchange_resp ) ) {
			$this->fail( $exchange_resp->get_error_message() );
		}

		$exchange_payload = json_decode( wp_remote_retrieve_body( $exchange_resp ), true );
		if ( empty( $exchange_payload['access_token'] ) ) {
			$msg = isset( $exchange_payload['error']['message'] ) ? $exchange_payload['error']['message'] : 'Could not exchange for a long-lived token.';
			$this->fail( $msg );
		}

		PCC_SP_Settings::save( array(
			'ig_access_token'        => $exchange_payload['access_token'],
			'ig_business_account_id' => $user_id,
		) );

		wp_safe_redirect( add_query_arg( 'pcc_sp_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_ig' );
		PCC_SP_Settings::save( array(
			'ig_access_token'        => '',
			'ig_business_account_id' => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'Instagram connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
