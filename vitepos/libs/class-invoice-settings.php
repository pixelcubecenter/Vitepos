<?php
/**
 * Its Invoice Settings lib model.
 *
 * @since: 30/05/2022
 * @author: Sarwar Hasan
 * @version 1.0.0
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class Invoice_Settings
 */
class Invoice_Settings {
	/**
	 * Its property header
	 *
	 * @var $header
	 */
	public $header;
	/**
	 * Its property vat_reg_no
	 *
	 * @var $vat_reg_no
	 */
	public $vat_reg_no;
	/**
	 * Its property show_logo
	 *
	 * @var bool
	 */
	public $show_logo = false;
	/**
	 * Its property logo
	 *
	 * @var $logo
	 */
	public $logo;
	/**
	 * Its property page_width
	 *
	 * @var int
	 */
	public $page_width = 80;
	/**
	 * Its property font_size
	 *
	 * @var int
	 */
	public $font_size = 12;
	/**
	 * Its property font_size
	 *
	 * @var int
	 */
	public $page_ps = 3;
	/**
	 * Its property font_size
	 *
	 * @var int
	 */
	public $page_pe = 7;
	/**
	 * Its property show_header
	 *
	 * @var bool
	 */
	public $show_header = true;
	/**
	 * Its property show_vat_reg
	 *
	 * @var bool
	 */
	public $show_vat_reg = false;
	/**
	 * Its property show_tax_qr_code
	 *
	 * @var bool $show_tax_qr_code
	 */
	public $show_tax_qr_code = false;
	/**
	 * Its property tax_qr_code_position
	 *
	 * @var string $code_type
	 */
	public $tax_qr_code_position = 'F';
	/**
	 * Its property show_barcode
	 *
	 * @var bool $show_barcode
	 */
	public $show_barcode = false;
	/**
	 * Its property show_barcode
	 *
	 * @var bool $show_barcode
	 */
	public $show_token_no = false;
	/**
	 * Its property token_fs
	 *
	 * @var int
	 */
	public $token_fs = 14;
	/**
	 * Its property show_barcode
	 *
	 * @var string $code_type
	 */
	public $token_position = 'F';
	/**
	 * Its property show_barcode
	 *
	 * @var string $code_type
	 */
	public $code_type = 'B';
	/**
	 * Its property show_barcode
	 *
	 * @var string $code_type
	 */
	public $barcode_position = 'F';
	/**
	 * Its property vat_reg_no_label
	 *
	 * @var $vat_reg_no_label
	 */
	public $vat_reg_no_label;
	/**
	 * Its property show_outlet_info
	 *
	 * @var bool $show_outlet_info
	 */
	public $show_outlet_info = true;
	/**
	 * Its property show_outlet_name
	 *
	 * @var bool $show_outlet_name
	 */
	public $show_outlet_name = true;
	/**
	 * Its property show_outlet_email
	 *
	 * @var bool $show_outlet_email
	 */
	public $show_outlet_email = false;
	/**
	 * Its property show_outlet_phone
	 *
	 * @var bool
	 */
	public $show_outlet_phone = false;
	/**
	 * Its property show_outlet_address
	 *
	 * @var bool $show_outlet_address
	 */
	public $show_outlet_address = true;
	/**
	 * Its property show_outlet_website
	 *
	 * @var bool $show_outlet_website
	 */
	public $show_outlet_website = false;
	/**
	 * Its property show_counter_info
	 *
	 * @var bool $show_counter_info
	 */
	public $show_counter_info = true;
	/**
	 * Its property counter_operator_label
	 *
	 * @var $counter_operator_label
	 */
	public $counter_operator_label;
	/**
	 * Its property show_counter_info
	 *
	 * @var bool $show_current_status
	 */
	public $show_current_status = false;
	/**
	 * Its property show_counter_info
	 *
	 * @var bool $show_order_type
	 */
	public $show_order_type = false;
	/**
	 * Its property show_counter_info
	 *
	 * @var bool $show_counter_info
	 */
	public $show_waiter_info = false;
	/**
	 * Its property show_counter_info
	 *
	 * @var bool $show_counter_info
	 */
	public $show_table_info = false;
	/**
	 * Its property show_counter_no
	 *
	 * @var bool $show_counter_no
	 */
	public $show_counter_no = false;
	/**
	 * Its property counter_no_label
	 *
	 * @var $counter_no_label
	 */
	public $counter_no_label;
	/**
	 * Its property show_customer_info
	 *
	 * @var bool $show_customer_info
	 */
	public $show_customer_info = true;
	/**
	 * Its property customer_info_label
	 *
	 * @var $customer_info_label
	 */
	public $customer_info_label;
	/**
	 * Its property show_customer_name
	 *
	 * @var bool $show_customer_name
	 */
	public $show_customer_name = true;
	/**
	 * Its property show_customer_id
	 *
	 * @var bool $show_customer_id
	 */
	public $show_customer_id = false;
	/**
	 * Its property customer_id_label
	 *
	 * @var $customer_id_label
	 */
	public $customer_id_label;
	/**
	 * Its property show_customer_phone
	 *
	 * @var bool $show_customer_phone
	 */
	public $show_customer_phone = true;
	/**
	 * Its property customer_phone_label
	 *
	 * @var $customer_phone_label
	 */
	public $customer_phone_label;
	/**
	 * Its property show_customer_address
	 *
	 * @var bool $show_customer_address
	 */
	public $show_customer_address = true;
	/**
	 * Its property show_customer_address
	 *
	 * @var bool $show_customer_c_fields
	 */
	public $show_customer_c_fields = false;
	/**
	 * Its property show_order_no
	 *
	 * @var bool $show_order_no
	 */
	public $show_order_no = true;
	/**
	 * Its property order_no_label
	 *
	 * @var $order_no_label
	 */
	public $order_no_label;
	/**
	 * Its property order_no_label
	 *
	 * @var $order_no_label
	 */
	public $token_no_label;
	/**
	 * Its property show_serial_no
	 *
	 * @var bool $show_customer_reward
	 */
	public $show_customer_reward = false;
	/**
	 * Its property order_no_label
	 *
	 * @var string $customer_reward_label
	 */
	public $customer_reward_label = '';
	/**
	 * Its property show_serial_no
	 *
	 * @var bool $show_order_used_reward
	 */
	public $show_order_used_reward = false;
	/**
	 * Its property order_no_label
	 *
	 * @var string $order_used_reward_label
	 */
	public $order_used_reward_label = '';
	/**
	 * Its property show_serial_no
	 *
	 * @var bool $show_oreder_recieved_reward
	 */
	public $show_oreder_recieved_reward = false;
	/**
	 * Its property order_no_label
	 *
	 * @var string $order_recieved_reward_label
	 */
	public $order_recieved_reward_label = '';
	/**
	 * Its property show_serial_no
	 *
	 * @var bool $show_serial_no
	 */
	public $show_serial_no = true;

