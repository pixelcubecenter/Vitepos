<?php
/** @package VitePos\Tests */

use PHPUnit\Framework\TestCase;
use VitePos\Libs\VitePOS_Account_FIFO;

final class AccountFifoTest extends TestCase {

	/** today = day 100 (unix-day index used only for age math in the test). */
	private function ts( int $day ): int {
		return $day * 86400;
	}

	public function test_credit_clears_oldest_debits_first(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 40.0, 'created_at_ts' => $this->ts( 10 ) ),
			array( 'id' => 2, 'amount' => 30.0, 'created_at_ts' => $this->ts( 20 ) ),
		);
		$r = VitePOS_Account_FIFO::compute( $debits, 50.0, $this->ts( 100 ) );

		$this->assertSame( 20.0, $r['outstanding'] );        
		$this->assertCount( 1, $r['open_debts'] );           
		$this->assertSame( 2, $r['open_debts'][0]['id'] );
		$this->assertSame( 20.0, $r['open_debts'][0]['remaining'] );
		$this->assertSame( 80, $r['open_debts'][0]['age_days'] ); 
	}

	public function test_fully_paid_means_zero_outstanding_and_store_credit(): void {
		$debits = array( array( 'id' => 1, 'amount' => 25.0, 'created_at_ts' => $this->ts( 5 ) ) );
		$r = VitePOS_Account_FIFO::compute( $debits, 100.0, $this->ts( 100 ) );

		$this->assertSame( 0.0, $r['outstanding'] );
		$this->assertSame( array(), $r['open_debts'] );
	}

	public function test_aging_buckets(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 10.0, 'created_at_ts' => $this->ts( 100 - 10 ) ), 
			array( 'id' => 2, 'amount' => 10.0, 'created_at_ts' => $this->ts( 100 - 45 ) ), 
			array( 'id' => 3, 'amount' => 10.0, 'created_at_ts' => $this->ts( 100 - 75 ) ), 
			array( 'id' => 4, 'amount' => 10.0, 'created_at_ts' => $this->ts( 100 - 120 ) ), 
		);
		$r = VitePOS_Account_FIFO::compute( $debits, 0.0, $this->ts( 100 ) );
		$this->assertSame( array( 'b0_30' => 10.0, 'b31_60' => 10.0, 'b61_90' => 10.0, 'b90_plus' => 10.0 ), $r['aging'] );
	}

	public function test_zero_debits_is_empty(): void {
		$r = VitePOS_Account_FIFO::compute( array(), 0.0, $this->ts( 100 ) );
		$this->assertSame( 0.0, $r['outstanding'] );
		$this->assertSame( array(), $r['open_debts'] );
	}

	/**
	 * A per-debit `targeted_paid` (a credit earmarked to one invoice) clears that
	 * specific debit BEFORE the oldest-first FIFO runs — so a newer invoice can be
	 * paid off while an older one stays open.
	 */
	public function test_targeted_paid_clears_a_specific_newer_debit_before_older(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 40.0, 'created_at_ts' => $this->ts( 10 ), 'targeted_paid' => 0.0 ),
			array( 'id' => 2, 'amount' => 30.0, 'created_at_ts' => $this->ts( 20 ), 'targeted_paid' => 30.0 ),
		);
		
		$r = VitePOS_Account_FIFO::compute( $debits, 0.0, $this->ts( 100 ) );

		$this->assertSame( 40.0, $r['outstanding'] );   
		$this->assertCount( 1, $r['open_debts'] );
		$this->assertSame( 1, $r['open_debts'][0]['id'] );
		$this->assertSame( 40.0, $r['open_debts'][0]['remaining'] );
	}

	/**
	 * A partial tag leaves its invoice partly open; untagged pool credit still
	 * flows oldest-first over what remains.
	 */
	public function test_partial_tag_then_untagged_fifo(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 40.0, 'created_at_ts' => $this->ts( 10 ), 'targeted_paid' => 0.0 ),
			array( 'id' => 2, 'amount' => 30.0, 'created_at_ts' => $this->ts( 20 ), 'targeted_paid' => 10.0 ),
		);
		
		
		$r = VitePOS_Account_FIFO::compute( $debits, 40.0, $this->ts( 100 ) );

		$this->assertSame( 20.0, $r['outstanding'] );
		$this->assertCount( 1, $r['open_debts'] );
		$this->assertSame( 2, $r['open_debts'][0]['id'] );
		$this->assertSame( 10.0, $r['open_debts'][0]['paid'] );
		$this->assertSame( 20.0, $r['open_debts'][0]['remaining'] );
	}

	/**
	 * A `credit_redeem` row (store credit spent at the till) is self-funded, so it
	 * never appears as an open invoice — even when older debt drains the pool first.
	 * The redemption's funding is reserved up front, so the outstanding total still
	 * lands on the genuine debt rows.
	 */
	public function test_credit_redeem_is_never_open(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 40.0, 'created_at_ts' => $this->ts( 10 ), 'entry_type' => 'debt_incur' ),
			array( 'id' => 2, 'amount' => 100.0, 'created_at_ts' => $this->ts( 20 ), 'entry_type' => 'debt_incur' ),
			array( 'id' => 3, 'amount' => 15.0, 'created_at_ts' => $this->ts( 20 ), 'entry_type' => 'credit_redeem' ),
		);
		
		$r = VitePOS_Account_FIFO::compute( $debits, 50.0, $this->ts( 100 ) );

		$this->assertSame( 105.0, $r['outstanding'] );               
		$this->assertCount( 2, $r['open_debts'] );                   
		$open_ids = array_map( static fn( $o ) => $o['id'], $r['open_debts'] );
		$this->assertNotContains( 3, $open_ids );                    
		$this->assertSame( array( 1, 2 ), $open_ids );
		$this->assertSame( 5.0, $r['open_debts'][0]['remaining'] );  
		$this->assertSame( 100.0, $r['open_debts'][1]['remaining'] ); 
	}

	/** Missing `targeted_paid` defaults to 0 — legacy callers behave exactly as before. */
	public function test_missing_targeted_paid_defaults_to_zero(): void {
		$debits = array(
			array( 'id' => 1, 'amount' => 40.0, 'created_at_ts' => $this->ts( 10 ) ),
			array( 'id' => 2, 'amount' => 30.0, 'created_at_ts' => $this->ts( 20 ) ),
		);
		$r = VitePOS_Account_FIFO::compute( $debits, 50.0, $this->ts( 100 ) );
		$this->assertSame( 20.0, $r['outstanding'] );
		$this->assertSame( 2, $r['open_debts'][0]['id'] );
	}
}
