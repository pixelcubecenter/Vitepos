<?php
/**
 * Its used for ui cart item object
 *
 * @since: 21/09/2021
 * @author: Sarwar Hasan
 * @version 1.0.0
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

if ( ! class_exists( __NAMESPACE__ . '\Cart_Item' ) ) {
	/**
	 * Class Cart_Item
	 *
	 * @package VitePos\Libs
	 */
	class Cart_Item {
		/**
		 * Its property product_name
		 *
		 * @var string
		 */
		public $product_name;
		/**
		 * Its property product_id
		 *
		 * @var int
		 */
		public $product_id;
		/**
		 * Its property variation_id
		 *
		 * @var int
		 */
		public $variation_id;
		/**
		 * Its property quantity
		 *
		 * @var int
		 */
		public $quantity = 1;
		/**
		 * Its property quantity
		 *
		 * @var int
		 */
		public $stock_quantity = 0;
		/**
		 * Its property quantity
		 *
		 * @var int
		 */
		public $manage_stock = 0;
		/**
		 * Its property desc
		 *
		 * @var string
		 */
		public $desc;
		/**
		 * Its property desc
		 *
		 * @var mixed
		 */
		public $barcode;
		/**
		 * Its property price
		 *
		 * @var float
		 */
		public $price = 0.0;
		/**
		 * Its property regular_price
		 *
		 * @var float
		 */
		public $regular_price = 0.0;
		/**
		 * Its property tax
		 *
		 * @var float
		 */
		public $tax = 0.0;
		/**
		 * Its property tax
		 *
		 * @var string
		 */
		public $tax_class = 0.0;
		/**
		 * Its property tax
		 *
		 * @var array
		 */
		public $tax_rates = [];
		/**
		 * Its property fee
		 *
		 * @var float
		 */
		public $fee = 0.0;
		/**
		 * Its property fee
		 *
		 * @var float
		 */
		public $purchase_cost = 0.0;
		/**
		 * Its property image
		 *
		 * @var float
		 */
		public $image = 0.0;
	}
}
