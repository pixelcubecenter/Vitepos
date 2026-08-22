<?php
/**
 * @package VitePos\Tests
 */

use PHPUnit\Framework\TestCase;
use VitePos\Libs\VitePOS_Debt_FIFO_Core;

final class FifoCoreTest extends TestCase {

	/** Oldest-first allocation, exact clear of first debt and partial of second. */
	public function test_allocates_oldest_first(): void {
		$debts = array(
			array( 'id' => 1, 'remaining' => 40.0 ),
			array( 'id' => 2, 'remaining' => 30.0 ),
		);
		$plan = VitePOS_Debt_FIFO_Core::plan( $debts, 60.0 );

		$this->assertSame( 0.0, $plan['surplus'] );
		$this->assertSame( 60.0, $plan['allocated'] );
		$this->assertCount( 2, $plan['allocations'] );
		$this->assertSame( array( 'debt_id' => 1, 'amount' => 40.0, 'new_remaining' => 0.0, 'status' => 'C' ), $plan['allocations'][0] );
		$this->assertSame( array( 'debt_id' => 2, 'amount' => 20.0, 'new_remaining' => 10.0, 'status' => 'P' ), $plan['allocations'][1] );
	}

	/** Overpayment returns surplus and clears everything. */
	public function test_overpayment_reports_surplus(): void {
		$debts = array( array( 'id' => 1, 'remaining' => 25.0 ) );
		$plan  = VitePOS_Debt_FIFO_Core::plan( $debts, 100.0 );

		$this->assertSame( 25.0, $plan['allocated'] );
		$this->assertSame( 75.0, $plan['surplus'] );
		$this->assertSame( 'C', $plan['allocations'][0]['status'] );
	}

	/** Zero / negative amount is a no-op. */
	public function test_zero_amount_no_op(): void {
		$plan = VitePOS_Debt_FIFO_Core::plan( array( array( 'id' => 1, 'remaining' => 10.0 ) ), 0.0 );
		$this->assertSame( array(), $plan['allocations'] );
		$this->assertSame( 0.0, $plan['surplus'] );
	}

	public function test_status_for_buckets(): void {
		$this->assertSame( 'O', VitePOS_Debt_FIFO_Core::status_for( 50.0, 50.0 ) ); 
		$this->assertSame( 'P', VitePOS_Debt_FIFO_Core::status_for( 50.0, 20.0 ) ); 
		$this->assertSame( 'C', VitePOS_Debt_FIFO_Core::status_for( 50.0, 0.0 ) );  
	}
}
