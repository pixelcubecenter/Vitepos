<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Instagram {

	/**
	 * Single-image post: create a media container for one attachment.
	 * @return string|WP_Error Container (creation) ID, or WP_Error.
	 */
	public static function create_container( $attachment_id, $caption ) {
		return self::create_media_container( array(
			'image_url' => wp_get_attachment_url( $attachment_id ),
			'caption'   => $caption,
		) );
	}

	/**
	 * Story: a single image published as an Instagram Story (no caption).
	 * @return string|WP_Error Container ID.
	 */
	public static function create_story_container( $attachment_id ) {
		return self::create_media_container( array(
			'image_url'  => wp_get_attachment_url( $attachment_id ),
			'media_type' => 'STORIES',
		) );
	}

	/**
	 * Carousel child: create a media container flagged as a carousel item
	 * (no caption — the caption lives on the parent carousel container).
	 * @return string|WP_Error
	 */
	public static function create_carousel_child( $attachment_id ) {
		return self::create_media_container( array(
			'image_url'        => wp_get_attachment_url( $attachment_id ),
			'is_carousel_item' => 'true',
		) );
	}

	/**
	 * Carousel parent: combines previously created children into one post.
	 * @param array $children_container_ids
	 * @return string|WP_Error
	 */
	public static function create_carousel_container( $children_container_ids, $caption ) {
		return self::create_media_container( array(
			'media_type' => 'CAROUSEL',
			'children'   => implode( ',', $children_container_ids ),
			'caption'    => $caption,
		) );
	}

	private static function create_media_container( $fields ) {
		$settings = PCC_SP_Settings::get_all();

		if ( empty( $settings['ig_access_token'] ) || empty( $settings['ig_business_account_id'] ) ) {
			return new WP_Error( 'pcc_sp_no_ig_creds', 'Instagram is not connected yet.' );
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

		$fields['access_token'] = $settings['ig_access_token'];
		$endpoint = self::graph_url( $settings['ig_business_account_id'] . '/media', $settings );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'body'    => $fields,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Instagram media creation failed (HTTP ' . $code . ')';
			if ( ! empty( $fields['image_url'] ) ) {
				$msg .= ' [image: ' . $fields['image_url'] . ']';
			}
			return new WP_Error( 'pcc_sp_ig_container_failed', $msg );
		}

		return $payload['id'];
	}

	/**
	 * Poll a container's status. Returns 'FINISHED', 'IN_PROGRESS', 'ERROR', or 'EXPIRED'.
	 */
	public static function get_container_status( $container_id ) {
		$settings = PCC_SP_Settings::get_all();
		$endpoint = self::graph_url( $container_id, $settings, array(
			'fields'       => 'status_code',
			'access_token' => $settings['ig_access_token'],
		) );

		$response = wp_remote_get( $endpoint, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $payload['status_code'] ) ? $payload['status_code'] : 'UNKNOWN';
	}

	/**
	 * Publish a previously created container (single-image or carousel parent).
	 * @return string|WP_Error Published media ID, or WP_Error.
	 */
	public static function publish_container( $container_id ) {
		$settings = PCC_SP_Settings::get_all();
		$endpoint = self::graph_url( $settings['ig_business_account_id'] . '/media_publish', $settings );

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 60,
			'body'    => array(
				'creation_id'  => $container_id,
				'access_token' => $settings['ig_access_token'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $payload['id'] ) ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'Instagram publish failed (HTTP ' . $code . ')';
			return new WP_Error( 'pcc_sp_ig_publish_failed', $msg );
		}

		return $payload['id'];
	}

	/**
	 * Caption for a single-product post, from the configurable template.
	 */
	public static function build_single_caption( $product ) {
		$settings = PCC_SP_Settings::get_all();
		$price    = number_format( (float) $product->get_price(), 0 );

		$replacements = array(
			'{title}'    => $product->get_name(),
			'{price}'    => $price,
			'{currency}' => $settings['currency_label'],
			'{website}'  => $settings['website_text'],
		);

		return strtr( $settings['caption_template'], $replacements );
	}

	/**
	 * Caption for a multi-product carousel post: one line per product plus
	 * the website, since the per-product template doesn't make sense when
	 * several different products/prices are in the same post.
	 */
	public static function build_carousel_caption( $products ) {
		$settings = PCC_SP_Settings::get_all();
		$lines    = array();
		foreach ( $products as $product ) {
			$price   = number_format( (float) $product->get_price(), 0 );
			$lines[] = '• ' . $product->get_name() . ' — ' . $price . ' ' . $settings['currency_label'];
		}
		$lines[] = '';
		$lines[] = 'Available now at ' . $settings['website_text'];
		return implode( "\n", $lines );
	}

	private static function graph_url( $path, $settings, $query_args = array() ) {
		$version = ! empty( $settings['fb_graph_version'] ) ? $settings['fb_graph_version'] : 'v21.0';
		$host    = ! empty( $settings['graph_host'] ) ? $settings['graph_host'] : 'graph.instagram.com';
		$url     = "https://{$host}/{$version}/{$path}";
		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}
		return $url;
	}
}
