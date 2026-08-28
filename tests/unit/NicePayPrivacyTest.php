<?php
/**
 * Personal-data exporter/eraser contract tests.
 */

use PHPUnit\Framework\TestCase;

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-privacy.php';

final class NicePayPrivacyWpdbFake {
	public $prefix = 'wp_';
	public $rows = array();
	public $refund_rows = array();
	public $last_query = '';
	public $queries = array();
	public $query_result = 0;

	public function prepare( $query, ...$values ) {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_results( $query ) {
		$this->last_query = $query;
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'nicepay_refund_attempts' ) ) {
			return $this->refund_rows;
		}
		return $this->rows;
	}

	public function query( $query ) {
		$this->last_query = $query;
		$this->queries[] = $query;
		return $this->query_result;
	}
}

final class NicePayPrivacyTest extends TestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		global $wpdb, $wp_options;
		$this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
		$wpdb = new NicePayPrivacyWpdbFake();
		$wp_options = array();
	}

	protected function tearDown(): void {
		global $wpdb, $wp_options;
		$wpdb = $this->previous_wpdb;
		$wp_options = array();
	}

	public function test_registers_wordpress_privacy_callbacks(): void {
		$exporters = NicePay_Privacy::register_exporter( array() );
		$erasers   = NicePay_Privacy::register_eraser( array() );

		$this->assertSame( array( 'NicePay_Privacy', 'export' ), $exporters['nicepay-transactions']['callback'] );
		$this->assertSame( array( 'NicePay_Privacy', 'erase' ), $erasers['nicepay-transactions']['callback'] );
	}

	public function test_exporter_returns_only_allowlisted_subject_and_financial_fields(): void {
		global $wpdb;
		$wpdb->rows = array(
			(object) array(
				'id' => 7, 'tid' => 'safe-tid', 'moid' => 'safe-moid', 'wc_order_id' => 42,
				'amount' => '1004', 'currency' => 'KRW', 'payment_method' => 'CARD',
				'status' => 'paid', 'buyer_name' => 'Buyer', 'buyer_email' => 'buyer@example.com',
				'buyer_tel' => '01012345678', 'created_at' => '2026-08-20 10:00:00',
				'auth_token' => 'must-not-export', 'payment_data' => 'must-not-export',
			),
		);
		$wpdb->refund_rows = array(
			(object) array(
				'id' => 9, 'transaction_id' => 7, 'requested_amount' => '500',
				'currency' => 'KRW', 'reason' => 'Buyer requested by phone',
				'status' => 'confirmed', 'requested_at' => '2026-08-21 10:00:00',
			),
		);

		$result = NicePay_Privacy::export( 'buyer@example.com', 1 );
		$serialized = wp_json_encode( $result );

		$this->assertTrue( $result['done'] );
		$this->assertSame( 'nicepay-transaction-7', $result['data'][0]['item_id'] );
		$this->assertStringContainsString( "buyer_email = 'buyer@example.com'", $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'auth_token', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'payment_data', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'must-not-export', $serialized );
		$this->assertStringContainsString( 'nicepay-refund-attempt-9', $serialized );
		$this->assertStringContainsString( 'Buyer requested by phone', $serialized );
		$this->assertStringContainsString( 'transaction_id IN (7)', $wpdb->last_query );
	}

	public function test_eraser_anonymizes_contact_data_and_preserves_financial_references(): void {
		global $wpdb, $wp_options;
		$wpdb->rows = array( (object) array( 'id' => 7 ) );
		$wpdb->query_result = 1;
		$wp_options['nicepay_saved_shortcodes'] = array(
			array(
				'id' => 'merchant-offer', 'buyer_name' => 'Buyer',
				'buyer_email' => 'buyer@example.com', 'buyer_tel' => '01012345678',
				'is_preset' => false,
			),
		);

		$result = NicePay_Privacy::erase( 'buyer@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$queries = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( "status NOT IN ('pending', 'approving', 'needs_reconciliation')", $queries );
		$this->assertStringContainsString( "buyer_name = ''", $queries );
		$this->assertStringContainsString( 'payment_data = NULL', $queries );
		$this->assertStringContainsString( "UPDATE wp_nicepay_refund_attempts SET reason = ''", $queries );
		$this->assertStringNotContainsString( 'tid =', $queries );
		$this->assertStringNotContainsString( 'amount =', $queries );
		$this->assertArrayNotHasKey( 'buyer_name', $wp_options['nicepay_saved_shortcodes'][0] );
		$this->assertArrayNotHasKey( 'buyer_email', $wp_options['nicepay_saved_shortcodes'][0] );
		$this->assertArrayNotHasKey( 'buyer_tel', $wp_options['nicepay_saved_shortcodes'][0] );
	}

	public function test_invalid_email_never_queries_the_ledger(): void {
		global $wpdb;

		$this->assertSame( array( 'data' => array(), 'done' => true ), NicePay_Privacy::export( 'not-an-email' ) );
		$this->assertSame( '', $wpdb->last_query );
	}
}
