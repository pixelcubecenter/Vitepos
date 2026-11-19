<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tumblr posting through the API v2 "Neue Post Format" (NPF).
 *
 * Images are uploaded with the post itself (multipart), so Tumblr never has
 * to download them from your website. One post holds all the images (up to 10
 * here), followed by the caption text.
 *
 * Tumblr's OAuth2 access tokens are short-lived, so a refresh token is stored
 * (scope offline_access) and used automatically before each post.
 */
class PCC_SP_Tumblr {

	const API_BASE  = 'https://api.tumblr.com/v2';
	const TOKEN_URL = 'https://api.tumblr.com/v2/oauth2/token';

	/**
	 * Form-encoded request to the OAuth2 token endpoint.
	 *
	 * @return array|WP_Error Decoded JSON on success.
	 */
	public static function token_request( array $body ) {
		$settings            = PCC_SP_Settings::get_all();
		$body['client_id']     = $settings['tumblr_client_id'];
		$body['client_secret'] = $settings['tumblr_client_secret'];

		$resp = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 60,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$raw     = wp_remote_retrieve_body( $resp );
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['access_token'] ) ) {
			$msg = '';
			if ( is_array( $payload ) ) {
				if ( ! empty( $payload['error_description'] ) ) {
					$msg = $payload['error_description'];
				} elseif ( ! empty( $payload['error'] ) ) {
					$msg = is_string( $payload['error'] ) ? $payload['error'] : wp_json_encode( $payload['error'] );
				} elseif ( ! empty( $payload['errors'][0]['detail'] ) ) {
					$msg = $payload['errors'][0]['detail'];
				} elseif ( ! empty( $payload['meta']['msg'] ) ) {
					$msg = $payload['meta']['msg'];
				}
			}
			if ( ! $msg ) {
				$msg = 'No access token returned (HTTP ' . intval( wp_remote_retrieve_response_code( $resp ) ) . '): ' . substr( (string) $raw, 0, 300 );
			}
			return new WP_Error( 'pcc_sp_tumblr_token', $msg );
		}
		return $payload;
	}

	/**
	 * Save a token response (access token, new refresh token if any, expiry).
	 */
	public static function store_tokens( array $payload ) {
		$expires_in = ! empty( $payload['expires_in'] ) ? intval( $payload['expires_in'] ) : 2400;
		$save       = array(
			'tumblr_access_token' => $payload['access_token'],
			'tumblr_expires_at'   => time() + $expires_in,
		);
		if ( ! empty( $payload['refresh_token'] ) ) {
			$save['tumblr_refresh_token'] = $payload['refresh_token'];
		}
		PCC_SP_Settings::save( $save );
	}

	/**
	 * A valid access token, refreshing it first when it is (nearly) expired.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$s = PCC_SP_Settings::get_all();

		$expires = intval( $s['tumblr_expires_at'] );
		if ( ! empty( $s['tumblr_access_token'] ) && ( 0 === $expires || $expires - 120 > time() ) ) {
			return $s['tumblr_access_token'];
		}

		if ( empty( $s['tumblr_refresh_token'] ) ) {
			return new WP_Error( 'pcc_sp_tumblr_expired', 'The Tumblr login expired and there is no refresh token. Reconnect Tumblr in Settings.' );
		}

		$payload = self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $s['tumblr_refresh_token'],
		) );
		if ( is_wp_error( $payload ) ) {
			return new WP_Error( 'pcc_sp_tumblr_refresh', 'Could not refresh the Tumblr login (' . $payload->get_error_message() . '). Reconnect Tumblr in Settings.' );
		}
		self::store_tokens( $payload );
		return $payload['access_token'];
	}

	/**
	 * The primary blog name of the logged-in user (used right after connecting).
	 *
	 * @return string Empty when it cannot be determined.
	 */
	public static function primary_blog_name( $access_token ) {
		$resp = wp_remote_get( self::API_BASE . '/user/info', array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
		) );
		if ( is_wp_error( $resp ) ) {
			return '';
		}
		$data  = json_decode( wp_remote_retrieve_body( $resp ), true );
		$blogs = isset( $data['response']['user']['blogs'] ) ? $data['response']['user']['blogs'] : array();
		foreach ( (array) $blogs as $blog ) {
			if ( ! empty( $blog['primary'] ) && ! empty( $blog['name'] ) ) {
				return $blog['name'];
			}
		}
		return ( ! empty( $blogs[0]['name'] ) ) ? $blogs[0]['name'] : '';
	}

	/**
	 * Post 1-10 images with a caption.
	 *
	 * @param int[]  $attachment_ids
	 * @param string $caption
	 * @return string|WP_Error The new post's ID.
	 */
	public static function publish( array $attachment_ids, $caption ) {
		if ( ! PCC_SP_Settings::is_tumblr_configured() ) {
			return new WP_Error( 'pcc_sp_no_tumblr', 'Tumblr is not connected yet.' );
		}
		$attachment_ids = array_slice( array_values( $attachment_ids ), 0, 10 );
		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'pcc_sp_tumblr_no_images', 'No images to send.' );
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$s    = PCC_SP_Settings::get_all();
		$blog = trim( (string) $s['tumblr_blog'] );
		$blog = preg_replace( '#^https?://#', '', $blog );
		$blog = rtrim( $blog, '/' );

		$content = array();
		$files   = array();
		foreach ( $attachment_ids as $i => $id ) {
			$part = PCC_SP_Telegram::photo_part( $id ); // JPEG/PNG bytes under the size limit.
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			$ident            = 'img' . $i;
			$files[ $ident ]  = $part;
			$content[]        = array(
				'type'  => 'image',
				'media' => array( array( 'type' => $part['mime'], 'identifier' => $ident ) ),
			);
		}
		if ( '' !== trim( (string) $caption ) ) {
			$content[] = array( 'type' => 'text', 'text' => (string) $caption );
		}

		$state = in_array( $s['tumblr_state'], array( 'published', 'draft', 'private' ), true ) ? $s['tumblr_state'] : 'published';
		$json  = array( 'content' => $content, 'state' => $state );
		$tags  = trim( (string) $s['tumblr_tags'] );
		if ( '' !== $tags ) {
			$json['tags'] = $tags;
		}

		$boundary = 'pccsp' . wp_generate_password( 24, false );
		$body     = '--' . $boundary . "\r\n";
		$body    .= "Content-Disposition: form-data; name=\"json\"\r\n";
		$body    .= "Content-Type: application/json\r\n\r\n";
		$body    .= wp_json_encode( $json ) . "\r\n";
		foreach ( $files as $ident => $file ) {
			$body .= '--' . $boundary . "\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $ident . '"; filename="' . $file['name'] . "\"\r\n";
			$body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n";
			$body .= $file['content'] . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		$resp = wp_remote_post( self::API_BASE . '/blog/' . rawurlencode( $blog ) . '/posts', array(
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			if ( is_array( $data ) ) {
				if ( ! empty( $data['errors'][0]['detail'] ) ) {
					$detail = $data['errors'][0]['detail'];
				} elseif ( ! empty( $data['errors'][0]['title'] ) ) {
					$detail = $data['errors'][0]['title'];
				} elseif ( ! empty( $data['meta']['msg'] ) ) {
					$detail = $data['meta']['msg'];
				}
			}
			if ( ! $detail ) {
				$detail = substr( (string) wp_remote_retrieve_body( $resp ), 0, 300 );
			}
			$hint = '';
			if ( 404 === $code ) {
				$hint = ' Check the blog name in Settings → Tumblr (just the part before .tumblr.com).';
			} elseif ( 401 === $code || 403 === $code ) {
				$hint = ' Reconnect Tumblr in Settings.';
			}
			return new WP_Error( 'pcc_sp_tumblr_failed', 'Tumblr post failed (HTTP ' . $code . '): ' . $detail . '.' . $hint );
		}

		if ( is_array( $data ) && ! empty( $data['response']['id_string'] ) ) {
			return (string) $data['response']['id_string'];
		}
		if ( is_array( $data ) && ! empty( $data['response']['id'] ) ) {
			return (string) $data['response']['id'];
		}
		return 'created';
	}
}
