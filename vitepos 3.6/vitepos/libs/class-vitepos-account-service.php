<?php
/**
 * Account service: customer credit/debit meta + unified ledger.
 *
 * Replaces VitePOS_Debt_Manager and VitePOS_Collection_Manager and centralises
 * the four meta fields and all ledger reads/writes.
 *
 * @package VitePos\Libs
 */

namespace VitePos\Libs;

use Vitepos\Models\Database\Mapbd_Pos_Credit_Ledger;
use Vitepos\Models\Database\Mapbd_Pos_Warehouse;

/**
 * Class VitePOS_Account_Service
 *
 * @package VitePos\Libs
 */
class VitePOS_Account_Service {

	const META_ENABLED = '_vt_is_credit_enabled';
	const META_LIMIT   = '_vt_credit_limit';
	const META_CREDIT  = '_vt_credit_amount';
	const META_DEBIT   = '_vt_debit_amount';
	const DEFAULT_LIMIT = 100;

	

	/**
	 * Is the whole debt/credit feature enabled?
	 *
	 * The master switch is the "Debt / On Account" payment method (id 'N'). When an
	 * admin disables that method the entire feature is gated off everywhere.
	 *
	 * @return bool
	 */
	public static function is_feature_enabled() {
		$method = \VitePos\Modules\POS_Payment::get_payment_methods( 'N' );
		return ( $method instanceof Payment_Method_Base ) && $method->is_enable();
	}

	/**
	 * Is the "single item purchase without limit" exception enabled?
	 *
	 * Admin toggle on the Debt / On Account ('N') payment method. When off, only
	 * the standard credit-limit rule decides; when on, a single physical unit can
	 * be sold on account even over the limit (provided the customer owes nothing).
	 *
	 * @return bool
	 */
	public static function is_single_unlimited_enabled() {
		$method = \VitePos\Modules\POS_Payment::get_payment_methods( 'N' );
		if ( ! ( $method instanceof Payment_Method_Base ) ) {
			return false;
		}
		return 'Y' === $method->get_admin_setting( 'single_unlimited' );
	}

	/**
	 * Is the customer credit-enabled.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return bool
	 */
	public static function is_enabled( $customer_id ) {
		return 'Y' === get_user_meta( $customer_id, self::META_ENABLED, true );
	}

	/**
	 * Store-wide default limit (filterable).
	 *
	 * Reads the "Default Credit Limit" admin setting off the Debt / On Account
	 * ('N') payment method and falls back to self::DEFAULT_LIMIT when it was never
	 * saved (or saved blank / non-numeric).
	 *
	 * @return float
	 */
	public static function get_default_limit() {
		$limit  = null;
		$method = \VitePos\Modules\POS_Payment::get_payment_methods( 'N' );
		if ( $method instanceof Payment_Method_Base ) {
			$limit = $method->get_admin_setting( 'default_limit' );
		}
		if ( ! is_numeric( $limit ) ) {
			$limit = self::DEFAULT_LIMIT;
		}
		return (float) apply_filters( 'vitepos/filter/credit/default-limit', (float) $limit );
	}

	/**
	 * Resolve a customer's credit limit.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return float
	 */
	public static function get_limit( $customer_id ) {
		$limit = get_user_meta( $customer_id, self::META_LIMIT, true );
		if ( 0 == $limit ||'' === $limit || null === $limit || false === $limit ) {
			return self::get_default_limit();
		}
		return (float) $limit;
	}

	/**
	 * Admin: enable credit + optional limit.
	 *
	 * @param int         $customer_id Customer id.
	 * @param bool|string $enabled Enabled flag.
	 * @param float|null  $limit Optional limit.
	 *
	 * @return bool
	 */
	public static function set_customer_credit( $customer_id, $enabled, $limit = null ) {
		if ( empty( $customer_id ) ) {
			return false;
		}
		$limit = null === $limit ? VitePOS_Credit_Settings::get_limit( $customer_id ) : (float) $limit;
		$is_enabled = ( true === $enabled || 'Y' === $enabled || 1 === $enabled || '1' === $enabled );
		update_user_meta( $customer_id, self::META_ENABLED, $is_enabled ? 'Y' : 'N' );
		if ( null !== $limit && '' !== $limit && is_numeric( $limit ) ) {
			update_user_meta( $customer_id, self::META_LIMIT, (float) $limit );
		}
		return true;
	}

	

	/** Cumulative money paid in (add-only). */
	public static function get_credit_total( $customer_id ) {
		return max( 0.0, round( (float) get_user_meta( $customer_id, self::META_CREDIT, true ), 4 ) );
	}

