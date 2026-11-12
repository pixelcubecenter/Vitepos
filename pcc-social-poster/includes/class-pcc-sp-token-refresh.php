<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram and Threads access tokens last 60 days and must be refreshed
 * BEFORE they expire (an expired one can only be replaced by reconnecting).
 * This renews them about once a week, from the existing 5-minute check, using
 * a single cheap option read when nothing is due.
 */
class PCC_SP_Token_Refresh {

	const OPTION = 'pcc_sp_token_refresh';   // array( last => ts, errors => array )
	const EVERY  = 7 * DAY_IN_SECONDS;

	public static function maybe_run() {
		$state = get_option( self::OPTION, array() );
		$last  = isset( $state['last'] ) ? (int) $state['last'] : 0;
		if ( time() - $last < self::EVERY ) {
			return;
		}
		// Claim the slot first so two overlapping runs don't both refresh.
		$state['last'] = time();
		update_option( self::OPTION, $state, false );

		$errors = array();
		$s      = PCC_SP_Settings::get_all();

		if ( ! empty( $s['ig_access_token'] ) ) {
			$e = self::refresh( 'https://graph.instagram.com/refresh_access_token', 'ig_refresh_token', $s['ig_access_token'], 'ig_access_token' );
			if ( $e ) {
				$errors['instagram'] = $e;
			}
		}
		if ( ! empty( $s['threads_access_token'] ) ) {
			$e = self::refresh( 'https://graph.threads.net/refresh_access_token', 'th_refresh_token', $s['threads_access_token'], 'threads_access_token' );
			if ( $e ) {
				$errors['threads'] = $e;
			}
		}

		$state['errors'] = $errors;
		update_option( self::OPTION, $state, false );
	}

	/** @return string Error text, or '' on success. */
	private static function refresh( $url, $grant, $token, $setting_key ) {
		$resp = wp_remote_get( add_query_arg( array( 'grant_type' => $grant, 'access_token' => $token ), $url ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : 'Refresh failed (HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . ').';
		}
		PCC_SP_Settings::save( array( $setting_key => $data['access_token'] ) );
		return '';
	}

	/** Admin notice when a refresh failed (usually: token already expired, reconnect). */
	public static function notice() {
		$state = get_option( self::OPTION, array() );
		if ( empty( $state['errors'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( $state['errors'] as $platform => $msg ) {
			echo '<div class="notice notice-warning"><p><strong>PCC Social Poster:</strong> could not renew the ' . esc_html( ucfirst( $platform ) ) . ' login (' . esc_html( $msg ) . '). Go to Social Poster → Settings and click Reconnect for ' . esc_html( ucfirst( $platform ) ) . '.</p></div>';
		}
	}
}
add_action( 'admin_notices', array( 'PCC_SP_Token_Refresh', 'notice' ) );
