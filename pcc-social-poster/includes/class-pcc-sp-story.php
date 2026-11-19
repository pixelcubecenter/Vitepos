<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram + Facebook Page Stories.
 *
 * - Story image: the same no-AI renderer, with its own 9:16 template.
 * - "Story Post" column on the Products list (one-click button).
 * - "Story Post" box on the Add/Edit product screen with an "Auto Post"
 *   checkbox (on by default for new products). Auto posting only happens when
 *   the product is published from that screen by a person: products created by
 *   importer plugins never submit the box, so they are never auto-posted.
 * - Stories are processed ahead of normal posts and do not count toward the
 *   "minutes between posts" gap or the daily limit.
 */
class PCC_SP_Story {

	const META_AUTO = '_pcc_sp_story_auto';
	const META_DONE = '_pcc_sp_story_done';
	const META_LAST = '_pcc_sp_story_last';
	const LOG       = 'pcc_sp_story_log';

	/** @var int[] Products created/updated by the POS in this request. */
	private static $pos_ids = array();

	/** platform key => array( column prefix, label ) */
	public static function platform_map() {
		return array(
			'instagram_story' => array( 'igstory', 'Instagram' ),
			'facebook_story'  => array( 'fbstory', 'Facebook' ),
		);
	}

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_pos_product' ), 20 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_pos_product' ), 20 );

		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_box' ), 99, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_pcc_sp_story_now', array( __CLASS__, 'ajax_story_now' ) );
		add_action( 'wp_ajax_pcc_sp_queue_stories', array( __CLASS__, 'ajax_queue_stories' ) );
	}

	/* ------------------------------------------------------------------
	 * Platforms / readiness
	 * ---------------------------------------------------------------- */

	/** Story platforms switched on in Settings AND connected. */
	public static function platforms() {
		$s = PCC_SP_Settings::get_all();
		$p = array();
		if ( ! empty( $s['story_platform_instagram'] ) && PCC_SP_Settings::is_instagram_configured() ) {
			$p[] = 'instagram_story';
		}
		if ( ! empty( $s['story_platform_facebook'] ) && PCC_SP_Settings::is_facebook_configured() ) {
			$p[] = 'facebook_story';
		}
		return $p;
	}

	public static function is_template_ready() {
		return ! empty( PCC_SP_Settings::get( 'story_template_attachment_id' ) );
	}

	/* ------------------------------------------------------------------
	 * Queueing
	 * ---------------------------------------------------------------- */

	/**
	 * @param int        $product_id
	 * @param array|null $platforms story platform keys (null = Settings defaults)
	 * @return int|WP_Error Queue post id.
	 */
	public static function queue_product( $product_id, $platforms = null ) {
		if ( ! self::is_template_ready() ) {
			return new WP_Error( 'pcc_sp_no_story_template', 'No story template is set (Settings → Stories).' );
		}
		if ( null === $platforms ) {
			$platforms = self::platforms();
		}
		$platforms = array_values( array_intersect( (array) $platforms, array_keys( self::platform_map() ) ) );
		if ( empty( $platforms ) ) {
			return new WP_Error( 'pcc_sp_no_story_platform', 'Instagram/Facebook is not connected, or story posting is switched off in Settings → Stories.' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'pcc_sp_no_product', 'Product not found.' );
		}
		if ( ! $product->get_image_id() ) {
			return new WP_Error( 'pcc_sp_no_product_image', 'This product has no featured image.' );
		}
		if ( PCC_SP_DB::product_has_open_job( $product_id, true ) ) {
			return new WP_Error( 'pcc_sp_story_open', 'A story for this product is already queued.' );
		}
		$post_id = PCC_SP_DB::insert_post( 0, 'story', 1, implode( ',', $platforms ) );
		PCC_SP_DB::insert_job( $post_id, $product->get_id(), $product->get_name() );
		return $post_id;
	}

	/**
	 * Queue and run right now (used by the product-list button).
	 *
	 * @return array( ok => bool, message => string )
	 */
	public static function post_now( $product_id ) {
		$post_id = self::queue_product( $product_id );
		if ( is_wp_error( $post_id ) ) {
			return array( 'ok' => false, 'message' => $post_id->get_error_message() );
		}
		// Mark it taken before running so the 5-minute check can't grab it too.
		PCC_SP_DB::update_post( $post_id, array( 'status' => 'generating' ) );
		PCC_SP_Queue::instance()->process_post( PCC_SP_DB::get_post( $post_id ) );
		update_post_meta( $product_id, self::META_DONE, '1' );

		$last = get_post_meta( $product_id, self::META_LAST, true );
		if ( is_array( $last ) ) {
			return array( 'ok' => ! empty( $last['ok'] ), 'message' => $last['msg'] );
		}
		return array( 'ok' => false, 'message' => 'Finished, but no result was recorded. See Queue & History.' );
	}

	/** Called by the queue after a story post was processed. */
	public static function record_result( $post_id ) {
		$post = PCC_SP_DB::get_post( $post_id );
		if ( ! $post || 'story' !== $post->method ) {
			return;
		}
		$jobs = PCC_SP_DB::get_jobs_for_post( $post_id );
		if ( empty( $jobs ) || empty( $jobs[0]->product_id ) ) {
			return;
		}
		$product_id = (int) $jobs[0]->product_id;
		$wanted     = array_filter( array_map( 'trim', explode( ',', $post->platforms ) ) );
		$ok         = true;
		$parts      = array();

		foreach ( self::platform_map() as $key => $info ) {
			if ( ! in_array( $key, $wanted, true ) ) {
				continue;
			}
			$status = $post->{$info[0] . '_status'};
			$error  = $post->{$info[0] . '_error_message'};
			if ( 'posted' === $status ) {
				$parts[] = $info[1] . ' story posted';
			} else {
				$ok      = false;
				$why     = $error ? $error : ( $jobs[0]->error_message ? $jobs[0]->error_message : ( $post->error_message ? $post->error_message : 'not posted' ) );
				$parts[] = $info[1] . ': ' . $why;
			}
		}
		update_post_meta( $product_id, self::META_LAST, array( 't' => time(), 'ok' => $ok, 'msg' => implode( ' · ', $parts ) ) );
	}

	/* ------------------------------------------------------------------
	 * Auto post
	 * ---------------------------------------------------------------- */

	/** @return string What happened (shown in the Settings log). */
	private static function maybe_auto_queue( $product_id ) {
		if ( '1' === get_post_meta( $product_id, self::META_DONE, true ) ) {
			return 'already handled';
		}
		if ( 'publish' !== get_post_status( $product_id ) ) {
			return 'not published yet';
		}
		if ( ! self::is_template_ready() || empty( self::platforms() ) ) {
			return 'skipped: no story template or Instagram/Facebook not connected';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->get_image_id() ) {
			return 'skipped: no featured image yet'; // Not done: posts once it has an image and is saved again.
		}
		$post_id = self::queue_product( $product_id );
		if ( is_wp_error( $post_id ) ) {
			return 'skipped: ' . $post_id->get_error_message();
		}
		update_post_meta( $product_id, self::META_DONE, '1' );
		PCC_SP_Queue::kick();
		return 'story queued';
	}

	/* ------------------------------------------------------------------
	 * Products added from the Vitepos POS (REST/ajax, not the product screen)
	 * ---------------------------------------------------------------- */

	private static function is_pos_request() {
		$bits = array(
			isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
			isset( $_REQUEST['rest_route'] ) ? wp_unslash( $_REQUEST['rest_route'] ) : '',
			isset( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : '',
		);
		foreach ( $bits as $bit ) {
			if ( is_string( $bit ) && false !== stripos( $bit, 'vitepos' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function log( $product_id, $source, $note ) {
		$log = get_option( self::LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array( 't' => time(), 'id' => (int) $product_id, 'src' => $source, 'note' => $note ) );
		update_option( self::LOG, array_slice( $log, 0, 8 ), false );
	}

	public static function on_pos_product( $product_id ) {
		if ( ! get_current_user_id() ) {
			return; // Cron / background importers: never auto-posted.
		}
		$api = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax();
		if ( ! $api ) {
			return; // Normal product screen: handled by the Story Post box.
		}
		if ( ! self::is_pos_request() ) {
			return;
		}
		if ( '1' !== PCC_SP_Settings::get( 'story_auto_pos' ) ) {
			return;
		}
		// Only fresh products: editing an old product in the POS must not post a story.
		$created = get_post_time( 'U', true, $product_id );
		if ( $created && ( time() - (int) $created ) > 30 * MINUTE_IN_SECONDS ) {
			return;
		}
		if ( ! self::$pos_ids ) {
			// Wait until the end of the request so the POS has finished saving the image/price.
			add_action( 'shutdown', array( __CLASS__, 'run_pos_queue' ) );
		}
		self::$pos_ids[ (int) $product_id ] = true;
	}

	public static function run_pos_queue() {
		foreach ( array_keys( self::$pos_ids ) as $product_id ) {
			$note = self::maybe_auto_queue( $product_id );
			if ( 'already handled' !== $note ) {
				self::log( $product_id, 'POS', $note );
			}
		}
		self::$pos_ids = array();
	}

	/** For the Settings page. */
	public static function recent_log() {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Scheduled products that go live later (only when the box was ticked). */
	public static function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new || 'future' !== $old || ! $post || 'product' !== $post->post_type ) {
			return;
		}
		if ( '1' === get_post_meta( $post->ID, self::META_AUTO, true ) ) {
			self::maybe_auto_queue( $post->ID );
		}
	}

	public static function save_box( $post_id, $post ) {
		if ( ! isset( $_POST['pcc_sp_story_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcc_sp_story_nonce'] ) ), 'pcc_sp_story_box' ) ) {
			return; // Not submitted from the product screen (importers, Quick Edit, REST…).
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$on = ! empty( $_POST['pcc_sp_story_auto'] ) ? '1' : '0';
		update_post_meta( $post_id, self::META_AUTO, $on );
		if ( '1' === $on && 'publish' === $post->post_status ) {
			self::maybe_auto_queue( $post_id );
		}
	}

	/* ------------------------------------------------------------------
	 * Product screens
	 * ---------------------------------------------------------------- */

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'product' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'pcc-sp-story', PCC_SP_URL . 'assets/story.js', array( 'jquery' ), PCC_SP_VERSION, true );
		wp_localize_script( 'pcc-sp-story', 'pccSpStory', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'pcc_sp_nonce' ),
		) );
	}

	public static function add_column( $cols ) {
		$cols['pcc_story'] = 'Story Post';
		return $cols;
	}

	private static function last_line( $product_id ) {
		$last = get_post_meta( $product_id, self::META_LAST, true );
		if ( ! is_array( $last ) || empty( $last['t'] ) ) {
			return '';
		}
		$when  = wp_date( 'j M H:i', (int) $last['t'] );
		$color = ! empty( $last['ok'] ) ? '#1e5e1e' : '#b32d2e';
		$msg   = function_exists( 'mb_substr' ) ? mb_substr( (string) $last['msg'], 0, 140 ) : substr( (string) $last['msg'], 0, 140 );
		return '<span style="color:' . esc_attr( $color ) . ';font-size:12px;">' . esc_html( $when . ' — ' . $msg ) . '</span>';
	}

	public static function render_column( $column, $post_id ) {
		if ( 'pcc_story' !== $column ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			echo '—';
			return;
		}
		echo '<button type="button" class="button pcc-sp-story-btn" data-id="' . intval( $post_id ) . '">Post to Story</button>';
		echo '<div class="pcc-sp-story-msg" style="margin-top:4px;">' . self::last_line( $post_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function add_box() {
		add_meta_box( 'pcc_sp_story_box', 'Story Post', array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	public static function render_box( $post ) {
		$is_new  = ( 'auto-draft' === $post->post_status );
		$checked = $is_new ? true : ( '1' === get_post_meta( $post->ID, self::META_AUTO, true ) );
		wp_nonce_field( 'pcc_sp_story_box', 'pcc_sp_story_nonce' );
		?>
		<p>
			<label><input type="checkbox" name="pcc_sp_story_auto" value="1" <?php checked( $checked ); ?> /> <strong>Auto Post</strong></label>
		</p>
		<p class="description">Posts this product to your Instagram/Facebook Story when it is published. Needs a featured image.</p>
		<?php if ( 'publish' === $post->post_status ) : ?>
			<p>
				<button type="button" class="button pcc-sp-story-btn" data-id="<?php echo intval( $post->ID ); ?>">Post to Story now</button>
			</p>
			<div class="pcc-sp-story-msg"><?php echo self::last_line( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Ajax
	 * ---------------------------------------------------------------- */

	public static function ajax_story_now() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( 'missing product' );
		}
		$res = self::post_now( $product_id );
		wp_send_json_success( $res );
	}

	/** Generate & Post → Story section. */
	public static function ajax_queue_stories() {
		check_ajax_referer( 'pcc_sp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden' );
		}
		$product_ids = isset( $_POST['product_ids'] ) ? array_map( 'absint', (array) $_POST['product_ids'] ) : array();
		$platforms   = isset( $_POST['platforms'] ) ? array_map( 'sanitize_text_field', (array) $_POST['platforms'] ) : array();

		$queued  = 0;
		$skipped = 0;
		$last_err = '';
		foreach ( $product_ids as $product_id ) {
			$r = self::queue_product( $product_id, $platforms );
			if ( is_wp_error( $r ) ) {
				$skipped++;
				$last_err = $r->get_error_message();
			} else {
				$queued++;
			}
		}
		if ( $queued ) {
			PCC_SP_Queue::kick();
		}
		wp_send_json_success( array( 'queued' => $queued, 'skipped' => $skipped, 'error' => $queued ? '' : $last_err ) );
	}

	/* ------------------------------------------------------------------
	 * Generate & Post page section
	 * ---------------------------------------------------------------- */

	public static function render_section() {
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		$ig_ok      = PCC_SP_Settings::is_instagram_configured();
		$fb_ok      = PCC_SP_Settings::is_facebook_configured();
		?>
		<hr style="margin:30px 0;" />
		<h2>Story</h2>
		<?php if ( ! self::is_template_ready() ) : ?>
			<div class="notice notice-warning inline"><p>No story template is set yet. Add one under <a href="<?php echo esc_url( admin_url( 'admin.php?page=pcc-sp-settings' ) ); ?>">Settings → Stories</a>.</p></div>
		<?php endif; ?>
		<p>Post products as Instagram and/or Facebook Page Stories (one story per product, using your story template). Stories are posted right away, ahead of normal posts, and don't count toward the daily limit. You can also post a single product from the <strong>Story Post</strong> column on the Products list, or let new products post automatically (Auto Post box on the Add product page).</p>
		<table class="form-table pcc-sp-inline-form">
			<tr>
				<th>Category</th>
				<td>
					<select id="pcc-sp-story-category" style="min-width:320px;">
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
					<label><input type="checkbox" id="pcc-sp-story-ig" value="instagram_story" <?php echo $ig_ok ? 'checked' : 'disabled'; ?> /> Instagram Story</label>
					&nbsp;&nbsp;
					<label><input type="checkbox" id="pcc-sp-story-fb" value="facebook_story" <?php echo $fb_ok ? 'checked' : 'disabled'; ?> /> Facebook Page Story</label>
				</td>
			</tr>
		</table>

		<div id="pcc-sp-story-products-wrap" style="display:none;">
			<p>
				<button type="button" class="button" id="pcc-sp-story-select-all">Select all</button>
				<button type="button" class="button" id="pcc-sp-story-select-none">Select none</button>
				<span id="pcc-sp-story-select-count"></span>
			</p>
			<table class="widefat striped pcc-sp-thumbs-table" id="pcc-sp-story-products-table">
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
				<button type="button" class="button button-primary" id="pcc-sp-story-queue-btn">Queue selected as stories</button>
				<span id="pcc-sp-story-result"></span>
			</p>
		</div>
		<div id="pcc-sp-story-loading" style="display:none;">Loading…</div>
		<?php
	}
}
