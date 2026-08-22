<?php
/**
 * FIFO debt allocation engine (ledger-backed).
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Debt_FIFO_Allocator
 *
 * @package VitePos\Libs
 */
class VitePOS_Debt_FIFO_Allocator {

	/**
	 * No-op: FIFO is computed on read now (VitePOS_Account_FIFO). Retained as a
	 * shim so any stale caller does not fatal. Nothing is allocated or mutated.
	 *
	 * @param int   $repay_ledger_id Unused.
	 * @param int   $customer_id Unused.
	 * @param float $amount Amount (echoed back as allocated).
	 *
	 * @return \stdClass { is_ok:bool, message:string, allocated:float, surplus:float, allocations:array }
	 */
	public static function allocate( $repay_ledger_id, $customer_id, $amount ) {
		return (object) array(
			'is_ok'       => true,
			'message'     => '',
			'allocated'   => round( (float) $amount, 4 ),
			'surplus'     => 0.0,
			'allocations' => array(),
		);
	}
}