	/**
	 * Its property show_unit_cost
	 *
	 * @var bool $show_full_item_name
	 */
	public $show_full_item_name = false;
	/**
	 * Its property show_unit_cost
	 *
	 * @var bool $show_item_price
	 */
	public $show_item_price = false;

	/**
	 * Its property show_unit_cost
	 *
	 * @var bool $show_unit_cost
	 */
	public $show_unit_cost = true;
	/**
	 * Its property show_unit_cost
	 *
	 * @var bool $show_unit_tax_percentage
	 */
	public $show_unit_tax_percentage = false;
	/**
	 * Its property show_unit_cost
	 *
	 * @var string $unit_tax_label
	 */
	public $unit_tax_label = "Tax";
	/**
	 * Its property show_discount
	 *
	 * @var bool $show_discount
	 */
	public $show_discount = true;
	/**
	 * Its property show_tax
	 *
	 * @var bool $show_tax
	 */
	public $show_tax = true;
	/**
	 * Its property is_separate_tax
	 *
	 * @var bool $is_separate_tax
	 */
	public $is_separate_tax = false;
	/**
	 * Its property show_fee
	 *
	 * @var bool $show_fee
	 */
	public $show_fee = true;
	/**
	 * Its property show_payment_method
	 *
	 * @var bool $show_payment_method
	 */
	public $show_payment_method = true;
	/**
	 * Its property show_payment_method
	 *
	 * @var bool $show_order_c_fields
	 */
	public $show_order_c_fields = false;
	/**
	 * Its property show_footer
	 *
	 * @var bool $show_footer
	 */
	public $show_footer = true;
	/**
	 * Its property footer
	 *
	 * @var $footer
	 */
	public $footer;
	/**
	 * Its property branding
	 *
	 * @var bool $branding
	 */
	public $branding = false;

