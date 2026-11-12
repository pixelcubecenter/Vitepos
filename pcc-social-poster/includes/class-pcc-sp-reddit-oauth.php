<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Reddit_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-reddit-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_reddit', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_reddit', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_reddit' ), 'pcc_sp_connect_reddit' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_reddit' ), 'pcc_sp_disconnect_reddit' );
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
		check_admin_referer( 'pcc_sp_connect_reddit' );

		$s = PCC_SP_Settings::get_all();
		if ( empty( $s['reddit_client_id'] ) || empty( $s['reddit_client_secret'] ) ) {
			wp_die( 'Set your Reddit Client ID and Secret first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_reddit_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $s['reddit_client_id'],
			'response_type' => 'code',
			'state'         => $state,
			'redirect_uri'  => self::redirect_uri(),
			'duration'      => 'permanent',
			'scope'         => 'identity submit',
		);
		wp_redirect( 'https://www.reddit.com/api/v1/authorize?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( isset( $_GET['error'] ) ) {
			$this->fail( sanitize_text_field( wp_unslash( $_GET['error'] ) ) );
		}
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$this->fail( 'No authorization code was returned.' );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_reddit_oauth_state' );
		delete_transient( 'pcc_sp_reddit_oauth_state' );
		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$s    = PCC_SP_Settings::get_all();
		$resp = wp_remote_post( 'https://www.reddit.com/api/v1/access_token', array(
			'timeout'    => 30,
			'user-agent' => PCC_SP_Reddit::user_agent(),
			'headers'    => array( 'Authorization' => 'Basic ' . base64_encode( $s['reddit_client_id'] . ':' . $s['reddit_client_secret'] ) ),
			'body'       => array(
				'grant_type'   => 'authorization_code',
				'code'         => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
				'redirect_uri' => self::redirect_uri(),
			),
		) );
		if ( is_wp_error( $resp ) ) {
			$this->fail( $resp->get_error_message() );
		}
		$raw  = wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'No token returned (HTTP ' . intval( wp_remote_retrieve_response_code( $resp ) ) . '). Raw: ' . substr( (string) $raw, 0, 300 );
			$this->fail( $msg );
		}

		// Look up the account name (needed for the User-Agent and profile posting).
		$username = '';
		$me       = wp_remote_get( 'https://oauth.reddit.com/api/v1/me', array(
			'timeout'    => 30,
			'user-agent' => PCC_SP_Reddit::user_agent(),
			'headers'    => array( 'Authorization' => 'Bearer ' . $data['access_token'] ),
		) );
		if ( ! is_wp_error( $me ) ) {
			$me_data = json_decode( wp_remote_retrieve_body( $me ), true );
			if ( ! empty( $me_data['name'] ) ) {
				$username = $me_data['name'];
			}
		}

		PCC_SP_Settings::save( array(
			'reddit_access_token'  => $data['access_token'],
			'reddit_refresh_token' => $data['refresh_token'],
			'reddit_expires_at'    => time() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ),
			'reddit_username'      => $username,
		) );

		wp_safe_redirect( add_query_arg( 'pcc_sp_reddit_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_reddit' );
		PCC_SP_Settings::save( array(
			'reddit_access_token'  => '',
			'reddit_refresh_token' => '',
			'reddit_expires_at'    => '',
			'reddit_username'      => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'Reddit connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
