<?php
/** @package VitePos\Tests */

use PHPUnit\Framework\TestCase;
use VitePos\Libs\VitePOS_Debt_FIFO_Core;

final class RefundAllocationTest extends TestCase {

	/** Order paid $60 cash + $40 on-account; first $30 partial refund → all to debt. */
	public function test_partial_refund_consumes_debt_first(): void {
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 40.0, 0.0, 60.0, 0.0, 0.0, 0.0, 30.0 );
		$this->assertSame( 30.0, $r['to_debt'] );
		$this->assertSame( 0.0, $r['to_credit'] );
		$this->assertSame( 0.0, $r['to_cash'] );
	}

	/** Same order, a second $30 refund: $10 finishes the debt, $20 spills to cash. */
	public function test_sequential_partials_walk_down_the_ladder(): void {
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 40.0, 0.0, 60.0, 30.0, 0.0, 0.0, 30.0 );
		$this->assertSame( 10.0, $r['to_debt'] );
		$this->assertSame( 0.0, $r['to_credit'] );
		$this->assertSame( 20.0, $r['to_cash'] );
	}

	/** Ladder order debt → store credit → cash on a three-way split. */
	public function test_ladder_order_debt_then_credit_then_cash(): void {
		
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 20.0, 15.0, 65.0, 0.0, 0.0, 0.0, 100.0 );
		$this->assertSame( 20.0, $r['to_debt'] );
		$this->assertSame( 15.0, $r['to_credit'] );
		$this->assertSame( 65.0, $r['to_cash'] );
	}

	/** Pure cash order: everything goes to cash. */
	public function test_pure_cash_all_to_cash(): void {
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 0.0, 0.0, 50.0, 0.0, 0.0, 0.0, 50.0 );
		$this->assertSame( 0.0, $r['to_debt'] );
		$this->assertSame( 0.0, $r['to_credit'] );
		$this->assertSame( 50.0, $r['to_cash'] );
	}

	/** Sub-cent residue after the three min() steps folds into cash. */
	public function test_rounding_residue_folds_into_cash(): void {
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 0.0, 0.0, 10.005, 0.0, 0.0, 0.0, 10.005 );
		$this->assertSame( 10.005, round( $r['to_debt'] + $r['to_credit'] + $r['to_cash'], 4 ) );
	}

	/**
	 * Allocations are never negative, and a remainder that no tracked bucket can
	 * absorb falls to cash (safe default — never trap the customer's money).
	 * This over-room case can't occur in practice (refund ≤ order total) but the
	 * function must stay well-behaved.
	 */
	public function test_overflow_beyond_all_rooms_falls_to_cash_never_negative(): void {
		$r = VitePOS_Debt_FIFO_Core::allocate_refund( 40.0, 0.0, 0.0, 40.0, 0.0, 0.0, 10.0 );
		$this->assertGreaterThanOrEqual( 0.0, $r['to_debt'] );
		$this->assertGreaterThanOrEqual( 0.0, $r['to_credit'] );
		$this->assertGreaterThanOrEqual( 0.0, $r['to_cash'] );
		$this->assertSame( 0.0, $r['to_debt'] );   
		$this->assertSame( 0.0, $r['to_credit'] ); 
		$this->assertSame( 10.0, $r['to_cash'] );  
	}
}
