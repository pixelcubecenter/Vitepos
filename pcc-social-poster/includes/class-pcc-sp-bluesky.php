<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bluesky posting through the AT Protocol (login with an App Password).
 *
 * Bluesky limits: 4 images per post, each under ~1 MB, 300 characters of
 * text. So images are recompressed to fit, a batch of up to 10 becomes a
 * thread of up to 3 posts (caption on the first, the rest are replies that
 * carry only images), and the caption is shortened to 300 characters.
 */
class PCC_SP_Bluesky {

	const SERVICE      = 'https://bsky.social';
	const MAX_IMAGES   = 4;
	const MAX_BYTES    = 950000; // Bluesky's limit is 1,000,000 bytes per image.
	const TEXT_LIMIT   = 300;

	/* ------------------------------------------------------------------
	 * API helpers
	 * ---------------------------------------------------------------- */

	/**
	 * @return array|WP_Error Decoded JSON response.
	 */
	private static function call( $nsid, array $body, $jwt = '', $raw = null, $content_type = '' ) {
		$headers = array();
		if ( '' !== $jwt ) {
			$headers['Authorization'] = 'Bearer ' . $jwt;
		}
		if ( null !== $raw ) {
			$headers['Content-Type'] = $content_type;
			$payload                 = $raw;
		} else {
			$headers['Content-Type'] = 'application/json';
			$payload                 = wp_json_encode( $body );
		}

		$resp = wp_remote_post( self::SERVICE . '/xrpc/' . $nsid, array(
			'timeout' => 90,
			'headers' => $headers,
			'body'    => $payload,
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : substr( (string) wp_remote_retrieve_body( $resp ), 0, 300 );
			$kind = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : '';
			$hint = '';
			if ( 'AuthenticationRequired' === $kind || 'AuthFactorTokenRequired' === $kind || 401 === $code ) {
				$hint = ' Check the handle and App Password in Settings → Bluesky (create the App Password at bsky.app → Settings → Privacy and security → App passwords; your normal password will not work).';
			} elseif ( 429 === $code ) {
				$hint = ' Bluesky rate limit reached; try again later.';
			}
			return new WP_Error( 'pcc_sp_bluesky_failed', 'Bluesky ' . $nsid . ' failed (HTTP ' . $code . ( $kind ? ', ' . $kind : '' ) . '): ' . $err . '.' . $hint );
		}
		return $data;
	}

	/**
	 * @return array|WP_Error array( accessJwt, did, handle )
	 */
	public static function session() {
		$s = PCC_SP_Settings::get_all();
		if ( '' === trim( (string) $s['bluesky_identifier'] ) || '' === trim( (string) $s['bluesky_app_password'] ) ) {
			return new WP_Error( 'pcc_sp_no_bluesky', 'Bluesky is not set up yet.' );
		}
		$identifier = ltrim( trim( (string) $s['bluesky_identifier'] ), '@' );
		$data       = self::call( 'com.atproto.server.createSession', array(
			'identifier' => $identifier,
			'password'   => trim( (string) $s['bluesky_app_password'] ),
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['accessJwt'] ) || empty( $data['did'] ) ) {
			return new WP_Error( 'pcc_sp_bluesky_session', 'Bluesky login returned no session.' );
		}
		return $data;
	}

	/* ------------------------------------------------------------------
	 * Images
	 * ---------------------------------------------------------------- */

	/**
	 * JPEG bytes under Bluesky's size limit, plus pixel size.
	 *
	 * @return array|WP_Error array( content, width, height )
	 */
	private static function image_bytes( $attachment_id ) {
		$file = PCC_SP_Image_Utils::get_file_part( $attachment_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$img = @imagecreatefromstring( $file['content'] );
		if ( ! $img ) {
			return new WP_Error( 'pcc_sp_bluesky_image', 'Could not read image ' . intval( $attachment_id ) . ' for Bluesky.' );
		}
		imagepalettetotruecolor( $img );

		// Flatten any transparency onto white (JPEG has none).
		$w   = imagesx( $img );
		$h   = imagesy( $img );
		$max = 2000;
		$scale = ( $w > $max || $h > $max ) ? min( $max / $w, $max / $h ) : 1;
		$nw  = max( 1, (int) round( $w * $scale ) );
		$nh  = max( 1, (int) round( $h * $scale ) );

		$best = null;
		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			$canvas = imagecreatetruecolor( $nw, $nh );
			imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
			imagecopyresampled( $canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
			$quality = array( 88, 80, 72, 64, 56, 48 );
			ob_start();
			imagejpeg( $canvas, null, $quality[ $attempt ] );
			$bytes = ob_get_clean();
			imagedestroy( $canvas );
			$best = $bytes;
			if ( strlen( $bytes ) <= self::MAX_BYTES ) {
				imagedestroy( $img );
				return array( 'content' => $bytes, 'width' => $nw, 'height' => $nh );
			}
			// Still too big: shrink the picture a little and retry at lower quality.
			$nw = max( 1, (int) round( $nw * 0.85 ) );
			$nh = max( 1, (int) round( $nh * 0.85 ) );
		}
		imagedestroy( $img );
		return new WP_Error( 'pcc_sp_bluesky_image_size', 'Image ' . intval( $attachment_id ) . ' is too large for Bluesky even after compression (' . strlen( (string) $best ) . ' bytes).' );
	}

	/* ------------------------------------------------------------------
	 * Text
	 * ---------------------------------------------------------------- */

	private static function shorten( $text ) {
		$text = trim( (string) $text );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) <= self::TEXT_LIMIT ? $text : rtrim( mb_substr( $text, 0, self::TEXT_LIMIT - 1 ) ) . '…';
		}
		return strlen( $text ) <= self::TEXT_LIMIT ? $text : rtrim( substr( $text, 0, self::TEXT_LIMIT - 1 ) ) . '…';
	}

