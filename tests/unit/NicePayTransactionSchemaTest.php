<?php
/**
 * Tests for the pure NicePay transaction schema definition.
 */

use PHPUnit\Framework\TestCase;

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-transaction-schema.php';

class NicePayTransactionSchemaTest extends TestCase {

	public function test_create_table_sql_is_plain_and_dbdelta_compatible(): void {
		$sql = NicePay_Transaction_Schema::create_table_sql(
			'wp_nicepay_transactions',
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_transactions (', $sql );
		$this->assertStringNotContainsString( 'IF NOT EXISTS', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_moid (moid)', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_tid (tid)', $sql );
		$this->assertStringEndsWith( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $sql );
	}

	public function test_schema_preserves_non_sensitive_legacy_columns_and_adds_r02_lifecycle_fields(): void {
		$columns = NicePay_Transaction_Schema::columns();

		$legacy = array(
			'id', 'tid', 'order_id', 'wc_order_id', 'moid', 'amount', 'payment_method',
			'pay_method_name', 'status', 'result_code', 'result_msg', 'auth_token',
			'buyer_name', 'buyer_email', 'buyer_tel', 'goods_name', 'card_code',
			'card_name', 'card_quota', 'bank_code', 'bank_name',
			'vbank_num', 'vbank_exp_date', 'payment_data', 'created_at', 'updated_at',
		);
		$added = array(
				'flow', 'source_ref', 'active_attempt_key', 'config_fingerprint', 'expected_method', 'allowed_methods',
			'binding_token_hash', 'wc_order_key_hash', 'edi_date', 'offer_expires_at',
			'mid', 'mode', 'currency', 'captured_amount', 'refunded_amount',
			'remaining_amount', 'approval_state', 'approval_attempts',
			'approval_started_at', 'approved_at', 'reconciliation_status',
			'reconciliation_checked_at', 'reconciliation_note', 'receipt_token_hash',
			'receipt_issued_at', 'otid', 'cancel_moid',
			'cancel_status', 'cancel_amount', 'cancel_result_code', 'cancel_result_msg',
			'cancel_requested_at', 'cancel_completed_at', 'net_cancel_status',
			'net_cancel_result_code', 'net_cancel_result_msg', 'net_cancel_requested_at',
			'net_cancel_completed_at', 'vbank_issued_at', 'vbank_expires_at',
			'vbank_deposited_at',
			'cc_part_cl', 'clickpay_cl', 'card_type',
		);

		foreach ( array_merge( $legacy, $added ) as $column ) {
			$this->assertArrayHasKey( $column, $columns, $column );
		}
		$this->assertArrayNotHasKey( 'card_no', $columns, 'Fresh installs must not create a PAN storage column.' );
	}

	public function test_indexes_cover_operational_queries(): void {
		$indexes = NicePay_Transaction_Schema::indexes();

		$this->assertContains( 'KEY idx_status_created (status, created_at)', $indexes );
		$this->assertContains( 'KEY idx_created_at (created_at)', $indexes );
		$this->assertContains( 'KEY idx_flow_status (flow, status)', $indexes );
		$this->assertContains( 'KEY idx_source_ref (flow, source_ref)', $indexes );
		$this->assertContains( 'KEY idx_reconciliation_status (reconciliation_status)', $indexes );
		$this->assertContains( 'KEY idx_updated_at (updated_at)', $indexes );
		$this->assertContains( 'KEY idx_receipt_token_hash (receipt_token_hash)', $indexes );
		$this->assertContains( 'KEY idx_vbank_expires_at (vbank_expires_at)', $indexes );
		$this->assertContains( 'UNIQUE KEY uniq_active_attempt (active_attempt_key)', $indexes );
	}

	public function test_refund_attempt_schema_is_append_only_and_indexed(): void {
		$sql = NicePay_Transaction_Schema::create_refund_table_sql(
			'wp_nicepay_refund_attempts',
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		$this->assertStringContainsString( 'transaction_id bigint(20) UNSIGNED NOT NULL', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_cancel_moid (cancel_moid)', $sql );
		$this->assertStringContainsString( 'KEY idx_transaction_created (transaction_id, created_at)', $sql );
		$this->assertStringNotContainsString( 'buyer_email', $sql );
	}

	public function test_prepare_write_whitelists_columns_and_aligns_formats(): void {
		$result = NicePay_Transaction_Schema::prepare_write(
			array(
				'moid'              => 'SP_123',
				'wc_order_id'       => 42,
				'captured_amount'   => '1000.00',
				'approval_attempts' => 1,
				'unknown_column'    => 'drop me',
				'id'                => 99,
			)
		);

		$this->assertSame(
			array(
				'moid'              => 'SP_123',
				'wc_order_id'       => 42,
				'captured_amount'   => '1000.00',
				'approval_attempts' => 1,
			),
			$result['data']
		);
		$this->assertSame( array( '%s', '%d', '%s', '%d' ), $result['formats'] );
	}

	public function test_invalid_table_name_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		NicePay_Transaction_Schema::create_table_sql( 'wp_transactions; DROP TABLE wp_users' );
	}
}
