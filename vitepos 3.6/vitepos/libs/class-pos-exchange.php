<?php
/**
 * POS Exchange class for Vitepos
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

use Vitepos\Models\Database\Mapbd_Pos_Cash_Drawer;
use Vitepos\Models\Database\Mapbd_Pos_Cash_Drawer_Types;
use VitePos\Modules\POS_Settings;

/**
 * Class POS_Exchange
 */
class POS_Exchange {

	/**
	 * Refund payload
	 *
	 * @var array
	 */
	protected $refund_payload;

	/**
	 * New order payload
	 *
	 * @var array
	 */
	protected $new_order_payload;

	/**
	 * Outlet ID
	 *
	 * @var int
	 */
	protected $outlet_id;

	/**
	 * Counter ID
	 *
	 * @var int
	 */
	protected $counter_id;

	/**
	 * Error string
	 *
	 * @var string
	 */
	protected $error_message = '';

	/**
	 * Response array
	 *
	 * @var array
	 */
	protected $response_data = array();

	/**
	 * Refund amount
	 *
	 * @var float
	 */
	protected $refund_amount = 0.0;
	
	/**
	 * Refund amount
	 *
	 * @var float
	 */
	protected $return_amount = 0.0;
	/**
	 * Payment data
	 *
	 * @var null
	 */
	protected $payment_data = null;

	/**
	 * Refund ID
	 *
	 * @var int
	 */
	protected $refund_id = 0;
	
	/**
	 * Refund ID
	 *
	 * @var Mapbd_Pos_Cash_Drawer
	 */
	protected $cash_drawer=null;
	
	/**
	 * Refund ID
	 *
	 * @var int
	 */
	protected $user_id=0;

	/**
	 * Old order ID
	 *
	 * @var int
	 */
	protected $old_order_id = 0;

	/**
	 * POS_Exchange constructor.
	 *
	 * @param array $refund_payload
	 * @param array $new_order_payload
	 * @param int   $outlet_id
	 * @param int   $counter_id
	 */
	public function __construct( $refund_payload, $new_order_payload, $outlet_id, $counter_id ) {
		$this->refund_payload    = $refund_payload;
		$this->new_order_payload = $new_order_payload;
		$this->outlet_id         = $outlet_id;
		$this->counter_id        = $counter_id;
	}

	/**
	 * Get Error Message
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->error_message;
	}

	/**
	 * Get Response Data
	 *
	 * @return array
	 */
	public function get_response_data() {
		return $this->response_data;
	}

	/**
	 * Process the entire exchange
	 *
	 * @return bool
	 */
	public function process_exchange() {
		
		
		$refund_id = 0;
		if ( ! empty( $this->refund_payload['items'] ) ) {
			$refund_id = $this->process_refund();
			if ( ! $refund_id ) {
				return false;
			}
		}

		
		if ( ! empty( $this->new_order_payload['items'] ) ) {
			$new_order_id = $this->process_new_order( $refund_id );
			if ( ! $new_order_id ) {
				if ( $refund_id ) {
					$this->rollback_refund( $refund_id );
				}
				return false; 
			}
			$this->response_data = (array) self::get_exchange_response_data( $new_order_id, $this->payment_data );
		}

		return true;
	}

	/**
	 * Rollback refund
	 *
	 * @param int $refund_id Its refund_id.
	 * 
	 * @return void
	 */
	protected function rollback_refund( $refund_id ) {
		if ( ! empty( $refund_id ) ) {
			wp_delete_post( $refund_id, true );
		}
	}

