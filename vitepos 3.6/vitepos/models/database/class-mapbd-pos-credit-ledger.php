<?php
/**
 * Unified credit/debit ledger model.
 *
 * @package Vitepos\Models\Database
 */

namespace Vitepos\Models\Database;

use VitePos\Core\ViteposModel;

/**
 * Class Mapbd_Pos_Credit_Ledger
 *
 * @properties id,customer_id,entry_type,amount,prev_balance,prev_outlet_balance,status,credit_balance_after,debit_balance_after,order_id,outlet_id,counter_id,note,target_id,created_by,created_at
 */
#[\AllowDynamicProperties]
class Mapbd_Pos_Credit_Ledger extends ViteposModel {
	public $id;
	public $customer_id;
	public $entry_type;
	public $amount;
	public $prev_balance;
	public $prev_outlet_balance;
	public $status;
	public $credit_balance_after;
	public $debit_balance_after;
	public $order_id;
	public $outlet_id;
	public $counter_id;
	public $note;
	
	
	
	public $target_id;
	public $created_by;
	public $created_at;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->set_validation();
		$this->table_name     = 'apbd_pos_credit_ledger';
		$this->primary_key    = 'id';
		$this->unique_key     = array();
		$this->multi_key      = array();
		$this->auto_inc_field = array( 'id' );
		$this->app_base_name  = 'apbd-elite-pos';
	}

	/**
	 * Validation rules.
	 */
	public function set_validation() {
		$this->validations = array(
			'id'          => array(
				'Text' => 'Id',
				'Rule' => 'max_length[20]|integer',
			),
			'customer_id' => array(
				'Text' => 'Customer',
				'Rule' => 'required|integer',
			),
			'entry_type'  => array(
				'Text' => 'Entry Type',
				'Rule' => 'required|max_length[20]',
			),
			'status'      => array(
				'Text' => 'Status',
				'Rule' => 'max_length[1]',
			),
		);
	}

	/**
	 * Status raw options (debt_incur rows only).
	 *
	 * @param string $property Property name.
	 * @param bool   $is_with_select Prepend a "Select" row.
	 *
	 * @return array
	 */
	public function get_property_raw_options( $property, $is_with_select = false ) {
		$return_obj = array();
		if ( 'status' === $property ) {
			$return_obj = array(
				'O' => 'Open',
				'P' => 'Partial',
				'C' => 'Cleared',
			);
		}
		if ( $is_with_select ) {
			return array_merge( array( '' => 'Select' ), $return_obj );
		}
		return $return_obj;
	}

	/**
	 * Create the table (idempotent).
	 */
	public static function create_db_table() {
		$this_obj = new static();
		$table    = $this_obj->db->prefix . $this_obj->table_name;
		if ( $this_obj->db->get_var( "show tables like '{$table}'" ) != $table ) {
			$sql = "CREATE TABLE `{$table}` (
					  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					  `customer_id` bigint(20) unsigned NOT NULL DEFAULT 0,
					  `entry_type` varchar(20) NOT NULL DEFAULT '',
					  `amount` decimal(10,2) NOT NULL DEFAULT 0,
					  `prev_balance` decimal(10,2) NOT NULL DEFAULT 0,
					  `prev_outlet_balance` decimal(10,2) NOT NULL DEFAULT 0.0,
					  `status` char(1) NOT NULL DEFAULT '' COMMENT 'radio(O=Open,P=Partial,C=Cleared)',
					  `credit_balance_after` decimal(10,2) NOT NULL DEFAULT 0,
					  `debit_balance_after` decimal(10,2) NOT NULL DEFAULT 0,
					  `order_id` bigint(20) unsigned NOT NULL DEFAULT 0,
					  `target_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Specific Order Id For Debt Paymnet',
					  `outlet_id` int(11) NOT NULL DEFAULT 0,
					  `counter_id` int(11) NOT NULL DEFAULT 0,
					  `note` text NULL,
					  `created_by` bigint(20) unsigned NOT NULL DEFAULT 0,
					  `created_at` datetime DEFAULT NULL,
					  PRIMARY KEY (`id`),
					  KEY `customer_type` (`customer_id`,`entry_type`),
					  KEY `customer_status` (`customer_id`,`status`),
					  KEY `customer_target` (`customer_id`,`target_id`),
					  KEY `order_id` (`order_id`)
					) ";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}
}
