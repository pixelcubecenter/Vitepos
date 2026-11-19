<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_TikTok_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-tiktok-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_tiktok', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_tiktok', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_tiktok' ), 'pcc_sp_connect_tiktok' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_tiktok' ), 'pcc_sp_disconnect_tiktok' );
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
		check_admin_referer( 'pcc_sp_connect_tiktok' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['tiktok_client_key'] ) || empty( $settings['tiktok_client_secret'] ) ) {
			wp_die( 'Set your TikTok Client Key and Client Secret first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state    = wp_generate_password( 24, false );
		$verifier = self::generate_code_verifier();
		set_transient( 'pcc_sp_tiktok_oauth_state', $state, 10 * MINUTE_IN_SECONDS );
		set_transient( 'pcc_sp_tiktok_code_verifier', $verifier, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_key'            => $settings['tiktok_client_key'],
			'redirect_uri'          => self::redirect_uri(),
			'scope'                 => 'user.info.basic,video.publish,video.upload',
			'response_type'         => 'code',
			'state'                 => $state,
			'code_challenge'        => self::code_challenge( $verifier ),
			'code_challenge_method' => 'S256',
		);

		wp_redirect( 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$this->fail( isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.' );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_tiktok_oauth_state' );
		$verifier    = get_transient( 'pcc_sp_tiktok_code_verifier' );
		delete_transient( 'pcc_sp_tiktok_oauth_state' );
		delete_transient( 'pcc_sp_tiktok_code_verifier' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) || ! $verifier ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$settings = PCC_SP_Settings::get_all();
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		$token_resp = wp_remote_post( 'https://open.tiktokapis.com/v2/oauth/token/', array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'client_key'    => $settings['tiktok_client_key'],
				'client_secret' => $settings['tiktok_client_secret'],
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => $verifier,
			),
		) );

		if ( is_wp_error( $token_resp ) ) {
			$this->fail( $token_resp->get_error_message() );
		}

		$payload = json_decode( wp_remote_retrieve_body( $token_resp ), true );
		if ( empty( $payload['access_token'] ) ) {
			$msg = isset( $payload['error_description'] ) ? $payload['error_description'] : 'No access token returned by TikTok.';
			$this->fail( $msg );
		}

		PCC_SP_Settings::save( array(
			'tiktok_access_token'  => $payload['access_token'],
			'tiktok_refresh_token' => isset( $payload['refresh_token'] ) ? $payload['refresh_token'] : '',
			'tiktok_open_id'       => isset( $payload['open_id'] ) ? $payload['open_id'] : '',
		) );

		// Best-effort: fetch the display name for a friendlier "Connected as ..." label.
		$info_resp = wp_remote_get( add_query_arg( 'fields', 'display_name', 'https://open.tiktokapis.com/v2/user/info/' ), array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $payload['access_token'] ),
		) );
		if ( ! is_wp_error( $info_resp ) ) {
			$info = json_decode( wp_remote_retrieve_body( $info_resp ), true );
			if ( ! empty( $info['data']['user']['display_name'] ) ) {
				PCC_SP_Settings::save( array( 'tiktok_username' => $info['data']['user']['display_name'] ) );
			}
		}

		wp_safe_redirect( add_query_arg( 'pcc_sp_tiktok_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_tiktok' );
		PCC_SP_Settings::save( array(
			'tiktok_access_token'  => '',
			'tiktok_refresh_token' => '',
			'tiktok_open_id'       => '',
			'tiktok_username'      => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private static function generate_code_verifier() {
		// TikTok requires 43-128 chars from [A-Za-z0-9-._~].
		return substr( bin2hex( random_bytes( 48 ) ), 0, 96 );
	}

	private static function code_challenge( $verifier ) {
		$hash = hash( 'sha256', $verifier, true );
		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
	}

	private function fail( $msg ) {
		wp_die( 'TikTok connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
