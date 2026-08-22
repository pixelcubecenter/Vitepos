<?php
/**
 * Pure FIFO allocation planner (no WordPress / DB dependency).
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Debt_FIFO_Core
 *
 * @package VitePos\Libs
 */
class VitePOS_Debt_FIFO_Core {

	/**
	 * Plan a repayment across open debts, oldest first.
	 *
	 * @param array $debts  Ordered oldest-first; each item array{ id:int, remaining:float }.
	 * @param float $amount Repayment amount.
	 *
	 * @return array{ allocations: array, allocated: float, surplus: float }
	 *               Each allocation: array{ debt_id:int, amount:float, new_remaining:float, status:string }.
	 */
	public static function plan( array $debts, $amount ) {
		$amount      = round( (float) $amount, 4 );
		$allocations = array();
		$allocated   = 0.0;

		if ( $amount <= 0 ) {
			return array(
				'allocations' => array(),
				'allocated'   => 0.0,
				'surplus'     => 0.0,
			);
		}

		$leftover = $amount;
		foreach ( $debts as $debt ) {
			if ( $leftover <= 0 ) {
				break;
			}
			$remaining = round( (float) $debt['remaining'], 4 );
			if ( $remaining <= 0 ) {
				continue;
			}
			$take          = min( $remaining, $leftover );
			$new_remaining = round( $remaining - $take, 4 );
			$allocations[] = array(
				'debt_id'       => (int) $debt['id'],
				'amount'        => round( $take, 4 ),
				'new_remaining' => $new_remaining,
				'status'        => $new_remaining <= 0 ? 'C' : 'P',
			);
			$allocated += $take;
			$leftover   = round( $leftover - $take, 4 );
		}

		return array(
			'allocations' => $allocations,
			'allocated'   => round( $allocated, 4 ),
			'surplus'     => round( $leftover, 4 ),
		);
	}

	/**
	 * Resolve a debt row's status from its total and current remaining.
	 *
	 * @param float $total Original debt amount.
	 * @param float $remaining Current remaining amount.
	 *
	 * @return string 'O' (untouched) | 'P' (partial) | 'C' (cleared).
	 */
	public static function status_for( $total, $remaining ) {
		$total     = round( (float) $total, 4 );
		$remaining = round( (float) $remaining, 4 );
		if ( $remaining <= 0 ) {
			return 'C';
		}
		if ( $remaining >= $total ) {
			return 'O';
		}
		return 'P';
	}

	/**
	 * Allocate a refund across the buckets it was paid from, in the fixed ladder
	 * order debt → redeemed store credit → cash. Each bucket is capped at its
	 * remaining room (original paid amount minus what prior refunds already took).
	 * Sub-cent residue folds into cash. Pure: no WordPress / DB.
	 *
	 * @param float $debt_paid     On-account (N) amount on the order.
	 * @param float $credit_paid   Redeemed store-credit (X) amount on the order.
	 * @param float $cash_paid     Real-money (cash/card/...) amount on the order.
	 * @param float $prior_debt    Debt already allocated by previous refunds.
	 * @param float $prior_credit  Store credit already allocated by previous refunds.
	 * @param float $prior_cash    Cash already allocated by previous refunds.
	 * @param float $refund_amount This refund's amount to allocate.
	 *
	 * @return array{ to_debt: float, to_credit: float, to_cash: float }
	 */
	public static function allocate_refund( $debt_paid, $credit_paid, $cash_paid, $prior_debt, $prior_credit, $prior_cash, $refund_amount ) {
		$left        = round( (float) $refund_amount, 4 );
		$debt_room   = max( 0.0, round( (float) $debt_paid - (float) $prior_debt, 4 ) );
		$credit_room = max( 0.0, round( (float) $credit_paid - (float) $prior_credit, 4 ) );
		$cash_room   = max( 0.0, round( (float) $cash_paid - (float) $prior_cash, 4 ) );

		if ( $left <= 0 ) {
			return array(
				'to_debt'   => 0.0,
				'to_credit' => 0.0,
				'to_cash'   => 0.0,
			);
		}

		$to_debt = min( $left, $debt_room );
		$left    = round( $left - $to_debt, 4 );

		$to_credit = min( $left, $credit_room );
		$left      = round( $left - $to_credit, 4 );

		$to_cash = min( $left, $cash_room );
		$left    = round( $left - $to_cash, 4 );

		
		if ( $left > 0 ) {
			$to_cash = round( $to_cash + $left, 4 );
		}

		return array(
			'to_debt'   => round( $to_debt, 4 ),
			'to_credit' => round( $to_credit, 4 ),
			'to_cash'   => round( $to_cash, 4 ),
		);
	}
}