	/**
	 * Clickable links: explicit http(s) URLs and your own website address
	 * (the "Website text" setting). Bluesky needs byte offsets ("facets").
	 */
	private static function facets( $text ) {
		$facets   = array();
		$patterns = array( '#https?://[^\s]+#iu' );
		$site     = trim( (string) PCC_SP_Settings::get( 'website_text' ) );
		if ( '' !== $site && false === stripos( $site, ' ' ) ) {
			$patterns[] = '#(?<![\w/.@-])' . preg_quote( $site, '#' ) . '(?![\w-])#iu';
		}
		$taken = array();
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $m[0] as $hit ) {
				$start = $hit[1];
				$match = rtrim( $hit[0], '.,;:!?)' );
				$end   = $start + strlen( $match );
				foreach ( $taken as $t ) {
					if ( $start < $t[1] && $end > $t[0] ) {
						continue 2; // Overlaps one already linked.
					}
				}
				$taken[] = array( $start, $end );
				$uri     = preg_match( '#^https?://#i', $match ) ? $match : 'https://' . $match;
				$facets[] = array(
					'index'    => array( 'byteStart' => $start, 'byteEnd' => $end ),
					'features' => array( array( '$type' => 'app.bsky.richtext.facet#link', 'uri' => $uri ) ),
				);
			}
		}
		return $facets;
	}

	/* ------------------------------------------------------------------
	 * Publishing
	 * ---------------------------------------------------------------- */

	/**
	 * @param int[]  $attachment_ids
	 * @param string $caption
	 * @return string|WP_Error The first post's at:// URI.
	 */
	public static function publish( array $attachment_ids, $caption ) {
		if ( ! PCC_SP_Settings::is_bluesky_configured() ) {
			return new WP_Error( 'pcc_sp_no_bluesky', 'Bluesky is not set up yet.' );
		}
		$attachment_ids = array_values( $attachment_ids );
		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'pcc_sp_bluesky_no_images', 'No images to send.' );
		}

		$session = self::session();
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$jwt = $session['accessJwt'];
		$did = $session['did'];

		$root   = null;
		$parent = null;
		$first  = '';
		$notes  = '';

		foreach ( array_chunk( $attachment_ids, self::MAX_IMAGES ) as $n => $chunk ) {
			$images = array();
			foreach ( $chunk as $id ) {
				$img = self::image_bytes( $id );
				if ( is_wp_error( $img ) ) {
					$notes = $img->get_error_message();
					break 2;
				}
				$blob = self::call( 'com.atproto.repo.uploadBlob', array(), $jwt, $img['content'], 'image/jpeg' );
				if ( is_wp_error( $blob ) ) {
					$notes = $blob->get_error_message();
					break 2;
				}
				if ( empty( $blob['blob'] ) ) {
					$notes = 'Bluesky returned no image reference.';
					break 2;
				}
				$images[] = array(
					'alt'         => '',
					'image'       => $blob['blob'],
					'aspectRatio' => array( 'width' => $img['width'], 'height' => $img['height'] ),
				);
			}

			$text   = ( 0 === $n ) ? self::shorten( $caption ) : '';
			$record = array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => $text,
				'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'embed'     => array( '$type' => 'app.bsky.embed.images', 'images' => $images ),
			);
			if ( '' !== $text ) {
				$facets = self::facets( $text );
				if ( $facets ) {
					$record['facets'] = $facets;
				}
			}
			if ( null !== $root ) {
				$record['reply'] = array( 'root' => $root, 'parent' => $parent );
			}

			$created = self::call( 'com.atproto.repo.createRecord', array(
				'repo'       => $did,
				'collection' => 'app.bsky.feed.post',
				'record'     => $record,
			), $jwt );
			if ( is_wp_error( $created ) ) {
				if ( '' === $first ) {
					return $created; // Nothing posted yet: plain failure, safe to retry.
				}
				$notes = $created->get_error_message();
				break;
			}
			$ref = array( 'uri' => $created['uri'], 'cid' => $created['cid'] );
			if ( null === $root ) {
				$root  = $ref;
				$first = $created['uri'];
			}
			$parent = $ref;
		}

		if ( '' === $first ) {
			return new WP_Error( 'pcc_sp_bluesky_failed', '' !== $notes ? $notes : 'Bluesky post failed.' );
		}
		return '' !== $notes ? $first . ' (some images were not posted: ' . $notes . ')' : $first;
	}

	/* ------------------------------------------------------------------
	 * Settings page: test button
	 * ---------------------------------------------------------------- */

	public static function test_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_bluesky_test' ), 'pcc_sp_bluesky_test' );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_bluesky_test' );

		$session = self::session();
		if ( is_wp_error( $session ) ) {
			$ok  = false;
			$msg = $session->get_error_message();
		} else {
			$ok  = true;
			$msg = 'Connected as @' . ( isset( $session['handle'] ) ? $session['handle'] : $session['did'] ) . '.';
		}

		wp_safe_redirect( add_query_arg( array(
			'pcc_sp_bsky_ok'  => $ok ? '1' : '0',
			'pcc_sp_bsky_msg' => rawurlencode( 'Bluesky: ' . $msg ),
		), admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}
}
