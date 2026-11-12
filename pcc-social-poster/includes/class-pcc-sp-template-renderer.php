<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes a post image without calling any AI: overlays the product photo
 * (background removed via a border flood-fill, not AI), category, title,
 * and price onto a fixed template PNG, at positions configured in Settings.
 */
class PCC_SP_Template_Renderer {

	/**
	 * @param WC_Product $product
	 * @return int|WP_Error Attachment ID, or WP_Error.
	 */
	public static function generate_for_product( $product, $kind = 'post' ) {
		$settings = PCC_SP_Settings::get_all();
		$story    = ( 'story' === $kind );
		$p        = $story ? 'story_' : 'tpl_'; // setting-name prefix for boxes and colors
		$tpl_key  = $story ? 'story_template_attachment_id' : 'template_base_attachment_id';

		if ( empty( $settings[ $tpl_key ] ) ) {
			return new WP_Error( 'pcc_sp_no_template', $story ? 'No story template image is set in Settings → Stories.' : 'No template base image is set in Settings.' );
		}

		$canvas = PCC_SP_Image_Utils::load_as_gd_image( $settings[ $tpl_key ] );
		if ( is_wp_error( $canvas ) ) {
			return $canvas;
		}

		$product_image_id = $product->get_image_id();
		if ( ! $product_image_id ) {
			imagedestroy( $canvas );
			return new WP_Error( 'pcc_sp_no_product_image', 'This product has no featured image.' );
		}

		$cutout = self::load_and_cutout( $product_image_id, intval( $settings['tpl_bg_tolerance'] ) );
		if ( is_wp_error( $cutout ) ) {
			imagedestroy( $canvas );
			return $cutout;
		}

		// Composite the cutout into the product image box, "contain" fit,
		// centered horizontally, bottom-aligned (so it sits on the shadow).
		$box = PCC_SP_Settings::get_box( $p . 'image_box' );
		self::composite_contain( $canvas, $cutout, $box, 'bottom' );
		imagedestroy( $cutout );

		$text_color  = self::hex_to_gd_color( $canvas, $settings[ $p . 'text_color' ] );
		$price_color = self::hex_to_gd_color( $canvas, $settings[ $p . 'price_color' ] );

		$bold_font     = PCC_SP_PATH . 'assets/fonts/Poppins-Bold.ttf';
		$semibold_font = PCC_SP_PATH . 'assets/fonts/Poppins-SemiBold.ttf';

		$category_name = self::get_category_name( $product );
		if ( $category_name ) {
			$cat_box = PCC_SP_Settings::get_box( $p . 'category_box' );
			self::draw_fitted_text( $canvas, $category_name, $semibold_font, $cat_box, $text_color, $story ? 64 : 46, $story ? 24 : 20 );
		}

		$title_box = PCC_SP_Settings::get_box( $p . 'title_box' );
		self::draw_fitted_text( $canvas, html_entity_decode( $product->get_name(), ENT_QUOTES ), $bold_font, $title_box, $text_color, $story ? 56 : 40, $story ? 22 : 16, true );

		$price_box = PCC_SP_Settings::get_box( $p . 'price_box' );
		$price     = self::format_price( $product );
		if ( '' !== $price ) {
			self::draw_fitted_text( $canvas, $price, $bold_font, $price_box, $price_color, $story ? 56 : 40, $story ? 22 : 16 );
		}

		$attach_id = PCC_SP_Image_Utils::save_gd_image_as_attachment( $canvas, $product->get_id(), $story ? 'jpeg' : 'png' );
		imagedestroy( $canvas );

		return $attach_id;
	}