	/**
	 * Process the refund payload
	 *
	 * @return bool
	 */
	protected function process_refund() {
		
		$order_id = isset( $this->refund_payload['old_order_id'] ) ? $this->refund_payload['old_order_id'] : '';

		if ( empty( $order_id ) ) {
			$order_id = isset( $this->refund_payload['order_id'] ) ? $this->refund_payload['order_id'] : '';
		}

		
		if ( empty( $order_id ) && ! empty( $this->refund_payload['items'][0]['order_id'] ) ) {
			$order_id = $this->refund_payload['items'][0]['order_id'];
		}

		$this->old_order_id = $order_id;

		if ( empty( $order_id ) ) {
			return true; 
		}

		$user = wp_get_current_user();
		$this->user_id = $user->ID;
		if ( empty( $this->user_id ) ) {
			$this->error_message = 'Refund is not possible, no cash drawer found (user missing)';
			return false;
		}

		$this->cash_drawer = Mapbd_Pos_Cash_Drawer::get_by_counter( $this->outlet_id, $this->counter_id, $this->user_id );
		if ( empty( $this->cash_drawer->id ) ) {
			$this->error_message = 'Refund is not possible, no cash drawer found';
			return false;
		}

		

		if ( ! empty( $order_id ) ) {
			$order = wc_get_order( $order_id );
			$round_total = 0.0;
			
			$rounds = $order->get_items( 'fee' );
			foreach($rounds as $round){
				$key = $round->get_meta( '_vtp_dis_uid' );
				if ( ! empty( $key ) && 'RF'==$key ) {
					$round_total += $round->get_total();
				}
			}
			if ( ! empty( $order ) && $order instanceof \WC_Order ) {
				if ( 'N' == \VitePos\Modules\POS_Settings::get_module_option( 'discounted_order_exchange', 'N' ) ) {
					$has_discount = false;
					$to_fee = $order->get_total_fees();
					$to_dis = $order->get_discount_total();
					$coupons = $order->get_coupon_codes();
					if(count($coupons)>0)
					{
						$has_discount = true;
					}
					if($round_total<0 && !$has_discount)
					{
						$dif = $to_fee + $round_total;
						if($dif<0)
						{
							$has_discount = true;
						}else{
							if($to_dis<0)
							{
								$dis_dif = $to_dis + $round_total;
								if($dis_dif<0 && $dis_dif!=$round_total)
								{
									$has_discount = true;
								}
							}
							
						}
					}
					$vtp_discount = (float) $order->get_meta( '_vtp_discount_total' );
					if($vtp_discount< 0 && !$has_discount) 
					{
						if( $vtp_discount+$round_total<0 && $vtp_discount!=$round_total)
						{
							$has_discount = true;
						}
					}
					if ( $has_discount ) {
						$this->error_message = 'Exchange on discounted order is not allowed';
						return false;
					}
				}
				$refunded_amount   = appsbd_wc_amount( $order->get_total_refunded() );
				$total_amount      = $order->get_total();
				$can_refund_amount = appsbd_wc_amount( $order->get_remaining_refund_amount() );

				if ( 'refunded' == $order->get_status() || 0 > $can_refund_amount ) {
					$this->error_message = 'Order already refunded';
					return false;
				}


				$refund_amount = 0.0;
				$refund_args   = array(
					'reason'         => 'Exchange via POS',
					'order_id'       => $order_id,
					'refund_payment' => false,
					'restock_items'  => true, 
				);

				$line_items = array();
				$items      = $this->refund_payload['items'];

				$vitepos_order_items = POS_Order::get_order_items( $order, true );

				$exchanged_items = array();
				foreach ( $items as $item ) {
					if ( ! isset( $vitepos_order_items[ $item['item_id'] ] ) ) {
						$this->error_message = 'Item not found in the main order';
						return false;
					}

					$item['refund_qty'] = (int) $item['quantity'];
					$order_item         = &$vitepos_order_items[ $item['item_id'] ];

					if ( $order_item->refunded_qty + $item['refund_qty'] > $order_item->quantity ) {
						$this->error_message = 'Item refund quantity is greater than the order quantity';
						return false;
					}

					$r_total = ( (float) $order_item->price ) + ( (float) $order_item->fee_amount ) - ( (float) $order_item->discount_amount );
					

					$item_total = $item['refund_qty'] * $r_total;
					$tax_total  = $item['refund_qty'] * $order_item->tax_amount;
					$taxes      = array();

					if ( ! empty( $order_item->total_taxes ) ) {
						foreach ( $order_item->total_taxes as $tax ) {
							$amount            = $tax->amount / $order_item->quantity;
							$taxes[ $tax->id ] = appsbd_wc_amount( $amount * floatval( $item['refund_qty'] ) );
						}
					}
					$is_incl_tax =apply_filters(
						'woocommerce_prices_include_tax',
						get_option( 'woocommerce_prices_include_tax' ) === 'yes'
					);	
					if($is_incl_tax)
					{
						$tax_total = 0;
					}
					$refund_amount += $item_total + $tax_total;

					if ( $can_refund_amount < $refund_amount && $refund_amount - $can_refund_amount <= 0.02 ) {
						$refund_amount = $can_refund_amount;
					}	

					
					$line_items[ $item['item_id'] ] = array(
						'qty'          => $item['refund_qty'],
						'refund_total' => $item_total,
						'refund_tax'   => ! empty( $taxes ) ? $taxes : $tax_total,
					);

					$exchanged_items[] = array(
						'product_name' => $order_item->product_name,
						'quantity'     => $item['refund_qty'],
						'price'        => $r_total,
						'item_tax_total'    => $tax_total,
					);
				}

				$is_all_refunded = true;
				foreach ( $vitepos_order_items as $item_id => $o_item ) {
					$current_refund_qty = 0;
					foreach ( $items as $req_item ) {
						if ( $req_item['item_id'] == $item_id ) {
							$current_refund_qty = (int) $req_item['quantity'];
							break;
						}
					}
					if ( $o_item->refunded_qty + $current_refund_qty < $o_item->quantity ) {
						$is_all_refunded = false;
						break;
					}
				}

				if ( $is_all_refunded ) {
					$refund_amount = $can_refund_amount;
				}

				$new_o_total = $this->new_order_payload['sub_total'] + $this->new_order_payload['tax_total'];
				$refund_args['line_items'] = $line_items;
				$refund_args['amount']     = $refund_amount;
				$refund                    = wc_create_refund( $refund_args );

				if ( is_wp_error( $refund ) ) {
					$this->error_message = $refund->get_error_message();
					return false;
				} else {
					$refund->set_refunded_by( $this->user_id );
					$refund->update_meta_data( '_vtp_processed_by', $this->user_id );
					$refund->add_meta_data( '_vtp_outlet_id', $this->outlet_id );
					$refund->add_meta_data( '_vtp_counter_id', $this->counter_id );
					$refund->add_meta_data( '_vtp_cash_drawer_id', $this->cash_drawer->id );
					$refund->add_meta_data( '_vtp_from_exchange', 'Y' );
					$refund->add_meta_data( '_vtp_new_order_amount', $new_o_total );
					$refund->add_meta_data('_vtp_refund_discount', $this->refund_payload['ex_discount']);
					$refund->add_meta_data('_vtp_refund_fee', $this->refund_payload['ex_fees']);
					$refund->save();

					update_post_meta( $order_id, '_vtp_outlet_id', $this->outlet_id );
					update_post_meta( $order_id, '_vtp_cash_drawer_id', $this->cash_drawer->id );
					update_post_meta( $order_id, '_vtp_counter_id', $this->counter_id );

					$this->refund_amount = $refund_amount;
					$this->refund_id = $refund->get_id();
					return $refund->get_id();
				}
			} else {
				$this->error_message = 'Order not found for refund';
				return false;
			}
		}
		
		return 0;
	}

