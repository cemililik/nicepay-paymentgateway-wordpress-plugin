<?php
/**
 * Tests for transaction write whitelisting and atomic approval claims.
 */

use PHPUnit\Framework\TestCase;

class NicePayTransactionRepositoryWpdbFake {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 17;
    public $insert_data = array();
    public $insert_formats = array();
    public $query_result = 1;
    public $last_query = '';
    public $update_result = 1;
    public $update_data = array();
    public $update_where = array();
    public $get_var_result = 0;
    public $get_row_result = null;
    public $get_results_result = array();

    public function insert( $table, $data, $formats ) {
        $this->insert_data    = $data;
        $this->insert_formats = $formats;
        return 1;
    }

    public function prepare( $query, ...$values ) {
        if ( 1 === count( $values ) && is_array( $values[0] ) ) {
            $values = $values[0];
        }
        foreach ( $values as $value ) {
            $replacement = is_int( $value ) ? (string) $value : "'" . addslashes( $value ) . "'";
            $query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }
        return $query;
    }

    public function query( $query ) {
        $this->last_query = $query;
        return $this->query_result;
    }

    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        $this->update_data  = $data;
        $this->update_where = $where;
        return $this->update_result;
    }

    public function get_var( $query ) {
        $this->last_query = $query;
        return $this->get_var_result;
    }

    public function get_row( $query ) {
        $this->last_query = $query;
        return $this->get_row_result;
    }

    public function get_results( $query ) {
        $this->last_query = $query;
        return $this->get_results_result;
    }
}

class NicePayAbortApiFake {
    public $response;
    public $contexts = array();

    public function __construct( $response ) {
        $this->response = $response;
    }

    public function request_net_cancel( $context ) {
        $this->contexts[] = $context;
        return $this->response;
    }
}

class NicePayTransactionRepositoryTest extends TestCase {

    private $previous_wpdb;

    protected function setUp(): void {
        global $wpdb;
        $this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
        $wpdb = new NicePayTransactionRepositoryWpdbFake();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
    }

