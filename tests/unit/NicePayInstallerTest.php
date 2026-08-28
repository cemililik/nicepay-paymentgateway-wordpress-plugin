<?php
/**
 * Tests for the versioned NicePay schema installer.
 */

use PHPUnit\Framework\TestCase;

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-installer.php';

class NicePayInstallerWpdbFake {
	public $prefix = 'wp_';
	public $last_error = '';
	public $table_exists = false;
	public $refund_table_exists = false;
	public $audit_table_exists = false;
	public $duplicate_groups = 0;
	public $duplicate_tid_groups = 0;
	public $queries = array();
	public $existing_columns = array( 'tid', 'card_no', 'auth_token', 'status', 'payment_data' );
	public $schema_columns = array();
	public $schema_indexes = array(
		'uniq_moid',
		'uniq_tid',
		'uniq_active_attempt',
		'idx_source_ref',
		'idx_status_offer_expiry',
		'idx_status_approval_started',
	);
	public $refund_indexes = array( 'uniq_cancel_moid' );
	public $audit_indexes = array( 'idx_reconciliation_transaction' );

	public function __construct() {
		$this->schema_columns = array_keys( NicePay_Transaction_Schema::columns() );
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $query, ...$values ) {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%s/', "'" . addslashes( $value ) . "'", $query, 1 );
		}
		return $query;
	}

		public function get_var( $query ) {
			$this->queries[] = $query;
			$result = null;
			if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
				$result = $this->table_lookup_result( $query );
			} elseif ( 0 === strpos( $query, 'SHOW COLUMNS FROM' ) ) {
				$result = $this->column_lookup_result( $query );
			} elseif ( 0 === strpos( $query, 'SELECT COUNT(*) FROM' ) ) {
				$result = false !== strpos( $query, 'nicepay_duplicate_tids' ) ? $this->duplicate_tid_groups : $this->duplicate_groups;
			}
			return $result;
		}

		private function table_lookup_result( $query ) {
			$result = $this->table_exists ? 'wp_nicepay_transactions' : null;
			if ( false !== strpos( $query, 'wp_nicepay_reconciliation_audit' ) ) {
				$result = $this->audit_table_exists ? 'wp_nicepay_reconciliation_audit' : null;
			} elseif ( false !== strpos( $query, 'wp_nicepay_refund_attempts' ) ) {
				$result = $this->refund_table_exists ? 'wp_nicepay_refund_attempts' : null;
			}
			return $result;
		}

		private function column_lookup_result( $query ) {
			$result = null;
			foreach ( $this->existing_columns as $column ) {
				if ( false !== strpos( $query, "'" . $column . "'" ) ) {
					$result = $column;
					break;
				}
			}
			return $result;
		}

	public function query( $query ) {
		$this->queries[] = $query;
		return empty( $this->last_error ) ? 1 : false;
	}

	public function get_results( $query ) {
		$this->queries[] = $query;
		if ( 0 === strpos( $query, 'SHOW COLUMNS FROM' ) ) {
			return array_map( static function ( $column ) {
				return (object) array( 'Field' => $column );
			}, $this->schema_columns );
		}
			$indexes = $this->schema_indexes;
			if ( false !== strpos( $query, 'wp_nicepay_reconciliation_audit' ) ) {
				$indexes = $this->audit_indexes;
			} elseif ( false !== strpos( $query, 'wp_nicepay_refund_attempts' ) ) {
				$indexes = $this->refund_indexes;
			}
		return array_map( static function ( $index ) {
			return (object) array( 'Key_name' => $index );
		}, $indexes );
	}
}

class NicePayInstallerTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$this->reset_options();
		}

		protected function tearDown(): void {
			$this->reset_options();
			parent::tearDown();
		}

		private function reset_options(): void {
			global $wp_options;
			$wp_options = array();
		}

	public function test_current_version_is_idempotent_and_skips_dbdelta(): void {
		$wpdb = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;
		$wpdb->refund_table_exists = true;
		$wpdb->audit_table_exists = true;
		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( NicePay_Installer::VERIFIED_VERSION_OPTION, NicePay_Installer::schema_version() );
		$calls = 0;

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function () use ( &$calls ) {
				++$calls;
				return array();
			}
		);

		$this->assertSame( 'current', $result['status'] );
		$this->assertSame( 0, $calls );
		$this->assertCount( 3, $wpdb->queries );
	}

	public function test_new_install_runs_dbdelta_and_stores_schema_version(): void {
		$wpdb       = new NicePayInstallerWpdbFake();
		$received_sql = array();

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function ( $sql ) use ( &$received_sql, $wpdb ) {
				$received_sql[] = $sql;
				$wpdb->table_exists = true;
				$wpdb->refund_table_exists = true;
				$wpdb->audit_table_exists = true;
				return array( 'Created table wp_nicepay_transactions' );
			}
		);

		$this->assertSame( 'installed', $result['status'] );
		$this->assertCount( 3, $received_sql );
		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_transactions (', $received_sql[0] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_refund_attempts (', $received_sql[1] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_reconciliation_audit (', $received_sql[2] );
		$this->assertStringNotContainsString( 'IF NOT EXISTS', implode( "\n", $received_sql ) );
		$this->assertSame( 'wp_nicepay_refund_attempts', $result['refund_table'] );
		$this->assertSame(
			NicePay_Installer::schema_version(),
			get_option( NicePay_Installer::VERSION_OPTION )
		);
	}

	public function test_duplicate_moids_block_upgrade_without_changing_version(): void {
		$wpdb                   = new NicePayInstallerWpdbFake();
		$wpdb->table_exists     = true;
		$wpdb->duplicate_groups = 2;
		update_option( NicePay_Installer::VERSION_OPTION, 'old' );
		$calls = 0;

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function () use ( &$calls ) {
				++$calls;
				return array();
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_duplicate_moid', $result->get_error_code() );
		$this->assertSame( 0, $calls );
		$this->assertSame( 'old', get_option( NicePay_Installer::VERSION_OPTION ) );
	}

	public function test_db_error_does_not_advance_schema_version(): void {
		$wpdb             = new NicePayInstallerWpdbFake();
		$wpdb->last_error = 'index creation failed';

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function () {
				return array();
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_db_error', $result->get_error_code() );
		$this->assertFalse( get_option( NicePay_Installer::VERSION_OPTION, false ) );
	}

	public function test_duplicate_detection_sql_includes_empty_moids(): void {
		$sql = NicePay_Installer::duplicate_moid_count_sql( 'wp_nicepay_transactions' );

		$this->assertStringContainsString( 'GROUP BY moid HAVING COUNT(*) > 1', $sql );
		$this->assertStringNotContainsString( "moid <> ''", $sql );
	}

	public function test_duplicate_non_empty_tids_block_upgrade(): void {
		$wpdb                       = new NicePayInstallerWpdbFake();
		$wpdb->table_exists         = true;
		$wpdb->duplicate_tid_groups = 1;

		$result = NicePay_Installer::maybe_install( $wpdb, function () {
			return array();
		} );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_duplicate_tid', $result->get_error_code() );
		$this->assertFalse( get_option( NicePay_Installer::VERSION_OPTION, false ) );
	}

	public function test_existing_table_normalizes_empty_tid_after_altering_nullability_and_preserves_payload(): void {
		$wpdb               = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;

		$result = NicePay_Installer::maybe_install( $wpdb, function () use ( $wpdb ) {
			$wpdb->refund_table_exists = true;
			$wpdb->audit_table_exists = true;
			return array();
		} );

		$this->assertSame( 'installed', $result['status'] );
		$queries = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'MODIFY tid varchar(50) NULL', $queries );
		$this->assertStringContainsString( "SET tid = NULL WHERE tid = ''", $queries );
		$this->assertStringContainsString( "SET card_no = ''", $queries );
		$this->assertStringContainsString( "SET auth_token = ''", $queries );
		$this->assertStringNotContainsString( 'SET payment_data = NULL', $queries );
	}

	public function test_upgrade_without_legacy_card_column_does_not_query_it(): void {
		$wpdb                    = new NicePayInstallerWpdbFake();
		$wpdb->table_exists      = true;
		$wpdb->existing_columns  = array( 'tid', 'auth_token', 'status', 'payment_data' );
		update_option( NicePay_Installer::VERSION_OPTION, '2026.08.20.3' );

		$result = NicePay_Installer::maybe_install( $wpdb, function () use ( $wpdb ) {
			$wpdb->refund_table_exists = true;
			$wpdb->audit_table_exists = true;
			return array();
		} );

		$this->assertSame( 'installed', $result['status'] );
		$this->assertStringNotContainsString( 'SET card_no', implode( "\n", $wpdb->queries ) );
	}

	public function test_current_version_recreates_a_missing_refund_table(): void {
		$wpdb               = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;
		$wpdb->audit_table_exists = true;
		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( NicePay_Installer::VERIFIED_VERSION_OPTION, NicePay_Installer::schema_version() );
		$calls = 0;

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function () use ( &$calls, $wpdb ) {
				++$calls;
				$wpdb->refund_table_exists = true;
				$wpdb->audit_table_exists = true;
				return array();
			}
		);

		$this->assertSame( 'installed', $result['status'] );
		$this->assertSame( 3, $calls );
		$this->assertTrue( NicePay_Installer::is_current( $wpdb ) );
	}

	public function test_missing_table_after_dbdelta_fails_closed(): void {
		$wpdb = new NicePayInstallerWpdbFake();

		$result = NicePay_Installer::maybe_install( $wpdb, function () {
			return array();
		} );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_table_missing', $result->get_error_code() );
		$this->assertFalse( NicePay_Installer::is_current( $wpdb ) );
	}

	public function test_newer_schema_version_is_never_downgraded(): void {
		$wpdb = new NicePayInstallerWpdbFake();
		update_option( NicePay_Installer::VERSION_OPTION, '9999.1.0' );

		$result = NicePay_Installer::maybe_install( $wpdb, static function () {
			return array();
		} );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_downgrade_blocked', $result->get_error_code() );
		$this->assertSame( '9999.1.0', get_option( NicePay_Installer::VERSION_OPTION ) );
	}

	public function test_missing_unique_index_does_not_mark_schema_current(): void {
		$wpdb                       = new NicePayInstallerWpdbFake();
		$wpdb->table_exists         = true;
		$wpdb->refund_table_exists  = true;
		$wpdb->audit_table_exists   = true;
		$wpdb->schema_indexes       = array( 'uniq_moid', 'uniq_active_attempt', 'idx_source_ref' );

		$result = NicePay_Installer::maybe_install( $wpdb, static function () {
			return array();
		} );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_verification_failed', $result->get_error_code() );
		$this->assertFalse( get_option( NicePay_Installer::VERSION_OPTION, false ) );
	}

	public function test_payment_payload_scrub_requires_explicit_confirmation(): void {
		$wpdb               = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;

		$result = NicePay_Installer::scrub_legacy_payment_payloads( $wpdb, false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'nicepay_schema_scrub_confirmation_required', $result->get_error_code() );
		$this->assertStringNotContainsString( 'SET payment_data = NULL', implode( "\n", $wpdb->queries ) );
	}
}
