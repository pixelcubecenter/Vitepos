<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_LinkedIn_OAuth {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const CALLBACK_PATH = '/pcc-sp-linkedin-callback';

	private function __construct() {
		add_action( 'admin_post_pcc_sp_connect_linkedin', array( $this, 'connect' ) );
		add_action( 'admin_post_pcc_sp_disconnect_linkedin', array( $this, 'disconnect' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback' ) );
	}

	public static function redirect_uri() {
		return home_url( self::CALLBACK_PATH );
	}

	public static function connect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_connect_linkedin' ), 'pcc_sp_connect_linkedin' );
	}

	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_disconnect_linkedin' ), 'pcc_sp_disconnect_linkedin' );
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
		check_admin_referer( 'pcc_sp_connect_linkedin' );

		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['linkedin_client_id'] ) || empty( $settings['linkedin_client_secret'] ) || empty( $settings['linkedin_org_id'] ) ) {
			wp_die( 'Set your LinkedIn Client ID, Client Secret and Company Page ID first, then save settings before connecting. <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'pcc_sp_linkedin_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'response_type' => 'code',
			'client_id'     => $settings['linkedin_client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'state'         => $state,
			'scope'         => 'w_organization_social',
		);

		wp_redirect( 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query( $params ) );
		exit;
	}

	public function callback() {
		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			$err = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : 'No authorization code was returned.';
			$this->fail( $err );
		}

		$state       = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$saved_state = get_transient( 'pcc_sp_linkedin_oauth_state' );
		delete_transient( 'pcc_sp_linkedin_oauth_state' );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			$this->fail( 'Invalid state — please try connecting again.' );
		}

		$settings = PCC_SP_Settings::get_all();

		$resp = wp_remote_post( 'https://www.linkedin.com/oauth/v2/accessToken', array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'code'          => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
				'redirect_uri'  => self::redirect_uri(),
				'client_id'     => $settings['linkedin_client_id'],
				'client_secret' => $settings['linkedin_client_secret'],
			),
		) );

		if ( is_wp_error( $resp ) ) {
			$this->fail( $resp->get_error_message() );
		}

		$raw     = wp_remote_retrieve_body( $resp );
		$payload = json_decode( $raw, true );

		if ( empty( $payload['access_token'] ) ) {
			$msg = '';
			if ( is_array( $payload ) ) {
				if ( ! empty( $payload['error_description'] ) ) {
					$msg = $payload['error_description'];
				} elseif ( ! empty( $payload['error'] ) ) {
					$msg = is_string( $payload['error'] ) ? $payload['error'] : wp_json_encode( $payload['error'] );
				}
			}
			if ( ! $msg ) {
				$msg = 'No access token returned (HTTP ' . intval( wp_remote_retrieve_response_code( $resp ) ) . '). Raw response: ' . substr( (string) $raw, 0, 500 );
			}
			$this->fail( $msg );
		}

		$expires_in = ! empty( $payload['expires_in'] ) ? intval( $payload['expires_in'] ) : 60 * DAY_IN_SECONDS;

		PCC_SP_Settings::save( array(
			'linkedin_access_token' => $payload['access_token'],
			'linkedin_expires_at'   => time() + $expires_in,
		) );

		wp_safe_redirect( add_query_arg( 'pcc_sp_linkedin_connected', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	public function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_disconnect_linkedin' );
		PCC_SP_Settings::save( array(
			'linkedin_access_token' => '',
			'linkedin_expires_at'   => '',
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=pcc-sp-settings' ) );
		exit;
	}

	private function fail( $msg ) {
		wp_die( 'LinkedIn connection failed: ' . esc_html( $msg ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ) . '">Back to settings</a>' );
	}
}
