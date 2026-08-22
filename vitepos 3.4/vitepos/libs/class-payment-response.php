<?php
/**
 * Its pos order model
 *
 * @since: 21/09/2021
 * @author: Sarwar Hasan
 * @version 1.0.0
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class Payment_Response
 *
 * @package VitePos\Libs
 */
class Payment_Response {
	/**
	 * Its property order_status
	 *
	 * @var bool
	 */
	public $order_status = true;
	/**
	 * Its property need_payment
	 *
	 * @var bool
	 */
	public $need_payment = true;
	/**
	 * Its property next
	 *
	 * @var string
	 */
	public $next = '';
	/**
	 * Its property payment_data
	 *
	 * @var null
	 */
	public $payment_data = null;
}
