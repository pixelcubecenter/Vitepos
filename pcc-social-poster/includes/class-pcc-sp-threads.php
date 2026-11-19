<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Threads {

	/**
	 * Single-post container.
	 * @return string|WP_Error Container (creation) ID, or WP_Error.
	 */
	public static function create_container( $attachment_id, $text ) {
		return self::create_media_container( array(
			'media_type' => 'IMAGE',
			'image_url'  => wp_get_attachment_url( $attachment_id ),
			'text'       => $text,
		) );
	}

	/**
	 * Carousel child (no text — text lives on the parent carousel container).
	 * @return string|WP_Error
	 */
	public static function create_carousel_child( $attachment_id ) {
		return self::create_media_container( array(
			'media_type'       => 'IMAGE',
			'image_url'        => wp_get_attachment_url( $attachment_id ),
			'is_carousel_item' => 'true',
		) );
	}

	/**
	 * Carousel parent, combining previously created children.
	 * @param string[] $children_container_ids
	 * @return string|WP_Error
	 */
	public static function create_carousel_container( $children_container_ids, $text ) {
		return self::create_media_container( array(
			'media_type' => 'CAROUSEL',
			'children'   => implode( ',', $children_container_ids ),
			'text'       => $text,
		) );
	}

	private static function create_media_container( $fields ) {
		$settings = PCC_SP_Settings::get_all();

		if ( ! PCC_SP_Settings::is_threads_configured() ) {
			return new WP_Error( 'pcc_sp_no_threads_creds', 'Threads is not connected yet.' );
		}
		if ( isset( $fields['image_url'] ) && ! $fields['image_url'] ) {
			return new WP_Error( 'pcc_sp_no_image_url', 'Could not resolve a public URL for the generated image.' );
		}
		if ( ! empty( $fields['image_url'] ) ) {
			$check = PCC_SP_Image_Utils::check_public_image_url( $fields['image_url'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		$fields['access_token'] = $settings['threads_access_token'];
		$endpoint = self::graph_url( $settings['threads_user_id'] . '/threads', $settings );

		$response = wp_remote_post( $endpoint, array( 'timeout' => 60, 'body' => $fields ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Threads media creation failed (HTTP ' . $code . ')';
			if ( ! empty( $fields['image_url'] ) ) {
				$msg .= ' [image: ' . $fields['image_url'] . ']';
			}
			return new WP_Error( 'pcc_sp_threads_container_failed', $msg );
		}

		return $payload['id'];
	}

	/**
	 * Poll a container's status. Returns 'FINISHED', 'IN_PROGRESS', 'ERROR', or 'EXPIRED'.
	 */
	public static function get_container_status( $container_id ) {
		$settings = PCC_SP_Settings::get_all();
		$endpoint = self::graph_url( $container_id, $settings, array(
			'fields'       => 'status',
			'access_token' => $settings['threads_access_token'],
		) );

		$response = wp_remote_get( $endpoint, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $payload['status'] ) ? $payload['status'] : 'UNKNOWN';
	}

	/**
	 * @return string|WP_Error Published post ID, or WP_Error.
	 */
	public static function publish_container( $container_id ) {
		$settings = PCC_SP_Settings::get_all();
		$endpoint = self::graph_url( $settings['threads_user_id'] . '/threads_publish', $settings );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'body'    => array(
				'creation_id'  => $container_id,
				'access_token' => $settings['threads_access_token'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Threads publish failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_threads_publish_failed', $msg );
		}

		return $payload['id'];
	}

	private static function graph_url( $path, $settings, $query_args = array() ) {
		$version = ! empty( $settings['threads_graph_version'] ) ? $settings['threads_graph_version'] : 'v1.0';
		$url     = "https://graph.threads.net/{$version}/{$path}";
		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}
		return $url;
	}
}
