<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Queue {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'pcc_sp_tick', array( $this, 'tick' ) );
		add_action( 'pcc_sp_kick', array( $this, 'tick' ) );
	}

	/**
	 * Ask for a queue check a few seconds from now (used right after posts
	 * are queued, so they start without waiting for the 5-minute check).
	 */
	public static function kick() {
		if ( ! wp_next_scheduled( 'pcc_sp_kick' ) ) {
			wp_schedule_single_event( time() + 10, 'pcc_sp_kick' );
		}
	}

	/**
	 * Process the next queued post if allowed.
	 *
	 * @param bool $force true (the "Process next post now" button) skips the
	 *                    minutes-between-posts wait and the daily cap.
	 * @return string Human-readable result (shown by the button; ignored by cron).
	 */
	public function tick( $force = false ) {
		$force = ( true === $force );

		// Weekly renewal of the 60-day Instagram/Threads tokens (one option read when not due).
		PCC_SP_Token_Refresh::maybe_run();

		// One cheap query. On an idle queue (the normal case) that is the
		// ONLY work this check does.
		$counts = PCC_SP_DB::pending_counts();
		if ( 0 === $counts['queued'] && 0 === $counts['active'] ) {
			return 'Nothing is waiting in the queue.';
		}

		if ( $counts['active'] > 0 ) {
			PCC_SP_DB::recover_stale_posts();
			if ( PCC_SP_DB::has_active_post() ) {
				return 'Another post is being generated or posted right now. Wait for it to finish (or Cancel it if it is stuck).';
			}
		}

		// Stories go first and skip the gap/daily limit (they are not normal posts).
		$post     = PCC_SP_DB::get_next_queued_post( 'story' );
		$is_story = (bool) $post;
		if ( ! $post ) {
			$post = PCC_SP_DB::get_next_queued_post( 'feed' );
		}
		if ( ! $post ) {
			return 'Nothing is waiting in the queue.';
		}

		$settings = PCC_SP_Settings::get_all();

		if ( ! $force && ! $is_story ) {
			$last_posted = PCC_SP_DB::get_last_posted_time();
			if ( $last_posted ) {
				$interval = intval( $settings['post_interval_minutes'] );
				$elapsed  = ( current_time( 'timestamp' ) - strtotime( $last_posted ) ) / 60;
				if ( $elapsed < $interval ) {
					return 'Waiting for the "minutes between posts" gap (' . $interval . ' min): about ' . max( 1, (int) ceil( $interval - $elapsed ) ) . ' more minute(s).';
				}
			}

			$since = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );
			if ( PCC_SP_DB::count_posted_since( $since ) >= intval( $settings['max_posts_per_day'] ) ) {
				return 'Daily limit reached (' . intval( $settings['max_posts_per_day'] ) . ' posts). The queue continues tomorrow.';
			}
		}

		$this->process_post( $post );
		return 'Processed post #' . intval( $post->id ) . '.';
	}

	public function process_post( $post ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		PCC_SP_DB::update_post( $post->id, array( 'status' => 'generating' ) );

		$jobs     = PCC_SP_DB::get_jobs_for_post( $post->id );
		$products = array();

		foreach ( $jobs as $job ) {
			if ( 'manual' === $post->method ) {
				// Images were chosen by hand; nothing to generate.
				if ( $job->image_attachment_id ) {
					$products[ $job->id ] = true;
				}
				continue;
			}
			if ( 'ready' === $job->status && $job->image_attachment_id ) {
				$product = wc_get_product( $job->product_id );
				if ( $product ) {
					$products[ $job->id ] = $product;
				}
				continue;
			}

			$product = wc_get_product( $job->product_id );
			if ( ! $product ) {
				PCC_SP_DB::update_job( $job->id, array( 'status' => 'failed', 'error_message' => 'Product no longer exists.' ) );
				continue;
			}

			PCC_SP_DB::update_job( $job->id, array( 'status' => 'generating' ) );

			$attachment_id = $this->generate_image( $post->method, $product );

			if ( is_wp_error( $attachment_id ) ) {
				PCC_SP_DB::update_job( $job->id, array(
					'status'        => 'failed',
					'error_message' => $attachment_id->get_error_message(),
				) );
				continue;
			}

			PCC_SP_DB::update_job( $job->id, array(
				'status'              => 'ready',
				'image_attachment_id' => $attachment_id,
			) );
			$products[ $job->id ] = $product;
		}

		if ( empty( $products ) ) {
			PCC_SP_DB::update_post( $post->id, array(
				'status'        => 'failed',
				'error_message' => 'All products in this post failed to generate an image.',
			) );
			if ( 'story' === $post->method ) {
				PCC_SP_Story::record_result( $post->id );
			}
			return;
		}

		$ready_jobs = array_values( array_filter( PCC_SP_DB::get_jobs_for_post( $post->id ), function ( $j ) {
			return 'ready' === $j->status && $j->image_attachment_id;
		} ) );

		PCC_SP_DB::update_post( $post->id, array( 'status' => 'posting' ) );

		$platforms = array_filter( array_map( 'trim', explode( ',', $post->platforms ) ) );
		if ( empty( $platforms ) ) {
			$platforms = array( 'instagram' );
		}

		$results = array();

		if ( in_array( 'instagram', $platforms, true ) ) {
			$results['instagram'] = ( 'posted' === $post->ig_status ) ? true : $this->publish_instagram( $post, $ready_jobs, $products );
		}
		if ( in_array( 'facebook', $platforms, true ) ) {
			$results['facebook'] = ( 'posted' === $post->fb_status ) ? true : $this->publish_facebook( $post, $ready_jobs, $products );
		}
		if ( in_array( 'tiktok', $platforms, true ) ) {
			$results['tiktok'] = ( 'posted' === $post->tiktok_status ) ? true : $this->publish_tiktok( $post, $ready_jobs, $products );
		}
		if ( in_array( 'threads', $platforms, true ) ) {
			$results['threads'] = ( 'posted' === $post->threads_status ) ? true : $this->publish_threads( $post, $ready_jobs, $products );
		}

		if ( in_array( 'linkedin', $platforms, true ) ) {
			$results['linkedin'] = ( 'posted' === $post->linkedin_status ) ? true : $this->publish_linkedin( $post, $ready_jobs, $products );
		}

		if ( in_array( 'telegram', $platforms, true ) ) {
			$results['telegram'] = ( 'posted' === $post->telegram_status ) ? true : $this->publish_telegram( $post, $ready_jobs, $products );
		}

		if ( in_array( 'telegram_private', $platforms, true ) ) {
			$results['telegram_private'] = ( 'posted' === $post->telegram_private_status ) ? true : $this->publish_telegram_private( $post, $ready_jobs );
		}

		if ( in_array( 'tumblr', $platforms, true ) ) {
			$results['tumblr'] = ( 'posted' === $post->tumblr_status ) ? true : $this->publish_tumblr( $post, $ready_jobs, $products );
		}

		if ( in_array( 'pinterest', $platforms, true ) ) {
			$results['pinterest'] = ( 'posted' === $post->pinterest_status ) ? true : $this->publish_pinterest( $post, $ready_jobs, $products );
		}

		if ( in_array( 'bluesky', $platforms, true ) ) {
			$results['bluesky'] = ( 'posted' === $post->bluesky_status ) ? true : $this->publish_bluesky( $post, $ready_jobs, $products );
		}

		if ( in_array( 'mastodon', $platforms, true ) ) {
			$results['mastodon'] = ( 'posted' === $post->mastodon_status ) ? true : $this->publish_mastodon( $post, $ready_jobs, $products );
		}

		if ( in_array( 'instagram_story', $platforms, true ) ) {
			$results['instagram_story'] = ( 'posted' === $post->igstory_status ) ? true : $this->publish_ig_story( $post, $ready_jobs );
		}

		if ( in_array( 'facebook_story', $platforms, true ) ) {
			$results['facebook_story'] = ( 'posted' === $post->fbstory_status ) ? true : $this->publish_fb_story( $post, $ready_jobs );
		}

		if ( in_array( 'reddit', $platforms, true ) ) {
			$results['reddit'] = ( 'posted' === $post->reddit_status ) ? true : $this->publish_reddit( $post, $ready_jobs, $products );
		}

		$succeeded = count( array_filter( $results ) );
		$total     = count( $results );

		if ( 0 === $total ) {
			PCC_SP_DB::update_post( $post->id, array( 'status' => 'failed', 'error_message' => 'No platform selected.' ) );
			return;
		}

		if ( $succeeded === $total ) {
			PCC_SP_DB::update_post( $post->id, array( 'status' => 'posted', 'posted_at' => current_time( 'mysql' ) ) );
		} elseif ( $succeeded > 0 ) {
			PCC_SP_DB::update_post( $post->id, array( 'status' => 'partial', 'posted_at' => current_time( 'mysql' ) ) );
		} else {
			PCC_SP_DB::update_post( $post->id, array( 'status' => 'failed' ) );
		}

		if ( 'story' === $post->method ) {
			PCC_SP_Story::record_result( $post->id );
		}
	}

	/**
	 * Manual posts carry the caption the admin typed; product posts build
	 * theirs from the product data.
	 */
	private function caption_for( $post, $ready_jobs, $products ) {
		if ( 'manual' === $post->method ) {
			return (string) $post->caption;
		}
		if ( count( $ready_jobs ) === 1 ) {
			return PCC_SP_Instagram::build_single_caption( $products[ $ready_jobs[0]->id ] );
		}
		return PCC_SP_Instagram::build_carousel_caption( array_values( $products ) );
	}

	/**
	 * Every product post uses the no-AI template. (Older queue rows that were
	 * created with the removed Gemini/OpenAI methods also fall back to it
	 * when retried.)
	 */
	private function generate_image( $method, $product ) {
		return PCC_SP_Template_Renderer::generate_for_product( $product, 'story' === $method ? 'story' : 'post' );
	}

	/* --------------------------- Instagram --------------------------- */

	private function publish_instagram( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'ig_status' => 'posting' ) );

		if ( count( $ready_jobs ) === 1 ) {
			$job     = $ready_jobs[0];
			$product = $products[ $job->id ];
			$caption = $this->caption_for( $post, $ready_jobs, $products );
			PCC_SP_DB::update_post( $post->id, array( 'caption' => $caption ) );

			$container_id = PCC_SP_Instagram::create_container( $job->image_attachment_id, $caption );
		} else {
			$caption = $this->caption_for( $post, $ready_jobs, $products );
			PCC_SP_DB::update_post( $post->id, array( 'caption' => $caption ) );

			$children = array();
			foreach ( $ready_jobs as $job ) {
				$child_id = PCC_SP_Instagram::create_carousel_child( $job->image_attachment_id );
				if ( is_wp_error( $child_id ) ) {
					PCC_SP_DB::update_post( $post->id, array(
						'ig_status'     => 'failed',
						'error_message' => 'Carousel item failed (' . $job->product_title . '): ' . $child_id->get_error_message(),
					) );
					return false;
				}
				PCC_SP_DB::update_job( $job->id, array( 'ig_child_id' => $child_id ) );
				$children[] = $child_id;
			}
			$container_id = PCC_SP_Instagram::create_carousel_container( $children, $caption );
		}

		if ( is_wp_error( $container_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'ig_status' => 'failed', 'error_message' => $container_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'ig_container_id' => $container_id ) );

		if ( ! $this->wait_for_ig_container( $container_id, $post->id ) ) {
			return false;
		}

		$media_id = PCC_SP_Instagram::publish_container( $container_id );
		if ( is_wp_error( $media_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'ig_status' => 'failed', 'error_message' => $media_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'ig_status' => 'posted', 'ig_media_id' => $media_id ) );
		return true;
	}

	private function wait_for_ig_container( $container_id, $post_id ) {
		$status   = 'IN_PROGRESS';
		$attempts = 0;
		while ( 'FINISHED' !== $status && $attempts < 15 ) {
			$status = PCC_SP_Instagram::get_container_status( $container_id );
			if ( is_wp_error( $status ) ) {
				PCC_SP_DB::update_post( $post_id, array( 'ig_status' => 'failed', 'error_message' => $status->get_error_message() ) );
				return false;
			}
			if ( 'FINISHED' === $status ) {
				return true;
			}
			if ( in_array( $status, array( 'ERROR', 'EXPIRED' ), true ) ) {
				PCC_SP_DB::update_post( $post_id, array( 'ig_status' => 'failed', 'error_message' => 'Instagram container status: ' . $status ) );
				return false;
			}
			$attempts++;
			sleep( 3 );
		}
		return true; // Timed out polling but not marked errored — attempt publish anyway.
	}

	/* --------------------------- Facebook --------------------------- */

	private function publish_facebook( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'fb_status' => 'posting' ) );

		if ( count( $ready_jobs ) === 1 ) {
			$job     = $ready_jobs[0];
			$product = $products[ $job->id ];
			$caption = $this->caption_for( $post, $ready_jobs, $products ); // Same caption logic works for both platforms.
			$post_id = PCC_SP_Facebook::publish_single( $job->image_attachment_id, $caption );
		} else {
			$caption        = $this->caption_for( $post, $ready_jobs, $products );
			$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
			$post_id        = PCC_SP_Facebook::publish_multi( $attachment_ids, $caption );
		}

		if ( is_wp_error( $post_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'fb_status' => 'failed', 'fb_error_message' => $post_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'fb_status' => 'posted', 'fb_post_id' => $post_id ) );
		return true;
	}

	/* --------------------------- TikTok --------------------------- */

	private function publish_tiktok( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'tiktok_status' => 'posting' ) );

		$caption = ( count( $ready_jobs ) === 1 )
			? $this->caption_for( $post, $ready_jobs, $products )
			: $this->caption_for( $post, $ready_jobs, $products );

		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$publish_id      = PCC_SP_TikTok::publish_photos( $attachment_ids, $caption );

		if ( is_wp_error( $publish_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'tiktok_status' => 'failed', 'tiktok_error_message' => $publish_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'tiktok_status' => 'posted', 'tiktok_post_id' => $publish_id ) );
		return true;
	}

	/* --------------------------- Telegram --------------------------- */

	private function publish_telegram( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'telegram_status' => 'posting' ) );

		$caption        = $this->caption_for( $post, $ready_jobs, $products );
		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Telegram::publish( $attachment_ids, $caption );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'telegram_status' => 'failed', 'telegram_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'telegram_status' => 'posted', 'telegram_post_id' => $result ) );
		return true;
	}

	/* ----------------------- Telegram Private ----------------------- */

	// Images only, no text, to a Telegram group.
	private function publish_telegram_private( $post, $ready_jobs ) {
		PCC_SP_DB::update_post( $post->id, array( 'telegram_private_status' => 'posting' ) );

		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Telegram::publish_private( $attachment_ids );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'telegram_private_status' => 'failed', 'telegram_private_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'telegram_private_status' => 'posted', 'telegram_private_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Tumblr --------------------------- */

	private function publish_tumblr( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'tumblr_status' => 'posting' ) );

		$caption        = $this->caption_for( $post, $ready_jobs, $products );
		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Tumblr::publish( $attachment_ids, $caption );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'tumblr_status' => 'failed', 'tumblr_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'tumblr_status' => 'posted', 'tumblr_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Pinterest --------------------------- */

	private function publish_pinterest( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'pinterest_status' => 'posting' ) );

		$caption   = $this->caption_for( $post, $ready_jobs, $products );
		$manual    = ( 'manual' === $post->method );
		$pin_title = '';
		if ( $manual ) {
			// Manual images have no product: title = first line of your text, no link.
			$lines     = preg_split( '/\r\n|\r|\n/', trim( $caption ) );
			$pin_title = isset( $lines[0] ) ? trim( $lines[0] ) : '';
		}

		$items = array();
		foreach ( $ready_jobs as $job ) {
			$link = '';
			if ( ! $manual && $job->product_id ) {
				$permalink = get_permalink( $job->product_id );
				$link      = $permalink ? $permalink : '';
			}
			$items[] = array(
				'attachment_id' => $job->image_attachment_id,
				'title'         => $job->product_title,
				'link'          => $link,
			);
		}

		$result = PCC_SP_Pinterest::publish( $items, $caption, $pin_title );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'pinterest_status' => 'failed', 'pinterest_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'pinterest_status' => 'posted', 'pinterest_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Bluesky --------------------------- */

	private function publish_bluesky( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'bluesky_status' => 'posting' ) );

		$caption        = $this->caption_for( $post, $ready_jobs, $products );
		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Bluesky::publish( $attachment_ids, $caption );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'bluesky_status' => 'failed', 'bluesky_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'bluesky_status' => 'posted', 'bluesky_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Mastodon --------------------------- */

	private function publish_mastodon( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'mastodon_status' => 'posting' ) );

		$caption        = $this->caption_for( $post, $ready_jobs, $products );
		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Mastodon::publish( $attachment_ids, $caption );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'mastodon_status' => 'failed', 'mastodon_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'mastodon_status' => 'posted', 'mastodon_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Stories --------------------------- */

	private function publish_ig_story( $post, $ready_jobs ) {
		PCC_SP_DB::update_post( $post->id, array( 'igstory_status' => 'posting' ) );
		$fail = function ( $msg ) use ( $post ) {
			PCC_SP_DB::update_post( $post->id, array( 'igstory_status' => 'failed', 'igstory_error_message' => $msg ) );
			return false;
		};

		$container_id = PCC_SP_Instagram::create_story_container( $ready_jobs[0]->image_attachment_id );
		if ( is_wp_error( $container_id ) ) {
			return $fail( $container_id->get_error_message() );
		}

		$status   = 'IN_PROGRESS';
		$attempts = 0;
		while ( 'FINISHED' !== $status && $attempts < 15 ) {
			$status = PCC_SP_Instagram::get_container_status( $container_id );
			if ( is_wp_error( $status ) ) {
				return $fail( $status->get_error_message() );
			}
			if ( in_array( $status, array( 'ERROR', 'EXPIRED' ), true ) ) {
				return $fail( 'Instagram story container status: ' . $status );
			}
			if ( 'FINISHED' !== $status ) {
				$attempts++;
				sleep( 3 );
			}
		}

		$media_id = PCC_SP_Instagram::publish_container( $container_id );
		if ( is_wp_error( $media_id ) ) {
			return $fail( $media_id->get_error_message() );
		}
		PCC_SP_DB::update_post( $post->id, array( 'igstory_status' => 'posted', 'igstory_post_id' => $media_id ) );
		return true;
	}

	private function publish_fb_story( $post, $ready_jobs ) {
		PCC_SP_DB::update_post( $post->id, array( 'fbstory_status' => 'posting' ) );
		$result = PCC_SP_Facebook::publish_story( $ready_jobs[0]->image_attachment_id );
		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'fbstory_status' => 'failed', 'fbstory_error_message' => $result->get_error_message() ) );
			return false;
		}
		PCC_SP_DB::update_post( $post->id, array( 'fbstory_status' => 'posted', 'fbstory_post_id' => $result ) );
		return true;
	}

	/* --------------------------- Reddit --------------------------- */

	private function publish_reddit( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'reddit_status' => 'posting' ) );

		$caption        = $this->caption_for( $post, $ready_jobs, $products );
		$attachment_ids = wp_list_pluck( $ready_jobs, 'image_attachment_id' );
		$result         = PCC_SP_Reddit::publish( $attachment_ids, $caption );

		if ( is_wp_error( $result ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'reddit_status' => 'failed', 'reddit_error_message' => $result->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'reddit_status' => 'posted', 'reddit_post_id' => $result ) );
		return true;
	}

	/* --------------------------- LinkedIn --------------------------- */

	private function publish_linkedin( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'linkedin_status' => 'posting' ) );

		// LinkedIn multi-image posts accept 2–20 images; our batches max at 10.
		$urns = array();
		$alts = array();
		foreach ( $ready_jobs as $job ) {
			$urn = PCC_SP_LinkedIn::upload_image( $job->image_attachment_id );
			if ( is_wp_error( $urn ) ) {
				PCC_SP_DB::update_post( $post->id, array(
					'linkedin_status'        => 'failed',
					'linkedin_error_message' => $job->product_title . ': ' . $urn->get_error_message(),
				) );
				return false;
			}
			$urns[] = $urn;
			$alts[] = $job->product_title;
		}

		if ( 1 === count( $ready_jobs ) ) {
			$caption = $this->caption_for( $post, $ready_jobs, $products );
		} else {
			$caption = $this->caption_for( $post, $ready_jobs, $products );
		}
		$text = PCC_SP_LinkedIn::escape_commentary( $caption );

		$post_id = PCC_SP_LinkedIn::create_post( $urns, $text, $alts );
		if ( is_wp_error( $post_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'linkedin_status' => 'failed', 'linkedin_error_message' => $post_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'linkedin_status' => 'posted', 'linkedin_post_id' => $post_id ) );
		return true;
	}

	/* --------------------------- Threads --------------------------- */

	private function publish_threads( $post, $ready_jobs, $products ) {
		PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'posting' ) );

		if ( count( $ready_jobs ) === 1 ) {
			$job     = $ready_jobs[0];
			$text    = self::truncate_for_threads( $this->caption_for( $post, $ready_jobs, $products ) );
			$container_id = PCC_SP_Threads::create_container( $job->image_attachment_id, $text );
		} else {
			$text     = self::truncate_for_threads( $this->caption_for( $post, $ready_jobs, $products ) );
			$children = array();
			foreach ( $ready_jobs as $job ) {
				$child_id = PCC_SP_Threads::create_carousel_child( $job->image_attachment_id );
				if ( is_wp_error( $child_id ) ) {
					PCC_SP_DB::update_post( $post->id, array(
						'threads_status'        => 'failed',
						'threads_error_message' => 'Carousel item failed (' . $job->product_title . '): ' . $child_id->get_error_message(),
					) );
					return false;
				}
				$children[] = $child_id;
			}
			$container_id = PCC_SP_Threads::create_carousel_container( $children, $text );
		}

		if ( is_wp_error( $container_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'failed', 'threads_error_message' => $container_id->get_error_message() ) );
			return false;
		}

		$status   = 'IN_PROGRESS';
		$attempts = 0;
		while ( 'FINISHED' !== $status && $attempts < 15 ) {
			$status = PCC_SP_Threads::get_container_status( $container_id );
			if ( is_wp_error( $status ) ) {
				PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'failed', 'threads_error_message' => $status->get_error_message() ) );
				return false;
			}
			if ( 'FINISHED' === $status ) {
				break;
			}
			if ( in_array( $status, array( 'ERROR', 'EXPIRED' ), true ) ) {
				PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'failed', 'threads_error_message' => 'Threads container status: ' . $status ) );
				return false;
			}
			$attempts++;
			sleep( 3 );
		}

		$media_id = PCC_SP_Threads::publish_container( $container_id );
		if ( is_wp_error( $media_id ) ) {
			PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'failed', 'threads_error_message' => $media_id->get_error_message() ) );
			return false;
		}

		PCC_SP_DB::update_post( $post->id, array( 'threads_status' => 'posted', 'threads_post_id' => $media_id ) );
		return true;
	}

	/**
	 * Threads posts are limited to 500 characters (UTF-8 bytes for emoji,
	 * but plain-text length is a safe-enough proxy here). Trim on a word
	 * boundary rather than cutting mid-word.
	 */
	private static function truncate_for_threads( $text, $limit = 500 ) {
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $limit : strlen( $text ) <= $limit ) {
			return $text;
		}
		$truncated = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit - 1 ) : substr( $text, 0, $limit - 1 );
		$last_space = strrpos( $truncated, ' ' );
		if ( false !== $last_space && $last_space > 0 ) {
			$truncated = substr( $truncated, 0, $last_space );
		}
		return rtrim( $truncated ) . '…';
	}
}
