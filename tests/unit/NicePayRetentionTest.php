<?php
/**
 * Tests for the opt-in financial-record retention policy.
 */

use PHPUnit\Framework\TestCase;

class NicePayRetentionWpdbFake {
	public $prefix = 'wp_';
	public $selected_ids = array();
	public $count_result = '0';
	public $ledger_delete_result = null;
	public $queries = array();

	public function query( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, 'DELETE ledger FROM' ) ) {
			return null === $this->ledger_delete_result
				? count( $this->selected_ids )
				: $this->ledger_delete_result;
		}

		return 1;
	}

	public function get_col( $query ) {
		$this->queries[] = $query;
		return $this->selected_ids;
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		return $this->count_result;
	}
}

class NicePayRetentionTest extends TestCase {

	private $previous_wpdb;

	protected function setUp(): void {
		global $wpdb, $wp_options;

		parent::setUp();
		$this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
		$wpdb                = new NicePayRetentionWpdbFake();
		$wp_options          = array();
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_default_and_malformed_settings_fail_closed_to_indefinite_retention(): void {
		$this->assertSame( NicePay_Retention::default_settings(), NicePay_Retention::get_settings() );

		update_option(
			NicePay_Retention::SETTINGS_OPTION,
			array( 'mode' => 'custom', 'days' => 365, 'acknowledged' => 'no' )
		);
		$this->assertSame( 'indefinite', NicePay_Retention::get_settings()['mode'] );

		update_option(
			NicePay_Retention::SETTINGS_OPTION,
			array( 'mode' => 'custom', 'days' => NicePay_Retention::MAX_DAYS + 1, 'acknowledged' => 'yes' )
		);
		$this->assertSame( 'indefinite', NicePay_Retention::get_settings()['mode'] );

		update_option(
			NicePay_Retention::SETTINGS_OPTION,
			array( 'mode' => 'custom', 'days' => array( 365 ), 'acknowledged' => 'yes' )
		);
		$this->assertSame( 'indefinite', NicePay_Retention::get_settings()['mode'] );
	}

	public function test_custom_policy_requires_valid_days_and_explicit_acknowledgement(): void {
		$valid = NicePay_Retention::sanitize_settings(
			array( 'mode' => 'custom', 'days' => '2555', 'acknowledged' => 'yes' )
		);
		$this->assertSame(
			array( 'mode' => 'custom', 'days' => 2555, 'acknowledged' => 'yes' ),
			$valid
		);

		update_option( NicePay_Retention::SETTINGS_OPTION, $valid );
		$this->assertSame(
			$valid,
			NicePay_Retention::sanitize_settings(
				array( 'mode' => 'custom', 'days' => 30, 'acknowledged' => 'no' )
			)
		);
		$this->assertSame(
			$valid,
			NicePay_Retention::sanitize_settings(
				array( 'mode' => 'custom', 'days' => 0, 'acknowledged' => 'yes' )
			)
		);
		$this->assertSame(
			$valid,
			NicePay_Retention::sanitize_settings(
				array( 'mode' => 'custom', 'days' => array( '30' ), 'acknowledged' => 'yes' )
			)
		);
		$this->assertSame(
			$valid,
			NicePay_Retention::sanitize_settings(
				array( 'mode' => 'custom', 'days' => '-30', 'acknowledged' => 'yes' )
			)
		);
		$this->assertSame(
			$valid,
			NicePay_Retention::sanitize_settings(
				array( 'mode' => 'custom', 'days' => '30.5', 'acknowledged' => 'yes' )
			)
		);

		$this->assertSame(
			NicePay_Retention::default_settings(),
			NicePay_Retention::sanitize_settings( array( 'mode' => 'indefinite' ) )
		);
	}

	public function test_purge_locks_and_deletes_only_rows_that_remain_eligible(): void {
		global $wpdb;

		$wpdb->selected_ids         = array( '11', 12, 12, 0 );
		$wpdb->ledger_delete_result = 2;
		$result                     = NicePay_Retention::purge_batch( 365, 25 );

		$this->assertSame( 2, $result );
		$sql = implode( "\n", $wpdb->queries );
		$this->assertStringStartsWith( 'START TRANSACTION', $wpdb->queries[0] );
		$this->assertStringContainsString( 'FOR UPDATE', $sql );
		$this->assertStringContainsString( "ledger.status IN ('paid', 'partially_refunded', 'refunded', 'failed', 'abandoned', 'expired', 'cancelled')", $sql );
		$this->assertStringContainsString( "ledger.reconciliation_status <> 'required'", $sql );
		$this->assertStringContainsString( "ledger.approval_state NOT IN ('pending', 'approving', 'needs_reconciliation')", $sql );
		$this->assertStringContainsString( "ledger.cancel_status NOT IN ('requested', 'unknown')", $sql );
		$this->assertStringContainsString( "ledger.net_cancel_status <> 'unknown'", $sql );
		$this->assertStringContainsString( "refund_attempt.status IN ('requested', 'unknown')", $sql );
		$this->assertStringContainsString( 'DELETE FROM wp_nicepay_refund_attempts WHERE transaction_id IN (11,12)', $sql );
		$this->assertStringContainsString( 'DELETE ledger FROM wp_nicepay_transactions AS ledger', $sql );
		$this->assertSame( 'COMMIT', end( $wpdb->queries ) );
	}

	public function test_purge_rolls_back_when_the_parent_delete_count_changes(): void {
		global $wpdb;

		$wpdb->selected_ids        = array( 21, 22 );
		$wpdb->ledger_delete_result = 1;
		$result                     = NicePay_Retention::purge_batch( 365, 10 );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'nicepay_retention_delete_mismatch', $result->get_error_code() );
		$this->assertSame( 'ROLLBACK', end( $wpdb->queries ) );
	}

	public function test_eligible_count_uses_the_same_unresolved_state_guards(): void {
		global $wpdb;

		$wpdb->count_result = '7';
		$this->assertSame( 7, NicePay_Retention::count_eligible( 730 ) );
		$sql = end( $wpdb->queries );
		$this->assertStringContainsString( 'SELECT COUNT(*)', $sql );
		$this->assertStringContainsString( 'NOT EXISTS', $sql );
		$this->assertStringContainsString( 'INTERVAL 730 DAY', $sql );
	}

	public function test_admin_surface_explains_scope_and_permanent_effects(): void {
		$source = file_get_contents( NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php' );

		$this->assertStringContainsString( 'Financial record retention', $source );
		$this->assertStringContainsString( 'This is a permanent, legally significant deletion policy.', $source );
		$this->assertStringContainsString( 'does not delete WooCommerce orders, backups, logs or records held by NICEPAY', $source );
		$this->assertStringContainsString( 'prevents future refunds through this plugin', $source );
		$this->assertStringContainsString( 'NicePay_Retention::SETTINGS_OPTION', $source );
	}
}