	/** Cumulative value taken (add-only). */
	public static function get_debit_total( $customer_id ) {
		return max( 0.0, round( (float) get_user_meta( $customer_id, self::META_DEBIT, true ), 4 ) );
	}

	/** Signed account balance: positive = store credit, negative = owes. */
	public static function get_net( $customer_id ) {
		return round( self::get_credit_total( $customer_id ) - self::get_debit_total( $customer_id ), 4 );
	}

	/** Spendable store credit. Replaces the old get_credit_balance(). */
	public static function get_credit_balance( $customer_id ) {
		return max( 0.0, self::get_net( $customer_id ) );
	}

	/** Amount owed. Replaces the old get_debit_balance(). */
	public static function get_debit_balance( $customer_id ) {
		return max( 0.0, round( -1 * self::get_net( $customer_id ), 4 ) );
	}

	/**
	 * Oldest-first debit ledger rows for a customer (value-taken entries).
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return array Rows: { id, amount, created_at_ts, order_id, created_at }.
	 */
	public static function get_debit_rows_raw( $customer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, entry_type, order_id, created_at, UNIX_TIMESTAMP(created_at) AS created_at_ts
				 FROM {$table}
				 WHERE customer_id = %d AND entry_type IN ('debt_incur','credit_redeem')
				 ORDER BY created_at ASC, id ASC",
				$customer_id
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'            => (int) $r->id,
				'amount'        => round( (float) $r->amount, 4 ),
				'entry_type'    => $r->entry_type,
				'created_at_ts' => (int) $r->created_at_ts,
				'order_id'      => (int) $r->order_id,
				'created_at'    => $r->created_at,
			);
		}
		return $out;
	}

	/**
	 * Sum of credits earmarked to each debit row (targeted invoice payments),
	 * keyed by the targeted debit's ledger id. Untagged credits are excluded.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return array Map: debit_ledger_id => tagged_amount (float).
	 */
	public static function get_tagged_credits_by_debit( $customer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_id, COALESCE(SUM(amount),0) AS total
				 FROM {$table}
				 WHERE customer_id = %d
				   AND entry_type IN ('credit_topup','debt_repay')
				   AND target_id IS NOT NULL AND target_id > 0
				 GROUP BY target_id",
				$customer_id
			)
		);
		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r->target_id ] = round( (float) $r->total, 4 );
		}
		return $map;
	}

	/**
	 * FIFO snapshot (outstanding + open_debts + aging) for one customer.
	 *
	 * Targeted invoice payments are applied to their own debit first (clamped to
	 * the invoice amount); whatever is left of the cumulative credit — the untagged
	 * pool plus any tag overspill — flows oldest-first exactly as before. Tags
	 * pointing at a non-existent/cleared debit simply stay in the untagged pool.
	 */
	public static function fifo_snapshot( $customer_id ) {
		$debits       = self::get_debit_rows_raw( $customer_id );
		$tag_by_debit = self::get_tagged_credits_by_debit( $customer_id );
		$targeted_sum = 0.0;
		foreach ( $debits as &$d ) {
			$tag                = isset( $tag_by_debit[ $d['id'] ] ) ? $tag_by_debit[ $d['id'] ] : 0.0;
			$tp                 = min( $d['amount'], max( 0.0, $tag ) ); 
			$d['targeted_paid'] = $tp;
			$targeted_sum       = round( $targeted_sum + $tp, 4 );
		}
		unset( $d );
		
		
		$untagged_total = round( self::get_credit_total( $customer_id ) - $targeted_sum, 4 );
		if ( $untagged_total < 0 ) {
			$untagged_total = 0.0;
		}
		return VitePOS_Account_FIFO::compute( $debits, $untagged_total, time() );
	}

	

	/**
	 * Customer pays money in (repayment / top-up / surplus): append a credit row
	 * and increment the cumulative credit total. Never subtracts.
	 *
	 * @param int    $customer_id Customer id.
	 * @param float  $amount      Amount paid in.
	 * @param array  $ctx         Context (order_id, outlet_id, counter_id, note).
	 * @param string $type        Ledger entry_type label (default 'debt_repay').
	 *
	 * @return float New net balance.
	 */
	public static function add_credit( $customer_id, $amount, $ctx = array(), $type = 'debt_repay' ) {
		$amount = round( (float) $amount, 4 );
		if ( empty( $customer_id ) || $amount <= 0 ) {
			return self::get_net( $customer_id );
		}
		$new = round( self::get_credit_total( $customer_id ) + $amount, 4 );
		update_user_meta( $customer_id, self::META_CREDIT, $new );
		self::write_ledger( $customer_id, $type, $amount, $ctx );
		return self::get_net( $customer_id );
	}

	/**
	 * Pay a SPECIFIC invoice: money paid in, earmarked to one debit row so the
	 * read-time FIFO clears that exact invoice first (regardless of age). Still a
	 * normal money-in credit, so the net balance moves identically to add_credit().
	 *
	 * If the target is not a valid debit row owned by this customer, it falls back
	 * to a plain untagged credit — a payment is never lost or rejected.
	 *
	 * @param int   $customer_id     Customer id.
	 * @param float $amount          Amount paid in.
	 * @param int   $target_debit_id Ledger id of the targeted debit row.
	 * @param array $ctx             Context (order_id, outlet_id, counter_id, note).
	 *
	 * @return float New net balance.
	 */
	public static function add_targeted_credit( $customer_id, $amount, $target_debit_id, $ctx = array() ) {
		$target_debit_id = (int) $target_debit_id;
		if ( $target_debit_id > 0 && self::is_customer_debit_row( $customer_id, $target_debit_id ) ) {
			$ctx['target_id'] = $target_debit_id;
		}
		return self::add_credit( $customer_id, $amount, $ctx, 'debt_repay' );
	}

	/**
	 * Is the given ledger id a debit (value-taken) row belonging to this customer.
	 *
	 * @param int $customer_id Customer id.
	 * @param int $ledger_id   Ledger row id.
	 *
	 * @return bool
	 */
	private static function is_customer_debit_row( $customer_id, $ledger_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE id = %d AND customer_id = %d AND entry_type IN ('debt_incur','credit_redeem')",
				$ledger_id,
				$customer_id
			)
		);
		return ! empty( $found );
	}

	/**
	 * Customer spends store credit (redemption at the till). In the net model this
	 * is just a debit; whether they still have credit or now owe is reflected by net.
	 *
	 * @param int   $customer_id Customer id.
	 * @param float $amount      Amount redeemed.
	 * @param array $ctx         Context.
	 *
	 * @return float Amount applied as debit.
	 */
	public static function redeem_credit( $customer_id, $amount, $ctx = array() ) {
		$amount = round( (float) $amount, 4 );
		if ( empty( $customer_id ) || $amount <= 0 ) {
			return 0.0;
		}
		self::add_debit( $customer_id, $amount, $ctx, 'credit_redeem' );
		return $amount;
	}

	/**
	 * Customer takes value (on-account purchase / store-credit redemption):
	 * append a debit row and increment the cumulative debit total. Never subtracts.
	 *
	 * @param int    $customer_id Customer id.
	 * @param float  $amount      Amount taken.
	 * @param array  $ctx         Context.
	 * @param string $type        Ledger entry_type label (default 'debt_incur').
	 *
	 * @return float New net balance.
	 */
	public static function add_debit( $customer_id, $amount, $ctx = array(), $type = 'debt_incur' ) {
		$amount = round( (float) $amount, 4 );
		if ( empty( $customer_id ) || $amount <= 0 ) {
			return self::get_net( $customer_id );
		}
		$new = round( self::get_debit_total( $customer_id ) + $amount, 4 );
		update_user_meta( $customer_id, self::META_DEBIT, $new );
		
		
		self::write_ledger( $customer_id, $type, $amount, $ctx, 0.0, '' );
		return self::get_net( $customer_id );
	}

	/**
	 * Low-level ledger row writer (snapshots balances around the change).
	 *
	 * `prev_balance` is the customer's SIGNED net balance immediately BEFORE this
	 * entry (positive = store credit, negative = owed). The caller has already
	 * applied this entry's amount to the cumulative meta totals, so the previous
	 * net is the current net minus this entry's signed effect (money-in credits
	 * raise net; value-taken debits lower it).
	 *
	 * @param int    $customer_id Customer id.
	 * @param string $type credit_topup|credit_redeem|debt_incur|debt_repay.
	 * @param float  $amount Amount (positive).
	 * @param array  $ctx Context: order_id, outlet_id, counter_id, note.
	 * @param float  $remaining Deprecated/unused (column retired for prev_balance); kept for call-site compat.
	 * @param string $status Status (debt_incur only).
	 *
	 * @return int Inserted id (0 on failure).
	 */
	public static function write_ledger( $customer_id, $type, $amount, $ctx = array(), $remaining = 0.0, $status = '' ) {
		unset( $remaining ); 
		$amount       = round( (float) $amount, 4 );
		$credit_after = self::get_credit_balance( $customer_id );
		$debit_after  = self::get_debit_balance( $customer_id );
		$net_after    = round( $credit_after - $debit_after, 4 );
		
		$is_credit    = in_array( $type, array( 'credit_topup', 'debt_repay' ), true );
		$signed_delta = $is_credit ? $amount : -1 * $amount;
		$prev_balance = round( $net_after - $signed_delta, 4 );

		
		
		
		
		$outlet_id           = ! empty( $ctx['outlet_id'] ) ? (int) $ctx['outlet_id'] : 0;
		$prev_outlet_balance = 0.0;
		if ( $outlet_id > 0 ) {
			$prev_outlet_balance = self::apply_outlet_balance( $outlet_id, $is_credit, $amount );
		}

		$row = new Mapbd_Pos_Credit_Ledger();
		$row->customer_id( $customer_id );
		$row->entry_type( $type );
		$row->amount( $amount );
		$row->prev_balance( $prev_balance );
		$row->prev_outlet_balance( $prev_outlet_balance );
		$row->status( $status );
		$row->credit_balance_after( $credit_after );
		$row->debit_balance_after( $debit_after );
		$row->order_id( ! empty( $ctx['order_id'] ) ? (int) $ctx['order_id'] : 0 );
		$row->outlet_id( ! empty( $ctx['outlet_id'] ) ? (int) $ctx['outlet_id'] : 0 );
		$row->counter_id( ! empty( $ctx['counter_id'] ) ? (int) $ctx['counter_id'] : 0 );
		$row->note( isset( $ctx['note'] ) ? $ctx['note'] : '' );
		
		
		if ( ! empty( $ctx['target_id'] ) ) {
			$row->target_id( (int) $ctx['target_id'] );
		}
		$row->created_by( get_current_user_id() );
		$row->created_at( gmdate( 'Y-m-d H:i:s' ) );
		if ( ! $row->save() ) {
			return 0;
		}
		return (int) $row->id;
	}

	/**
	 * Book an amount onto an outlet's (warehouse's) running credit/debit totals and
	 * return the outlet net (credit_amount − debit_amount) as it was BEFORE this
	 * entry, for snapshotting into the ledger row's prev_outlet_balance.
	 *
	 * The increment is an atomic SQL expression so concurrent writes don't clobber
	 * each other; the returned "before" net is read in the same call.
	 *
	 * @param int   $outlet_id Warehouse/outlet id.
	 * @param bool  $is_credit True = money-in (credit_amount), false = value-taken (debit_amount).
	 * @param float $amount    Amount to book (positive).
	 *
	 * @return float Outlet net before this entry.
	 */
	private static function apply_outlet_balance( $outlet_id, $is_credit, $amount ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_warehouse';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_row(
			$wpdb->prepare( "SELECT credit_amount, debit_amount FROM {$table} WHERE id = %d", $outlet_id ),
			ARRAY_A
		);
		if ( empty( $current ) ) {
			return 0.0;
		}
		$prev_net = round( (float) $current['credit_amount'] - (float) $current['debit_amount'], 4 );
		$column   = $is_credit ? 'credit_amount' : 'debit_amount';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET {$column} = {$column} + %f WHERE id = %d", $amount, $outlet_id )
		);
		return $prev_net;
	}

	

	/**
	 * Customer summary payload.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return \stdClass
	 */
	public static function get_summary( $customer_id ) {
		if ( ! self::is_feature_enabled() ) {
			return (object) array(
				'outstanding'    => 0,
				'total_debt'     => 0,
				'open_count'     => 0,
				'credit_enabled' => 'N',
				'credit_limit'   => 0,
				'store_credit'   => 0,
				'net'            => 0,
				'total_debit'    => 0,
				'total_credit'   => 0,
			);
		}
		$snap  = self::fifo_snapshot( $customer_id );
		$limit = self::get_limit( $customer_id );
		return (object) array(
			'outstanding'    => $snap['outstanding'],
			'total_debt'     => $snap['outstanding'],
			'open_count'     => count( $snap['open_debts'] ),
			'credit_enabled' => self::is_enabled( $customer_id ) ? 'Y' : 'N',
			'credit_limit'   => $limit,
			'store_credit'   => self::get_credit_balance( $customer_id ),
			'net'            => self::get_net( $customer_id ),
			
			
			'total_debit'    => self::get_debit_total( $customer_id ),
			'total_credit'   => self::get_credit_total( $customer_id ),
		);
	}

	/**
	 * Count of open/partial debts.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return int
	 */
	public static function open_debt_count( $customer_id ) {
		$snap = self::fifo_snapshot( $customer_id );
		return count( $snap['open_debts'] );
	}

	/**
	 * Open debit rows for a customer, derived from the read-time FIFO snapshot.
	 *
	 * @param int         $customer_id Customer id.
	 * @param string|null $status Optional legacy status filter (O/P/C).
	 *
	 * @return array Rows: { id, order_id, total_amount, paid_amount, remaining_amount, status, status_label, created_at }.
	 */
	public static function get_debt_rows( $customer_id, $status = null ) {
		$snap = self::fifo_snapshot( $customer_id );
		$rows = self::get_debit_rows_raw( $customer_id );
		$byid = array();
		foreach ( $rows as $r ) {
			$byid[ $r['id'] ] = $r;
		}
		
		
		if ( 'C' === $status ) {
			return array();
		}
		$out = array();
		foreach ( $snap['open_debts'] as $d ) {
			$src   = isset( $byid[ $d['id'] ] ) ? $byid[ $d['id'] ] : array( 'order_id' => 0, 'created_at' => '' );
			$is_partial = $d['remaining'] < $d['amount'];
			$out[] = array(
				'id'               => $d['id'],
				'order_id'         => (int) $src['order_id'],
				'total_amount'     => $d['amount'],
				'paid_amount'      => $d['paid'],
				'remaining_amount' => $d['remaining'],
				'status'           => $is_partial ? 'P' : 'O',
				'status_label'     => $is_partial ? 'Partial' : 'Open',
				'created_at'       => $src['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Total number of statement rows for a customer (grid record count).
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return int
	 */
	public static function count_statement( $customer_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE customer_id = %d AND entry_type IN ('credit_topup','credit_redeem','debt_incur','debt_repay')",
				$customer_id
			)
		);
	}

	/**
	 * Full chronological account statement (all money-in and value-taken entries).
	 *
	 * type 'C' = money in (credit_topup, debt_repay); type 'D' = value taken
	 * (debt_incur, credit_redeem). `balance` is the net (store credit minus owed)
	 * recorded at the time of the entry.
	 *
	 * @param int $customer_id Customer id.
	 * @param int $limit Page size.
	 * @param int $page Page (1-based).
	 *
	 * @return array Rows: { type:'C'|'D', entry_type, amount, prev_balance, balance, order_id, note, created_at }.
	 */
	public static function get_statement( $customer_id, $limit = 50, $page = 1 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'apbd_pos_credit_ledger';
		$limit  = (int) $limit;
		$offset = max( 0, ( (int) $page - 1 ) * $limit );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE customer_id = %d AND entry_type IN ('credit_topup','credit_redeem','debt_incur','debt_repay')
				LIMIT %d OFFSET %d",
				$customer_id,
				$limit,
				$offset
			)
		);
		$credits = array( 'credit_topup', 'debt_repay' );
		$out     = array();
		foreach ( (array) $rows as $r ) {
			$net_after = round( (float) $r->credit_balance_after - (float) $r->debit_balance_after, 4 );
			$out[]     = array(
				'type'         => in_array( $r->entry_type, $credits, true ) ? 'C' : 'D',
				'entry_type'   => $r->entry_type,
				'amount'       => round( (float) $r->amount, 4 ),
				
				'prev_balance' => round( (float) $r->prev_balance, 4 ),
				'balance'      => $net_after,
				'order_id'     => (int) $r->order_id,
				'note'         => $r->note,
				'created_at'   => $r->created_at,
			);
		}
		return $out;
	}

	/**
	 * Outstanding / top-debtors report.
	 *
	 * @param int    $limit  Max rows.
	 * @param string $order  ASC|DESC.
	 * @param string $search Optional search term matched against name, email or phone.
	 *
	 * @return array Rows: { customer_id, display_name, user_email, debt_count, total_debit, total_credit, outstanding, store_credit }.
	 */
	public static function get_outstanding_report( $limit = 50, $order = 'DESC', $search = '' ) {
		global $wpdb;
		$search = trim( (string) $search );
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ledger_ids = $wpdb->get_col(
			"SELECT DISTINCT customer_id FROM {$table}
			 WHERE entry_type IN ('debt_incur','credit_redeem')"
		);
		
		$enabled_query = new \WP_User_Query(
			array(
				'role__in'   => array( 'customer', 'subscriber' ),
				'fields'     => 'ID',
				'number'     => -1,
				'meta_key'   => self::META_ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'Y',                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$enabled_ids = array_map( 'intval', (array) $enabled_query->get_results() );
		$enabled_map = array_flip( $enabled_ids );
		
		$ids = array_values( array_unique( array_merge( array_map( 'intval', (array) $ledger_ids ), $enabled_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		
		
		
		
		if ( '' !== $search ) {
			$name_match = new \WP_User_Query(
				array(
					'include'        => $ids,
					'fields'         => 'ID',
					'number'         => -1,
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				)
			);
			$meta_match = new \WP_User_Query(
				array(
					'include'    => $ids,
					'fields'     => 'ID',
					'number'     => -1,
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'first_name',
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'last_name',
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'billing_phone',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				)
			);
			$ids = array_values(
				array_unique(
					array_merge(
						array_map( 'intval', (array) $name_match->get_results() ),
						array_map( 'intval', (array) $meta_match->get_results() )
					)
				)
			);
			if ( empty( $ids ) ) {
				return array();
			}
		}

		$out = array();
		foreach ( $ids as $cid ) {
			$cid         = (int) $cid;
			$is_enabled  = isset( $enabled_map[ $cid ] );
			$snap        = self::fifo_snapshot( $cid );
			$outstanding = (float) $snap['outstanding'];
			
			if ( $outstanding <= 0 && ! $is_enabled ) {
				continue;
			}
			$user  = get_userdata( $cid );
			$out[] = array(
				'customer_id'    => $cid,
				'display_name'   => $user->first_name ? ($user->first_name.' '.$user->last_name) : $user->display_name,
				'user_email'     => $user ? $user->user_email : '',
				'phone'          => $user ? get_user_meta( $cid, 'billing_phone', true ) : '',
				'debt_count'     => count( $snap['open_debts'] ),
				'total_debit'    => self::get_debit_total( $cid ),
				'total_credit'   => self::get_credit_total( $cid ),
				'outstanding'    => $outstanding,
				
				
				
				'store_credit'   => self::get_credit_balance( $cid ),
				'credit_enabled' => $is_enabled,
			);
		}
		usort(
			$out,
			function ( $a, $b ) use ( $order ) {
				
				$a_has = $a['outstanding'] > 0 ? 1 : 0;
				$b_has = $b['outstanding'] > 0 ? 1 : 0;
				if ( $a_has !== $b_has ) {
					return $b_has <=> $a_has;
				}
				if ( $a_has ) {
					return ( 'ASC' === strtoupper( $order ) )
						? ( $a['outstanding'] <=> $b['outstanding'] )
						: ( $b['outstanding'] <=> $a['outstanding'] );
				}
				
				return strcasecmp( $a['display_name'], $b['display_name'] );
			}
		);
		return array_slice( $out, 0, (int) $limit );
	}

	/**
	 * Per-customer FIFO allocation log ("actual scenario") — debt only.
	 *
	 * Walks the debt_incur rows oldest-first, draining the cumulative credit pool, so
	 * each order shows how much pooled credit FIFO applied to it and what remains.
	 * Unlike fifo_snapshot()/compute(), settled rows are kept (the full story).
	 *
	 * credit_redeem rows are excluded: they are self-funded (store credit the customer
	 * already held, spent at the till), not debt. Their funding is reserved from the
	 * pool first and netted out of the summary's pool/debit totals so the visible rows
	 * still reconcile with the headers.
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return array {
	 *     rows:    array of { order_id, created_at, amount, applied, remaining, age_days, status },
	 *     summary: object { total_debit, credit_pool, credit_applied, leftover_credit, outstanding }
	 * }
	 */
	public static function get_fifo_log( $customer_id ) {
		$debits           = self::get_debit_rows_raw( $customer_id );
		$credit_pool      = self::get_credit_total( $customer_id );
		$tag_by_debit     = self::get_tagged_credits_by_debit( $customer_id );
		$now              = time();
		$rows             = array();
		$credit_applied   = 0.0;
		$outstanding      = 0.0;
		$debt_total       = 0.0;

		
		
		
		
		
		$targeted_sum = 0.0;
		foreach ( $debits as &$d ) {
			$tag                = isset( $tag_by_debit[ $d['id'] ] ) ? $tag_by_debit[ $d['id'] ] : 0.0;
			$tp                 = min( round( (float) $d['amount'], 4 ), max( 0.0, round( (float) $tag, 4 ) ) );
			$d['targeted_paid'] = $tp;
			$targeted_sum       = round( $targeted_sum + $tp, 4 );
		}
		unset( $d );

		
		
		$remaining_credit = round( max( 0.0, (float) $credit_pool - $targeted_sum ), 4 );

		
		
		
		
		
		$reserved = 0.0;
		foreach ( $debits as $d ) {
			if ( 'credit_redeem' !== $d['entry_type'] ) {
				continue;
			}
			$amount           = round( (float) $d['amount'], 4 );
			$targeted         = isset( $d['targeted_paid'] ) ? $d['targeted_paid'] : 0.0;
			$take             = min( max( 0.0, $amount - $targeted ), max( 0.0, $remaining_credit ) );
			$remaining_credit = round( $remaining_credit - $take, 4 );
			$reserved         = round( $reserved + $take, 4 );
		}

		
		
		foreach ( $debits as $d ) {
			if ( 'credit_redeem' === $d['entry_type'] ) {
				continue;
			}
			$amount = round( (float) $d['amount'], 4 );
			if ( $amount <= 0 ) {
				continue;
			}
			$targeted         = isset( $d['targeted_paid'] ) ? $d['targeted_paid'] : 0.0;
			$fifo_paid        = min( $amount - $targeted, max( 0.0, $remaining_credit ) );
			$remaining_credit = round( $remaining_credit - $fifo_paid, 4 );
			$applied          = round( $targeted + $fifo_paid, 4 );
			$rem              = round( $amount - $applied, 4 );
			$age_days         = max( 0, (int) floor( ( $now - (int) $d['created_at_ts'] ) / 86400 ) );
			$rows[]           = array(
				'order_id'   => (int) $d['order_id'],
				'created_at' => $d['created_at'],
				'amount'     => $amount,
				'applied'    => round( $applied, 4 ),
				'remaining'  => $rem,
				'age_days'   => $age_days,
				'status'     => ( $rem <= 0 ) ? 'settled' : 'open',
			);
			$credit_applied = round( $credit_applied + $applied, 4 );
			$outstanding    = round( $outstanding + $rem, 4 );
			$debt_total     = round( $debt_total + $amount, 4 );
		}
		$summary = (object) array(
			'total_debit'     => $debt_total,
			'credit_pool'     => round( max( 0.0, (float) $credit_pool - $reserved ), 4 ),
			'credit_applied'  => $credit_applied,
			'leftover_credit' => round( max( 0.0, $remaining_credit ), 4 ),
			'outstanding'     => $outstanding,
		);
		return array(
			'rows'    => $rows,
			'summary' => $summary,
		);
	}

	/**
	 * Aging report buckets (by age of each open debt_incur row).
	 *
	 * @return array { b0_30, b31_60, b61_90, b90_plus }.
	 */
	public static function get_aging_report() {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT DISTINCT customer_id FROM {$table}
			 WHERE entry_type IN ('debt_incur','credit_redeem')"
		);
		$buckets = array( 'b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0 );
		foreach ( (array) $ids as $cid ) {
			$snap = self::fifo_snapshot( (int) $cid );
			foreach ( $buckets as $k => $v ) {
				$buckets[ $k ] = round( $v + $snap['aging'][ $k ], 4 );
			}
		}
		return $buckets;
	}

	

	/**
	 * Outlet-wise debit/credit summary across the whole ledger.
	 *
	 * One row per outlet that has ledger activity. Debit = value taken
	 * (debt_incur, credit_redeem); credit = money in (credit_topup, debt_repay).
	 * `net` is credit minus debit for the outlet.
	 *
	 * @return array Rows: { outlet_id, outlet_name, entry_count, total_debit, total_credit, net }.
	 */
	public static function get_outlet_report() {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT outlet_id,
					COUNT(*) AS entry_count,
					SUM(CASE WHEN entry_type IN ('credit_topup','debt_repay') THEN amount ELSE 0 END) AS total_credit,
					SUM(CASE WHEN entry_type IN ('debt_incur','credit_redeem') THEN amount ELSE 0 END) AS total_debit
			 FROM {$table}
			 WHERE entry_type IN ('credit_topup','credit_redeem','debt_incur','debt_repay')
			 GROUP BY outlet_id
			 ORDER BY outlet_id ASC",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$outlet_id    = (int) $r['outlet_id'];
			$total_debit  = round( (float) $r['total_debit'], 4 );
			$total_credit = round( (float) $r['total_credit'], 4 );
			$name         = $outlet_id > 0 ? Mapbd_Pos_Warehouse::get_outlet_name_by_id( $outlet_id ) : '';
			$out[]        = array(
				'outlet_id'    => $outlet_id,
				'outlet_name'  => ! empty( $name ) ? $name : ( $outlet_id > 0 ? ( '#' . $outlet_id ) : __( 'Unassigned', 'vitepos' ) ),
				'entry_count'  => (int) $r['entry_count'],
				'total_debit'  => $total_debit,
				'total_credit' => $total_credit,
				'net'          => round( $total_credit - $total_debit, 4 ),
			);
		}
		return $out;
	}

	/**
	 * Total number of ledger rows recorded against an outlet (grid record count).
	 *
	 * @param int $outlet_id Outlet id.
	 *
	 * @return int
	 */
	public static function count_outlet_ledger( $outlet_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'apbd_pos_credit_ledger';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE outlet_id = %d AND entry_type IN ('credit_topup','credit_redeem','debt_incur','debt_repay')",
				$outlet_id
			)
		);
	}

	/**
	 * Chronological debit/credit ledger log for a single outlet.
	 *
	 * Same row shape as get_statement() but scoped to one outlet and carrying the
	 * customer id so the admin log can show who each entry belongs to.
	 *
	 * @param int $outlet_id Outlet id.
	 * @param int $limit Page size.
	 * @param int $page Page (1-based).
	 *
	 * @return array Rows: { type:'C'|'D', entry_type, customer_id, amount, prev_balance, balance, order_id, note, created_at }.
	 */
	public static function get_outlet_ledger( $outlet_id, $limit = 20, $page = 1 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'apbd_pos_credit_ledger';
		$limit  = (int) $limit;
		$offset = max( 0, ( (int) $page - 1 ) * $limit );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE outlet_id = %d AND entry_type IN ('credit_topup','credit_redeem','debt_incur','debt_repay')
				 LIMIT %d OFFSET %d",
				$outlet_id,
				$limit,
				$offset
			)
		);
		$credits = array( 'credit_topup', 'debt_repay' );
		$out     = array();
		foreach ( (array) $rows as $r ) {
			$net_after = round( (float) $r->credit_balance_after - (float) $r->debit_balance_after, 4 );
			$out[]     = array(
				'type'         => in_array( $r->entry_type, $credits, true ) ? 'C' : 'D',
				'entry_type'   => $r->entry_type,
				'customer_id'  => (int) $r->customer_id,
				'amount'       => round( (float) $r->amount, 4 ),
				'prev_balance' => round( (float) $r->prev_balance, 4 ),
				'balance'      => $net_after,
				'order_id'     => (int) $r->order_id,
				'note'         => $r->note,
				'created_at'   => appsbd_get_wp_datetime_with_format($r->created_at),
			);
		}
		return $out;
	}

	

	/**
	 * Credit info payload (same keys the frontend already consumes).
	 *
	 * @param int $customer_id Customer id.
	 *
	 * @return \stdClass
	 */
	public static function get_credit_info( $customer_id ) {
		if ( ! self::is_feature_enabled() ) {
			return (object) array(
				'credit_enabled'   => 'N',
				'credit_limit'     => 0,
				'current_debt'     => 0,
				'available_credit' => 0,
				'store_credit'     => 0,
			);
		}
		$enabled = self::is_enabled( $customer_id );
		$limit   = self::get_limit( $customer_id );
		$debt    = self::get_debit_balance( $customer_id );
		return (object) array(
			'credit_enabled'   => $enabled ? 'Y' : 'N',
			'credit_limit'     => $limit,
			'current_debt'     => $debt,
			'available_credit' => max( 0, $limit - $debt ),
			'store_credit'     => self::get_credit_balance( $customer_id ),
		);
	}

	/**
	 * Attach credit info to a user payload (keys unchanged).
	 *
	 * @param \stdClass $users_obj User payload (by reference).
	 * @param int       $customer_id Customer id.
	 *
	 * @return void
	 */
	public static function attach_to_user_object( &$users_obj, $customer_id ) {
		if ( empty( $customer_id ) ) {
			return;
		}
		
		$info                        = self::get_credit_info( $customer_id );
		$users_obj->credit_enabled     = $info->credit_enabled;
		$users_obj->credit_limit       = $info->credit_limit;
		$users_obj->current_debt       = $info->current_debt;
		$users_obj->available_credit   = $info->available_credit;
		$users_obj->store_credit       = $info->store_credit;
		$users_obj->current_balance    = self::get_net($customer_id);
	}
}
