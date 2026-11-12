<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pinterest posting through API v5.
 *
 * - 1 image  -> one Pin (image_base64), linked to the product page.
 * - 2-5      -> one carousel Pin (multiple_image_base64), each slide linked to
 *               its own product page. Pinterest carousels hold 2-5 images, so
 *               a batch of up to 10 products becomes up to 2 Pins.
 * Images are uploaded as data, so Pinterest never downloads them from your site.
 *
 * NOTE: until Pinterest grants "Standard" access, Pins made through the API
 * are visible only to the app owner (Trial access).
 */
class PCC_SP_Pinterest {

	const API_BASE        = 'https://api.pinterest.com/v5';
	const SANDBOX_BASE    = 'https://api-sandbox.pinterest.com/v5';
	const CAROUSEL_MAX    = 5;
	const TITLE_LIMIT     = 100;
	const DESC_LIMIT      = 800;
	const BOARDS_TRANSIENT = 'pcc_sp_pinterest_boards';

	/**
	 * Apps with Trial access must call the sandbox address; only apps with
	 * Standard access may use the normal one. (OAuth login always uses the
	 * normal address.)
	 */
	public static function api_base() {
		return ( 'production' === PCC_SP_Settings::get( 'pinterest_environment' ) ) ? self::API_BASE : self::SANDBOX_BASE;
	}