	/**
	 * The bind by array is generated by appsbd
	 *
	 * @param array $data_arr , It the array of bind property.
	 */
	public function bind_by_array( $data_arr ) {

		foreach ( $data_arr as $key => $val ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $val;
			}
		}
	}

	/**
	 * The bind by  array for ajax is generated by appsbd
	 *
	 * @param array $data_arr , It the array of bind property.
	 */
	public function bind_by_array_for_ajax( $data_arr ) {
		foreach ( $data_arr as $key => $val ) {
			if ( property_exists( $this, $key ) ) {
				if ( is_bool( $this->{$key} ) ) {
					$this->{$key} = (bool) $val;
				} else {
					$this->{$key} = $val;
				}
			}
		}
	}

	/**
	 * The save settings is generated by appsbd
	 *
	 * @param array $data , It the array of bind property.
	 */
	public static function save_settings( $data ) {
		$save_object = new self();
		$save_object->bind_by_array( $data );

		return update_option( 'vtpos_inv_setting', $save_object ) || add_option( 'vtpos_inv_setting', $save_object );
	}

	/**
	 * The get settings is generated by appsbd
	 *
	 * @return false|mixed
	 */
	public static function get_settings() {
		$obj         = new self();
		$dv_settings = get_option( 'vtpos_inv_setting' );
		if ( ! empty( $dv_settings ) ) {
			$obj->bind_by_array_for_ajax( $dv_settings );
			if ( empty( $obj->token_no_label ) ) {
				$obj->token_no_label = 'Token No';
			}
			if ( appsbd_is_activated_plugin( 'vite-rewards-pro/vite-rewards-pro.php' ) ) {
				if ( empty( $obj->customer_reward_label ) ) {
					$obj->customer_reward_label = 'Available Points';
				}
				if ( empty( $obj->order_used_reward_label ) ) {
					$obj->order_used_reward_label = 'Order Used Points';
				}
				if ( empty( $obj->order_recieved_reward_label ) ) {
					$obj->order_recieved_reward_label = 'Order Received Points';
				}
			} else {
				$obj->show_customer_reward        = false;
				$obj->show_order_used_reward      = false;
				$obj->show_oreder_recieved_reward = false;
			}

			return $obj;
		}
		$obj->header                 = "<h1 class='ql-align-center'>AppsBd Store</h1>";
		$obj->footer                 = '<h5 class="ql-align-center"><strong>Thank You For Purchasing</strong></h5>';
		$obj->vat_reg_no_label       = 'Vat No';
		$obj->vat_reg_no             = '# XXXX XXXX';
		$obj->counter_operator_label = 'Processed By';
		$obj->counter_no_label       = 'Counter';
		$obj->customer_info_label    = 'Customer Info';
		$obj->customer_id_label      = 'ID';
		$obj->customer_phone_label   = 'Phone';
		$obj->order_no_label         = 'Order No';
		$obj->token_no_label         = 'Token No';

		return $obj;
	}
}
