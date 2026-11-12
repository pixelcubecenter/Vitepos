<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Tumblr_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-tumblr-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_tumblr', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_tumblr', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_tumblr' ), 'pcc_sp_connect_tumblr' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_tumblr' ), 'pcc_sp_disconnect_tumblr' );
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
		check_admin_referer( 'pcc_sp_connect_tumblr' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['tumblr_client_id'] ) || empty( $settings['tumblr_client_secret'] ) ) {
			wp_die( 'Set your Tumblr OAuth Consumer Key and Secret Key first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_tumblr_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['tumblr_client_id'],
			'response_type' => 'code',
			'scope'         => 'basic write offline_access',
			'state'         => $state,
			'redirect_uri'  => self::redirect_uri(),
		);

		wp_redirect( 'https://www.tumblr.com/oauth2/authorize?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$err = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.';
			$this->fail( $err );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_tumblr_oauth_state' );
		delete_transient( 'pcc_sp_tumblr_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$payload = PCC_SP_Tumblr::token_request( array(
			'grant_type'   => 'authorization_code',
			'code'         => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
			'redirect_uri' => self::redirect_uri(),
		) );
		if ( is_wp_error( $payload ) ) {
			$this->fail( $payload->get_error_message() );
		}

		PCC_SP_Tumblr::store_tokens( $payload );

		// Fill in the blog name automatically when it was left empty.
		if ( '' === trim( (string) PCC_SP_Settings::get( 'tumblr_blog' ) ) ) {
			$name = PCC_SP_Tumblr::primary_blog_name( $payload['access_token'] );
			if ( '' !== $name ) {
				PCC_SP_Settings::save( array( 'tumblr_blog' => $name ) );
			}
		}

		wp_safe_redirect( add_query_arg( 'pcc_sp_tumblr_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_tumblr' );
		PCC_SP_Settings::save( array(
			'tumblr_access_token'  => '',
			'tumblr_refresh_token' => '',
			'tumblr_expires_at'    => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'Tumblr connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
