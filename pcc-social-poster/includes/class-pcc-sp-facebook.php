<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Facebook {

	/**
	 * Single-image post: publish one photo directly to the Page, with a caption.
	 * @return string|WP_Error Post ID, or WP_Error.
	 */
	public static function publish_single( $attachment_id, $caption ) {
		$settings = PCC_SP_Settings::get_all();
		if ( ! PCC_SP_Settings::is_facebook_configured() ) {
			return new WP_Error( 'pcc_sp_no_fb_creds', 'Facebook Page is not connected yet.' );
		}

		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return new WP_Error( 'pcc_sp_no_image_url', 'Could not resolve a public URL for the generated image.' );
		}

		$endpoint = self::graph_url( $settings['fb_page_id'] . '/photos', $settings );
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'body'    => array(
				'url'          => $image_url,
				'caption'      => $caption,
				'published'    => 'true',
				'access_token' => $settings['fb_page_access_token'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Facebook post failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_fb_post_failed', $msg );
		}

		// The /photos endpoint returns {id, post_id} — post_id is the actual
		// feed post; id is the photo object. Prefer post_id when present.
		return ! empty( $payload['post_id'] ) ? $payload['post_id'] : $payload['id'];
	}

	/**
	 * Page photo story: upload the photo unpublished, then turn it into a story.
	 * @return string|WP_Error Story post id (or photo id).
	 */
	public static function publish_story( $attachment_id ) {
		$settings = PCC_SP_Settings::get_all();
		if ( ! PCC_SP_Settings::is_facebook_configured() ) {
			return new WP_Error( 'pcc_sp_no_fb_creds', 'Facebook Page is not connected yet.' );
		}
		$image_url = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return new WP_Error( 'pcc_sp_no_image_url', 'Could not resolve a public URL for the generated image.' );
		}

		$response = wp_remote_post( self::graph_url( $settings['fb_page_id'] . '/photos', $settings ), array(
			'timeout' => 60,
			'body'    => array(
				'url'          => $image_url,
				'published'    => 'false',
				'access_token' => $settings['fb_page_access_token'],
			),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Facebook photo upload failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_fb_photo_failed', $msg );
		}
		$photo_id = $payload['id'];

		$response = wp_remote_post( self::graph_url( $settings['fb_page_id'] . '/photo_stories', $settings ), array(
			'timeout' => 60,
			'body'    => array(
				'photo_id'     => $photo_id,
				'access_token' => $settings['fb_page_access_token'],
			),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || ( empty( $payload['success'] ) && empty( $payload['post_id'] ) ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Facebook story failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_fb_story_failed', $msg );
		}
		return ! empty( $payload['post_id'] ) ? (string) $payload['post_id'] : (string) $photo_id;
	}

	/**
	 * Multi-image post: upload each photo unpublished, then attach them all
	 * to a single feed post (Facebook's native multi-photo post format).
	 * @param int[] $attachment_ids
	 * @return string|WP_Error Post ID, or WP_Error.
	 */
	public static function publish_multi( $attachment_ids, $caption ) {
		$settings = PCC_SP_Settings::get_all();
		if ( ! PCC_SP_Settings::is_facebook_configured() ) {
			return new WP_Error( 'pcc_sp_no_fb_creds', 'Facebook Page is not connected yet.' );
		}

		$media = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$image_url = wp_get_attachment_url( $attachment_id );
			if ( ! $image_url ) {
				continue;
			}
			$endpoint = self::graph_url( $settings['fb_page_id'] . '/photos', $settings );
			$response = wp_remote_post( $endpoint, array(
				'timeout' => 60,
				'body'    => array(
					'url'          => $image_url,
					'published'    => 'false',
					'access_token' => $settings['fb_page_access_token'],
				),
			) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$payload = json_decode( wp_remote_retrieve_body( $response ), true );
			$code    = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
				$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Facebook photo upload failed (HTTP ' . $code . ')';
				return new WP_Error( 'pcc_sp_fb_photo_failed', $msg );
			}
			$media[] = $payload['id'];
		}

		if ( empty( $media ) ) {
			return new WP_Error( 'pcc_sp_fb_no_photos', 'No photos could be uploaded to Facebook.' );
		}

		$attached_media = array();
		foreach ( $media as $photo_id ) {
			$attached_media[] = wp_json_encode( array( 'media_fbid' => $photo_id ) );
		}

		$endpoint = self::graph_url( $settings['fb_page_id'] . '/feed', $settings );
		$body     = array(
			'message'      => $caption,
			'access_token' => $settings['fb_page_access_token'],
		);
		foreach ( $attached_media as $i => $json ) {
			$body[ "attached_media[{$i}]" ] = $json;
		}

		$response = wp_remote_post( $endpoint, array( 'timeout' => 60, 'body' => $body ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Facebook multi-photo post failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_fb_feed_failed', $msg );
		}

		return $payload['id'];
	}

	private static function graph_url( $path, $settings, $query_args = array() ) {
		$version = ! empty( $settings['fb_graph_version'] ) ? $settings['fb_graph_version'] : 'v21.0';
		$url     = "https://graph.facebook.com/{$version}/{$path}";
		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}
		return $url;
	}
}
