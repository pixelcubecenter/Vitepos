<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mastodon posting (any server) with an access token from
 * Preferences → Development → New application.
 *
 * Images are uploaded directly (/api/v2/media), then attached to a status.
 * Limits are read from your server (default 4 images and 500 characters per
 * post), so a batch of up to 10 products becomes a thread: the caption goes
 * on the first post and the remaining posts carry only images.
 */
class PCC_SP_Mastodon {

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/** @return string Base URL like https://mastodon.social (no trailing slash), or ''. */
	public static function base() {
		$host = trim( (string) PCC_SP_Settings::get( 'mastodon_instance' ) );
		$host = preg_replace( '#^https?://#i', '', $host );
		$host = trim( $host, " \t\n\r/" );
		$host = preg_replace( '#/.*$#', '', $host );
		return '' === $host ? '' : 'https://' . $host;
	}

	private static function token() {
		return trim( (string) PCC_SP_Settings::get( 'mastodon_access_token' ) );
	}

	private static function error_from( $resp, $what ) {
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );
		$err  = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : substr( (string) $body, 0, 300 );
		$hint = '';
		if ( 401 === $code ) {
			$hint = ' Check the server and access token in Settings → Mastodon (the token needs the write:media and write:statuses permissions).';
		} elseif ( 403 === $code ) {
			$hint = ' The token is missing a permission: create the application with write:media and write:statuses ticked.';
		} elseif ( 429 === $code ) {
			$hint = ' Mastodon rate limit reached (media uploads are limited to about 30 per 30 minutes); try again later.';
		}
		return new WP_Error( 'pcc_sp_mastodon_failed', 'Mastodon ' . $what . ' failed (HTTP ' . $code . '): ' . $err . '.' . $hint );
	}

	/**
	 * @return array|WP_Error Decoded JSON; sets $code (by reference) to the HTTP status.
	 */
	private static function request( $method, $path, array $args, &$code = 0, $what = 'request' ) {
		$base = self::base();
		if ( '' === $base || '' === self::token() ) {
			return new WP_Error( 'pcc_sp_no_mastodon', 'Mastodon is not set up yet.' );
		}
		$args['method']  = $method;
		$args['timeout'] = 90;
		$args['headers'] = array_merge( array( 'Authorization' => 'Bearer ' . self::token() ), isset( $args['headers'] ) ? $args['headers'] : array() );
		$resp = wp_remote_request( $base . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			if ( 206 === $code ) {
				return array();
			}
			return self::error_from( $resp, $what );
		}
		return $data;
	}

	/**
	 * Per-post limits from the server, cached for a day.
	 *
	 * @return array( images, chars )
	 */
	private static function limits() {
		$cached = get_transient( 'pcc_sp_mastodon_limits' );
		if ( is_array( $cached ) && ( $cached['base'] ?? '' ) === self::base() ) {
			return $cached;
		}
		$limits = array( 'base' => self::base(), 'images' => 4, 'chars' => 500 );
		$code   = 0;
		$data   = self::request( 'GET', '/api/v2/instance', array(), $code, 'server info' );
		if ( is_array( $data ) && ! is_wp_error( $data ) && isset( $data['configuration']['statuses'] ) ) {
			$st = $data['configuration']['statuses'];
			if ( ! empty( $st['max_media_attachments'] ) ) {
				$limits['images'] = max( 1, min( 10, (int) $st['max_media_attachments'] ) );
			}
			if ( ! empty( $st['max_characters'] ) ) {
				$limits['chars'] = max( 50, (int) $st['max_characters'] );
			}
			set_transient( 'pcc_sp_mastodon_limits', $limits, DAY_IN_SECONDS );
		}
		return $limits;
	}

	private static function shorten( $text, $limit ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) <= $limit ? $text : rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
		}
		return strlen( $text ) <= $limit ? $text : rtrim( substr( $text, 0, $limit - 1 ) ) . '…';
	}

	/* ------------------------------------------------------------------
	 * Media
	 * ---------------------------------------------------------------- */

	/** @return string|WP_Error Media id. */
	private static function upload( $attachment_id ) {
		$part = PCC_SP_Telegram::photo_part( $attachment_id );
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		$boundary = 'pccsp' . wp_generate_password( 24, false );
		$name     = preg_replace( '/[^A-Za-z0-9._-]/', '_', (string) $part['name'] );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $name . '"' . "\r\n"
			. 'Content-Type: ' . $part['mime'] . "\r\n\r\n"
			. $part['content'] . "\r\n"
			. '--' . $boundary . "--\r\n";

		$code = 0;
		$data = self::request( 'POST', '/api/v2/media', array(
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
		), $code, 'image upload' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'pcc_sp_mastodon_media', 'Mastodon returned no media id.' );
		}
		$id = (string) $data['id'];

		// 202 = still processing on the server; wait until it is ready.
		if ( 202 === $code ) {
			for ( $i = 0; $i < 15; $i++ ) {
				sleep( 2 );
				$c   = 0;
				$chk = self::request( 'GET', '/api/v1/media/' . rawurlencode( $id ), array(), $c, 'image check' );
				if ( is_wp_error( $chk ) ) {
					return $chk;
				}
				if ( 200 === $c ) {
					return $id;
				}
			}
			return new WP_Error( 'pcc_sp_mastodon_media', 'Mastodon is still processing an image; try again in a few minutes.' );
		}
		return $id;
	}

	/* ------------------------------------------------------------------
	 * Publishing
	 * ---------------------------------------------------------------- */

	/**
	 * @param int[]  $attachment_ids
	 * @param string $caption
	 * @return string|WP_Error URL (or id) of the first post.
	 */
	public static function publish( array $attachment_ids, $caption ) {
		if ( ! PCC_SP_Settings::is_mastodon_configured() ) {
			return new WP_Error( 'pcc_sp_no_mastodon', 'Mastodon is not set up yet.' );
		}
		$attachment_ids = array_values( $attachment_ids );
		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'pcc_sp_mastodon_no_images', 'No images to send.' );
		}

		$limits     = self::limits();
		$visibility = PCC_SP_Settings::get( 'mastodon_visibility' );
		if ( ! in_array( $visibility, array( 'public', 'unlisted', 'private' ), true ) ) {
			$visibility = 'public';
		}

		$first    = '';
		$reply_to = '';
		$notes    = '';

		foreach ( array_chunk( $attachment_ids, $limits['images'] ) as $n => $chunk ) {
			$media = array();
			foreach ( $chunk as $id ) {
				$mid = self::upload( $id );
				if ( is_wp_error( $mid ) ) {
					$notes = $mid->get_error_message();
					break 2;
				}
				$media[] = $mid;
			}

			$fields = array(
				'status'     => ( 0 === $n ) ? self::shorten( $caption, $limits['chars'] ) : '',
				'media_ids'  => $media,
				'visibility' => $visibility,
			);
			if ( '' !== $reply_to ) {
				$fields['in_reply_to_id'] = $reply_to;
			}

			$code    = 0;
			$created = self::request( 'POST', '/api/v1/statuses', array(
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Idempotency-Key' => md5( wp_generate_password( 20, false ) . microtime() ),
				),
				'body'    => wp_json_encode( $fields ),
			), $code, 'post' );
			if ( is_wp_error( $created ) ) {
				if ( '' === $first ) {
					return $created; // Nothing posted yet: safe to retry.
				}
				$notes = $created->get_error_message();
				break;
			}
			if ( empty( $created['id'] ) ) {
				if ( '' === $first ) {
					return new WP_Error( 'pcc_sp_mastodon_failed', 'Mastodon returned no post id.' );
				}
				break;
			}
			if ( '' === $first ) {
				$first = ! empty( $created['url'] ) ? (string) $created['url'] : (string) $created['id'];
			}
			$reply_to = (string) $created['id'];
		}

		if ( '' === $first ) {
			return new WP_Error( 'pcc_sp_mastodon_failed', '' !== $notes ? $notes : 'Mastodon post failed.' );
		}
		return '' !== $notes ? $first . ' (some images were not posted: ' . $notes . ')' : $first;
	}

	/* ------------------------------------------------------------------
	 * Settings page: test button
	 * ---------------------------------------------------------------- */

	public static function test_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_mastodon_test' ), 'pcc_sp_mastodon_test' );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_mastodon_test' );

		delete_transient( 'pcc_sp_mastodon_limits' );
		$code = 0;
		$data = self::request( 'GET', '/api/v1/accounts/verify_credentials', array(), $code, 'login check' );
		if ( is_wp_error( $data ) ) {
			$ok  = false;
			$msg = $data->get_error_message();
		} else {
			$l   = self::limits();
			$ok  = true;
			$msg = 'Connected as @' . ( isset( $data['acct'] ) ? $data['acct'] : '?' ) . ' on ' . preg_replace( '#^https?://#', '', self::base() ) . ' (' . $l['images'] . ' images and ' . $l['chars'] . ' characters per post).';
		}

		wp_safe_redirect( add_query_arg( array(
			'pcc_sp_masto_ok'  => $ok ? '1' : '0',
			'pcc_sp_masto_msg' => rawurlencode( 'Mastodon: ' . $msg ),
		), admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}
}
