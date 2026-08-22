<?php
/**
 * Backward-compatible shim + dynamic product registrar.
 *
 * Delegates customer credit state to VitePOS_Account_Service and owns the two
 * hidden virtual products used by the collection flow.
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Credit_Settings
 *
 * @package VitePos\Libs
 */
class VitePOS_Credit_Settings {

	

	/**
	 * Whether credit is enabled.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return bool
	 */
	public static function is_enabled( $customer_id ) {
		return VitePOS_Account_Service::is_enabled( $customer_id );
	}

	/**
	 * Credit limit.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return float
	 */
	public static function get_limit( $customer_id ) {
		return VitePOS_Account_Service::get_limit( $customer_id );
	}

	/**
	 * Set credit settings.
	 *
	 * @param int         $customer_id Customer id.
	 * @param bool|string $enabled Enabled flag.
	 * @param float|null  $limit Limit.
	 *
	 * @return bool
	 */
	public static function set_customer_credit( $customer_id, $enabled, $limit = null ) {
		return VitePOS_Account_Service::set_customer_credit( $customer_id, $enabled, $limit );
	}

	/**
	 * Spendable store credit.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return float
	 */
	public static function get_store_credit( $customer_id ) {
		return VitePOS_Account_Service::get_credit_balance( $customer_id );
	}

	/**
	 * Credit info payload.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return \stdClass
	 */
	public static function get_credit_info( $customer_id ) {
		return VitePOS_Account_Service::get_credit_info( $customer_id );
	}

	/**
	 * Attach credit info to a user payload.
	 *
	 * @param \stdClass $users_obj User payload by reference.
	 * @param int       $customer_id Customer id.
	 *
	 * @return void
	 */
	public static function attach_to_user_object( &$users_obj, $customer_id ) {
		VitePOS_Account_Service::attach_to_user_object( $users_obj, $customer_id );
	}

	

	/**
	 * One-time cleanup of the obsolete hidden collection products.
	 *
	 * Collection orders now carry a bare order line item (no backing product),
	 * so the previously-created hidden "Store Credit Top-up" / "Debt Repayment"
	 * products are obsolete and were leaking into the POS grid. Trash any product
	 * still flagged with the old identifying meta and drop the cached option ids.
	 * Guarded so it runs only once.
	 *
	 * @return void
	 */
	public static function cleanup_collection_products() {
		if ( 'Y' === get_option( '_vt_collection_products_cleaned', '' ) ) {
			return;
		}
		foreach ( array( '_vt_topup_product', '_vt_repay_product' ) as $flag ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => $flag, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => 'Y', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			foreach ( (array) $ids as $pid ) {
				wp_trash_post( (int) $pid );
			}
		}
		delete_option( '_vt_credit_topup_product_id' );
		delete_option( '_vt_debt_repay_product_id' );
		update_option( '_vt_collection_products_cleaned', 'Y' );
	}
}
