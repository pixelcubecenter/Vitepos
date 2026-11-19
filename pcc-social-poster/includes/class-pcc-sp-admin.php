<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_Admin {

	private static $instance = null;
	const CAROUSEL_MAX = 10;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_pcc_sp_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_pcc_sp_telegram_test', array( 'PCC_SP_Telegram', 'handle_test' ) );
		add_action( 'admin_post_pcc_sp_bluesky_test', array( 'PCC_SP_Bluesky', 'handle_test' ) );
		add_action( 'admin_post_pcc_sp_mastodon_test', array( 'PCC_SP_Mastodon', 'handle_test' ) );
		add_action( 'admin_post_pcc_sp_pinterest_create_board', array( 'PCC_SP_Pinterest', 'handle_create_board' ) );

		add_action( 'wp_ajax_pcc_sp_get_products', array( $this, 'ajax_get_products' ) );
		add_action( 'wp_ajax_pcc_sp_queue_products', array( $this, 'ajax_queue_products' ) );
		add_action( 'wp_ajax_pcc_sp_queue_manual', array( $this, 'ajax_queue_manual' ) );
		add_action( 'wp_ajax_pcc_sp_queue_status', array( $this, 'ajax_queue_status' ) );
		add_action( 'wp_ajax_pcc_sp_retry_post', array( $this, 'ajax_retry_post' ) );
		add_action( 'wp_ajax_pcc_sp_cancel_post', array( $this, 'ajax_cancel_post' ) );
		add_action( 'wp_ajax_pcc_sp_run_tick_now', array( $this, 'ajax_run_tick_now' ) );
	}

	public function menu() {
		add_menu_page( 'PCC Social Poster', 'Social Poster', 'manage_woocommerce', 'pcc-sp', array( $this, 'render_generate_page' ), 'dashicons-instagram', 56 );
		add_submenu_page( 'pcc-sp', 'Generate & Post', 'Generate & Post', 'manage_woocommerce', 'pcc-sp', array( $this, 'render_generate_page' ) );
		add_submenu_page( 'pcc-sp', 'Post Manually', 'Post Manually', 'manage_woocommerce', 'pcc-sp-manual', array( $this, 'render_manual_page' ) );
		add_submenu_page( 'pcc-sp', 'Queue & History', 'Queue & History', 'manage_woocommerce', 'pcc-sp-queue', array( $this, 'render_queue_page' ) );
		add_submenu_page( 'pcc-sp', 'Settings', 'Settings', 'manage_options', 'pcc-sp-settings', array( $this, 'render_settings_page' ) );
	}

	public function assets( $hook ) {
		if ( strpos( $hook, 'pcc-sp' ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'pcc-sp-admin', PCC_SP_URL . 'assets/admin.css', array(), PCC_SP_VERSION );
		wp_enqueue_script( 'pcc-sp-admin', PCC_SP_URL . 'assets/admin.js', array( 'jquery' ), PCC_SP_VERSION, true );
		wp_localize_script( 'pcc-sp-admin', 'pccSp', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pcc_sp_nonce' ),
			'carouselMax' => self::CAROUSEL_MAX,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Generate & Post page
	 * ------------------------------------------------------------------- */

	public function render_generate_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		?>
		<div class="wrap pcc-sp-wrap">
			<h1>Generate &amp; Post</h1>
			<?php if ( ! PCC_SP_Settings::is_template_configured() ) : ?>
				<div class="notice notice-warning"><p>No template base image is set yet. Add one under <a href="<?php echo esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ); ?>">Settings → Template image</a> before queuing product posts.</p></div>
			<?php endif; ?>
			<?php if ( ! PCC_SP_Settings::is_instagram_configured() && ! PCC_SP_Settings::is_facebook_configured() && ! PCC_SP_Settings::is_tiktok_configured() && ! PCC_SP_Settings::is_threads_configured() && ! PCC_SP_Settings::is_linkedin_configured() && ! PCC_SP_Settings::is_telegram_configured() && ! PCC_SP_Settings::is_telegram_private_configured() && ! PCC_SP_Settings::is_tumblr_configured() && ! PCC_SP_Settings::is_pinterest_configured() && ! PCC_SP_Settings::is_bluesky_configured() && ! PCC_SP_Settings::is_mastodon_configured() && ! PCC_SP_Settings::is_reddit_configured() ) : ?>
				<div class="notice notice-warning"><p>Connect Instagram, Facebook, TikTok, Threads, LinkedIn, Telegram, Tumblr, Pinterest, Bluesky, Mastodon, and/or Reddit on the
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ); ?>">Settings</a> page before queuing posts.</p></div>
			<?php endif; ?>

			<p>Pick a category and which platform(s) to post to, then select products and queue them. Each product image is built from your template. Up to <?php echo intval( self::CAROUSEL_MAX ); ?> selected products at a time are combined into a single post per platform (a carousel on Instagram, a multi-photo post on Facebook); more than that is automatically split into multiple posts.</p>

			<table class="form-table pcc-sp-inline-form">
				<tr>
					<th>Category</th>
					<td>
						<select id="pcc-sp-category">
							<option value="">— Select a category —</option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?> (<?php echo intval( $cat->count ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>Post to</th>
					<td>
						<label><input type="checkbox" id="pcc-sp-platform-ig" value="instagram" <?php echo PCC_SP_Settings::is_instagram_configured() ? 'checked' : 'disabled'; ?> /> Instagram</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-fb" value="facebook" <?php echo PCC_SP_Settings::is_facebook_configured() ? '' : 'disabled'; ?> /> Facebook Page</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-tiktok" value="tiktok" <?php echo PCC_SP_Settings::is_tiktok_configured() ? '' : 'disabled'; ?> /> TikTok</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-threads" value="threads" <?php echo PCC_SP_Settings::is_threads_configured() ? '' : 'disabled'; ?> /> Threads</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-linkedin" value="linkedin" <?php echo PCC_SP_Settings::is_linkedin_configured() ? '' : 'disabled'; ?> /> LinkedIn Page</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-telegram" value="telegram" <?php echo PCC_SP_Settings::is_telegram_configured() ? '' : 'disabled'; ?> /> Telegram</label>
						&nbsp;&nbsp;
						<label title="Images only, no text, to your Telegram group"><input type="checkbox" id="pcc-sp-platform-telegram-private" value="telegram_private" <?php echo PCC_SP_Settings::is_telegram_private_configured() ? '' : 'disabled'; ?> /> Telegram Private</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-tumblr" value="tumblr" <?php echo PCC_SP_Settings::is_tumblr_configured() ? '' : 'disabled'; ?> /> Tumblr</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-pinterest" value="pinterest" <?php echo PCC_SP_Settings::is_pinterest_configured() ? '' : 'disabled'; ?> /> Pinterest</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-bluesky" value="bluesky" <?php echo PCC_SP_Settings::is_bluesky_configured() ? '' : 'disabled'; ?> /> Bluesky</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-mastodon" value="mastodon" <?php echo PCC_SP_Settings::is_mastodon_configured() ? '' : 'disabled'; ?> /> Mastodon</label>
						&nbsp;&nbsp;
						<label><input type="checkbox" id="pcc-sp-platform-reddit" value="reddit" <?php echo PCC_SP_Settings::is_reddit_configured() ? '' : 'disabled'; ?> /> Reddit</label>
						<?php if ( ! PCC_SP_Settings::is_instagram_configured() || ! PCC_SP_Settings::is_facebook_configured() || ! PCC_SP_Settings::is_tiktok_configured() || ! PCC_SP_Settings::is_threads_configured() || ! PCC_SP_Settings::is_linkedin_configured() || ! PCC_SP_Settings::is_telegram_configured() || ! PCC_SP_Settings::is_telegram_private_configured() || ! PCC_SP_Settings::is_tumblr_configured() || ! PCC_SP_Settings::is_pinterest_configured() || ! PCC_SP_Settings::is_bluesky_configured() || ! PCC_SP_Settings::is_mastodon_configured() || ! PCC_SP_Settings::is_reddit_configured() ) : ?>
							<p class="description">Greyed-out platforms aren't connected yet — do that in Settings first. TikTok posts go to your TikTok inbox as drafts by default (Settings → TikTok → Post mode).</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<div id="pcc-sp-products-wrap" style="display:none;">
				<p>
					<button type="button" class="button" id="pcc-sp-select-all">Select all</button>
					<button type="button" class="button" id="pcc-sp-select-none">Select none</button>
					<span id="pcc-sp-select-count"></span>
				</p>
				<table class="widefat striped" id="pcc-sp-products-table">
					<thead>
						<tr>
							<th style="width:30px;"></th>
							<th style="width:60px;">Image</th>
							<th>Product</th>
							<th>Price</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<p>
					<button type="button" class="button button-primary" id="pcc-sp-queue-btn">Queue selected products</button>
					<span id="pcc-sp-queue-result"></span>
				</p>
			</div>

			<div id="pcc-sp-loading" style="display:none;">Loading…</div>

			<?php PCC_SP_Story::render_section(); ?>
		</div>
		<?php
	}

	public function ajax_get_products() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		if ( ! $category_id ) {
			wp_send_json_error( 'missing category' );
		}

		$query = new WP_Query( array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'tax_query'              => array(
				array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $category_id ),
			),
		) );
		update_post_thumbnail_cache( $query );

		// One query for "already queued" instead of one per product.
		$open_ids = array_flip( PCC_SP_DB::open_product_ids( ! empty( $_POST['story'] ) ) );

		$products = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}
			$products[] = array(
				'id'       => $product->get_id(),
				'title'    => $product->get_name(),
				'price'    => $product->get_price() !== '' ? number_format( (float) $product->get_price(), 0 ) : '—',
				'thumb'    => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
				'has_open' => isset( $open_ids[ $product->get_id() ] ),
			);
		}

		wp_send_json_success( $products );
	}

	public function ajax_queue_products() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
		$method      = 'template';
		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();

		$platforms_in = isset( $_POST['platforms'] ) ? (array) $_POST['platforms'] : array( 'instagram' );
		$platforms    = array_values( array_intersect( array_map( 'sanitize_text_field', $platforms_in ), array( 'instagram', 'facebook', 'tiktok', 'threads', 'linkedin', 'telegram', 'telegram_private', 'tumblr', 'pinterest', 'bluesky', 'mastodon', 'reddit' ) ) );
		if ( empty( $platforms ) ) {
			$platforms = array( 'instagram' );
		}
		$platforms_str = implode( ',', $platforms );

		$valid   = array();
		$skipped = 0;
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || PCC_SP_DB::product_has_open_job( $product_id ) ) {
				$skipped++;
				continue;
			}
			$valid[] = $product;
		}

		$posts_created = 0;
		foreach ( array_chunk( $valid, self::CAROUSEL_MAX ) as $chunk ) {
			$post_id = PCC_SP_DB::insert_post( $category_id, $method, count( $chunk ), $platforms_str );
			foreach ( $chunk as $product ) {
				PCC_SP_DB::insert_job( $post_id, $product->get_id(), $product->get_name() );
			}
			$posts_created++;
		}

		if ( $posts_created ) {
			PCC_SP_Queue::kick();
		}

		wp_send_json_success( array( 'queued' => count( $valid ), 'skipped' => $skipped, 'posts' => $posts_created ) );
	}

	/* ---------------------------------------------------------------------
	 * Post Manually page
	 * ------------------------------------------------------------------- */

	public function render_manual_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$platforms = array(
			'instagram' => array( 'Instagram', PCC_SP_Settings::is_instagram_configured() ),
			'facebook'  => array( 'Facebook Page', PCC_SP_Settings::is_facebook_configured() ),
			'tiktok'    => array( 'TikTok', PCC_SP_Settings::is_tiktok_configured() ),
			'threads'   => array( 'Threads', PCC_SP_Settings::is_threads_configured() ),
			'linkedin'  => array( 'LinkedIn Page', PCC_SP_Settings::is_linkedin_configured() ),
			'telegram'  => array( 'Telegram', PCC_SP_Settings::is_telegram_configured() ),
			'telegram_private' => array( 'Telegram Private (images only)', PCC_SP_Settings::is_telegram_private_configured() ),
			'tumblr'    => array( 'Tumblr', PCC_SP_Settings::is_tumblr_configured() ),
			'pinterest' => array( 'Pinterest', PCC_SP_Settings::is_pinterest_configured() ),
			'bluesky'   => array( 'Bluesky', PCC_SP_Settings::is_bluesky_configured() ),
			'mastodon'  => array( 'Mastodon', PCC_SP_Settings::is_mastodon_configured() ),
			'reddit'    => array( 'Reddit', PCC_SP_Settings::is_reddit_configured() ),
		);
		?>
		<div class="wrap pcc-sp-wrap">
			<h1>Post Manually</h1>
			<p>Choose one or more images from your Media Library, write your own text, and pick where to post. Up to <?php echo intval( self::CAROUSEL_MAX ); ?> images go out as one post per platform (a carousel on Instagram, a multi-photo post elsewhere). Images are posted exactly as they are — no logo, price or template is added.</p>

			<table class="form-table pcc-sp-inline-form">
				<tr>
					<th>Images</th>
					<td>
						<button type="button" class="button" id="pcc-sp-manual-pick">Choose images…</button>
						<span id="pcc-sp-manual-count" class="description"></span>
						<div id="pcc-sp-manual-thumbs" class="pcc-sp-manual-thumbs"></div>
						<p class="description">Drag to reorder is not available; the images are posted in the order you pick them. Use ✕ to remove one.</p>
					</td>
				</tr>
				<tr>
					<th><label for="pcc-sp-manual-text">Text</label></th>
					<td>
						<textarea id="pcc-sp-manual-text" rows="8" class="large-text" placeholder="Write your caption here…"></textarea>
						<p class="description"><span id="pcc-sp-manual-chars">0</span> characters. Limits: Threads 500 (trimmed automatically), TikTok title uses the first line (90 max), Instagram 2,200.</p>
					</td>
				</tr>
				<tr>
					<th>Post to</th>
					<td>
						<?php foreach ( $platforms as $key => $info ) : ?>
							<label style="margin-right:16px;"><input type="checkbox" class="pcc-sp-manual-platform" value="<?php echo esc_attr( $key ); ?>" <?php echo $info[1] ? '' : 'disabled'; ?> /> <?php echo esc_html( $info[0] ); ?></label>
						<?php endforeach; ?>
						<p class="description">Greyed-out platforms aren't connected yet — connect them in Settings.</p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="pcc-sp-manual-queue">Queue post</button>
				<span id="pcc-sp-manual-result" style="margin-left:10px;"></span>
			</p>
			<p class="description">Queued posts start within a few minutes, respecting your "minutes between posts" setting. Watch progress on <a href="<?php echo esc_url( admin_url( 'admin.php?page=pcc-sp-queue' ) ); ?>">Queue &amp; History</a>.</p>
		</div>
		<?php
	}

	public function ajax_queue_manual() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Forbidden.' );
		}

		$ids = isset( $_POST['attachment_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['attachment_ids'] ) ) ) ) : array();
		if ( empty( $ids ) ) {
			wp_send_json_error( 'Choose at least one image.' );
		}
		if ( count( $ids ) > self::CAROUSEL_MAX ) {
			wp_send_json_error( 'Choose at most ' . self::CAROUSEL_MAX . ' images per post.' );
		}

		$caption = isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '';
		if ( '' === trim( $caption ) ) {
			wp_send_json_error( 'Write some text for the post.' );
		}

		$platforms_in = isset( $_POST['platforms'] ) ? (array) $_POST['platforms'] : array();
		$platforms    = array_values( array_intersect( array_map( 'sanitize_text_field', $platforms_in ), array( 'instagram', 'facebook', 'tiktok', 'threads', 'linkedin', 'telegram', 'telegram_private', 'tumblr', 'pinterest', 'bluesky', 'mastodon', 'reddit' ) ) );
		if ( empty( $platforms ) ) {
			wp_send_json_error( 'Choose at least one platform.' );
		}

		// Normalize formats Instagram/Facebook/LinkedIn reject (WebP/AVIF).
		$final_ids = array();
		foreach ( $ids as $id ) {
			if ( ! wp_attachment_is_image( $id ) ) {
				wp_send_json_error( 'Attachment ' . $id . ' is not an image.' );
			}
			$ok = PCC_SP_Image_Utils::ensure_jpeg_or_png_attachment( $id );
			if ( is_wp_error( $ok ) ) {
				wp_send_json_error( $ok->get_error_message() );
			}
			$final_ids[] = $ok;
		}

		$post_id = PCC_SP_DB::insert_post( 0, 'manual', count( $final_ids ), implode( ',', $platforms ) );
		PCC_SP_DB::update_post( $post_id, array( 'caption' => $caption ) );

		foreach ( $ids as $i => $orig_id ) {
			$title  = get_the_title( $orig_id );
			$title  = $title ? $title : ( 'Image ' . $orig_id );
			$job_id = PCC_SP_DB::insert_job( $post_id, 0, mb_substr( $title, 0, 200 ) );
			PCC_SP_DB::update_job( $job_id, array( 'status' => 'ready', 'image_attachment_id' => $final_ids[ $i ] ) );
		}

		PCC_SP_Queue::kick();

		wp_send_json_success( array( 'post_id' => $post_id, 'images' => count( $final_ids ) ) );
	}

	public function ajax_run_tick_now() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		// An explicit click skips the wait between posts and the daily cap.
		$message = PCC_SP_Queue::instance()->tick( true );
		wp_send_json_success( array( 'message' => $message ) );
	}

	/* ---------------------------------------------------------------------
	 * Queue & History page
	 * ------------------------------------------------------------------- */

	public function render_queue_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap pcc-sp-wrap">
			<h1>Queue &amp; History</h1>
			<p>
				<button type="button" class="button" id="pcc-sp-refresh-queue">Refresh</button>
				<button type="button" class="button" id="pcc-sp-run-now">Process next post now</button>
				<span id="pcc-sp-run-result" style="margin-left:10px;"></span>
			</p>
			<table class="widefat striped" id="pcc-sp-queue-table">
				<thead>
					<tr>
						<th>ID</th>
						<th>Method</th>
						<th>Products</th>
						<th>Status</th>
						<th>Instagram</th>
						<th>Facebook</th>
						<th>TikTok</th>
						<th>Threads</th>
						<th>LinkedIn</th>
						<th>Telegram</th>
						<th>Telegram Private</th>
						<th>Tumblr</th>
						<th>Pinterest</th>
						<th>Bluesky</th>
						<th>Mastodon</th>
						<th>IG Story</th>
						<th>FB Story</th>
						<th>Reddit</th>
						<th>Updated</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	public function ajax_queue_status() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$posts     = PCC_SP_DB::get_posts( 100 );
		$summaries = PCC_SP_DB::summarize_products_bulk( wp_list_pluck( $posts, 'id' ) );
		$out       = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'              => $post->id,
				'method'          => $post->method,
				'platforms'       => $post->platforms,
				'products'        => isset( $summaries[ $post->id ] ) ? $summaries[ $post->id ] : '',
				'product_count'   => $post->product_count,
				'status'          => $post->status,
				'ig_status'       => $post->ig_status,
				'ig_media_id'     => $post->ig_media_id,
				'error_message'   => $post->error_message,
				'fb_status'       => $post->fb_status,
				'fb_post_id'      => $post->fb_post_id,
				'fb_error_message' => $post->fb_error_message,
				'tiktok_status'       => $post->tiktok_status,
				'tiktok_post_id'      => $post->tiktok_post_id,
				'tiktok_error_message' => $post->tiktok_error_message,
				'threads_status'       => $post->threads_status,
				'threads_post_id'      => $post->threads_post_id,
				'threads_error_message' => $post->threads_error_message,
				'linkedin_status'       => $post->linkedin_status,
				'linkedin_post_id'      => $post->linkedin_post_id,
				'linkedin_error_message' => $post->linkedin_error_message,
				'telegram_status'       => $post->telegram_status,
				'telegram_post_id'      => $post->telegram_post_id,
				'telegram_error_message' => $post->telegram_error_message,
				'telegram_private_status'       => $post->telegram_private_status,
				'telegram_private_post_id'      => $post->telegram_private_post_id,
				'telegram_private_error_message' => $post->telegram_private_error_message,
				'tumblr_status'         => $post->tumblr_status,
				'tumblr_post_id'        => $post->tumblr_post_id,
				'tumblr_error_message'  => $post->tumblr_error_message,
				'pinterest_status'      => $post->pinterest_status,
				'pinterest_post_id'     => $post->pinterest_post_id,
				'pinterest_error_message' => $post->pinterest_error_message,
				'bluesky_status'        => $post->bluesky_status,
				'bluesky_post_id'       => $post->bluesky_post_id,
				'bluesky_error_message' => $post->bluesky_error_message,
				'mastodon_status'       => $post->mastodon_status,
				'mastodon_post_id'      => $post->mastodon_post_id,
				'mastodon_error_message' => $post->mastodon_error_message,
				'igstory_status'        => $post->igstory_status,
				'igstory_post_id'       => $post->igstory_post_id,
				'igstory_error_message' => $post->igstory_error_message,
				'fbstory_status'        => $post->fbstory_status,
				'fbstory_post_id'       => $post->fbstory_post_id,
				'fbstory_error_message' => $post->fbstory_error_message,
				'reddit_status'         => $post->reddit_status,
				'reddit_post_id'        => $post->reddit_post_id,
				'reddit_error_message'  => $post->reddit_error_message,
				'updated_at'      => $post->updated_at,
			);
		}
		wp_send_json_success( $out );
	}

	public function ajax_retry_post() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = PCC_SP_DB::get_post( $id );
		if ( ! $post || ! in_array( $post->status, array( 'failed', 'partial' ), true ) ) {
			wp_send_json_error( 'not retryable' );
		}

		$reset_fields = array( 'status' => 'queued', 'error_message' => null );
		// Only reset the platform(s) that actually failed — a platform that
		// already posted successfully in a "partial" result stays untouched.
		if ( 'failed' === $post->ig_status ) {
			$reset_fields['ig_status'] = null;
		}
		if ( 'failed' === $post->fb_status ) {
			$reset_fields['fb_status']        = null;
			$reset_fields['fb_error_message'] = null;
		}
		if ( 'failed' === $post->tiktok_status ) {
			$reset_fields['tiktok_status']        = null;
			$reset_fields['tiktok_error_message'] = null;
		}
		if ( 'failed' === $post->threads_status ) {
			$reset_fields['threads_status']        = null;
			$reset_fields['threads_error_message'] = null;
		}
		if ( 'failed' === $post->linkedin_status ) {
			$reset_fields['linkedin_status']        = null;
			$reset_fields['linkedin_error_message'] = null;
		}
		if ( 'failed' === $post->telegram_status ) {
			$reset_fields['telegram_status']        = null;
			$reset_fields['telegram_error_message'] = null;
		}
		if ( 'failed' === $post->telegram_private_status ) {
			$reset_fields['telegram_private_status']        = null;
			$reset_fields['telegram_private_error_message'] = null;
		}
		if ( 'failed' === $post->tumblr_status ) {
			$reset_fields['tumblr_status']        = null;
			$reset_fields['tumblr_error_message'] = null;
		}
		if ( 'failed' === $post->pinterest_status ) {
			$reset_fields['pinterest_status']        = null;
			$reset_fields['pinterest_error_message'] = null;
		}
		if ( 'failed' === $post->bluesky_status ) {
			$reset_fields['bluesky_status']        = null;
			$reset_fields['bluesky_error_message'] = null;
		}
		if ( 'failed' === $post->mastodon_status ) {
			$reset_fields['mastodon_status']        = null;
			$reset_fields['mastodon_error_message'] = null;
		}
		if ( 'failed' === $post->igstory_status ) {
			$reset_fields['igstory_status']        = null;
			$reset_fields['igstory_error_message'] = null;
		}
		if ( 'failed' === $post->fbstory_status ) {
			$reset_fields['fbstory_status']        = null;
			$reset_fields['fbstory_error_message'] = null;
		}
		if ( 'failed' === $post->reddit_status ) {
			$reset_fields['reddit_status']        = null;
			$reset_fields['reddit_error_message'] = null;
		}
		PCC_SP_DB::update_post( $id, $reset_fields );

		// Only retry jobs that actually failed — keep already-generated images.
		global $wpdb;
		$wpdb->update( PCC_SP_DB::jobs_table(), array( 'status' => 'queued', 'error_message' => null, 'updated_at' => current_time( 'mysql' ) ), array( 'post_id' => $id, 'status' => 'failed' ) );
		wp_send_json_success();
	}

	public function ajax_cancel_post() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post = PCC_SP_DB::get_post( $id );
		if ( ! $post || ! in_array( $post->status, array( 'queued', 'generating', 'posting', 'failed', 'partial' ), true ) ) {
			wp_send_json_error( 'not cancellable' );
		}
		PCC_SP_DB::update_post( $id, array( 'status' => 'cancelled' ) );
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------- */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = PCC_SP_Settings::get_all();
		$img_box   = PCC_SP_Settings::get_box( 'tpl_image_box' );
		$cat_box   = PCC_SP_Settings::get_box( 'tpl_category_box' );
		$title_box = PCC_SP_Settings::get_box( 'tpl_title_box' );
		$price_box = PCC_SP_Settings::get_box( 'tpl_price_box' );
		?>
		<div class="wrap pcc-sp-wrap">
			<h1>PCC Social Poster — Settings</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pcc_sp_save_settings" />
				<?php wp_nonce_field( 'pcc_sp_save_settings' ); ?>

				<h2>Captions</h2>
				<table class="form-table">
					<tr>
						<th><label for="website_text">Website text</label></th>
						<td><input type="text" name="website_text" id="website_text" class="regular-text" value="<?php echo esc_attr( $s['website_text'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="phone_text">Phone number (optional)</label></th>
						<td><input type="text" name="phone_text" id="phone_text" class="regular-text" value="<?php echo esc_attr( $s['phone_text'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="currency_label">Currency label</label></th>
						<td><input type="text" name="currency_label" id="currency_label" class="regular-text" value="<?php echo esc_attr( $s['currency_label'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="caption_template">Single-product caption template</label></th>
						<td>
							<textarea name="caption_template" id="caption_template" rows="4" class="large-text"><?php echo esc_textarea( $s['caption_template'] ); ?></textarea>
							<p class="description">Used when a post has only 1 product. Placeholders: {title} {price} {currency} {website}. Multi-product (carousel) posts get an auto-built caption listing every product and price instead.</p>
						</td>
					</tr>
				</table>

				<h2>Template image</h2>
				<table class="form-table">
					<tr>
						<th>Template base image</th>
						<td>
							<?php $this->render_media_field( 'template_base', $s['template_base_attachment_id'], 200 ); ?>
							<p class="description">Your branded frame (logo, icons, "48h" badge, price pill, website/phone) with the product/title/category/price areas left blank — this plugin fills those in.</p>
						</td>
					</tr>
					<tr>
						<th>Placeholder positions</th>
						<td>
							<p class="description">Format: x,y,width,height in pixels, measured from the top-left of your template image.</p>
							<p><label>Product image area: <input type="text" name="tpl_image_box" value="<?php echo esc_attr( $s['tpl_image_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Category text area: <input type="text" name="tpl_category_box" value="<?php echo esc_attr( $s['tpl_category_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Title text area: <input type="text" name="tpl_title_box" value="<?php echo esc_attr( $s['tpl_title_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Price text area: <input type="text" name="tpl_price_box" value="<?php echo esc_attr( $s['tpl_price_box'] ); ?>" class="regular-text" /></label></p>
						</td>
					</tr>
					<tr>
						<th>Text colors</th>
						<td>
							<p><label>Category/title color: <input type="color" name="tpl_text_color" value="<?php echo esc_attr( $s['tpl_text_color'] ); ?>" /></label></p>
							<p><label>Price color: <input type="color" name="tpl_price_color" value="<?php echo esc_attr( $s['tpl_price_color'] ); ?>" /></label></p>
						</td>
					</tr>
					<tr>
						<th><label for="tpl_bg_tolerance">Background removal sensitivity</label></th>
						<td>
							<input type="number" min="5" max="100" name="tpl_bg_tolerance" id="tpl_bg_tolerance" value="<?php echo esc_attr( $s['tpl_bg_tolerance'] ); ?>" />
							<p class="description">Higher removes more (risk of eating into light parts of the product); lower is safer but may leave background fringes. Works best on plain/solid-color product photo backgrounds — it's a simple corner-color flood fill.</p>
						</td>
					</tr>
				</table>

				<h2>Stories</h2>
				<p class="description">Instagram Story and Facebook Page Story use their own template (a 9:16 image, 1206 × 2160 works well). Stories are posted one product at a time, without a caption (Instagram's API can't add link stickers).</p>
				<table class="form-table">
					<tr>
						<th>Story template image</th>
						<td>
							<?php $this->render_media_field( 'story_template', $s['story_template_attachment_id'], 260 ); ?>
							<p class="description">Your story frame with the category/title/product/price areas left blank.</p>
						</td>
					</tr>
					<tr>
						<th>Placeholder positions</th>
						<td>
							<p class="description">Format: x,y,width,height in pixels, from the top-left of the story template. The defaults match the 1206 × 2160 template you sent.</p>
							<p><label>Product image area: <input type="text" name="story_image_box" value="<?php echo esc_attr( $s['story_image_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Category text area: <input type="text" name="story_category_box" value="<?php echo esc_attr( $s['story_category_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Title text area: <input type="text" name="story_title_box" value="<?php echo esc_attr( $s['story_title_box'] ); ?>" class="regular-text" /></label></p>
							<p><label>Price text area: <input type="text" name="story_price_box" value="<?php echo esc_attr( $s['story_price_box'] ); ?>" class="regular-text" /></label></p>
						</td>
					</tr>
					<tr>
						<th>Text colors</th>
						<td>
							<p><label>Category/title color: <input type="color" name="story_text_color" value="<?php echo esc_attr( $s['story_text_color'] ); ?>" /></label></p>
							<p><label>Price color: <input type="color" name="story_price_color" value="<?php echo esc_attr( $s['story_price_color'] ); ?>" /></label></p>
						</td>
					</tr>
					<tr>
						<th>Post stories to</th>
						<td>
							<input type="hidden" name="story_platform_instagram" value="0" />
							<label><input type="checkbox" name="story_platform_instagram" value="1" <?php checked( $s['story_platform_instagram'], '1' ); ?> /> Instagram Story</label>
							&nbsp;&nbsp;
							<input type="hidden" name="story_platform_facebook" value="0" />
							<label><input type="checkbox" name="story_platform_facebook" value="1" <?php checked( $s['story_platform_facebook'], '1' ); ?> /> Facebook Page Story</label>
							<p>
								<input type="hidden" name="story_auto_pos" value="0" />
								<label><input type="checkbox" name="story_auto_pos" value="1" <?php checked( $s['story_auto_pos'], '1' ); ?> /> Also auto-post products added from the Vitepos POS</label>
							</p>
							<p class="description">Used by the "Story Post" button on the Products list and by Auto Post on new products. (The Story section on Generate &amp; Post lets you choose per batch.) Product background removal uses the same sensitivity as normal posts.</p>
						</td>
					</tr>
				</table>

				<?php $story_log = PCC_SP_Story::recent_log(); ?>
				<?php if ( $story_log ) : ?>
					<p><strong>Recent products added from the POS</strong> (to check whether auto story saw them):</p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( $story_log as $row ) : ?>
							<li><?php echo esc_html( wp_date( 'j M H:i', (int) $row['t'] ) . ' — product #' . (int) $row['id'] . ' (' . $row['src'] . '): ' . $row['note'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<h2>Instagram</h2>
				<?php if ( isset( $_GET['pcc_sp_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Instagram account connected successfully.</p></div>
				<?php endif; ?>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: Meta app → Instagram API → API setup with Instagram login → 4. Set up Instagram business login → Business login settings → Valid OAuth Redirect URIs.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ig_app_id">Instagram App ID</label></th>
						<td><input type="text" name="ig_app_id" id="ig_app_id" class="regular-text" value="<?php echo esc_attr( $s['ig_app_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ig_app_secret">Instagram App Secret</label></th>
						<td><input type="password" name="ig_app_secret" id="ig_app_secret" class="regular-text" value="<?php echo esc_attr( $s['ig_app_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['ig_access_token'] ) ) : ?>
					<p><strong>Connected.</strong> Instagram User ID: <code><?php echo esc_html( $s['ig_business_account_id'] ); ?></code></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Instagram?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your App ID and App Secret above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_OAuth::connect_url() ); ?>" class="button button-primary">Connect Instagram Account</a></p>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="ig_access_token">Access token (manual override)</label></th>
						<td><input type="password" name="ig_access_token" id="ig_access_token" class="regular-text" value="<?php echo esc_attr( $s['ig_access_token'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="ig_business_account_id">Instagram User ID (manual override)</label></th>
						<td><input type="text" name="ig_business_account_id" id="ig_business_account_id" class="regular-text" value="<?php echo esc_attr( $s['ig_business_account_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="graph_host">API host</label></th>
						<td>
							<select name="graph_host" id="graph_host">
								<option value="graph.instagram.com" <?php selected( $s['graph_host'], 'graph.instagram.com' ); ?>>graph.instagram.com (Instagram Login)</option>
								<option value="graph.facebook.com" <?php selected( $s['graph_host'], 'graph.facebook.com' ); ?>>graph.facebook.com (Facebook Login)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="fb_graph_version">Graph API version</label></th>
						<td><input type="text" name="fb_graph_version" id="fb_graph_version" class="regular-text" value="<?php echo esc_attr( $s['fb_graph_version'] ); ?>" /></td>
					</tr>
				</table>

				<h2>Facebook Page</h2>
				<?php if ( isset( $_GET['pcc_sp_fb_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Facebook Page connected successfully.</p></div>
				<?php endif; ?>
				<?php $fb_pages = get_transient( PCC_SP_FB_OAuth::PAGES_TRANSIENT ); ?>
				<?php if ( isset( $_GET['pcc_sp_fb_choose'] ) && $fb_pages ) : ?>
					<div class="notice notice-info">
						<p>You manage more than one Facebook Page — choose which one to post to:</p>
						<ul>
							<?php foreach ( $fb_pages as $p ) : ?>
								<li>
									<a class="button" href="<?php echo esc_url( PCC_SP_FB_OAuth::select_page_url( $p['id'] ) ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_FB_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: Meta app → Facebook Login for Business → Settings → Valid OAuth Redirect URIs.</p>
						</td>
					</tr>
					<tr>
						<th><label for="fb_app_id">Facebook App ID</label></th>
						<td><input type="text" name="fb_app_id" id="fb_app_id" class="regular-text" value="<?php echo esc_attr( $s['fb_app_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="fb_app_secret">Facebook App Secret</label></th>
						<td><input type="password" name="fb_app_secret" id="fb_app_secret" class="regular-text" value="<?php echo esc_attr( $s['fb_app_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="fb_config_id">Login Configuration ID</label></th>
						<td>
							<input type="text" name="fb_config_id" id="fb_config_id" class="regular-text" value="<?php echo esc_attr( $s['fb_config_id'] ); ?>" />
							<p class="description">Only needed if your app's Facebook Login for Business shows a "Configurations" section — create one there (asset type: Page, User access token, with pages_show_list/pages_manage_posts/pages_read_engagement) and paste its ID here. Leave blank for older apps that don't have Configurations.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['fb_page_access_token'] ) ) : ?>
					<p><strong>Connected to Page:</strong> <?php echo esc_html( $s['fb_page_name'] ); ?> (<code><?php echo esc_html( $s['fb_page_id'] ); ?></code>)</p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_FB_OAuth::connect_url() ); ?>" class="button">Reconnect / choose a different Page</a>
						<a href="<?php echo esc_url( PCC_SP_FB_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Facebook?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your App ID and App Secret above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_FB_OAuth::connect_url() ); ?>" class="button button-primary">Connect Facebook Page</a></p>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="fb_page_id">Page ID (manual override)</label></th>
						<td><input type="text" name="fb_page_id" id="fb_page_id" class="regular-text" value="<?php echo esc_attr( $s['fb_page_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="fb_page_access_token">Page access token (manual override)</label></th>
						<td><input type="password" name="fb_page_access_token" id="fb_page_access_token" class="regular-text" value="<?php echo esc_attr( $s['fb_page_access_token'] ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<h2>TikTok</h2>
				<?php if ( isset( $_GET['pcc_sp_tiktok_connected'] ) ) : ?>
					<div class="notice notice-success"><p>TikTok connected successfully.</p></div>
				<?php endif; ?>
				<div class="notice notice-info"><p>Until your TikTok app passes TikTok's audit, every post it makes is private/self-only — only visible in your own TikTok account, not the public. This is a TikTok requirement, not a plugin limitation.</p></div>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_TikTok_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: TikTok for Developers app → Login Kit → Redirect URI.</p>
						</td>
					</tr>
					<tr>
						<th><label for="tiktok_client_key">Client Key</label></th>
						<td><input type="text" name="tiktok_client_key" id="tiktok_client_key" class="regular-text" value="<?php echo esc_attr( $s['tiktok_client_key'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="tiktok_client_secret">Client Secret</label></th>
						<td><input type="password" name="tiktok_client_secret" id="tiktok_client_secret" class="regular-text" value="<?php echo esc_attr( $s['tiktok_client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="tiktok_post_mode">Post mode</label></th>
						<td>
							<select name="tiktok_post_mode" id="tiktok_post_mode">
								<option value="draft" <?php selected( $s['tiktok_post_mode'], 'draft' ); ?>>Draft — send to my TikTok inbox, I tap Post (no audit needed)</option>
								<option value="direct" <?php selected( $s['tiktok_post_mode'], 'direct' ); ?>>Direct Post — publish automatically (needs TikTok audit, otherwise private-only)</option>
							</select>
							<p class="description">Draft mode: after a queued post runs, open the TikTok app → Inbox → notification "Your content is ready" to review and publish. After switching to Draft, click Reconnect TikTok once so the draft permission is granted.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['tiktok_access_token'] ) ) : ?>
					<p><strong>Connected<?php echo $s['tiktok_username'] ? ' as: ' . esc_html( $s['tiktok_username'] ) : ''; ?></strong></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_TikTok_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_TikTok_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect TikTok?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your Client Key and Client Secret above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_TikTok_OAuth::connect_url() ); ?>" class="button button-primary">Connect TikTok</a></p>
				<?php endif; ?>

				<h2>Threads</h2>
				<?php if ( isset( $_GET['pcc_sp_threads_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Threads connected successfully.</p></div>
				<?php endif; ?>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_Threads_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: Meta app → add the "Access the Threads API" use case → Threads Login settings → Valid OAuth Redirect URIs.</p>
						</td>
					</tr>
					<tr>
						<th><label for="threads_app_id">Threads App ID</label></th>
						<td><input type="text" name="threads_app_id" id="threads_app_id" class="regular-text" value="<?php echo esc_attr( $s['threads_app_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="threads_app_secret">Threads App Secret</label></th>
						<td><input type="password" name="threads_app_secret" id="threads_app_secret" class="regular-text" value="<?php echo esc_attr( $s['threads_app_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['threads_access_token'] ) ) : ?>
					<p><strong>Connected.</strong> Threads User ID: <code><?php echo esc_html( $s['threads_user_id'] ); ?></code></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Threads_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_Threads_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Threads?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your App ID and App Secret above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_Threads_OAuth::connect_url() ); ?>" class="button button-primary">Connect Threads</a></p>
				<?php endif; ?>

				<h2>LinkedIn Company Page</h2>
				<?php if ( isset( $_GET['pcc_sp_linkedin_connected'] ) ) : ?>
					<div class="notice notice-success"><p>LinkedIn connected successfully.</p></div>
				<?php endif; ?>
				<p class="description">Posting to a Company Page needs LinkedIn's <strong>Community Management API</strong> (scope <code>w_organization_social</code>), which LinkedIn must approve for your app. You must also be a Super admin of the Page.</p>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_LinkedIn_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: LinkedIn developer app → Auth tab → Authorized redirect URLs for your app.</p>
						</td>
					</tr>
					<tr>
						<th><label for="linkedin_client_id">Client ID</label></th>
						<td><input type="text" name="linkedin_client_id" id="linkedin_client_id" class="regular-text" value="<?php echo esc_attr( $s['linkedin_client_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="linkedin_client_secret">Client Secret</label></th>
						<td><input type="password" name="linkedin_client_secret" id="linkedin_client_secret" class="regular-text" value="<?php echo esc_attr( $s['linkedin_client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="linkedin_org_id">Company Page ID</label></th>
						<td>
							<input type="text" name="linkedin_org_id" id="linkedin_org_id" class="regular-text" value="<?php echo esc_attr( $s['linkedin_org_id'] ); ?>" />
							<p class="description">The number in your Page's admin URL: linkedin.com/company/<strong>12345678</strong>/admin/</p>
						</td>
					</tr>
					<tr>
						<th><label for="linkedin_api_version">API version</label></th>
						<td>
							<input type="text" name="linkedin_api_version" id="linkedin_api_version" class="small-text" value="<?php echo esc_attr( $s['linkedin_api_version'] ); ?>" />
							<p class="description">YYYYMM format. Leave as-is unless LinkedIn reports the version as expired.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['linkedin_access_token'] ) ) : ?>
					<p><strong>Connected</strong> to Page ID <code><?php echo esc_html( $s['linkedin_org_id'] ); ?></code>.
					<?php if ( ! empty( $s['linkedin_expires_at'] ) ) : ?>
						Token expires <?php echo esc_html( wp_date( 'Y-m-d', (int) $s['linkedin_expires_at'] ) ); ?> — click Reconnect before then.
					<?php endif; ?></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_LinkedIn_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_LinkedIn_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect LinkedIn?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your Client ID, Client Secret and Page ID above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_LinkedIn_OAuth::connect_url() ); ?>" class="button button-primary">Connect LinkedIn</a></p>
				<?php endif; ?>

				<h2>Telegram channel</h2>
				<?php if ( isset( $_GET['pcc_sp_tg_msg'] ) ) : ?>
					<div class="notice <?php echo ( isset( $_GET['pcc_sp_tg_ok'] ) && '1' === $_GET['pcc_sp_tg_ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['pcc_sp_tg_msg'] ) ) ); ?></p></div>
				<?php endif; ?>
				<p class="description">No approval needed. Setup: (1) in Telegram open <strong>@BotFather</strong> → <code>/newbot</code> → copy the token. (2) Open your channel → Administrators → Add Administrator → pick your bot → allow <strong>Post Messages</strong>. (3) Enter the token and the channel below, Save, then click Test connection. Images are uploaded straight to Telegram (it does not download them from your website). Telegram captions hold 1,024 characters — longer text is sent as a text message right after the photos.</p>
				<table class="form-table">
					<tr>
						<th><label for="telegram_bot_token">Bot token</label></th>
						<td>
							<input type="password" name="telegram_bot_token" id="telegram_bot_token" class="regular-text" value="<?php echo esc_attr( $s['telegram_bot_token'] ); ?>" autocomplete="off" />
							<p class="description">Looks like <code>123456789:AAH…</code>. Anyone with this token can post as your bot — keep it private.</p>
						</td>
					</tr>
					<tr>
						<th><label for="telegram_chat_id">Channel</label></th>
						<td>
							<input type="text" name="telegram_chat_id" id="telegram_chat_id" class="regular-text" value="<?php echo esc_attr( $s['telegram_chat_id'] ); ?>" placeholder="@yourchannel or -1001234567890" />
							<p class="description">Public channel: its username with the @ (e.g. <code>@pixelcubecenter</code>). Private channel: the numeric ID that starts with <code>-100</code> — post anything in the channel, then open <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> and read <code>"chat":{"id":…}</code>.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<?php if ( PCC_SP_Settings::is_telegram_configured() ) : ?>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Telegram::test_url() ); ?>" class="button button-primary">Test Telegram connection</a>
						<?php if ( ! empty( $s['telegram_chat_title'] ) ) : ?>
							<span class="description">Last checked channel: <strong><?php echo esc_html( $s['telegram_chat_title'] ); ?></strong></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<h3>Telegram Private (group, images only)</h3>
				<p class="description">Posts the same images with <strong>no text</strong> to a Telegram group. Add your bot to the group as a member (group info → Add members), then enter the group ID below. By default it uses the same bot as the channel above; fill the second token only if you want a different bot. Group IDs are negative numbers (<code>-100…</code> for supergroups): add the bot, send a message in the group, then open <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> and read <code>"chat":{"id":…}</code>. If the bot cannot see group messages, turn off its privacy mode in @BotFather (<code>/setprivacy</code> → Disable) or just make it a group admin.</p>
				<table class="form-table">
					<tr>
						<th><label for="telegram_private_chat_id">Group ID</label></th>
						<td><input type="text" name="telegram_private_chat_id" id="telegram_private_chat_id" class="regular-text" value="<?php echo esc_attr( $s['telegram_private_chat_id'] ); ?>" placeholder="-1001234567890" /></td>
					</tr>
					<tr>
						<th><label for="telegram_private_bot_token">Bot token (optional)</label></th>
						<td>
							<input type="password" name="telegram_private_bot_token" id="telegram_private_bot_token" class="regular-text" value="<?php echo esc_attr( $s['telegram_private_bot_token'] ); ?>" autocomplete="off" />
							<p class="description">Leave empty to use the channel's bot.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<?php if ( PCC_SP_Settings::is_telegram_private_configured() ) : ?>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Telegram::test_url( 'private' ) ); ?>" class="button button-primary">Test Telegram Private connection</a>
						<?php if ( ! empty( $s['telegram_private_chat_title'] ) ) : ?>
							<span class="description">Last checked group: <strong><?php echo esc_html( $s['telegram_private_chat_title'] ); ?></strong></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<h2>Tumblr</h2>
				<?php if ( isset( $_GET['pcc_sp_tumblr_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Tumblr connected successfully.</p></div>
				<?php endif; ?>
				<p class="description">No approval needed. Setup: (1) go to <strong>tumblr.com/oauth/apps</strong> → Register application (any name, your website address, a short description; use the address below as both the Default callback URL and the OAuth2 redirect URL). (2) Copy the <strong>OAuth Consumer Key</strong> and click "Show secret key" to copy the <strong>Secret Key</strong> into the fields below. (3) Save, then click Connect Tumblr and allow access. Each post holds up to 10 photos plus the caption.</p>
				<table class="form-table">
					<tr>
						<th>Redirect URL to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_Tumblr_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
						</td>
					</tr>
					<tr>
						<th><label for="tumblr_client_id">OAuth Consumer Key</label></th>
						<td><input type="text" name="tumblr_client_id" id="tumblr_client_id" class="regular-text" value="<?php echo esc_attr( $s['tumblr_client_id'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="tumblr_client_secret">Secret Key</label></th>
						<td><input type="password" name="tumblr_client_secret" id="tumblr_client_secret" class="regular-text" value="<?php echo esc_attr( $s['tumblr_client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="tumblr_blog">Blog name</label></th>
						<td>
							<input type="text" name="tumblr_blog" id="tumblr_blog" class="regular-text" value="<?php echo esc_attr( $s['tumblr_blog'] ); ?>" placeholder="yourblog (or yourblog.tumblr.com)" />
							<p class="description">Leave empty and it is filled in automatically with your primary blog when you connect.</p>
						</td>
					</tr>
					<tr>
						<th><label for="tumblr_state">Post as</label></th>
						<td>
							<select name="tumblr_state" id="tumblr_state">
								<option value="published" <?php selected( $s['tumblr_state'], 'published' ); ?>>Published (public)</option>
								<option value="draft" <?php selected( $s['tumblr_state'], 'draft' ); ?>>Draft (review in Tumblr first)</option>
								<option value="private" <?php selected( $s['tumblr_state'], 'private' ); ?>>Private (only you)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="tumblr_tags">Tags (optional)</label></th>
						<td>
							<input type="text" name="tumblr_tags" id="tumblr_tags" class="regular-text" value="<?php echo esc_attr( $s['tumblr_tags'] ); ?>" placeholder="pc parts, gaming, pixelcubecenter" />
							<p class="description">Comma-separated, added to every Tumblr post.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['tumblr_refresh_token'] ) || ! empty( $s['tumblr_access_token'] ) ) : ?>
					<p><strong>Connected.</strong> Blog: <code><?php echo esc_html( $s['tumblr_blog'] ? $s['tumblr_blog'] : '(enter the blog name above)' ); ?></code></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Tumblr_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_Tumblr_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Tumblr?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your Consumer Key and Secret Key above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_Tumblr_OAuth::connect_url() ); ?>" class="button button-primary">Connect Tumblr</a></p>
				<?php endif; ?>

				<h2>Pinterest</h2>
				<?php if ( isset( $_GET['pcc_sp_pin_msg'] ) ) : ?>
					<div class="notice <?php echo ( isset( $_GET['pcc_sp_pin_ok'] ) && '1' === $_GET['pcc_sp_pin_ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['pcc_sp_pin_msg'] ) ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( isset( $_GET['pcc_sp_pinterest_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Pinterest connected successfully. Now choose a board below and save.</p></div>
				<?php endif; ?>
				<p class="description">Setup: (1) go to <strong>developers.pinterest.com</strong> → My apps → Connect app (describe your store; wait for Pinterest to approve it). (2) In the app's settings add the redirect URI below. (3) Copy the <strong>App ID</strong> and <strong>App secret key</strong> into the fields, Save, click Connect Pinterest, then pick a board and Save again. <strong>Important:</strong> a new Pinterest app starts on <em>Trial</em> access, where Pins you create through the API can only be seen by you. For public Pins, click Upgrade on your app in the Pinterest developer portal and send the demo video Pinterest asks for (a short screen recording of connecting this plugin and creating a Pin). Pinterest carousels hold 5 images, so a batch of up to 10 products becomes up to 2 Pins; every Pin links to the product page.</p>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td><input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_Pinterest_OAuth::redirect_uri() ); ?>" onclick="this.select();" /></td>
					</tr>
					<tr>
						<th><label for="pinterest_client_id">App ID</label></th>
						<td><input type="text" name="pinterest_client_id" id="pinterest_client_id" class="regular-text" value="<?php echo esc_attr( $s['pinterest_client_id'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="pinterest_client_secret">App secret key</label></th>
						<td><input type="password" name="pinterest_client_secret" id="pinterest_client_secret" class="regular-text" value="<?php echo esc_attr( $s['pinterest_client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="pinterest_environment">Mode</label></th>
						<td>
							<select name="pinterest_environment" id="pinterest_environment">
								<option value="sandbox" <?php selected( $s['pinterest_environment'], 'sandbox' ); ?>>Sandbox — for apps with Trial access</option>
								<option value="production" <?php selected( $s['pinterest_environment'], 'production' ); ?>>Production — only after Pinterest grants Standard access</option>
							</select>
							<p class="description">Trial apps may only use the Sandbox: Pins and boards made there are test data that you can see through the API but that are <strong>not shown publicly on Pinterest</strong>. After Pinterest approves Standard access, switch to Production, then choose a real board and Save. Changing the mode changes which boards exist, so re-select the board afterwards.</p>
						</td>
					</tr>
					<tr>
						<th><label for="pinterest_sandbox_token">Sandbox access token</label></th>
						<td>
							<input type="password" name="pinterest_sandbox_token" id="pinterest_sandbox_token" class="regular-text" value="<?php echo esc_attr( $s['pinterest_sandbox_token'] ); ?>" autocomplete="off" />
							<p class="description">Needed only if the sandbox says "Authentication failed". In the Pinterest developer portal open your app → <strong>Generate access token</strong> (tick <code>boards:read</code>, <code>boards:write</code>, <code>pins:read</code>, <code>pins:write</code>) → copy it here and Save. Used only in Sandbox mode; leave empty after you switch to Production.</p>
						</td>
					</tr>
					<tr>
						<th><label for="pinterest_board_id">Board</label></th>
						<td>
							<?php
							$pin_connected = ! empty( $s['pinterest_refresh_token'] ) || ! empty( $s['pinterest_access_token'] ) || ( 'production' !== $s['pinterest_environment'] && ! empty( $s['pinterest_sandbox_token'] ) );
							$pin_boards    = $pin_connected ? PCC_SP_Pinterest::boards() : array();
							if ( $pin_connected && is_wp_error( $pin_boards ) ) :
								?>
								<p class="description" style="color:#b32d2e;"><?php echo esc_html( $pin_boards->get_error_message() ); ?></p>
								<input type="text" name="pinterest_board_id" id="pinterest_board_id" class="regular-text" value="<?php echo esc_attr( $s['pinterest_board_id'] ); ?>" placeholder="board ID (numbers)" />
							<?php elseif ( $pin_connected && ! empty( $pin_boards ) ) : ?>
								<select name="pinterest_board_id" id="pinterest_board_id">
									<option value="">— Choose a board —</option>
									<?php foreach ( $pin_boards as $bid => $bname ) : ?>
										<option value="<?php echo esc_attr( $bid ); ?>" <?php selected( $s['pinterest_board_id'], (string) $bid ); ?>><?php echo esc_html( $bname ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Pins are posted to this board. Public boards only show Pins publicly after Standard access.</p>
							<?php elseif ( $pin_connected ) : ?>
								<p class="description">Connected, but no boards were found. Create a board on Pinterest first, then reload this page.</p>
								<input type="hidden" name="pinterest_board_id" value="<?php echo esc_attr( $s['pinterest_board_id'] ); ?>" />
							<?php else : ?>
								<p class="description">Connect Pinterest first (below); the list of your boards appears here afterwards.</p>
								<input type="hidden" name="pinterest_board_id" value="<?php echo esc_attr( $s['pinterest_board_id'] ); ?>" />
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['pinterest_refresh_token'] ) || ! empty( $s['pinterest_access_token'] ) || ( 'production' !== $s['pinterest_environment'] && ! empty( $s['pinterest_sandbox_token'] ) ) ) : ?>
					<p><strong>Connected.</strong></p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Pinterest::create_board_url() ); ?>" class="button button-primary">Create <?php echo 'production' === $s['pinterest_environment'] ? '' : 'sandbox '; ?>board "PCC Store Products"</a>
						<a href="<?php echo esc_url( PCC_SP_Pinterest_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_Pinterest_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Pinterest?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your App ID and App secret key above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_Pinterest_OAuth::connect_url() ); ?>" class="button button-primary">Connect Pinterest</a></p>
				<?php endif; ?>

				<h2>Bluesky</h2>
				<?php if ( isset( $_GET['pcc_sp_bsky_msg'] ) ) : ?>
					<div class="notice <?php echo ( isset( $_GET['pcc_sp_bsky_ok'] ) && '1' === $_GET['pcc_sp_bsky_ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['pcc_sp_bsky_msg'] ) ) ); ?></p></div>
				<?php endif; ?>
				<p class="description">No approval needed. In the Bluesky app go to <strong>Settings → Privacy and security → App passwords → Add App Password</strong>, name it (e.g. "store poster"), and copy the password (it looks like <code>xxxx-xxxx-xxxx-xxxx</code>). Do <strong>not</strong> use your normal password. Bluesky allows 4 images per post and 300 characters of text, so a batch of up to 10 products becomes a thread of up to 3 posts (caption on the first, images only on the replies) and long captions are shortened for Bluesky only.</p>
				<table class="form-table">
					<tr>
						<th><label for="bluesky_identifier">Handle</label></th>
						<td>
							<input type="text" name="bluesky_identifier" id="bluesky_identifier" class="regular-text" value="<?php echo esc_attr( $s['bluesky_identifier'] ); ?>" placeholder="yourname.bsky.social" autocomplete="off" />
							<p class="description">Your Bluesky handle, without the @.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bluesky_app_password">App Password</label></th>
						<td><input type="password" name="bluesky_app_password" id="bluesky_app_password" class="regular-text" value="<?php echo esc_attr( $s['bluesky_app_password'] ); ?>" autocomplete="off" /></td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<?php if ( PCC_SP_Settings::is_bluesky_configured() ) : ?>
					<p><a href="<?php echo esc_url( PCC_SP_Bluesky::test_url() ); ?>" class="button button-primary">Test Bluesky login</a></p>
				<?php endif; ?>

				<h2>Mastodon</h2>
				<?php if ( isset( $_GET['pcc_sp_masto_msg'] ) ) : ?>
					<div class="notice <?php echo ( isset( $_GET['pcc_sp_masto_ok'] ) && '1' === $_GET['pcc_sp_masto_ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['pcc_sp_masto_msg'] ) ) ); ?></p></div>
				<?php endif; ?>
				<p class="description">No approval needed. On your Mastodon server open <strong>Preferences → Development → New application</strong>, name it (e.g. "store poster"), tick only <code>write:media</code> and <code>write:statuses</code> (and <code>read:accounts</code> so the test button works), Submit, open the application and copy <strong>Your access token</strong>. Mastodon usually allows 4 images and 500 characters per post (the plugin reads your server's real limits), so a batch of up to 10 products becomes a thread: caption on the first post, images only on the replies.</p>
				<table class="form-table">
					<tr>
						<th><label for="mastodon_instance">Server</label></th>
						<td>
							<input type="text" name="mastodon_instance" id="mastodon_instance" class="regular-text" value="<?php echo esc_attr( $s['mastodon_instance'] ); ?>" placeholder="mastodon.social" autocomplete="off" />
							<p class="description">The address of the server where your account lives, e.g. <code>mastodon.social</code> (no @, no username).</p>
						</td>
					</tr>
					<tr>
						<th><label for="mastodon_access_token">Access token</label></th>
						<td><input type="password" name="mastodon_access_token" id="mastodon_access_token" class="regular-text" value="<?php echo esc_attr( $s['mastodon_access_token'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="mastodon_visibility">Visibility</label></th>
						<td>
							<select name="mastodon_visibility" id="mastodon_visibility">
								<option value="public" <?php selected( $s['mastodon_visibility'], 'public' ); ?>>Public</option>
								<option value="unlisted" <?php selected( $s['mastodon_visibility'], 'unlisted' ); ?>>Unlisted (not on public timelines)</option>
								<option value="private" <?php selected( $s['mastodon_visibility'], 'private' ); ?>>Followers only</option>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<?php if ( PCC_SP_Settings::is_mastodon_configured() ) : ?>
					<p><a href="<?php echo esc_url( PCC_SP_Mastodon::test_url() ); ?>" class="button button-primary">Test Mastodon login</a></p>
				<?php endif; ?>

				<h2>Reddit</h2>
				<?php if ( isset( $_GET['pcc_sp_reddit_connected'] ) ) : ?>
					<div class="notice notice-success"><p>Reddit connected successfully.</p></div>
				<?php endif; ?>
				<p class="description">Reddit requires you to request and receive approval for API access before this works. Image posts need a title; the plugin uses the first line of your text as the title and adds the full text as the first comment. Avoid posting store promotions to subreddits that ban self-promotion — your own profile (leave Subreddit empty) or your own subreddit is safest.</p>
				<table class="form-table">
					<tr>
						<th>Redirect URI to register</th>
						<td>
							<input type="text" readonly class="regular-text" style="width:420px;" value="<?php echo esc_attr( PCC_SP_Reddit_OAuth::redirect_uri() ); ?>" onclick="this.select();" />
							<p class="description">Paste into: reddit.com/prefs/apps → your app → redirect uri (app type: "web app").</p>
						</td>
					</tr>
					<tr>
						<th><label for="reddit_client_id">Client ID</label></th>
						<td>
							<input type="text" name="reddit_client_id" id="reddit_client_id" class="regular-text" value="<?php echo esc_attr( $s['reddit_client_id'] ); ?>" />
							<p class="description">The short code shown under your app's name.</p>
						</td>
					</tr>
					<tr>
						<th><label for="reddit_client_secret">Client Secret</label></th>
						<td><input type="password" name="reddit_client_secret" id="reddit_client_secret" class="regular-text" value="<?php echo esc_attr( $s['reddit_client_secret'] ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><label for="reddit_subreddit">Subreddit</label></th>
						<td>
							<input type="text" name="reddit_subreddit" id="reddit_subreddit" class="regular-text" value="<?php echo esc_attr( $s['reddit_subreddit'] ); ?>" placeholder="leave empty to post to your own profile" />
							<p class="description">Name without "r/" (e.g. <code>pixelcubecenter</code>). Empty = your own profile page (u/<?php echo esc_html( $s['reddit_username'] ? $s['reddit_username'] : 'you' ); ?>). Some subreddits require a flair and will reject posts without one.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>

				<h3>Connection</h3>
				<?php if ( ! empty( $s['reddit_refresh_token'] ) ) : ?>
					<p><strong>Connected</strong> as u/<?php echo esc_html( $s['reddit_username'] ); ?>.</p>
					<p>
						<a href="<?php echo esc_url( PCC_SP_Reddit_OAuth::connect_url() ); ?>" class="button">Reconnect</a>
						<a href="<?php echo esc_url( PCC_SP_Reddit_OAuth::disconnect_url() ); ?>" class="button" onclick="return confirm('Disconnect Reddit?');">Disconnect</a>
					</p>
				<?php else : ?>
					<p>Not connected yet. Save your Client ID and Secret above first, then click connect.</p>
					<p><a href="<?php echo esc_url( PCC_SP_Reddit_OAuth::connect_url() ); ?>" class="button button-primary">Connect Reddit</a></p>
				<?php endif; ?>

				<h2>Posting pace</h2>
				<table class="form-table">
					<tr>
						<th><label for="post_interval_minutes">Minutes between posts</label></th>
						<td><input type="number" min="1" name="post_interval_minutes" id="post_interval_minutes" value="<?php echo esc_attr( $s['post_interval_minutes'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="max_posts_per_day">Max posts per day</label></th>
						<td><input type="number" min="1" name="max_posts_per_day" id="max_posts_per_day" value="<?php echo esc_attr( $s['max_posts_per_day'] ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_media_field( $prefix, $attachment_id, $max_height ) {
		$url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		?>
		<div class="pcc-sp-media-field">
			<img id="<?php echo esc_attr( $prefix ); ?>_preview" src="<?php echo esc_url( $url ); ?>" style="max-height:<?php echo intval( $max_height ); ?>px;<?php echo $url ? '' : 'display:none;'; ?>" />
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>_attachment_id" id="<?php echo esc_attr( $prefix ); ?>_attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>" />
			<p>
				<button type="button" class="button" id="<?php echo esc_attr( $prefix ); ?>_upload_btn">Choose image</button>
				<button type="button" class="button" id="<?php echo esc_attr( $prefix ); ?>_remove_btn" <?php echo $url ? '' : 'style="display:none;"'; ?>>Remove</button>
			</p>
		</div>
		<?php
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pcc_sp_save_settings' );
		PCC_SP_Settings::save( $_POST );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=pcc-sp-settings' ) ) );
		exit;
	}
}
