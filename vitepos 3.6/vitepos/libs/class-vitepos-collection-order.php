<?php
/**
 * Builds WooCommerce orders for store-credit top-ups and debt repayments.
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Collection_Order
 *
 * @package VitePos\Libs
 */
class VitePOS_Collection_Order {

	/**
	 * Normalise an incoming tender list to clean { type, amount } rows.
	 *
	 * @param mixed $tender Raw tender list from the request.
	 *
	 * @return array<int, array{type:string, amount:float}>
	 */
	private static function normalise_tender( $tender ) {
		$rows = array();
		if ( ! is_array( $tender ) ) {
			return $rows;
		}
		foreach ( $tender as $row ) {
			$row    = (array) $row;
			$type   = isset( $row['type'] ) ? sanitize_text_field( (string) $row['type'] ) : '';
			$amount = isset( $row['amount'] ) ? round( (float) $row['amount'], 4 ) : 0.0;
			if ( '' === $type || $amount <= 0 ) {
				continue;
			}
			$rows[] = array(
				'type'   => $type,
				'amount' => $amount,
			);
		}
		return $rows;
	}

	/**
	 * Build a pending collection order and drive it through the gateway-aware
	 * POS payment pipeline (POS_Payment::checkout_account_collection). Cash-only
	 * tenders complete in this single call; terminal tenders return next='STP'
	 * and stay pending until the frontend finishes the card tap via the normal
	 * order/order-complete endpoint.
	 *
	 * @param array $args { customer_id:int, purpose:'C'|'D', amount:float,
	 *                      method:string, tender:array, note:string,
	 *                      outlet_id:int, counter_id:int,
	 *                      settle_remaining_as_credit:bool }.
	 *
	 * @return \stdClass { is_ok:bool, message:string, order_id:int, details:object|null }
	 */
	public static function create( array $args ) {
		$resp = (object) array(
			'is_ok'    => false,
			'message'  => '',
			'order_id' => 0,
			'details'  => null,
		);

		$customer_id = isset( $args['customer_id'] ) ? (int) $args['customer_id'] : 0;
		$purpose     = isset( $args['purpose'] ) ? $args['purpose'] : 'C';
		$amount      = isset( $args['amount'] ) ? round( (float) $args['amount'], 4 ) : 0.0;
		$outlet_id   = isset( $args['outlet_id'] ) ? (int) $args['outlet_id'] : 0;
		$counter_id  = isset( $args['counter_id'] ) ? (int) $args['counter_id'] : 0;

		if ( $customer_id <= 0 || $amount <= 0 ) {
			$resp->message = __( 'A customer and a positive amount are required.', 'vitepos' );
			return $resp;
		}
		if ( ! function_exists( 'wc_create_order' ) ) {
			$resp->message = __( 'WooCommerce is not available.', 'vitepos' );
			return $resp;
		}

		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		if ( is_wp_error( $order ) ) {
			$resp->message = $order->get_error_message();
			return $resp;
		}

		
		
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'D' === $purpose ? __( 'Debt Repayment', 'vitepos' ) : __( 'Store Credit Top-up', 'vitepos' ) );
		$item->set_quantity( 1 );
		$item->set_subtotal( $amount );
		$item->set_total( $amount );
		$item->set_taxes( array() );
		$order->add_item( $item );

		
		
		
		$order->update_meta_data( '_vt_collection_purpose', $purpose );
		
		
		$target_debt_id = isset( $args['target_debt_id'] ) ? (int) $args['target_debt_id'] : 0;
		if ( $target_debt_id > 0 ) {
			$order->update_meta_data( '_vt_target_debt_id', $target_debt_id );
		}
		$order->update_meta_data( '_is_vitepos', 'Y' );
		$order->update_meta_data( '_vt_outlet_id', $outlet_id );
		$order->update_meta_data( '_vt_counter_id', $counter_id );
		
		$order->update_meta_data( '_vtp_outlet_id', $outlet_id );
		$order->update_meta_data( '_vtp_counter_id', $counter_id );
		
		$order->update_meta_data( '_vt_stock_cal', 'Y' );
		if ( ! empty( $args['settle_remaining_as_credit'] ) ) {
			$order->update_meta_data( '_vt_settle_remaining_as_credit', 'Y' );
		}
		if ( ! empty( $args['note'] ) ) {
			$order->add_order_note( $args['note'] );
		}

		
		
		
		
		$tender       = self::normalise_tender( isset( $args['tender'] ) ? $args['tender'] : array() );
		$payment_list = array();
		foreach ( $tender as $row ) {
			$payment_list[] = array(
				'type'    => $row['type'],
				'amount'  => $row['amount'],
				'is_paid' => in_array( $row['type'], array( 'C', 'S', 'O' ), true ) ? 'Y' : 'N',
				'name'    => apply_filters( 'vitepos/filter/payment-method-name', '', $row['type'] ),
			);
		}
		if ( empty( $payment_list ) ) {
			
			
			$method         = isset( $args['method'] ) ? sanitize_text_field( (string) $args['method'] ) : 'cash';
			$payment_list[] = array(
				'type'    => 'C',
				'amount'  => $amount,
				'is_paid' => 'Y',
				'name'    => apply_filters( 'vitepos/filter/payment-method-name', '', 'C' ),
			);
			$order->set_payment_method( $method );
			$order->set_payment_method_title( $method );
		} else {
			$primary = $payment_list[0]['type'];
			$order->set_payment_method( $primary );
			$order->set_payment_method_title( count( $payment_list ) > 1 ? __( 'Multiple', 'vitepos' ) : $primary );
		}
		$order->update_meta_data( '_vtp_payment_list', $payment_list );
		$order->update_meta_data( '_vtp_tendered_amount', $amount );
		$order->update_meta_data( '_vtp_change_amount', 0.0 );

		$order->calculate_totals( false );
		$order->set_total( $amount );

		
		
		
		$order->set_status( 'pending' );
		$order->save();

		$order_id = $order->get_id();

		$payment = new \VitePos\Libs\POS_Payment(
			array( 'order_id' => $order_id ),
			$outlet_id,
			$counter_id
		);
		$payment->checkout_account_collection( $order_id );
		$details = $payment->get_order_details();

		$resp->order_id = $order_id;
		$resp->details  = $details;
		$resp->is_ok    = ! empty( $details ) && isset( $details->is_complete );
		if ( ! $resp->is_ok ) {
			$resp->message = __( 'Failed to process the collection.', 'vitepos' );
		}

		return $resp;
	}
}
