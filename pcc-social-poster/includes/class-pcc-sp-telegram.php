<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telegram channel posting through the Bot API.
 *
 * Unlike Instagram/Threads, Telegram does not fetch the image from your
 * website: this plugin uploads the image bytes straight to Telegram, so
 * hosting-side bot blocking of /wp-content/uploads/ cannot affect it.
 *
 * 1 image  -> sendPhoto
 * 2-10     -> sendMediaGroup (one album post)
 * Captions are limited to 1024 characters by Telegram. A longer text is sent
 * as a normal text message right after the photos instead of being cut off.
 */
class PCC_SP_Telegram {

	const CAPTION_LIMIT = 1024;
	const TEXT_LIMIT    = 4096;
	const MAX_BYTES     = 9500000; // Telegram photo limit is 10 MB.

	/* ------------------------------------------------------------------
	 * Low-level API call (JSON, or multipart when files are attached)
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $method  Bot API method, e.g. sendPhoto.
	 * @param array  $fields  Plain fields (arrays are JSON-encoded).
	 * @param array  $files   field name => array( 'name' => , 'mime' => , 'content' => ).
	 * @return array|WP_Error The "result" value of the response.
	 */
	private static function api( $method, array $fields = array(), array $files = array(), $token = null ) {
		if ( null === $token || '' === trim( (string) $token ) ) {
			$token = trim( (string) PCC_SP_Settings::get( 'telegram_bot_token' ) );
		}
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'pcc_sp_no_telegram_token', 'Telegram bot token is not set.' );
		}
		$url = 'https://api.telegram.org/bot' . $token . '/' . $method;

		$args = array( 'timeout' => 120 );

		if ( empty( $files ) ) {
			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = wp_json_encode( $fields );
		} else {
			$boundary = 'pccsp' . wp_generate_password( 24, false );
			$body     = '';
			foreach ( $fields as $name => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
				$body .= $value . "\r\n";
			}
			foreach ( $files as $name => $file ) {
				$body .= '--' . $boundary . "\r\n";
				$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['name'] . "\"\r\n";
				$body .= 'Content-Type: ' . $file['mime'] . "\r\n\r\n";
				$body .= $file['content'] . "\r\n";
			}
			$body .= '--' . $boundary . "--\r\n";

			$args['headers'] = array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
			$args['body']    = $body;
		}

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			// Never leak the token in an error message.
			return new WP_Error( 'pcc_sp_telegram_http', str_replace( $token, '***', $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
			$desc = is_array( $data ) && ! empty( $data['description'] ) ? $data['description'] : substr( (string) wp_remote_retrieve_body( $response ), 0, 200 );
			$hint = '';
			if ( false !== stripos( $desc, 'chat not found' ) ) {
				$hint = ' Check the channel ID, and that the bot was added to the channel as an administrator.';
			} elseif ( false !== stripos( $desc, 'not enough rights' ) || false !== stripos( $desc, 'need administrator' ) ) {
				$hint = ' Make the bot a channel administrator with the "Post Messages" permission.';
			} elseif ( 401 === $code || false !== stripos( $desc, 'unauthorized' ) ) {
				$hint = ' The bot token is wrong or was revoked — copy it again from @BotFather.';
			}
			if ( is_array( $data ) && ! empty( $data['parameters']['retry_after'] ) ) {
				$hint .= ' Telegram asks to wait ' . intval( $data['parameters']['retry_after'] ) . ' seconds.';
			}
			return new WP_Error( 'pcc_sp_telegram_failed', 'Telegram ' . $method . ' failed (HTTP ' . $code . '): ' . $desc . '.' . $hint );
		}
		return isset( $data['result'] ) ? $data['result'] : array();
	}

	/* ------------------------------------------------------------------
	 * Images
	 * ---------------------------------------------------------------- */

	/**
	 * Read an attachment as JPEG/PNG bytes under Telegram's size limit.
	 *
	 * @return array|WP_Error array( name, mime, content )
	 */
	public static function photo_part( $attachment_id ) {
		$file = PCC_SP_Image_Utils::get_file_part( $attachment_id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$mime = $file['mime'];
		// WebP is accepted by Telegram's photo upload only unreliably; treat
		// it like any unsupported type and convert.
		$needs_convert = ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || strlen( $file['content'] ) > self::MAX_BYTES;

		if ( $needs_convert ) {
			$img = @imagecreatefromstring( $file['content'] );
			if ( ! $img ) {
				return new WP_Error( 'pcc_sp_telegram_image', 'Could not read image ' . intval( $attachment_id ) . ' to send to Telegram.' );
			}
			imagepalettetotruecolor( $img );
			$w = imagesx( $img );
			$h = imagesy( $img );
			$max = 2560;
			if ( $w > $max || $h > $max ) {
				$scale   = min( $max / $w, $max / $h );
				$resized = imagecreatetruecolor( max( 1, (int) round( $w * $scale ) ), max( 1, (int) round( $h * $scale ) ) );
				imagefill( $resized, 0, 0, imagecolorallocate( $resized, 255, 255, 255 ) );
				imagecopyresampled( $resized, $img, 0, 0, 0, 0, imagesx( $resized ), imagesy( $resized ), $w, $h );
				imagedestroy( $img );
				$img = $resized;
			}
			ob_start();
			imagejpeg( $img, null, 88 );
			$bytes = ob_get_clean();
			imagedestroy( $img );
			if ( ! $bytes ) {
				return new WP_Error( 'pcc_sp_telegram_image', 'Could not prepare image ' . intval( $attachment_id ) . ' for Telegram.' );
			}
			return array( 'name' => 'photo-' . intval( $attachment_id ) . '.jpg', 'mime' => 'image/jpeg', 'content' => $bytes );
		}

		$ext = ( 'image/png' === $mime ) ? 'png' : 'jpg';
		return array( 'name' => 'photo-' . intval( $attachment_id ) . '.' . $ext, 'mime' => $mime, 'content' => $file['content'] );
	}

	/* ------------------------------------------------------------------
	 * Publishing
	 * ---------------------------------------------------------------- */

	private static function cut( $text, $limit ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) <= $limit ? $text : rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…';
		}
		return strlen( $text ) <= $limit ? $text : rtrim( substr( $text, 0, $limit - 1 ) ) . '…';
	}

	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}

	/**
	 * Post 1-10 images to the configured channel.
	 *
	 * @param int[]  $attachment_ids
	 * @param string $caption
	 * @return string|WP_Error Message ID of the first message.
	 */
	public static function publish( array $attachment_ids, $caption ) {
		if ( ! PCC_SP_Settings::is_telegram_configured() ) {
			return new WP_Error( 'pcc_sp_no_telegram', 'Telegram is not set up yet.' );
		}
		return self::publish_to( trim( (string) PCC_SP_Settings::get( 'telegram_chat_id' ) ), null, $attachment_ids, $caption );
	}

	/**
	 * "Telegram Private": the same images, with NO text, to a Telegram group.
	 *
	 * @return string|WP_Error
	 */
	public static function publish_private( array $attachment_ids ) {
		if ( ! PCC_SP_Settings::is_telegram_private_configured() ) {
			return new WP_Error( 'pcc_sp_no_telegram_private', 'Telegram Private is not set up yet.' );
		}
		return self::publish_to(
			trim( (string) PCC_SP_Settings::get( 'telegram_private_chat_id' ) ),
			PCC_SP_Settings::get( 'telegram_private_bot_token' ),
			$attachment_ids,
			''
		);
	}

	/**
	 * @param string      $chat_id
	 * @param string|null $token  null/empty = the main bot token.
	 * @return string|WP_Error Message ID of the first message.
	 */
	private static function publish_to( $chat_id, $token, array $attachment_ids, $caption ) {
		$attachment_ids = array_values( $attachment_ids );
		if ( empty( $attachment_ids ) ) {
			return new WP_Error( 'pcc_sp_telegram_no_images', 'No images to send.' );
		}
		if ( count( $attachment_ids ) > 10 ) {
			$attachment_ids = array_slice( $attachment_ids, 0, 10 );
		}

		$caption = (string) $caption;
		$fits    = self::length( $caption ) <= self::CAPTION_LIMIT;

		$files = array();
		foreach ( $attachment_ids as $i => $id ) {
			$part = self::photo_part( $id );
			if ( is_wp_error( $part ) ) {
				return $part;
			}
			$files[ 'file' . $i ] = $part;
		}

		if ( 1 === count( $files ) ) {
			$fields = array( 'chat_id' => $chat_id );
			if ( $fits && '' !== trim( $caption ) ) {
				$fields['caption'] = $caption;
			}
			$result = self::api( 'sendPhoto', $fields, array( 'photo' => $files['file0'] ), $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$first_id = isset( $result['message_id'] ) ? (string) $result['message_id'] : 'sent';
		} else {
			$media = array();
			foreach ( array_keys( $files ) as $i => $name ) {
				$item = array( 'type' => 'photo', 'media' => 'attach://' . $name );
				if ( 0 === $i && $fits && '' !== trim( $caption ) ) {
					$item['caption'] = $caption;
				}
				$media[] = $item;
			}
			$result = self::api( 'sendMediaGroup', array( 'chat_id' => $chat_id, 'media' => $media ), $files, $token );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$first_id = ( isset( $result[0]['message_id'] ) ) ? (string) $result[0]['message_id'] : 'sent';
		}

		// Text too long for a caption: send it as its own message so nothing
		// is lost. If this part fails the photos are already posted, so report
		// success with a note rather than triggering a duplicate on retry.
		if ( ! $fits && '' !== trim( $caption ) ) {
			$text = self::api( 'sendMessage', array(
				'chat_id'                  => $chat_id,
				'text'                     => self::cut( $caption, self::TEXT_LIMIT ),
				'disable_web_page_preview' => true,
			), array(), $token );
			if ( is_wp_error( $text ) ) {
				return $first_id . ' (photos sent, but the text message failed: ' . $text->get_error_message() . ')';
			}
		}

		return $first_id;
	}

	/* ------------------------------------------------------------------
	 * Settings page: "Test connection" button
	 * ---------------------------------------------------------------- */

	public static function test_url( $target = 'channel' ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=pcc_sp_telegram_test&target=' . ( 'private' === $target ? 'private' : 'channel' ) ), 'pcc_sp_telegram_test' );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_telegram_test' );

		$private = isset( $_GET['target'] ) && 'private' === $_GET['target'];
		$ok      = false;
		$msg     = '';

		if ( $private ) {
			$configured = PCC_SP_Settings::is_telegram_private_configured();
			$chat_id    = trim( (string) PCC_SP_Settings::get( 'telegram_private_chat_id' ) );
			$token      = PCC_SP_Settings::get( 'telegram_private_bot_token' );
			$title_key  = 'telegram_private_chat_title';
			$what       = 'group';
		} else {
			$configured = PCC_SP_Settings::is_telegram_configured();
			$chat_id    = trim( (string) PCC_SP_Settings::get( 'telegram_chat_id' ) );
			$token      = null;
			$title_key  = 'telegram_chat_title';
			$what       = 'channel';
		}

		if ( ! $configured ) {
			$msg = 'Save the bot token and the ' . $what . ' ID first.';
		} else {
			$me = self::api( 'getMe', array(), array(), $token );
			if ( is_wp_error( $me ) ) {
				$msg = $me->get_error_message();
			} else {
				$chat = self::api( 'getChat', array( 'chat_id' => $chat_id ), array(), $token );
				if ( is_wp_error( $chat ) ) {
					$msg = $chat->get_error_message();
				} else {
					$title = isset( $chat['title'] ) ? $chat['title'] : $chat_id;
					PCC_SP_Settings::save( array( $title_key => $title ) );

					$member = self::api( 'getChatMember', array( 'chat_id' => $chat_id, 'user_id' => $me['id'] ), array(), $token );
					if ( is_wp_error( $member ) ) {
						$msg = 'Found "' . $title . '" but could not check the bot\'s role: ' . $member->get_error_message();
					} elseif ( isset( $member['status'] ) && in_array( $member['status'], array( 'creator', 'administrator', 'member' ), true ) && ( 'member' !== $member['status'] || $private ) ) {
						if ( 'administrator' === $member['status'] && isset( $member['can_post_messages'] ) && ! $member['can_post_messages'] ) {
							$msg = 'Found "' . $title . '", but the bot may not post there. Allow "Post Messages" for it.';
						} else {
							$ok  = true;
							$msg = 'Connected: bot @' . ( isset( $me['username'] ) ? $me['username'] : '' ) . ' can post to "' . $title . '".';
						}
					} else {
						$msg = 'Found "' . $title . '", but the bot is not allowed to post there. ' . ( $private ? 'Add the bot to the group as a member.' : 'Add it under the channel\'s Administrators with the "Post Messages" permission.' );
					}
				}
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'pcc_sp_tg_ok'  => $ok ? '1' : '0',
			'pcc_sp_tg_msg' => rawurlencode( ( $private ? 'Telegram Private: ' : 'Telegram: ' ) . $msg ),
		), admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}
}
