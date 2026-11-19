<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Pinterest_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-pinterest-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_pinterest', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_pinterest', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_pinterest' ), 'pcc_sp_connect_pinterest' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_pinterest' ), 'pcc_sp_disconnect_pinterest' );
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
		check_admin_referer( 'pcc_sp_connect_pinterest' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['pinterest_client_id'] ) || empty( $settings['pinterest_client_secret'] ) ) {
			wp_die( 'Set your Pinterest App ID and App secret key first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_pinterest_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['pinterest_client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'boards:read,boards:write,pins:read,pins:write',
			'state'         => $state,
		);

		wp_redirect( 'https://www.pinterest.com/oauth/?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$err = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.';
			$this->fail( $err );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_pinterest_oauth_state' );
		delete_transient( 'pcc_sp_pinterest_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$payload = PCC_SP_Pinterest::token_request( array(
			'grant_type'         => 'authorization_code',
			'code'               => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
			'redirect_uri'       => self::redirect_uri(),
			'continuous_refresh' => 'true',
		) );
		if ( is_wp_error( $payload ) ) {
			$this->fail( $payload->get_error_message() );
		}

		PCC_SP_Pinterest::store_tokens( $payload );

		// Fresh login: reload the board list next time Settings opens.
		delete_transient( PCC_SP_Pinterest::BOARDS_TRANSIENT . '_sandbox' );
		delete_transient( PCC_SP_Pinterest::BOARDS_TRANSIENT . '_live' );

		wp_safe_redirect( add_query_arg( 'pcc_sp_pinterest_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_pinterest' );
		PCC_SP_Settings::save( array(
			'pinterest_access_token'  => '',
			'pinterest_refresh_token' => '',
			'pinterest_expires_at'    => '',
			'pinterest_board_id'      => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'Pinterest connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
