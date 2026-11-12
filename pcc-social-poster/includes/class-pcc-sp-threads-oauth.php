<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Threads_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-threads-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_threads', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_threads', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_threads' ), 'pcc_sp_connect_threads' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_threads' ), 'pcc_sp_disconnect_threads' );
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
		check_admin_referer( 'pcc_sp_connect_threads' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['threads_app_id'] ) || empty( $settings['threads_app_secret'] ) ) {
			wp_die( 'Set your Threads App ID and App Secret first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_threads_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['threads_app_id'],
			'redirect_uri'  => self::redirect_uri(),
			'scope'         => 'threads_basic,threads_content_publish',
			'response_type' => 'code',
			'state'         => $state,
		);

		wp_redirect( 'https://www.threads.net/oauth/authorize?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$this->fail( isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.' );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_threads_oauth_state' );
		delete_transient( 'pcc_sp_threads_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$settings = PCC_SP_Settings::get_all();
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$code     = rtrim( $code, '#_' );

		$token_resp = wp_remote_post( 'https://graph.threads.net/oauth/access_token', array(
			'timeout' => 60,
			'body'    => array(
				'client_id'     => $settings['threads_app_id'],
				'client_secret' => $settings['threads_app_secret'],
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
			),
		) );

		if ( is_wp_error( $token_resp ) ) {
			$this->fail( $token_resp->get_error_message() );
		}

		$raw_body      = wp_remote_retrieve_body( $token_resp );
		$http_code     = wp_remote_retrieve_response_code( $token_resp );
		$token_payload = json_decode( $raw_body, true );

		if ( empty( $token_payload['access_token'] ) ) {
			$msg = $this->extract_threads_error( $token_payload );
			if ( ! $msg ) {
				// Unrecognized error shape — show the raw response rather
				// than a generic message, so this is actually debuggable.
				$msg = 'No access token returned by Threads (HTTP ' . intval( $http_code ) . '). Raw response: ' . substr( (string) $raw_body, 0, 500 );
			}
			$this->fail( $msg );
		}

		$short_token = $token_payload['access_token'];
		$user_id     = isset( $token_payload['user_id'] ) ? $token_payload['user_id'] : '';

		// Exchange for a long-lived token (~60 days).
		$exchange_url = add_query_arg( array(
			'grant_type'    => 'th_exchange_token',
			'client_secret' => $settings['threads_app_secret'],
			'access_token'  => $short_token,
		), 'https://graph.threads.net/access_token' );

		$exchange_resp = wp_remote_get( $exchange_url, array( 'timeout' => 60 ) );
		$long_token    = $short_token;
		if ( ! is_wp_error( $exchange_resp ) ) {
			$exchange_payload = json_decode( wp_remote_retrieve_body( $exchange_resp ), true );
			if ( ! empty( $exchange_payload['access_token'] ) ) {
				$long_token = $exchange_payload['access_token'];
			}
		}

		PCC_SP_Settings::save( array(
			'threads_access_token' => $long_token,
			'threads_user_id'      => $user_id,
		) );

		wp_safe_redirect( add_query_arg( 'pcc_sp_threads_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_threads' );
		PCC_SP_Settings::save( array(
			'threads_access_token' => '',
			'threads_user_id'      => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	/**
	 * Meta's various endpoints return errors in more than one shape:
	 * {"error_type":...,"error_message":...} on Threads' own token endpoint,
	 * {"error":{"message":...}} on the regular Graph API style, or plain
	 * {"error":"..."} on some OAuth errors. Check all of them rather than
	 * assuming one and silently falling back to a useless generic message.
	 */
	private function extract_threads_error( $payload ) {
		if ( ! is_array( $payload ) ) {
			return '';
		}
		if ( ! empty( $payload['error_message'] ) ) {
			return $payload['error_message'];
		}
		if ( ! empty( $payload['error']['message'] ) ) {
			return $payload['error']['message'];
		}
		if ( ! empty( $payload['error'] ) && is_string( $payload['error'] ) ) {
			$desc = ! empty( $payload['error_description'] ) ? ( ': ' . $payload['error_description'] ) : '';
			return $payload['error'] . $desc;
		}
		return '';
	}

	private function fail( $msg ) {
		wp_die( 'Threads connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