	private static function cut( $text, $limit ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) <= $limit ? $text : rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
		}
		return strlen( $text ) <= $limit ? $text : rtrim( substr( $text, 0, $limit - 1 ) ) . '…';
	}

	/* ------------------------------------------------------------------
	 * Tokens
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error Decoded token response.
	 */
	public static function token_request( array $body ) {
		$s    = PCC_SP_Settings::get_all();
		$resp = wp_remote_post( self::API_BASE . '/oauth/token', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $s['pinterest_client_id'] . ':' . $s['pinterest_client_secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$raw  = wp_remote_retrieve_body( $resp );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : 'No access token returned (HTTP ' . intval( wp_remote_retrieve_response_code( $resp ) ) . '): ' . substr( (string) $raw, 0, 300 );
			return new WP_Error( 'pcc_sp_pinterest_token', $msg );
		}
		return $data;
	}

	public static function store_tokens( array $data ) {
		$save = array(
			'pinterest_access_token' => $data['access_token'],
			'pinterest_expires_at'   => time() + ( ! empty( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 30 * DAY_IN_SECONDS ),
		);
		if ( ! empty( $data['refresh_token'] ) ) {
			$save['pinterest_refresh_token'] = $data['refresh_token'];
		}
		PCC_SP_Settings::save( $save );
	}

	/**
	 * @return string|WP_Error A valid access token (refreshed when near expiry).
	 */
	private static function access_token() {
		$s = PCC_SP_Settings::get_all();

		// Trial apps: the sandbox may reject the normal login token, so a token
		// generated in the Pinterest developer portal can be used instead.
		if ( 'production' !== $s['pinterest_environment'] && '' !== trim( (string) $s['pinterest_sandbox_token'] ) ) {
			return trim( (string) $s['pinterest_sandbox_token'] );
		}

		$expires = intval( $s['pinterest_expires_at'] );
		if ( ! empty( $s['pinterest_access_token'] ) && ( 0 === $expires || $expires - 300 > time() ) ) {
			return $s['pinterest_access_token'];
		}
		if ( empty( $s['pinterest_refresh_token'] ) ) {
			return new WP_Error( 'pcc_sp_pinterest_expired', 'The Pinterest login expired and there is no refresh token. Reconnect Pinterest in Settings.' );
		}
		$data = self::token_request( array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $s['pinterest_refresh_token'],
		) );
		if ( is_wp_error( $data ) ) {
			return new WP_Error( 'pcc_sp_pinterest_refresh', 'Could not refresh the Pinterest login (' . $data->get_error_message() . '). Reconnect Pinterest in Settings.' );
		}
		self::store_tokens( $data );
		return $data['access_token'];
	}

	/* ------------------------------------------------------------------
	 * Boards
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error board id => board name (cached for 10 minutes).
	 */
	public static function boards( $force = false ) {
		$cache_key = self::BOARDS_TRANSIENT . '_' . ( 'production' === PCC_SP_Settings::get( 'pinterest_environment' ) ? 'live' : 'sandbox' );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$out      = array();
		$bookmark = '';
		for ( $page = 0; $page < 5; $page++ ) {
			$url = self::api_base() . '/boards?page_size=100' . ( $bookmark ? '&bookmark=' . rawurlencode( $bookmark ) : '' );
			$r   = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || ! is_array( $data ) ) {
				$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : 'HTTP ' . intval( wp_remote_retrieve_response_code( $r ) );
				$hint = '';
				if ( 'production' !== PCC_SP_Settings::get( 'pinterest_environment' ) && in_array( (int) wp_remote_retrieve_response_code( $r ), array( 401, 403 ), true ) ) {
					$hint = ' The sandbox may not accept the normal login token: in the Pinterest developer portal open your app, click "Generate access token" (tick boards:read, boards:write, pins:read, pins:write), and paste it into "Sandbox access token" below.';
				}
				return new WP_Error( 'pcc_sp_pinterest_boards', 'Could not load your Pinterest boards: ' . $msg . '.' . $hint );
			}
			foreach ( (array) $data['items'] as $b ) {
				if ( ! empty( $b['id'] ) ) {
					$out[ $b['id'] ] = isset( $b['name'] ) ? $b['name'] : $b['id'];
				}
			}
			$bookmark = ! empty( $data['bookmark'] ) ? $data['bookmark'] : '';
			if ( '' === $bookmark ) {
				break;
			}
		}
		set_transient( $cache_key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Create a board (used for the sandbox, which starts with none).
	 *
	 * @return string|WP_Error New board ID.
	 */
	public static function create_board( $name ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$r = wp_remote_post( self::api_base() . '/boards', array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'name' => $name, 'privacy' => 'PUBLIC' ) ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['id'] ) ) {
			$msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : substr( (string) wp_remote_retrieve_body( $r ), 0, 300 );
			return new WP_Error( 'pcc_sp_pinterest_board', 'Could not create the Pinterest board (HTTP ' . $code . '): ' . $msg );
		}
		return (string) $data['id'];
	}

	public static function create_board_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_pinterest_create_board' ), 'pcc_sp_pinterest_create_board' );
	}

	public static function handle_create_board() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_pinterest_create_board' );

		$id = self::create_board( 'PCC Store Products' );
		if ( is_wp_error( $id ) ) {
			$ok  = '0';
			$msg = $id->get_error_message();
		} else {
			PCC_SP_Settings::save( array( 'pinterest_board_id' => $id ) );
			delete_transient( self::BOARDS_TRANSIENT . '_sandbox' );
			delete_transient( self::BOARDS_TRANSIENT . '_live' );
			$ok  = '1';
			$msg = 'Board "PCC Store Products" created and selected.';
		}
		wp_safe_redirect( add_query_arg( array(
			'pcc_sp_pin_ok'  => $ok,
			'pcc_sp_pin_msg' => rawurlencode( 'Pinterest: ' . $msg ),
		), admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Publishing
	 * ---------------------------------------------------------------- */

	private static function create_pin( $token, array $body ) {
		$r = wp_remote_post( self::api_base() . '/pins', array(
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['id'] ) ) {
			$msg  = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : substr( (string) wp_remote_retrieve_body( $r ), 0, 300 );
			$hint = '';
			if ( 403 === $code && false !== stripos( $msg, 'sandbox' ) && 'production' === PCC_SP_Settings::get( 'pinterest_environment' ) ) {
				// Pinterest says this app is still on Trial: switch to the sandbox automatically.
				PCC_SP_Settings::save( array( 'pinterest_environment' => 'sandbox', 'pinterest_board_id' => '' ) );
				return new WP_Error( 'pcc_sp_pinterest_trial', 'Your Pinterest app is on Trial access, which only works in Pinterest\'s sandbox. The plugin switched to Sandbox mode. Open Settings → Pinterest, click "Create sandbox board" (or pick a board), then Retry this post.' );
			}
			if ( 401 === $code && 'production' !== PCC_SP_Settings::get( 'pinterest_environment' ) ) {
				$hint = ' The sandbox may not accept the normal login token: generate an access token in the Pinterest developer portal (app → "Generate access token") and paste it into Settings → Pinterest → "Sandbox access token".';
			} elseif ( 401 === $code || 403 === $code ) {
				$hint = ' If this keeps happening your Pinterest app may still be on Trial access, which cannot create Pins — apply for Standard access in the Pinterest developer portal — or reconnect Pinterest in Settings.';
			} elseif ( 429 === $code ) {
				$hint = ' Pinterest rate limit reached; try again later.';
			}
			return new WP_Error( 'pcc_sp_pinterest_failed', 'Pinterest pin failed (HTTP ' . $code . '): ' . $msg . '.' . $hint );
		}
		return (string) $data['id'];
	}

	/**
	 * @param array[] $items  Each: array( 'attachment_id' => int, 'title' => string, 'link' => string )
	 * @param string  $caption Used as the description.
	 * @param string  $pin_title Title when every item shares one (manual posts); '' = use product titles.
	 * @return string|WP_Error Pin ID(s), comma-separated.
	 */
	public static function publish( array $items, $caption, $pin_title = '' ) {
		if ( ! PCC_SP_Settings::is_pinterest_configured() ) {
			return new WP_Error( 'pcc_sp_no_pinterest', 'Pinterest is not set up yet (connect it and choose a board).' );
		}
		$items = array_values( $items );
		if ( empty( $items ) ) {
			return new WP_Error( 'pcc_sp_pinterest_no_images', 'No images to send.' );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$board_id    = PCC_SP_Settings::get( 'pinterest_board_id' );
		$description = self::cut( $caption, self::DESC_LIMIT );

		$ids   = array();
		$notes = '';
		foreach ( array_chunk( $items, self::CAROUSEL_MAX ) as $n => $chunk ) {
			$encoded = array();
			foreach ( $chunk as $item ) {
				$part = PCC_SP_Telegram::photo_part( $item['attachment_id'] ); // JPEG/PNG under the size limit.
				if ( is_wp_error( $part ) ) {
					$notes = $part->get_error_message();
					break 2;
				}
				$encoded[] = array(
					'content_type' => $part['mime'],
					'data'         => base64_encode( $part['content'] ),
					'title'        => $item['title'],
					'link'         => $item['link'],
				);
			}

			if ( 1 === count( $chunk ) ) {
				$title = '' !== $pin_title ? $pin_title : $chunk[0]['title'];
				$body  = array(
					'board_id'     => $board_id,
					'title'        => self::cut( $title, self::TITLE_LIMIT ),
					'description'  => $description,
					'alt_text'     => self::cut( $chunk[0]['title'], 500 ),
					'media_source' => array(
						'source_type'  => 'image_base64',
						'content_type' => $encoded[0]['content_type'],
						'data'         => $encoded[0]['data'],
					),
				);
				if ( '' !== $chunk[0]['link'] ) {
					$body['link'] = $chunk[0]['link'];
				}
			} else {
				$slides = array();
				foreach ( $encoded as $e ) {
					$slide = array(
						'content_type' => $e['content_type'],
						'data'         => $e['data'],
						'title'        => self::cut( $e['title'], self::TITLE_LIMIT ),
					);
					if ( '' !== $e['link'] ) {
						$slide['link'] = $e['link'];
					}
					$slides[] = $slide;
				}
				if ( '' !== $pin_title ) {
					$title = $pin_title;
				} else {
					$title = $chunk[0]['title'] . ( count( $chunk ) > 1 ? ' + ' . ( count( $chunk ) - 1 ) . ' more' : '' );
				}
				$body = array(
					'board_id'     => $board_id,
					'title'        => self::cut( $title, self::TITLE_LIMIT ),
					'description'  => $description,
					'media_source' => array(
						'source_type' => 'multiple_image_base64',
						'items'       => $slides,
					),
				);
			}

			$id = self::create_pin( $token, $body );
			if ( is_wp_error( $id ) ) {
				if ( empty( $ids ) ) {
					return $id; // Nothing posted yet: a plain failure, safe to retry.
				}
				$notes = $id->get_error_message(); // Earlier pins exist: report, don't duplicate on retry.
				break;
			}
			$ids[] = $id;
		}

		if ( empty( $ids ) ) {
			return new WP_Error( 'pcc_sp_pinterest_failed', '' !== $notes ? $notes : 'Pinterest pin failed.' );
		}
		$out = implode( ',', $ids );
		if ( '' !== $notes ) {
			$out .= ' (some images were not posted: ' . $notes . ')';
		}
		return $out;
	}
}
