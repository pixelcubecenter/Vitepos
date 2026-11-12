<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCC_SP_DB {

	public static function posts_table() {
		global $wpdb;
		return $wpdb->prefix . PCC_SP_TABLE_POSTS;
	}

	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . PCC_SP_TABLE_JOBS;
	}

	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$posts_table      = self::posts_table();
		$jobs_table       = self::jobs_table();

		$sql1 = "CREATE TABLE {$posts_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			method VARCHAR(20) NOT NULL DEFAULT 'template',
			platforms VARCHAR(255) NOT NULL DEFAULT 'instagram',
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			product_count INT UNSIGNED NOT NULL DEFAULT 1,
			caption LONGTEXT NULL,
			ig_status VARCHAR(20) NULL,
			ig_container_id VARCHAR(64) NULL,
			ig_media_id VARCHAR(64) NULL,
			fb_status VARCHAR(20) NULL,
			fb_post_id VARCHAR(64) NULL,
			tiktok_status VARCHAR(20) NULL,
			tiktok_post_id VARCHAR(64) NULL,
			threads_status VARCHAR(20) NULL,
			threads_post_id VARCHAR(64) NULL,
			linkedin_status VARCHAR(20) NULL,
			linkedin_post_id VARCHAR(128) NULL,
			telegram_status VARCHAR(20) NULL,
			telegram_post_id VARCHAR(255) NULL,
			telegram_private_status VARCHAR(20) NULL,
			telegram_private_post_id VARCHAR(255) NULL,
			tumblr_status VARCHAR(20) NULL,
			tumblr_post_id VARCHAR(255) NULL,
			pinterest_status VARCHAR(20) NULL,
			pinterest_post_id VARCHAR(255) NULL,
			bluesky_status VARCHAR(20) NULL,
			bluesky_post_id VARCHAR(255) NULL,
			igstory_status VARCHAR(20) NULL,
			igstory_post_id VARCHAR(255) NULL,
			fbstory_status VARCHAR(20) NULL,
			fbstory_post_id VARCHAR(255) NULL,
			mastodon_status VARCHAR(20) NULL,
			mastodon_post_id VARCHAR(255) NULL,
			reddit_status VARCHAR(20) NULL,
			reddit_post_id VARCHAR(255) NULL,
			error_message TEXT NULL,
			fb_error_message TEXT NULL,
			tiktok_error_message TEXT NULL,
			threads_error_message TEXT NULL,
			linkedin_error_message TEXT NULL,
			telegram_error_message TEXT NULL,
			telegram_private_error_message TEXT NULL,
			tumblr_error_message TEXT NULL,
			pinterest_error_message TEXT NULL,
			bluesky_error_message TEXT NULL,
			igstory_error_message TEXT NULL,
			fbstory_error_message TEXT NULL,
			mastodon_error_message TEXT NULL,
			reddit_error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			posted_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$sql2 = "CREATE TABLE {$jobs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL,
			product_title VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			image_attachment_id BIGINT UNSIGNED NULL,
			ig_child_id VARCHAR(64) NULL,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY product_id (product_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql1 );
		dbDelta( $sql2 );
	}

	/* ------------------------- Posts (batches) ------------------------- */

	public static function insert_post( $category_id, $method, $product_count, $platforms = 'instagram' ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::posts_table(),
			array(
				'category_id'   => $category_id,
				'method'        => $method,
				'platforms'     => $platforms,
				'status'        => 'queued',
				'product_count' => $product_count,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function update_post( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::posts_table(), $fields, array( 'id' => $id ) );
	}

	public static function get_post( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::posts_table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * @param string $kind 'any', 'story' (stories only) or 'feed' (everything except stories).
	 */
	public static function get_next_queued_post( $kind = 'any' ) {
		global $wpdb;
		$extra = '';
		if ( 'story' === $kind ) {
			$extra = " AND method = 'story'";
		} elseif ( 'feed' === $kind ) {
			$extra = " AND method <> 'story'";
		}
		return $wpdb->get_row( "SELECT * FROM " . self::posts_table() . " WHERE status = 'queued'{$extra} ORDER BY id ASC LIMIT 1" );
	}

	/**
	 * A post stuck in generating/posting (PHP timeout, killed request) would
	 * block the whole queue forever. Mark any that haven't moved in 10
	 * minutes as failed so they can be retried or cancelled.
	 */
	public static function recover_stale_posts( $minutes = 10 ) {
		global $wpdb;
		$cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $minutes * MINUTE_IN_SECONDS );
		$table  = self::posts_table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'failed', error_message = %s, updated_at = %s WHERE status IN ('generating','posting') AND updated_at < %s",
			'Timed out while generating/posting (no progress for ' . intval( $minutes ) . ' minutes). Click Retry to try again.',
			current_time( 'mysql' ),
			$cutoff
		) );
	}

	/**
	 * One cheap indexed query telling the background check whether there is
	 * anything to do at all. Returns array( 'queued' => n, 'active' => n ).
	 */
	public static function pending_counts() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM " . self::posts_table() . " WHERE status IN ('queued','generating','posting') GROUP BY status" );
		$out  = array( 'queued' => 0, 'active' => 0 );
		foreach ( (array) $rows as $row ) {
			if ( 'queued' === $row->status ) {
				$out['queued'] += intval( $row->c );
			} else {
				$out['active'] += intval( $row->c );
			}
		}
		return $out;
	}

	/**
	 * Product IDs that already sit in an unfinished post — one query for the
	 * whole product list instead of one query per product.
	 */
	public static function open_product_ids( $story = false ) {
		global $wpdb;
		$jobs  = self::jobs_table();
		$posts = self::posts_table();
		$cond  = $story ? "p.method = 'story'" : "p.method <> 'story'";
		$ids   = $wpdb->get_col(
			"SELECT DISTINCT j.product_id FROM {$jobs} j INNER JOIN {$posts} p ON p.id = j.post_id
			 WHERE j.product_id > 0 AND {$cond} AND p.status IN ('queued','generating','posting')"
		);
		return array_map( 'intval', (array) $ids );
	}

	public static function has_active_post() {
		global $wpdb;
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM " . self::posts_table() . " WHERE status IN ('generating','posting')" );
		return intval( $count ) > 0;
	}

	public static function count_posted_since( $mysql_datetime ) {
		global $wpdb;
		return intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::posts_table() . " WHERE status = 'posted' AND method <> 'story' AND posted_at >= %s",
			$mysql_datetime
		) ) );
	}

	public static function get_last_posted_time() {
		global $wpdb;
		return $wpdb->get_var( "SELECT posted_at FROM " . self::posts_table() . " WHERE status = 'posted' AND method <> 'story' ORDER BY posted_at DESC LIMIT 1" );
	}

	public static function get_posts( $limit = 50, $offset = 0 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::posts_table() . " ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit, $offset
		) );
	}

	/* --------------------------- Jobs (items) --------------------------- */

	public static function insert_job( $post_id, $product_id, $product_title ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::jobs_table(),
			array(
				'post_id'       => $post_id,
				'product_id'    => $product_id,
				'product_title' => $product_title,
				'status'        => 'queued',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	public static function update_job( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::jobs_table(), $fields, array( 'id' => $id ) );
	}

	public static function get_jobs_for_post( $post_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE post_id = %d ORDER BY id ASC', $post_id ) );
	}

	public static function get_job( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::jobs_table() . ' WHERE id = %d', $id ) );
	}

	public static function product_has_open_job( $product_id, $story = false ) {
		global $wpdb;
		$jobs  = self::jobs_table();
		$posts = self::posts_table();
		$cond  = $story ? "p.method = 'story'" : "p.method <> 'story'";
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$jobs} j INNER JOIN {$posts} p ON p.id = j.post_id
			 WHERE j.product_id = %d AND {$cond} AND p.status IN ('queued','generating','posting')",
			$product_id
		) );
		return intval( $count ) > 0;
	}

	/**
	 * Same summaries as summarize_products(), for many posts in ONE query.
	 *
	 * @param int[] $post_ids
	 * @return array post_id => "A, B, +2 more"
	 */
	public static function summarize_products_bulk( array $post_ids, $limit = 3 ) {
		global $wpdb;
		$post_ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		$in   = implode( ',', $post_ids );
		$rows = $wpdb->get_results( "SELECT post_id, product_title FROM " . self::jobs_table() . " WHERE post_id IN ({$in}) ORDER BY id ASC" );
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row->post_id ][] = $row->product_title;
		}
		$out = array();
		foreach ( $by as $pid => $titles ) {
			$shown = array_slice( $titles, 0, $limit );
			$more  = count( $titles ) - count( $shown );
			$out[ $pid ] = implode( ', ', $shown ) . ( $more > 0 ? ' +' . $more . ' more' : '' );
		}
		return $out;
	}

	/**
	 * Human-readable "Product A, Product B, +2 more" summary for a post row,
	 * built from its jobs, without extra queries per row on the list page.
	 */
	public static function summarize_products( $post_id, $limit = 3 ) {
		$jobs = self::get_jobs_for_post( $post_id );
		if ( empty( $jobs ) ) {
			return '';
		}
		$titles = wp_list_pluck( $jobs, 'product_title' );
		$shown  = array_slice( $titles, 0, $limit );
		$more   = count( $titles ) - count( $shown );
		$out    = implode( ', ', $shown );
		if ( $more > 0 ) {
			$out .= ' +' . $more . ' more';
		}
		return $out;
	}
}
