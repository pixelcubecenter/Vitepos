<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reddit posting: image post (1 image) or gallery post (2+ images), with the
 * full caption added as the first comment (image/gallery posts have no body).
 */
class PCC_SP_Reddit {

	const OAUTH_BASE = 'https://oauth.reddit.com';

	public static function user_agent() {
		$s    = PCC_SP_Settings::get_all();
		$user = ! empty( $s['reddit_username'] ) ? $s['reddit_username'] : 'unknown';
		return 'web:pcc-social-poster:' . PCC_SP_VERSION . ' (by /u/' . $user . ')';
	}

	/**
	 * Reddit access tokens last 1 hour; refresh before use.
	 * @return string|WP_Error access token
	 */
	public static function get_token() {
		$s = PCC_SP_Settings::get_all();
		if ( empty( $s['reddit_refresh_token'] ) ) {
			return new WP_Error( 'pcc_sp_reddit_no_token', 'Reddit is not connected yet.' );
		}
		if ( ! empty( $s['reddit_access_token'] ) && (int) $s['reddit_expires_at'] > time() + 120 ) {
			return $s['reddit_access_token'];
		}

		$resp = wp_remote_post( 'https://www.reddit.com/api/v1/access_token', array(
			'timeout'    => 30,
			'user-agent' => self::user_agent(),
			'headers'    => array( 'Authorization' => 'Basic ' . base64_encode( $s['reddit_client_id'] . ':' . $s['reddit_client_secret'] ) ),
			'body'       => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $s['reddit_refresh_token'],
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
			return new WP_Error( 'pcc_sp_reddit_refresh', 'Could not refresh the Reddit token (' . $msg . '). Try reconnecting Reddit in Settings.' );
		}
		PCC_SP_Settings::save( array(
			'reddit_access_token' => $data['access_token'],
			'reddit_expires_at'   => time() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ),
		) );
		return $data['access_token'];
	}

	private static function api( $path, $body, $token ) {
		$args = array(
			'timeout'    => 60,
			'user-agent' => self::user_agent(),
			'headers'    => array( 'Authorization' => 'Bearer ' . $token ),
			'body'       => $body,
		);
		$resp = wp_remote_post( self::OAUTH_BASE . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : substr( wp_remote_retrieve_body( $resp ), 0, 300 );
			return new WP_Error( 'pcc_sp_reddit_http', 'Reddit API error (HTTP ' . $code . '): ' . $msg );
		}
		return $data;
	}

	/** Reddit wraps errors as json.errors = [[CODE, message, field], ...] */
	private static function json_errors( $data ) {
		if ( is_array( $data ) && ! empty( $data['json']['errors'] ) ) {
			$parts = array();
			foreach ( $data['json']['errors'] as $e ) {
				$parts[] = ( isset( $e[0] ) ? $e[0] : '' ) . ( isset( $e[1] ) ? ': ' . $e[1] : '' );
			}
			return new WP_Error( 'pcc_sp_reddit_submit', 'Reddit rejected the post — ' . implode( '; ', $parts ) );
		}
		return null;
	}

