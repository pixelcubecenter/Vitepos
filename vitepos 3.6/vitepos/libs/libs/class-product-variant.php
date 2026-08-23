<?php
/**
 * Its pos product-variant model
 *
 * @since: 21/09/2021
 * @author: Sarwar Hasan
 * @version 1.0.0
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class Product Variant
 *
 * @package VitePos\Libs
 */
class Product_Variant {
	/**
	 * Its property id
	 *
	 * @var int
	 */
	public $id;
	/**
	 * Its property name
	 *
	 * @var string
	 */
	public $name;
	/**
	 * Its property slug
	 *
	 * @var string
	 */
	public $slug;
	/**
	 * Its property outlet_id
	 *
	 * @var int
	 */
	public $outlet_id;
	/**
	 * Its property image
	 *
	 * @var string
	 */
	public $image;
	/**
	 * Its property product_id
	 *
	 * @var int
	 */
	public $product_id;
	/**
	 * Its property sale_price
	 *
	 * @var float
	 */
	public $sale_price;        
	/**
	 * Its property regular_price
	 *
	 * @var float
	 */
	public $regular_price;     
	/**
	 * Its property price
	 *
	 * @var float
	 */
	public $price;             
	/**
	 * Its property in_stock
	 *
	 * @var bool
	 */
	public $in_stock;
	/**
	 * Its property manage_stock
	 *
	 * @var string
	 */
	public $manage_stock;
	/**
	 * Its property stock_quantity
	 *
	 * @var int
	 */
	public $stock_quantity;
	/**
	 * Its property low_stock_amount
	 *
	 * @var int
	 */
	public $low_stock_amount;
	/**
	 * Its property taxable
	 *
	 * @var string
	 */
	public $taxable;
	/**
	 * Its property tax_status
	 *
	 * @var string
	 */
	public $tax_status;
	/**
	 * Its property tax_class
	 *
	 * @var string
	 */
	public $tax_class;
	/**
	 * Its property tax_class
	 *
	 * @var integer
	 */
	public $tax_rate;
	/**
	 * Its property tax_class
	 *
	 * @var array
	 */
	public $tax_rates;
	/**
	 * Its property on_sale
	 *
	 * @var string
	 */
	public $on_sale;
	/**
	 * Its property attributes
	 *
	 * @var string
	 */
	public $attributes;
	/**
	 * Its property price_html
	 *
	 * @var string
	 */
	public $price_html;
	/**
	 * Its property barcode
	 *
	 * @var int
	 */
	public $barcode;
	/**
	 * Its property barcode
	 *
	 * @var int
	 */
	public $global_unique_id;
	/**
	 * Its property sku.
	 *
	 * @var string
	 */
	public $sku;
	/**
	 * Its property weight.
	 *
	 * @var float
	 */
	public $weight;
	/**
	 * Its property height.
	 *
	 * @var float
	 */
	public $height;
	/**
	 * Its property width.
	 *
	 * @var float
	 */
	public $width;
	/**
	 * Its property length.
	 *
	 * @var float
	 */
	public $length;
	/**
	 * Its property purchase_cost
	 *
	 * @var float
	 */
	public $purchase_cost;
	/**
	 * Its property is_parent_dimension
	 *
	 * @var boolean
	 */
	public $is_parent_dimension;
}
