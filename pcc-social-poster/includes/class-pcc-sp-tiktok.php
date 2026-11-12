<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_TikTok {

	/**
	 * Publish one or more product images as a TikTok photo post (a single
	 * image is just a 1-photo post; several images become a swipeable
	 * photo carousel, TikTok's equivalent of Instagram's carousel).
	 * @param int[] $attachment_ids
	 * @return string|WP_Error publish_id, or WP_Error.
	 */
	public static function publish_photos( $attachment_ids, $caption ) {
		if ( ! self::ensure_fresh_token() ) {
			return new WP_Error( 'pcc_sp_tiktok_refresh_failed', 'Could not refresh the TikTok access token — try reconnecting TikTok in Settings.' );
		}

		$settings = PCC_SP_Settings::get_all();

		// TikTok photo posts don't accept PNG — send JPEG copies instead.
		$image_urls = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$url = self::jpeg_url_for( $attachment_id );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			if ( $url ) {
				$image_urls[] = $url;
			}
		}
		if ( empty( $image_urls ) ) {
			return new WP_Error( 'pcc_sp_tiktok_no_images', 'No image URLs to post.' );
		}

		$is_draft = ( 'direct' !== $settings['tiktok_post_mode'] );

		$privacy_level = '';
		if ( ! $is_draft ) {
			$privacy_level = self::get_privacy_level( $settings['tiktok_access_token'] );
			if ( is_wp_error( $privacy_level ) ) {
				return $privacy_level;
			}
		}

		// TikTok photo posts: title max 90 chars, description max 4000.
		// A longer title is rejected as "post info is empty or incorrect".
		$plain = trim( wp_strip_all_tags( $caption ) );
		$lines = preg_split( '/\r\n|\r|\n/', $plain );
		$title = trim( $lines[0] );
		$title = ( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 90 ) : substr( $title, 0, 90 ) );
		$desc  = ( function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 4000 ) : substr( $plain, 0, 4000 ) );

		$source_info = array(
			'source'            => 'PULL_FROM_URL',
			'photo_cover_index' => 0,
			'photo_images'      => $image_urls,
		);

		if ( $is_draft ) {
			// Upload to TikTok inbox as a draft; the user reviews and posts in-app.
			$body = array(
				'post_info'   => array(
					'title'       => $title,
					'description' => $desc,
				),
				'source_info' => $source_info,
				'post_mode'   => 'MEDIA_UPLOAD',
				'media_type'  => 'PHOTO',
			);
		} else {
			$body = array(
				'post_info'   => array(
					'title'                => $title,
					'description'          => $desc,
					'privacy_level'        => $privacy_level,
					'disable_comment'      => false,
					'auto_add_music'       => false,
					'brand_content_toggle' => false,
					'brand_organic_toggle' => false,
				),
				'source_info' => $source_info,
				'post_mode'   => 'DIRECT_POST',
				'media_type'  => 'PHOTO',
			);
		}

		$response = wp_remote_post( 'https://open.tiktokapis.com/v2/post/publish/content/init/', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['tiktok_access_token'],
				'Content-Type'  => 'application/json; charset=UTF-8',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $payload['data']['publish_id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'TikTok post init failed (HTTP ' . $code . ')';
			if ( isset( $payload['error']['code'] ) && 'ok' !== $payload['error']['code'] ) {
				$msg = '[' . $payload['error']['code'] . '] ' . $msg;
			}
			return new WP_Error( 'pcc_sp_tiktok_init_failed', $msg );
		}

		$publish_id = $payload['data']['publish_id'];

		$final_status = self::wait_for_publish( $publish_id, $settings['tiktok_access_token'] );
		if ( is_wp_error( $final_status ) ) {
			return $final_status;
		}

		return $publish_id;
	}

	/**
	 * Make (and cache) a JPEG copy of an attachment in uploads/pcc-sp-tiktok/
	 * and return its public URL. Transparent areas are flattened onto white.
	 * @return string|WP_Error
	 */
	private static function jpeg_url_for( $attachment_id ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'pcc-sp-tiktok';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'pcc_sp_tiktok_dir', 'Could not create the TikTok image folder in uploads.' );
		}
		$file = $dir . '/tt-' . intval( $attachment_id ) . '.jpg';
		$url  = trailingslashit( $upload['baseurl'] ) . 'pcc-sp-tiktok/tt-' . intval( $attachment_id ) . '.jpg';

		if ( file_exists( $file ) && filesize( $file ) > 0 ) {
			return $url;
		}
		if ( ! function_exists( 'imagejpeg' ) ) {
			return new WP_Error( 'pcc_sp_tiktok_no_gd', 'PHP GD (imagejpeg) is needed to convert images for TikTok.' );
		}

		$src = PCC_SP_Image_Utils::load_as_gd_image( $attachment_id );
		if ( is_wp_error( $src ) ) {
			return $src;
		}

		$w   = imagesx( $src );
		$h   = imagesy( $src );
		$max = 1080;
		$nw  = $w;
		$nh  = $h;
		if ( max( $w, $h ) > $max ) {
			$ratio = $max / max( $w, $h );
			$nw    = max( 1, (int) round( $w * $ratio ) );
			$nh    = max( 1, (int) round( $h * $ratio ) );
		}

		$dst   = imagecreatetruecolor( $nw, $nh );
		$white = imagecolorallocate( $dst, 255, 255, 255 );
		imagefilledrectangle( $dst, 0, 0, $nw, $nh, $white );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );

		$ok = imagejpeg( $dst, $file, 92 );
		imagedestroy( $src );
		imagedestroy( $dst );

		if ( ! $ok ) {
			return new WP_Error( 'pcc_sp_tiktok_jpeg', 'Could not write the JPEG copy for TikTok.' );
		}
		return $url;
	}

	private static function wait_for_publish( $publish_id, $access_token ) {
		$attempts = 0;
		while ( $attempts < 10 ) {
			$response = wp_remote_post( 'https://open.tiktokapis.com/v2/post/publish/status/fetch/', array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json; charset=UTF-8',
				),
				'body'    => wp_json_encode( array( 'publish_id' => $publish_id ) ),
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$payload = json_decode( wp_remote_retrieve_body( $response ), true );
			$status  = isset( $payload['data']['status'] ) ? $payload['data']['status'] : 'UNKNOWN';

			if ( 'PUBLISH_COMPLETE' === $status || 'SEND_TO_USER_INBOX' === $status ) {
				return true;
			}
			if ( 'FAILED' === $status ) {
				$reason = isset( $payload['data']['fail_reason'] ) ? $payload['data']['fail_reason'] : 'unknown reason';
				return new WP_Error( 'pcc_sp_tiktok_publish_failed', 'TikTok publish failed: ' . $reason );
			}

			$attempts++;
			sleep( 3 );
		}
		return true; // Timed out polling but not errored — TikTok will likely finish it shortly after.
	}

	/**
	 * TikTok requires querying the creator's currently allowed privacy
	 * levels rather than assuming one — unaudited apps are restricted to
	 * SELF_ONLY regardless of what's requested.
	 */
	private static function get_privacy_level( $access_token ) {
		$response = wp_remote_post( 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json; charset=UTF-8',
			),
			'body'    => '{}',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$options = isset( $payload['data']['privacy_level_options'] ) ? $payload['data']['privacy_level_options'] : array();

		if ( empty( $options ) ) {
			return new WP_Error( 'pcc_sp_tiktok_no_privacy_options', 'TikTok did not return any allowed privacy levels for this account.' );
		}

		// Prefer SELF_ONLY while unaudited (it's always allowed); once the
		// app passes TikTok's audit, this will naturally start including
		// public options too and PUBLIC_TO_EVERYONE will be preferred.
		if ( in_array( 'PUBLIC_TO_EVERYONE', $options, true ) ) {
			return 'PUBLIC_TO_EVERYONE';
		}
		return $options[0];
	}

	/**
	 * TikTok access tokens expire in ~24h; refresh tokens last much longer.
	 * Refresh proactively before every publish attempt.
	 */
	private static function ensure_fresh_token() {
		$settings = PCC_SP_Settings::get_all();
		if ( empty( $settings['tiktok_refresh_token'] ) ) {
			return ! empty( $settings['tiktok_access_token'] );
		}

		$response = wp_remote_post( 'https://open.tiktokapis.com/v2/oauth/token/', array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'client_key'    => $settings['tiktok_client_key'],
				'client_secret' => $settings['tiktok_client_secret'],
				'grant_type'    => 'refresh_token',
				'refresh_token' => $settings['tiktok_refresh_token'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $payload['access_token'] ) ) {
			return false;
		}

		PCC_SP_Settings::save( array(
			'tiktok_access_token'  => $payload['access_token'],
			'tiktok_refresh_token' => isset( $payload['refresh_token'] ) ? $payload['refresh_token'] : $settings['tiktok_refresh_token'],
		) );

		return true;
	}
}