	/**
	 * Upload one image to Reddit's media store.
	 * @return array|WP_Error array( 'asset_id' => ..., 'url' => ... )
	 */
	private static function upload_image( $attachment_id, $token ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'pcc_sp_no_file', 'Image file not found (ID ' . intval( $attachment_id ) . ').' );
		}
		$mime = PCC_SP_Image_Utils::detect_real_mime( $path );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'pcc_sp_reddit_mime', 'Reddit accepts JPG/PNG images only (found ' . $mime . ').' );
		}

		$lease = self::api( '/api/media/asset.json', array(
			'filepath' => basename( $path ),
			'mimetype' => $mime,
		), $token );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		if ( empty( $lease['args']['action'] ) || empty( $lease['args']['fields'] ) || empty( $lease['asset']['asset_id'] ) ) {
			return new WP_Error( 'pcc_sp_reddit_lease', 'Reddit did not return an upload slot for the image.' );
		}

		$action   = $lease['args']['action'];
		$upload   = ( 0 === strpos( $action, '//' ) ? 'https:' : '' ) . $action;
		$boundary = '----pccsp' . wp_generate_password( 16, false );
		$body     = '';
		$key      = '';
		foreach ( $lease['args']['fields'] as $f ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . $f['name'] . "\"\r\n\r\n" . $f['value'] . "\r\n";
			if ( 'key' === $f['name'] ) {
				$key = $f['value'];
			}
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . basename( $path ) . "\"\r\nContent-Type: {$mime}\r\n\r\n" . file_get_contents( $path ) . "\r\n--{$boundary}--\r\n";

		$put = wp_remote_post( $upload, array(
			'timeout' => 120,
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
		) );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$code = wp_remote_retrieve_response_code( $put );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'pcc_sp_reddit_upload', 'Reddit image upload failed (HTTP ' . $code . ').' );
		}

		$url = '';
		if ( preg_match( '#<Location>([^<]+)</Location>#', wp_remote_retrieve_body( $put ), $m ) ) {
			$url = html_entity_decode( $m[1] );
		} elseif ( $key ) {
			$url = trailingslashit( $upload ) . $key;
		}
		return array( 'asset_id' => $lease['asset']['asset_id'], 'url' => $url );
	}

	/**
	 * @param int[]  $attachment_ids
	 * @param string $caption
	 * @return string|WP_Error post fullname/url
	 */
	public static function publish( $attachment_ids, $caption ) {
		$s     = PCC_SP_Settings::get_all();
		$token = self::get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$sr = trim( $s['reddit_subreddit'] );
		$sr = preg_replace( '#^(/?r/)#i', '', $sr );
		if ( '' === $sr ) {
			if ( empty( $s['reddit_username'] ) ) {
				return new WP_Error( 'pcc_sp_reddit_sr', 'Set a subreddit in Settings, or reconnect Reddit to post to your profile.' );
			}
			$sr = 'u_' . $s['reddit_username']; // Posting to your own profile.
		}

		$plain = trim( wp_strip_all_tags( $caption ) );
		$lines = preg_split( '/\r\n|\r|\n/', $plain );
		$title = trim( $lines[0] );
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 300 ) : substr( $title, 0, 300 );
		if ( '' === $title ) {
			$title = 'New post';
		}

		$assets = array();
		foreach ( $attachment_ids as $id ) {
			$a = self::upload_image( $id, $token );
			if ( is_wp_error( $a ) ) {
				return $a;
			}
			$assets[] = $a;
		}

		if ( count( $assets ) === 1 ) {
			$data = self::api( '/api/submit', array(
				'api_type'     => 'json',
				'kind'         => 'image',
				'sr'           => $sr,
				'title'        => $title,
				'url'          => $assets[0]['url'],
				'resubmit'     => 'true',
				'sendreplies'  => 'true',
			), $token );
		} else {
			$items = array();
			foreach ( array_slice( $assets, 0, 20 ) as $a ) {
				$items[] = array( 'caption' => '', 'outbound_url' => '', 'media_id' => $a['asset_id'] );
			}
			$resp = wp_remote_post( self::OAUTH_BASE . '/api/submit_gallery_post.json', array(
				'timeout'    => 60,
				'user-agent' => self::user_agent(),
				'headers'    => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'       => wp_json_encode( array(
					'api_type'    => 'json',
					'sr'          => $sr,
					'title'       => $title,
					'items'       => $items,
					'nsfw'        => false,
					'spoiler'     => false,
					'sendreplies' => true,
				) ),
			) );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'pcc_sp_reddit_http', 'Reddit gallery error (HTTP ' . $code . '): ' . substr( wp_remote_retrieve_body( $resp ), 0, 300 ) );
			}
		}
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$err = self::json_errors( $data );
		if ( $err ) {
			return $err;
		}

		$post_url  = isset( $data['json']['data']['url'] ) ? $data['json']['data']['url'] : '';
		$post_name = isset( $data['json']['data']['name'] ) ? $data['json']['data']['name'] : '';

		// Image/gallery posts have no body — put the full caption in a first comment.
		if ( $post_name && strlen( $plain ) > strlen( $title ) ) {
			self::api( '/api/comment', array(
				'api_type' => 'json',
				'thing_id' => $post_name,
				'text'     => $plain,
			), $token ); // Best-effort; the post itself already succeeded.
		}

		return $post_url ? $post_url : ( $post_name ? $post_name : 'posted' );
	}
}
