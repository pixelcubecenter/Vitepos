<?php
/**
 * @package VitePos\Tests
 */

use PHPUnit\Framework\TestCase;
use VitePos\Libs\VitePOS_Credit_Validator;

final class CreditRulesTest extends TestCase {

	public function test_within_limit_allowed(): void {
		$r = VitePOS_Credit_Validator::can_purchase_core( true, 40.0, 50.0, 100.0, 2, 3 );
		$this->assertTrue( $r['is_ok'] );
	}

	public function test_over_limit_blocked(): void {
		$r = VitePOS_Credit_Validator::can_purchase_core( true, 80.0, 50.0, 100.0, 2, 3 );
		$this->assertFalse( $r['is_ok'] );
	}

	public function test_single_item_exception_when_zero_debt_and_flag_on(): void {
		$r = VitePOS_Credit_Validator::can_purchase_core( true, 0.0, 500.0, 100.0, 1, 1, true );
		$this->assertTrue( $r['is_ok'] );
		$this->assertSame( 'single_item_exception', $r['reason'] );
	}

	public function test_single_item_exception_ignored_when_flag_off(): void {
		
		$r = VitePOS_Credit_Validator::can_purchase_core( true, 0.0, 500.0, 100.0, 1, 1 );
		$this->assertFalse( $r['is_ok'] );
		$this->assertSame( 'over_limit', $r['reason'] );
	}

	public function test_single_item_exception_denied_when_debt_outstanding(): void {
		$r = VitePOS_Credit_Validator::can_purchase_core( true, 10.0, 500.0, 100.0, 1, 1, true );
		$this->assertFalse( $r['is_ok'] );
	}

	public function test_not_enabled_blocked(): void {
		$r = VitePOS_Credit_Validator::can_purchase_core( false, 0.0, 10.0, 100.0, 1, 1 );
		$this->assertFalse( $r['is_ok'] );
	}
}