	private static function format_price( $product ) {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return '';
		}
		return number_format( (float) $price, 0 );
	}

	private static function get_category_name( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				return $term->name;
			}
		}
		return $terms[0]->name;
	}

	/**
	 * Loads a product photo and removes its background by flood-filling from
	 * the image borders: any border-connected pixel close in color to the
	 * corner/background color is made transparent. This only clears the
	 * background that touches the edges, not colors inside the product
	 * itself, and works well for typical studio product photography on a
	 * plain (usually white) background — but is not as precise as an AI
	 * segmentation model on busy/photographic backgrounds.
	 *
	 * @return resource|GdImage|WP_Error
	 */
	private static function load_and_cutout( $attachment_id, $tolerance ) {
		$file = PCC_SP_Image_Utils::get_file_part( $attachment_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$src = @imagecreatefromstring( $file['content'] );
		if ( ! $src ) {
			return new WP_Error( 'pcc_sp_gd_load_failed', 'Could not read the product image.' );
		}
		imagepalettetotruecolor( $src );

		// Downscale before flood-filling for performance — the box we'll
		// place this into is well under 700px wide, so extra resolution
		// only slows the pixel-by-pixel pass down for no visible benefit.
		$max_dim = 700;
		$w       = imagesx( $src );
		$h       = imagesy( $src );
		if ( $w > $max_dim || $h > $max_dim ) {
			$scale  = min( $max_dim / $w, $max_dim / $h );
			$new_w  = max( 1, (int) round( $w * $scale ) );
			$new_h  = max( 1, (int) round( $h * $scale ) );
			$resized = imagecreatetruecolor( $new_w, $new_h );
			imagecopyresampled( $resized, $src, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
			imagedestroy( $src );
			$src = $resized;
			$w   = $new_w;
			$h   = $new_h;
		}

		imagealphablending( $src, false );
		imagesavealpha( $src, true );

		// Background color = average of the four corners (truecolor pixels are
		// plain 0xAARRGGBB integers, so no per-pixel function call is needed).
		$r = $g = $b = 0;
		foreach ( array( array( 0, 0 ), array( $w - 1, 0 ), array( 0, $h - 1 ), array( $w - 1, $h - 1 ) ) as $c ) {
			$rgb = imagecolorat( $src, $c[0], $c[1] );
			$r  += ( $rgb >> 16 ) & 255;
			$g  += ( $rgb >> 8 ) & 255;
			$b  += $rgb & 255;
		}
		$r = (int) round( $r / 4 );
		$g = (int) round( $g / 4 );
		$b = (int) round( $b / 4 );

		// Flood fill from the borders. Pixels are tracked as one integer
		// (y * width + x) in a byte string and an int stack, which uses a
		// small fraction of the memory/CPU of the old array-of-arrays version.
		$visited     = str_repeat( "\0", $w * $h );
		$transparent = imagecolorallocatealpha( $src, $r, $g, $b, 127 );
		$stack       = array();

		for ( $x = 0; $x < $w; $x++ ) {
			foreach ( array( $x, ( $h - 1 ) * $w + $x ) as $idx ) {
				if ( "\0" === $visited[ $idx ] ) {
					$visited[ $idx ] = "\1";
					$stack[]         = $idx;
				}
			}
		}
		for ( $y = 1; $y < $h - 1; $y++ ) {
			foreach ( array( $y * $w, $y * $w + $w - 1 ) as $idx ) {
				if ( "\0" === $visited[ $idx ] ) {
					$visited[ $idx ] = "\1";
					$stack[]         = $idx;
				}
			}
		}

		$tolerance_sq = $tolerance * $tolerance;
		$last_x       = $w - 1;
		$last_y       = $h - 1;

		while ( $stack ) {
			$idx = array_pop( $stack );
			$y   = (int) ( $idx / $w );
			$x   = $idx - $y * $w;

			$rgb     = imagecolorat( $src, $x, $y );
			$dr      = ( ( $rgb >> 16 ) & 255 ) - $r;
			$dg      = ( ( $rgb >> 8 ) & 255 ) - $g;
			$db      = ( $rgb & 255 ) - $b;
			$dist_sq = $dr * $dr + $dg * $dg + $db * $db;

			if ( $dist_sq > $tolerance_sq ) {
				continue;
			}

			imagesetpixel( $src, $x, $y, $transparent );

			if ( $x < $last_x && "\0" === $visited[ $idx + 1 ] ) {
				$visited[ $idx + 1 ] = "\1";
				$stack[]             = $idx + 1;
			}
			if ( $x > 0 && "\0" === $visited[ $idx - 1 ] ) {
				$visited[ $idx - 1 ] = "\1";
				$stack[]             = $idx - 1;
			}
			if ( $y < $last_y && "\0" === $visited[ $idx + $w ] ) {
				$visited[ $idx + $w ] = "\1";
				$stack[]              = $idx + $w;
			}
			if ( $y > 0 && "\0" === $visited[ $idx - $w ] ) {
				$visited[ $idx - $w ] = "\1";
				$stack[]              = $idx - $w;
			}
		}
		unset( $visited, $stack );

		return $src;
	}

	/**
	 * "Contain" fit $src into $box on $dst, aligned per $valign
	 * ('center'|'top'|'bottom').
	 */
	private static function composite_contain( $dst, $src, $box, $valign = 'center' ) {
		$src_w = imagesx( $src );
		$src_h = imagesy( $src );
		if ( ! $src_w || ! $src_h ) {
			return;
		}
		$scale  = min( $box['w'] / $src_w, $box['h'] / $src_h );
		$draw_w = max( 1, (int) round( $src_w * $scale ) );
		$draw_h = max( 1, (int) round( $src_h * $scale ) );

		$dst_x = $box['x'] + (int) round( ( $box['w'] - $draw_w ) / 2 );
		if ( 'bottom' === $valign ) {
			$dst_y = $box['y'] + $box['h'] - $draw_h;
		} elseif ( 'top' === $valign ) {
			$dst_y = $box['y'];
		} else {
			$dst_y = $box['y'] + (int) round( ( $box['h'] - $draw_h ) / 2 );
		}

		imagealphablending( $dst, true );
		imagesavealpha( $dst, true );
		imagecopyresampled( $dst, $src, $dst_x, $dst_y, 0, 0, $draw_w, $draw_h, $src_w, $src_h );
	}

	/**
	 * Draws $text centered in $box, shrinking the font size (and, if
	 * $allow_wrap, wrapping to up to 2 lines) until it fits.
	 */
	private static function draw_fitted_text( $canvas, $text, $font, $box, $color, $max_size, $min_size, $allow_wrap = false ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return;
		}

		// Try single line first, shrinking down to $min_size.
		for ( $size = $max_size; $size >= $min_size; $size -= 2 ) {
			$bbox = imagettfbbox( $size, 0, $font, $text );
			$w    = abs( $bbox[2] - $bbox[0] );
			$h    = abs( $bbox[1] - $bbox[7] );
			if ( $w <= $box['w'] && $h <= $box['h'] ) {
				self::draw_centered_line( $canvas, $text, $font, $size, $box, $color, $bbox );
				return;
			}
		}

		if ( ! $allow_wrap ) {
			// Doesn't fit even at min size — draw at min size anyway
			// (will slightly overflow) rather than silently omitting it.
			$bbox = imagettfbbox( $min_size, 0, $font, $text );
			self::draw_centered_line( $canvas, $text, $font, $min_size, $box, $color, $bbox );
			return;
		}

		// Wrap into up to 2 lines, shrinking size until both lines fit.
		for ( $size = $max_size; $size >= $min_size; $size -= 2 ) {
			$lines = self::wrap_to_lines( $text, $font, $size, $box['w'], 2 );
			if ( count( $lines ) > 2 ) {
				continue;
			}
			$line_height = $size * 1.3;
			$total_h     = $line_height * count( $lines );
			if ( $total_h > $box['h'] ) {
				continue;
			}
			$fits = true;
			foreach ( $lines as $line ) {
				$bbox = imagettfbbox( $size, 0, $font, $line );
				if ( abs( $bbox[2] - $bbox[0] ) > $box['w'] ) {
					$fits = false;
					break;
				}
			}
			if ( ! $fits ) {
				continue;
			}

			$start_y = $box['y'] + (int) round( ( $box['h'] - $total_h ) / 2 ) + $size;
			foreach ( $lines as $i => $line ) {
				$bbox = imagettfbbox( $size, 0, $font, $line );
				$line_w = abs( $bbox[2] - $bbox[0] );
				$x = $box['x'] + (int) round( ( $box['w'] - $line_w ) / 2 );
				$y = $start_y + (int) round( $i * $line_height );
				imagettftext( $canvas, $size, 0, $x, $y, $color, $font, $line );
			}
			return;
		}

		// Nothing fit cleanly — draw one wrapped line at min size, best effort.
		$lines = self::wrap_to_lines( $text, $font, $min_size, $box['w'], 2 );
		$line  = implode( ' ', $lines );
		$bbox  = imagettfbbox( $min_size, 0, $font, $line );
		self::draw_centered_line( $canvas, $line, $font, $min_size, $box, $color, $bbox );
	}

	private static function draw_centered_line( $canvas, $text, $font, $size, $box, $color, $bbox ) {
		$w = abs( $bbox[2] - $bbox[0] );
		$h = abs( $bbox[1] - $bbox[7] );
		$x = $box['x'] + (int) round( ( $box['w'] - $w ) / 2 );
		$y = $box['y'] + (int) round( ( $box['h'] + $h ) / 2 );
		imagettftext( $canvas, $size, 0, $x, $y, $color, $font, $text );
	}

	private static function wrap_to_lines( $text, $font, $size, $max_width, $max_lines = 2 ) {
		$words   = preg_split( '/\s+/', trim( $text ) );
		$lines   = array();
		$current = '';
		$i       = 0;
		$n       = count( $words );

		while ( $i < $n ) {
			if ( count( $lines ) === $max_lines - 1 ) {
				// Last allowed line: absorb every remaining word, regardless of width.
				$rest    = implode( ' ', array_slice( $words, $i ) );
				$current = ( '' === $current ) ? $rest : $current . ' ' . $rest;
				break;
			}

			$word      = $words[ $i ];
			$candidate = ( '' === $current ) ? $word : $current . ' ' . $word;
			$bbox      = imagettfbbox( $size, 0, $font, $candidate );
			$w         = abs( $bbox[2] - $bbox[0] );

			if ( $w > $max_width && '' !== $current ) {
				$lines[] = $current;
				$current = $word;
			} else {
				$current = $candidate;
			}
			$i++;
		}

		if ( '' !== $current ) {
			$lines[] = $current;
		}
		return $lines;
	}

	private static function hex_to_gd_color( $canvas, $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 6 !== strlen( $hex ) ) {
			$hex = '000000';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return imagecolorallocate( $canvas, $r, $g, $b );
	}
}