    public function test_save_transaction_drops_unknown_columns_and_aligns_formats(): void {
        global $wpdb;

        $result = nicepay_save_transaction(
            array(
                'moid'           => 'SP_123',
                'wc_order_id'    => 42,
                'unknown_column' => 'must-not-reach-sql',
            )
        );

        $this->assertSame( 17, $result );
        $this->assertArrayNotHasKey( 'unknown_column', $wpdb->insert_data );
        $this->assertSame( 'SP_123', $wpdb->insert_data['moid'] );
        $this->assertSame( 42, $wpdb->insert_data['wc_order_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $wpdb->insert_data['created_at'] );
		$this->assertSame( $wpdb->insert_data['created_at'], $wpdb->insert_data['updated_at'] );
        $this->assertCount( count( $wpdb->insert_data ), $wpdb->insert_formats );
    }

    public function test_claim_uses_atomic_pending_to_approving_compare_and_set(): void {
        global $wpdb;

        $this->assertTrue( nicepay_claim_transaction_for_approval( 17, 'standalone' ) );
        $this->assertStringContainsString( "status = 'approving'", $wpdb->last_query );
        $this->assertStringContainsString( "approval_state = 'approving'", $wpdb->last_query );
        $this->assertStringContainsString( "status = 'pending'", $wpdb->last_query );
        $this->assertStringContainsString( "approval_state = 'pending'", $wpdb->last_query );
        $this->assertStringContainsString( "flow = 'standalone'", $wpdb->last_query );
    }

    public function test_claim_fails_for_replay_or_invalid_flow(): void {
        global $wpdb;
        $wpdb->query_result = 0;

        $this->assertFalse( nicepay_claim_transaction_for_approval( 17, 'standalone' ) );
        $this->assertFalse( nicepay_claim_transaction_for_approval( 17, 'unexpected' ) );
    }

    public function test_woocommerce_claim_atomically_abandons_sibling_attempts_for_the_same_order(): void {
        global $wpdb;

        $this->assertTrue( nicepay_claim_transaction_for_approval( 17, 'woocommerce' ) );
        $this->assertStringContainsString( 'INNER JOIN wp_nicepay_transactions AS target', $wpdb->last_query );
        $this->assertStringContainsString( "target.flow = 'woocommerce'", $wpdb->last_query );
        $this->assertStringContainsString( "target.source_ref <> ''", $wpdb->last_query );
        $this->assertStringContainsString( "'approving', 'abandoned'", $wpdb->last_query );
        $this->assertStringContainsString( 'candidate.source_ref = target.source_ref', $wpdb->last_query );
        $this->assertStringContainsString( 'candidate.active_attempt_key', $wpdb->last_query );
    }

    public function test_abandon_pending_attempts_is_scoped_to_flow_and_server_source(): void {
        global $wpdb;

        $this->assertTrue( nicepay_abandon_pending_transactions( 'woocommerce', '42' ) );
        $this->assertStringContainsString( "status = 'abandoned'", $wpdb->last_query );
        $this->assertStringContainsString( "flow = 'woocommerce'", $wpdb->last_query );
        $this->assertStringContainsString( "source_ref = '42'", $wpdb->last_query );
        $this->assertStringContainsString( "status = 'pending'", $wpdb->last_query );
        $this->assertStringContainsString( 'active_attempt_key = NULL', $wpdb->last_query );
    }

    public function test_refund_claim_is_atomic_and_blocks_unknown_or_inflight_states(): void {
        global $wpdb;

        $this->assertTrue( nicepay_claim_transaction_for_refund( 17, 'RF42_20260820120000_0123456789abcdef', '500' ) );
        $this->assertStringContainsString( "cancel_status = 'requested'", $wpdb->last_query );
        $this->assertStringContainsString( "status IN ('paid', 'partially_refunded')", $wpdb->last_query );
        $this->assertStringContainsString( "cancel_status NOT IN ('requested', 'unknown')", $wpdb->last_query );
        $this->assertStringContainsString( 'remaining_amount >=', $wpdb->last_query );

        $wpdb->query_result = 0;
        $this->assertFalse( nicepay_claim_transaction_for_refund( 17, 'RF42_20260820120000_fedcba9876543210', '500' ) );
    }

    public function test_refund_completion_requires_the_exact_reserved_cancel_attempt(): void {
        global $wpdb;

        $this->assertTrue( nicepay_complete_transaction_refund(
            17,
            'RF42_20260820120000_0123456789abcdef',
            array(
                'status'           => 'partially_refunded',
                'cancel_status'    => 'confirmed',
                'refunded_amount'  => '500',
                'remaining_amount' => '500',
            )
        ) );
        $this->assertSame(
            array(
                'id'            => 17,
                'cancel_moid'   => 'RF42_20260820120000_0123456789abcdef',
                'cancel_status' => 'requested',
            ),
            $wpdb->update_where
        );

        $wpdb->update_result = 0;
        $this->assertFalse( nicepay_complete_transaction_refund(
            17,
            'RF42_20260820120000_0123456789abcdef',
            array( 'cancel_status' => 'confirmed' )
        ) );
    }

    public function test_refund_attempt_history_is_created_before_transport_and_completed_by_identity(): void {
        global $wpdb;

        $attempt_id = nicepay_save_refund_attempt( array(
            'transaction_id'   => 17,
            'wc_order_id'      => 42,
            'tid'              => 'nicepay00m01012006221311045107',
            'cancel_moid'      => 'RF42_20260820120000_0123456789abcdef',
            'requested_amount' => '500',
            'reason'           => 'Customer request',
        ) );

        $this->assertSame( 17, $attempt_id );
        $this->assertSame( 'requested', $wpdb->insert_data['status'] );
        $this->assertSame( '500', $wpdb->insert_data['requested_amount'] );

        $this->assertTrue( nicepay_complete_refund_attempt(
            $attempt_id,
            'RF42_20260820120000_0123456789abcdef',
            array( 'status' => 'confirmed', 'result_code' => '2001' )
        ) );
        $this->assertSame(
            array(
                'id'          => 17,
                'cancel_moid' => 'RF42_20260820120000_0123456789abcdef',
                'status'      => 'requested',
            ),
            $wpdb->update_where
        );
    }

    public function test_strict_update_rejects_zero_affected_rows_while_idempotent_update_allows_it(): void {
        global $wpdb;

        $wpdb->update_result = 0;

        $this->assertTrue( nicepay_update_transaction( 17, array( 'status' => 'paid' ) ) );
        $this->assertFalse( nicepay_update_transaction( 17, array( 'status' => 'paid' ), true ) );
    }

	public function test_reconciliation_capture_is_atomic_and_appends_actor_reason_audit(): void {
		global $wpdb;
		$wpdb->get_row_result = (object) array(
			'id'                    => 17,
			'status'                => 'needs_reconciliation',
			'reconciliation_status' => 'required',
			'amount'                => '1000.00',
			'refunded_amount'       => '0.00',
		);

		$result = nicepay_resolve_reconciliation( 17, 'captured', 'Console case NC-42 verified', 9 );

		$this->assertSame( array( 'decision' => 'captured', 'status' => 'paid', 'amount' => '1000' ), $result );
		$this->assertSame( 'paid', $wpdb->update_data['status'] );
		$this->assertSame( 'approved', $wpdb->update_data['approval_state'] );
		$this->assertSame( 'resolved', $wpdb->update_data['reconciliation_status'] );
		$this->assertSame( 17, $wpdb->insert_data['transaction_id'] );
		$this->assertSame( 9, $wpdb->insert_data['actor_id'] );
		$this->assertSame( 'captured', $wpdb->insert_data['action'] );
		$this->assertSame( 'Console case NC-42 verified', $wpdb->insert_data['reason'] );
	}

	public function test_reconciliation_requires_reason_and_only_forward_pending_state(): void {
		global $wpdb;

		$missing_reason = nicepay_resolve_reconciliation( 17, 'reversed', '  ', 9 );
		$this->assertInstanceOf( WP_Error::class, $missing_reason );
		$this->assertSame( 'nicepay_reconciliation_reason_required', $missing_reason->get_error_code() );

		$wpdb->get_row_result = (object) array(
			'id'                    => 17,
			'status'                => 'paid',
			'reconciliation_status' => 'resolved',
			'amount'                => '1000',
			'refunded_amount'       => '0',
		);
		$state_changed = nicepay_resolve_reconciliation( 17, 'reversed', 'Verified', 9 );
		$this->assertInstanceOf( WP_Error::class, $state_changed );
		$this->assertSame( 'nicepay_reconciliation_state_changed', $state_changed->get_error_code() );
	}

    public function test_preapproval_abort_persists_a_confirmed_network_cancel(): void {
        global $wpdb;
		$posted_context = array( 'AuthToken' => 'attacker-token', 'TxTid' => 'attacker-tid', 'Amt' => '9999', 'NetCancelURL' => 'https://dc1-api.nicepay.co.kr/webapi/cancel_process.jsp' );
		$local_context  = $this->claimedAuthRow();
		$wpdb->get_row_result = $local_context;
        $api     = new NicePayAbortApiFake( array( 'ResultCode' => '2001', 'ResultMsg' => 'cancelled' ) );

		$result = nicepay_abort_authenticated_payment( 17, $posted_context, $api, 'order_snapshot_changed' );

        $this->assertFalse( $result['needs_reconciliation'] );
        $this->assertTrue( $result['persisted'] );
		$this->assertSame( 'verified-token', $api->contexts[0]['AuthToken'] );
		$this->assertSame( 'verified-tid', $api->contexts[0]['TxTid'] );
		$this->assertSame( '1004', $api->contexts[0]['Amt'] );
		$this->assertNotSame( $posted_context, $api->contexts[0] );
        $this->assertSame( 'failed', $wpdb->update_data['status'] );
        $this->assertSame( 'confirmed', $wpdb->update_data['net_cancel_status'] );
        $this->assertSame( '', $wpdb->update_data['auth_token'] );
        $this->assertNull( $wpdb->update_data['active_attempt_key'] );
    }

    public function test_preapproval_abort_preserves_context_for_unknown_network_cancel(): void {
        global $wpdb;
        $context = array( 'AuthToken' => 'verified-token', 'TxTid' => 'verified-tid', 'Amt' => '1004' );
		$wpdb->get_row_result = $this->claimedAuthRow();
        $api     = new NicePayAbortApiFake( new WP_Error( 'nicepay_net_cancel_timeout', 'timeout' ) );

        $result = nicepay_abort_authenticated_payment( 17, $context, $api, 'order_missing' );

        $this->assertTrue( $result['needs_reconciliation'] );
        $this->assertTrue( $result['persisted'] );
        $this->assertSame( 'needs_reconciliation', $wpdb->update_data['status'] );
        $this->assertSame( 'required', $wpdb->update_data['reconciliation_status'] );
        $this->assertSame( 'unknown', $wpdb->update_data['net_cancel_status'] );
        $this->assertSame( 'verified-token', $wpdb->update_data['auth_token'] );
    }

	public function test_net_cancel_is_not_attempted_without_a_claimed_local_context(): void {
		global $wpdb;
		$wpdb->get_row_result = null;
		$api = new NicePayAbortApiFake( array( 'ResultCode' => '2001' ) );

		$result = nicepay_abort_authenticated_payment(
			17,
			array( 'AuthToken' => 'posted-token', 'TxTid' => 'posted-tid', 'Amt' => '1004' ),
			$api,
			'local_context_missing'
		);

		$this->assertTrue( $result['needs_reconciliation'] );
		$this->assertSame( array(), $api->contexts );
		$this->assertSame( 'nicepay_local_auth_context_missing', $result['net_cancel_result_code'] );
	}

    public function test_mismatched_approval_requires_reconciliation_even_when_original_auth_is_reversed(): void {
        $audit = nicepay_get_mismatched_approval_audit(
            new WP_Error( 'nicepay_approval_tid_mismatch', 'mismatch' ),
            array( 'ResultCode' => '2001', 'ResultMsg' => 'cancelled' ),
            'verified-token'
        );

        $this->assertSame( 'needs_reconciliation', $audit['status'] );
        $this->assertSame( 'required', $audit['reconciliation_status'] );
        $this->assertSame( 'nicepay_approval_tid_mismatch', $audit['reconciliation_note'] );
        $this->assertSame( 'confirmed', $audit['net_cancel_status'] );
        $this->assertSame( '2001', $audit['net_cancel_result_code'] );
        $this->assertSame( 'verified-token', $audit['auth_token'] );
        $this->assertArrayNotHasKey( 'active_attempt_key', $audit );
    }

    public function test_mismatched_approval_records_unknown_original_auth_reversal(): void {
        $audit = nicepay_get_mismatched_approval_audit(
            new WP_Error( 'nicepay_approval_amount_mismatch', 'mismatch' ),
            new WP_Error( 'nicepay_net_cancel_timeout', 'timeout' ),
            'verified-token'
        );

        $this->assertSame( 'needs_reconciliation', $audit['status'] );
        $this->assertSame( 'unknown', $audit['net_cancel_status'] );
        $this->assertSame( 'nicepay_net_cancel_timeout', $audit['net_cancel_result_code'] );
        $this->assertNull( $audit['net_cancel_completed_at'] );
    }

    public function test_expiry_job_escalates_stale_approvals_for_reconciliation(): void {
        global $wpdb;

        $this->assertSame( 2, nicepay_expire_pending_transactions() );
        $this->assertStringContainsString( "status = 'needs_reconciliation'", $wpdb->last_query );
        $this->assertStringContainsString( "reconciliation_note = 'stale_approval_attempt'", $wpdb->last_query );
		$this->assertStringContainsString( 'active_attempt_key = NULL', $wpdb->last_query );
		$this->assertStringContainsString( "auth_token = ''", $wpdb->last_query );
        $this->assertStringContainsString( 'INTERVAL 30 MINUTE', $wpdb->last_query );
    }

    public function test_reconciliation_count_includes_every_unknown_money_state(): void {
        global $wpdb;

        $wpdb->get_var_result = 4;

        $this->assertSame( 4, nicepay_get_reconciliation_count() );
        $this->assertStringContainsString( "status = 'needs_reconciliation'", $wpdb->last_query );
        $this->assertStringContainsString( "reconciliation_status = 'required'", $wpdb->last_query );
        $this->assertStringContainsString( "cancel_status = 'unknown'", $wpdb->last_query );
        $this->assertStringContainsString( "net_cancel_status = 'unknown'", $wpdb->last_query );
    }

    public function test_default_ledger_page_selects_only_display_columns_in_created_order(): void {
        global $wpdb;

        nicepay_get_transactions();

        $this->assertStringStartsWith( 'SELECT id, tid, wc_order_id, moid, currency', $wpdb->last_query );
        $this->assertStringNotContainsString( 'SELECT *', $wpdb->last_query );
        $this->assertStringContainsString( 'ORDER BY created_at DESC LIMIT 20 OFFSET 0', $wpdb->last_query );
    }

    public function test_standalone_receipt_persists_only_a_token_hash_and_resolves_safe_states(): void {
        global $wpdb;

        $issued = nicepay_issue_standalone_receipt( 17 );

        $this->assertIsArray( $issued );
        $this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $issued['token'] );
        $this->assertStringContainsString( 'nicepay_receipt=', $issued['url'] );
        $this->assertSame( hash( 'sha256', $issued['token'] ), $wpdb->update_data['receipt_token_hash'] );
        $this->assertStringNotContainsString( $issued['token'], $wpdb->update_data['receipt_token_hash'] );

        $row                  = (object) array( 'id' => 17, 'status' => 'paid' );
        $wpdb->get_row_result = $row;
        $this->assertSame( $row, nicepay_get_standalone_receipt( $issued['token'] ) );
        $this->assertStringContainsString( "flow = 'standalone'", $wpdb->last_query );
        $this->assertStringContainsString( "status IN ('paid', 'partially_refunded', 'refunded')", $wpdb->last_query );
        $this->assertNull( nicepay_get_standalone_receipt( '../invalid' ) );
    }

    public function test_refund_attempt_history_is_loaded_in_one_bounded_query_and_grouped(): void {
        global $wpdb;

        $first = (object) array( 'id' => 2, 'transaction_id' => 17 );
        $second = (object) array( 'id' => 1, 'transaction_id' => 17 );
        $wpdb->get_results_result = array( $first, $second );

        $grouped = nicepay_get_refund_attempts_for_transactions( array( 17, 17, 0 ) );

        $this->assertSame( array( 17 => array( $first, $second ) ), $grouped );
        $this->assertStringContainsString( 'transaction_id IN (17)', $wpdb->last_query );
        $this->assertStringContainsString( 'ORDER BY created_at DESC, id DESC', $wpdb->last_query );
    }

	private function claimedAuthRow(): object {
		return (object) array(
			'id'             => 17,
			'status'         => 'approving',
			'approval_state' => 'approving',
			'next_app_url'   => 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp',
			'net_cancel_url' => 'https://dc1-api.nicepay.co.kr/webapi/cancel_process.jsp',
			'tid'            => 'verified-tid',
			'auth_token'     => 'verified-token',
			'amount'         => '1004.00',
			'mid'            => NICEPAY_TEST_MID,
			'moid'           => 'SP_ORDER_1',
			'expected_method'=> 'CARD',
		);
	}
}
