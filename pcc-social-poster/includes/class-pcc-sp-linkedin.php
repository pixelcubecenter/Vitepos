<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LinkedIn Company Page posting via the Posts + Images REST APIs.
 * Requires the Community Management API product (w_organization_social).
 */
class PCC_SP_LinkedIn {

	const API_BASE = 'https://api.linkedin.com/rest';

	private static function headers( $settings, $json = true ) {
		$h = array(
			'Authorization'             => 'Bearer ' . $settings['linkedin_access_token'],
			'Linkedin-Version'          => ! empty( $settings['linkedin_api_version'] ) ? $settings['linkedin_api_version'] : '202609',
			'X-Restli-Protocol-Version' => '2.0.0',
		);
		if ( $json ) {
			$h['Content-Type'] = 'application/json';
		}
		return $h;
	}

	private static function error_from( $response, $fallback ) {
		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg     = '';
		if ( is_array( $payload ) && ! empty( $payload['message'] ) ) {
			$msg = $payload['message'];
		} else {
			$msg = substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
		}
		return new WP_Error( 'pcc_sp_linkedin_failed', $fallback . ' (HTTP ' . intval( $code ) . ')' . ( $msg ? ': ' . $msg : '' ) );
	}

	/**
	 * Upload one local attachment to LinkedIn.
	 * @return string|WP_Error Image URN (urn:li:image:...).
	 */
	public static function upload_image( $attachment_id ) {
		$settings = PCC_SP_Settings::get_all();
		if ( ! PCC_SP_Settings::is_linkedin_configured() ) {
			return new WP_Error( 'pcc_sp_no_linkedin_creds', 'LinkedIn is not connected yet.' );
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'pcc_sp_no_file', 'Generated image file not found.' );
		}

		$init = wp_remote_post( self::API_BASE . '/images?action=initializeUpload', array(
			'timeout' => 60,
			'headers' => self::headers( $settings ),
			'body'    => wp_json_encode( array(
				'initializeUploadRequest' => array( 'owner' => 'urn:li:organization:' . $settings['linkedin_org_id'] ),
			) ),
		) );
		if ( is_wp_error( $init ) ) {
			return $init;
		}
		$code = wp_remote_retrieve_response_code( $init );
		$data = json_decode( wp_remote_retrieve_body( $init ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['value']['uploadUrl'] ) || empty( $data['value']['image'] ) ) {
			return self::error_from( $init, 'LinkedIn image upload init failed' );
		}

		$put = wp_remote_request( $data['value']['uploadUrl'], array(
			'method'  => 'PUT',
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['linkedin_access_token'],
				'Content-Type'  => 'application/octet-stream',
			),
			'body'    => file_get_contents( $path ),
		) );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$put_code = wp_remote_retrieve_response_code( $put );
		if ( $put_code < 200 || $put_code >= 300 ) {
			return self::error_from( $put, 'LinkedIn image upload failed' );
		}

		return $data['value']['image'];
	}

	/**
	 * Create the post. One image → content.media, 2+ → content.multiImage.
	 * @param string[] $image_urns
	 * @return string|WP_Error Post URN.
	 */
	public static function create_post( $image_urns, $text, $alt_texts = array() ) {
		$settings = PCC_SP_Settings::get_all();

		if ( 1 === count( $image_urns ) ) {
			$content = array( 'media' => array(
				'id'      => $image_urns[0],
				'altText' => isset( $alt_texts[0] ) ? $alt_texts[0] : '',
			) );
		} else {
			$images = array();
			foreach ( $image_urns as $i => $urn ) {
				$images[] = array(
					'id'      => $urn,
					'altText' => isset( $alt_texts[ $i ] ) ? $alt_texts[ $i ] : '',
				);
			}
			$content = array( 'multiImage' => array( 'images' => $images ) );
		}

		$body = array(
			'author'          => 'urn:li:organization:' . $settings['linkedin_org_id'],
			'commentary'      => $text,
			'visibility'      => 'PUBLIC',
			'distribution'    => array(
				'feedDistribution'              => 'MAIN_FEED',
				'targetEntities'                => array(),
				'thirdPartyDistributionChannels' => array(),
			),
			'content'         => $content,
			'lifecycleState'  => 'PUBLISHED',
			'isReshareDisabledByAuthor' => false,
		);

		$response = wp_remote_post( self::API_BASE . '/posts', array(
			'timeout' => 60,
			'headers' => self::headers( $settings ),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== (int) $code ) {
			return self::error_from( $response, 'LinkedIn post failed' );
		}
		$id = wp_remote_retrieve_header( $response, 'x-restli-id' );
		return $id ? $id : 'created';
	}

	/**
	 * LinkedIn "commentary" uses a reduced-text format where these characters
	 * must be escaped, otherwise the post is silently truncated.
	 */
	public static function escape_commentary( $text ) {
		return preg_replace( '/([\\\\|{}@\[\]()<>#*_~])/', '\\\\$1', $text );
	}
}
