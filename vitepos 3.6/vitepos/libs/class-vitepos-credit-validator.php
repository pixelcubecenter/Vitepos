<?php
/**
 * Customer credit validator for the Debt / On Account feature.
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Credit_Validator
 *
 * Encapsulates the Kibetech credit rules:
 *  - Standard rule:   ( current_debt + new_on_account ) <= limit
 *  - Single-product exception: current_debt == 0 AND one physical unit.
 *
 * @package VitePos\Libs
 */
class VitePOS_Credit_Validator {

	/**
	 * Resolve the credit limit for a customer (delegates to the settings store).
	 *
	 * @param int $customer_id Its customer_id param.
	 *
	 * @return float
	 */
	public static function get_limit( $customer_id ) {
		return VitePOS_Account_Service::get_limit( $customer_id );
	}

	/**
	 * Is the customer enabled for credit purchases.
	 *
	 * @param int $customer_id Its customer_id param.
	 *
	 * @return bool
	 */
	public static function is_credit_enabled( $customer_id ) {
		return VitePOS_Account_Service::is_enabled( $customer_id );
	}

	/**
	 * Standard limit rule.
	 *
	 * @param float $current_debt Its current_debt param.
	 * @param float $new_debt Its new_debt param.
	 * @param float $limit Its limit param.
	 *
	 * @return bool
	 */
	public static function validate_limit( $current_debt, $new_debt, $limit ) {
		return ( (float) $current_debt + (float) $new_debt ) <= (float) $limit;
	}

	/**
	 * Single-product over-limit exception.
	 *
	 * Allowed only when the customer owes nothing and the cart holds exactly one
	 * physical unit (one line item AND total quantity == 1).
	 *
	 * @param float $current_debt Its current_debt param.
	 * @param int   $item_count Its item_count param.
	 * @param int   $total_quantity Its total_quantity param.
	 *
	 * @return bool
	 */
	public static function validate_single_product_exception( $current_debt, $item_count, $total_quantity ) {
		return ( (float) $current_debt ) == 0.0 && 1 === (int) $item_count && 1 === (int) $total_quantity;
	}

	/**
	 * Pure credit decision (no meta / WP access) — the Kibetech rules.
	 *
	 * @param bool  $enabled        Whether the customer is credit-enabled.
	 * @param float $current_debt   Current outstanding debt.
	 * @param float $on_account     Deferred amount being requested.
	 * @param float $limit          Credit limit.
	 * @param int   $item_count     Line-item count.
	 * @param int   $total_quantity Total physical units.
	 * @param bool  $allow_single_unlimited Whether the single-item over-limit exception is enabled.
	 *
	 * @return array{ is_ok: bool, reason: string }
	 */
	public static function can_purchase_core( $enabled, $current_debt, $on_account, $limit, $item_count, $total_quantity, $allow_single_unlimited = false ) {
		if ( ! $enabled ) {
			return array( 'is_ok' => false, 'reason' => 'not_enabled' );
		}
		if ( $limit == 0 ) {
			return array( 'is_ok' => false, 'reason' => 'over_limit' );
		}
		if ( self::validate_limit( $current_debt, $on_account, $limit ) ) {
			return array( 'is_ok' => true, 'reason' => 'within_limit' );
		}
		
		
		if ( $allow_single_unlimited && self::validate_single_product_exception( $current_debt, $item_count, $total_quantity ) ) {
			return array( 'is_ok' => true, 'reason' => 'single_item_exception' );
		}
		return array( 'is_ok' => false, 'reason' => 'over_limit' );
	}

	/**
	 * Decide whether an on-account purchase is allowed.
	 *
	 * @param int   $customer_id Its customer_id param.
	 * @param float $on_account_amount The deferred (credit) portion of the sale.
	 * @param int   $item_count Number of line items in the order.
	 * @param int   $total_quantity Total physical units in the order.
	 *
	 * @return \stdClass { is_ok:bool, message:string }
	 */
	public static function can_purchase( $customer_id, $on_account_amount, $item_count, $total_quantity = null ) {
		$resp = (object) array(
			'is_ok'   => false,
			'message' => '',
		);

		if ( empty( $customer_id ) ) {
			$resp->message = __( 'A customer must be selected for an on-account sale.', 'vitepos' );
			return $resp;
		}
		if ( is_null( $total_quantity ) ) {
			$total_quantity = $item_count;
		}

		$current = VitePOS_Account_Service::get_debit_balance( $customer_id );
		$limit   = self::get_limit( $customer_id );

		$decision = self::can_purchase_core(
			self::is_credit_enabled( $customer_id ),
			$current,
			$on_account_amount,
			$limit,
			$item_count,
			$total_quantity,
			VitePOS_Account_Service::is_single_unlimited_enabled()
		);
		if ( $decision['is_ok'] ) {
			$resp->is_ok = true;
			return $resp;
		}

		if ( 'not_enabled' === $decision['reason'] ) {
			$resp->message = __( 'This customer is not approved for credit purchases.', 'vitepos' );
			return $resp;
		}

		$resp->message = sprintf(
			/* translators: 1: current debt, 2: new on-account amount, 3: credit limit */
			__( 'Credit blocked: current debt %1$s + %2$s exceeds the %3$s limit.', 'vitepos' ),
			wc_price( $current ),
			wc_price( $on_account_amount ),
			wc_price( $limit )
		);
		return $resp;
	}
}
