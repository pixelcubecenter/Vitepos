<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Some hosts/plugins silently convert media library files to AVIF for
 * performance, even when you re-upload as JPG/WebP, and WordPress's own
 * post_mime_type meta can end up out of sync with what's actually on disk.
 * Social platforms don't accept AVIF/WebP, so this always checks the real
 * file bytes and converts anything unsupported to PNG.
 */
class PCC_SP_Image_Utils {

	const ALLOWED_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * @return array{filename:string,mime:string,content:string}|WP_Error
	 */
	public static function get_file_part( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'pcc_sp_no_file', 'Attachment file not found for ID ' . $attachment_id );
		}

		$real_mime = self::detect_real_mime( $file_path );

		if ( in_array( $real_mime, self::ALLOWED_MIMES, true ) ) {
			return array(
				'filename' => basename( $file_path ),
				'mime'     => $real_mime,
				'content'  => file_get_contents( $file_path ),
			);
		}

		// Unsupported format (e.g. AVIF) — try to convert it to PNG.
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error(
				'pcc_sp_unsupported_image',
				'Image format (' . $real_mime . ') is not supported and this server could not convert it either. Please re-export this image as a JPG or PNG and re-upload it. (Attachment ID ' . $attachment_id . ')'
			);
		}

		$tmp_path = wp_tempnam( 'pcc-sp-' . $attachment_id . '.png' );
		$saved    = $editor->save( $tmp_path, 'image/png' );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$bytes = file_get_contents( $saved['path'] );
		@unlink( $saved['path'] );

		if ( false === $bytes ) {
			return new WP_Error( 'pcc_sp_convert_failed', 'Could not read converted image for attachment ' . $attachment_id );
		}

		return array(
			'filename' => 'converted-' . $attachment_id . '.png',
			'mime'     => 'image/png',
			'content'  => $bytes,
		);
	}

	/**
	 * Instagram/Threads/TikTok download the image from its public URL. If the
	 * URL returns a web page (Cloudflare/security challenge, redirect to
	 * login, old domain) instead of an image, they fail with vague errors.
	 * Check the URL the way a crawler would and explain what we got.
	 *
	 * @return true|WP_Error  true when OK or when the check itself couldn't run.
	 */
	public static function check_public_image_url( $url ) {
		$resp = wp_remote_get( $url, array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
			'headers'     => array( 'Range' => 'bytes=0-2047' ),
		) );
		if ( is_wp_error( $resp ) ) {
			return true; // Can't test from here (loopback blocked, etc.) — let the platform try.
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$type = strtolower( (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
		if ( $code >= 200 && $code < 300 && preg_match( '#^image/(jpeg|jpg|png)#', $type ) ) {
			return true;
		}
		if ( $code >= 200 && $code < 300 && 0 === strpos( $type, 'image/' ) ) {
			return new WP_Error(
				'pcc_sp_image_wrong_format',
				'Your server is serving this image as ' . $type . ' even though the file is a PNG/JPG: ' . $url . ' — Instagram and Threads reject that. Turn off WebP/AVIF conversion (image optimization, CDN "Polish"/"Mirage", or a hosting speed plugin) for /wp-content/uploads/.'
			);
		}
		$server = wp_remote_retrieve_header( $resp, 'server' );
		$hint   = '';
		if ( false !== stripos( (string) $server, 'cloudflare' ) || wp_remote_retrieve_header( $resp, 'cf-mitigated' ) ) {
			$hint = ' Cloudflare is answering instead of your site — allow Meta/TikTok crawlers or turn off the challenge/bot-fight rule for /wp-content/uploads/.';
		} elseif ( $code >= 300 && $code < 400 ) {
			$hint = ' The image URL redirects — check that the site address (Settings → General) and any HTTPS/CDN redirect are correct.';
		}
		return new WP_Error(
			'pcc_sp_image_url_unreachable',
			'The image URL did not return an image (HTTP ' . $code . ', ' . ( $type ? $type : 'no content type' ) . '): ' . $url . '.' . $hint
		);
	}

	/**
	 * Instagram/Facebook/LinkedIn only accept JPEG/PNG. Return an attachment ID
	 * that is one of those, converting (e.g. WebP/AVIF) into a new PNG
	 * attachment when needed.
	 * @return int|WP_Error
	 */
	public static function ensure_jpeg_or_png_attachment( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'pcc_sp_no_file', 'Image file not found (ID ' . intval( $attachment_id ) . ').' );
		}
		$mime = self::detect_real_mime( $path );
		if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return (int) $attachment_id;
		}
		$gd = self::load_as_gd_image( $attachment_id );
		if ( is_wp_error( $gd ) ) {
			return $gd;
		}
		$new_id = self::save_gd_image_as_attachment( $gd, 0 );
		imagedestroy( $gd );
		return $new_id;
	}

	/**
	 * Load an attachment as a true-color, alpha-enabled GD image resource,
	 * normalizing unsupported formats via get_file_part() first.
	 *
	 * @return resource|GdImage|WP_Error
	 */
	public static function load_as_gd_image( $attachment_id ) {
		$file = self::get_file_part( $attachment_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$img = @imagecreatefromstring( $file['content'] );
		if ( ! $img ) {
			return new WP_Error( 'pcc_sp_gd_load_failed', 'Could not load image data for attachment ' . $attachment_id );
		}
		imagepalettetotruecolor( $img );
		imagealphablending( $img, true );
		imagesavealpha( $img, true );
		return $img;
	}

	/**
	 * Save a GD image resource as a PNG WordPress attachment.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public static function save_gd_image_as_attachment( $gd_image, $product_id, $format = 'png' ) {
		$upload_dir = wp_upload_dir();
		$jpeg       = ( 'jpeg' === $format );
		$filename   = 'pcc-sp-' . ( $jpeg ? 'story-' : '' ) . intval( $product_id ) . '-' . time() . '-' . wp_rand( 1000, 9999 ) . ( $jpeg ? '.jpg' : '.png' );
		$file_path  = trailingslashit( $upload_dir['path'] ) . $filename;

		if ( $jpeg ) {
			// JPEG has no transparency: flatten onto white first.
			$w    = imagesx( $gd_image );
			$h    = imagesy( $gd_image );
			$flat = imagecreatetruecolor( $w, $h );
			imagefill( $flat, 0, 0, imagecolorallocate( $flat, 255, 255, 255 ) );
			imagecopy( $flat, $gd_image, 0, 0, 0, 0, $w, $h );
			$written = imagejpeg( $flat, $file_path, 92 );
			imagedestroy( $flat );
		} else {
			$written = imagepng( $gd_image, $file_path, 4 );
		}
		if ( ! $written ) {
			return new WP_Error( 'pcc_sp_write_failed', 'Could not save the generated image to disk.' );
		}

		$attachment = array(
			'post_mime_type' => $jpeg ? 'image/jpeg' : 'image/png',
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attach_id = wp_insert_attachment( $attachment, $file_path );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}
		// These images only need to be posted, so build just the small admin
		// thumbnail instead of every size the theme and plugins register
		// (the main CPU/disk cost of saving an image).
		$only_thumbnail = function ( $sizes ) {
			return isset( $sizes['thumbnail'] ) ? array( 'thumbnail' => $sizes['thumbnail'] ) : array();
		};
		add_filter( 'intermediate_image_sizes_advanced', $only_thumbnail, 999 );
		add_filter( 'big_image_size_threshold', '__return_false', 999 );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
		remove_filter( 'intermediate_image_sizes_advanced', $only_thumbnail, 999 );
		remove_filter( 'big_image_size_threshold', '__return_false', 999 );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	public static function detect_real_mime( $file_path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
			if ( $mime ) {
				return $mime;
			}
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file_path );
			if ( $mime ) {
				return $mime;
			}
		}
		$size = @getimagesize( $file_path );
		if ( $size && ! empty( $size['mime'] ) ) {
			return $size['mime'];
		}
		return 'application/octet-stream';
	}
}