	/**
	 * Process the new order payload
	 *
	 * @param int $refund_id Its refund_id.
	 * 
	 * @return bool
	 */
	protected function process_new_order( $refund_id = 0 ) {
		if ( $this->refund_amount > 0 ) {
			$exchange_discount = appsbd_wc_amount( $this->refund_amount );
			
			if ( ! isset( $this->new_order_payload['c_discounts'] ) || ! is_array( $this->new_order_payload['c_discounts'] ) ) {
				$this->new_order_payload['c_discounts'] = array();
			}
			
			if($exchange_discount<$this->new_order_payload['grand_total']){
				$this->new_order_payload['grand_total'] -= $exchange_discount;
				$this->new_order_payload['c_discounts'][] = array(
				'title'    => __( 'Exchange Adjustment', 'vitepos' ),
				'val' => $exchange_discount,
				'amount_type' => 'F',
				'type'   => 'E',
				'is_taxable'   => 'N',
				'data'   => array(
					'_vtp_exchange_refund_id'   => $refund_id,
					'_vtp_exchange_old_order_id' => $this->old_order_id,
					'_vtp_is_exchange'         => 'Y',
				),
			);
				$this->return_amount = 0;
			} else {
				$this->new_order_payload['c_discounts'][] = array(
					'title' => __( 'Exchange Adjustment', 'vitepos' ),
					'val' => $this->new_order_payload['grand_total'],
					'amount_type' => 'F',
					'type' => 'E',
					'is_taxable' => 'N',
					'data' => array(
						'_vtp_exchange_refund_id' => $refund_id,
						'_vtp_exchange_old_order_id' => $this->old_order_id,
						'_vtp_is_exchange' => 'Y',
					),
				);
				if ( POS_Settings::get_module_option( 'no_refund_on_exchange', 'N' ) == 'Y' ) {
					$this->return_amount = 0;
				} else {
					$this->return_amount = $exchange_discount - $this->new_order_payload['grand_total'];
				}
				$this->new_order_payload['grand_total'] = 0;
			}
		}

		if ( $this->return_amount > 0 ) {
			if ( ! Mapbd_Pos_Cash_Drawer::add_cash_entry( 'C', 'Order returned', $this->return_amount, $this->cash_drawer, $this->old_order_id, 'O' ) ) {
				$this->error_message = 'Refunded but cash drawer amount update failed';
			}
			Mapbd_Pos_Cash_Drawer_Types::AddLog( $this->cash_drawer->id, $this->user_id, $refund_id, 'R', $this->return_amount );
		}

		$payment = new POS_Payment( $this->new_order_payload, $this->outlet_id, $this->counter_id );
		
		if ( $this->refund_amount >= 0 ) {
			$payment->set_exchange_info( $this->refund_amount, $this->old_order_id, $refund_id,$this->return_amount );
		}
		
		$is_offline = isset( $this->new_order_payload['is_offline'] ) && $this->new_order_payload['is_offline'] ? true : false;
		$order_id   = isset( $this->new_order_payload['order_id'] ) ? $this->new_order_payload['order_id'] : '';
		
		$success = false;
		
		if ( ! empty( $order_id ) && 'B' != \VitePos\Modules\POS_Settings::get_pos_mode() ) {
			if ( $payment->order_checkout( $order_id ) ) {
				$success = true;
			}
		} elseif ( 'P' == \VitePos\Modules\POS_Settings::get_pos_mode() ) {
			if ( $payment->restaurant_checkout_pay_first( $is_offline ) ) {
				$success = true;
			} 
		} elseif ( 'B' == \VitePos\Modules\POS_Settings::get_pos_mode() ) {
			if ( ! empty( $order_id ) ) {
				$prev_order = wc_get_order( $order_id );
				$is_vitepos = $prev_order->get_meta( '_is_vitepos' );
				if ( 'vt_processing' == $prev_order->get_status() && 'completed' == $this->new_order_payload['status'] && 'Y' == $is_vitepos ) {
					$success = $payment->restaurant_checkout( $order_id, $is_offline );
				} else {
					$success = $payment->restaurant_checkout_pay_first( $is_offline );
				}
			} else {
				$success = $payment->restaurant_checkout_pay_first( $is_offline );
			}
		} else {
			if ( $payment->grocery_checkout( $is_offline ) ) {
				$this->payment_data = $payment->get_order_details();
				$success = true;
			}
		}
		
		if ( $success ) {
			$order_details = $payment->get_order_details();
			if ( ! empty( $order_details->order->order_id ) ) {
				$new_order_id = $order_details->order->order_id;
				if ( ! empty( $this->old_order_id ) ) {
					$o_order = wc_get_order($this->old_order_id);
					if(!empty($o_order))
					{
						$new_order_ids = $o_order->get_meta('_vtp_exchange_new_order_ids');
						if(!is_array($new_order_ids)){
							$new_order_ids = !empty($new_order_ids) ? explode(',',$new_order_ids) : [];
						}
						$new_order_ids[] = $new_order_id;
						$o_order->update_meta_data('_vtp_exchange_new_order_ids',$new_order_ids);
						if($this->return_amount>0){
							$total_return_amount = $o_order->get_meta('_vtp_exchange_return_amount');
							$total_return_amount = !empty($total_return_amount) ? (float)$total_return_amount : 0;
							$total_return_amount += (float)$this->return_amount;
							$o_order->update_meta_data('_vtp_exchange_return_amount',$total_return_amount);
						}
						$o_order->save();
					}
				}
			}

			if(!empty($this->refund_id)){
				$refund = wc_get_order ( $this->refund_id );
				$refund->update_meta_data('_vtp_ex_new_order_id',$new_order_id);
				if($this->return_amount>0){
					$refund->update_meta_data('_vtp_ex_returned_amount',(float)$this->return_amount);
				}
				$refund->save();
			}

			return $new_order_id;
		} else {
			$this->error_message = 'Failed to create new order for exchange';
			return 0;
		}
	}

