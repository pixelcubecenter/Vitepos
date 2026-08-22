<?php
/**
 * Pure read-time FIFO for the customer running account (no WordPress / DB).
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

/**
 * Class VitePOS_Account_FIFO
 *
 * @package VitePos\Libs
 */
class VitePOS_Account_FIFO {

	/**
	 * Walk debit rows oldest-first, consuming the cumulative credit total, to
	 * derive what is still outstanding, the open debit rows, and aging buckets.
	 *
	 * Each debit may carry an optional `targeted_paid` — a credit earmarked to that
	 * exact invoice. The tag is applied to its own debit BEFORE the oldest-first
	 * `$credit_total` (the untagged pool) runs, so a specific (even newer) invoice
	 * can be cleared while older debts stay open. Omitting `targeted_paid` (default
	 * 0) preserves the original pure-FIFO behaviour.
	 *
	 * @param array $debits      Oldest-first; each: array{ id:int, amount:float, created_at_ts:int, targeted_paid?:float }.
	 * @param float $credit_total Cumulative UNTAGGED credit available to FIFO oldest-first.
	 * @param int   $today_ts    Reference "now" (unix seconds) for age math.
	 *
	 * @return array{ outstanding: float, open_debts: array, aging: array }
	 */
	public static function compute( array $debits, $credit_total, $today_ts ) {
		$remaining_credit = round( (float) $credit_total, 4 );
		$open             = array();
		$outstanding      = 0.0;
		$aging            = array( 'b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0 );

		
		
		
		
		
		foreach ( $debits as $d ) {
			if ( ! isset( $d['entry_type'] ) || 'credit_redeem' !== $d['entry_type'] ) {
				continue;
			}
			$amount = round( (float) $d['amount'], 4 );
			if ( $amount <= 0 ) {
				continue;
			}
			$targeted         = isset( $d['targeted_paid'] ) ? min( $amount, max( 0.0, round( (float) $d['targeted_paid'], 4 ) ) ) : 0.0;
			$reserve          = min( max( 0.0, $amount - $targeted ), max( 0.0, $remaining_credit ) );
			$remaining_credit = round( $remaining_credit - $reserve, 4 );
		}

		foreach ( $debits as $d ) {
			$amount = round( (float) $d['amount'], 4 );
			if ( $amount <= 0 ) {
				continue;
			}
			
			if ( isset( $d['entry_type'] ) && 'credit_redeem' === $d['entry_type'] ) {
				continue;
			}
			
			
			$targeted  = isset( $d['targeted_paid'] ) ? min( $amount, max( 0.0, round( (float) $d['targeted_paid'], 4 ) ) ) : 0.0;
			$fifo_paid = min( $amount - $targeted, max( 0.0, $remaining_credit ) );
			$remaining_credit = round( $remaining_credit - $fifo_paid, 4 );
			$paid             = round( $targeted + $fifo_paid, 4 );
			$rem              = round( $amount - $paid, 4 );
			if ( $rem <= 0 ) {
				continue;
			}
			$age_days = (int) floor( ( $today_ts - (int) $d['created_at_ts'] ) / 86400 );
			$age_days = max( 0, $age_days );
			$open[]   = array(
				'id'         => (int) $d['id'],
				'amount'     => $amount,
				'paid'       => round( $paid, 4 ),
				'remaining'  => $rem,
				'age_days'   => $age_days,
			);
			$outstanding += $rem;
			if ( $age_days <= 30 ) {
				$aging['b0_30'] += $rem;
			} elseif ( $age_days <= 60 ) {
				$aging['b31_60'] += $rem;
			} elseif ( $age_days <= 90 ) {
				$aging['b61_90'] += $rem;
			} else {
				$aging['b90_plus'] += $rem;
			}
		}

		foreach ( $aging as $k => $v ) {
			$aging[ $k ] = round( $v, 4 );
		}

		return array(
			'outstanding' => round( $outstanding, 4 ),
			'open_debts'  => $open,
			'aging'       => $aging,
		);
	}
}