	/**
	 * Get exchange details by order id
	 *
	 * @param int $order_id Its new_order_id (the exchange order).
	 *
	 * @return \stdClass|null
	 */
	public static function get_exchange_response_data( $order_id, $payment_data = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$is_exchange = $order->get_meta( '_vtp_is_exchange','N' );
		if ( 'Y' != $is_exchange ) {
			return null;
		}
		$response            = new \stdClass();
		$response->new_order = POS_Order::get_from_woo_order_details( $order );
		$old_order_id        = $order->get_meta( '_vtp_old_order_id' );
		$old_order = null;
		$refunded_order = null;
		if ( ! empty( $old_order_id ) ) {
			$old_order = wc_get_order( $old_order_id );
			if ( ! empty( $old_order ) ) {
				$order_data = POS_Order::get_from_woo_order_details( $old_order );
				foreach( $order_data->refund_orders as $refund ) {
					if($refund->new_order_id == $order_id){
						$response->refund_order = $refund;
						break;
					}
				}	
			}
		}
		if(!empty($payment_data)){
			$response->payment_data = $payment_data;
		}
		$response->return_amount =  !empty($order->get_meta( '_vtp_exchanged_amount' )) ? (float) $order->get_meta( '_vtp_exchanged_amount' ) : 0;

		return $response;
	}

}
