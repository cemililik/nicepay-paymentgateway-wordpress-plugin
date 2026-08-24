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
	public $duplicate_groups = 0;
	public $duplicate_tid_groups = 0;
	public $queries = array();
	public $existing_columns = array( 'tid', 'card_no', 'auth_token', 'status', 'payment_data' );

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $query, $value ) {
		return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
			if ( false !== strpos( $query, 'wp_nicepay_refund_attempts' ) ) {
				return $this->refund_table_exists ? 'wp_nicepay_refund_attempts' : null;
			}
			return $this->table_exists ? 'wp_nicepay_transactions' : null;
		}
		if ( 0 === strpos( $query, 'SHOW COLUMNS FROM' ) ) {
			foreach ( $this->existing_columns as $column ) {
				if ( false !== strpos( $query, "'" . $column . "'" ) ) {
					return $column;
				}
			}
			return null;
		}
		if ( 0 === strpos( $query, 'SELECT COUNT(*) FROM' ) ) {
			return false !== strpos( $query, 'nicepay_duplicate_tids' )
				? $this->duplicate_tid_groups
				: $this->duplicate_groups;
		}
		return null;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return empty( $this->last_error ) ? 1 : false;
	}
}

class NicePayInstallerTest extends TestCase {

	protected function setUp(): void {
		global $wp_options;
		$wp_options = array();
	}

	protected function tearDown(): void {
		global $wp_options;
		$wp_options = array();
	}

	public function test_current_version_is_idempotent_and_skips_dbdelta(): void {
		$wpdb = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;
		$wpdb->refund_table_exists = true;
		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
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
		$this->assertCount( 2, $wpdb->queries );
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
				return array( 'Created table wp_nicepay_transactions' );
			}
		);

		$this->assertSame( 'installed', $result['status'] );
		$this->assertCount( 2, $received_sql );
		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_transactions (', $received_sql[0] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_nicepay_refund_attempts (', $received_sql[1] );
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

	public function test_existing_table_normalizes_empty_tid_and_scrubs_legacy_sensitive_data(): void {
		$wpdb               = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;

		$result = NicePay_Installer::maybe_install( $wpdb, function () use ( $wpdb ) {
			$wpdb->refund_table_exists = true;
			return array();
		} );

		$this->assertSame( 'installed', $result['status'] );
		$queries = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( "SET tid = NULL WHERE tid = ''", $queries );
		$this->assertStringContainsString( "SET card_no = ''", $queries );
		$this->assertStringContainsString( "SET auth_token = ''", $queries );
		$this->assertStringContainsString( 'SET payment_data = NULL', $queries );
	}

	public function test_upgrade_without_legacy_card_column_does_not_query_it(): void {
		$wpdb                    = new NicePayInstallerWpdbFake();
		$wpdb->table_exists      = true;
		$wpdb->existing_columns  = array( 'tid', 'auth_token', 'status', 'payment_data' );
		update_option( NicePay_Installer::VERSION_OPTION, '2026.08.20.3' );

		$result = NicePay_Installer::maybe_install( $wpdb, function () use ( $wpdb ) {
			$wpdb->refund_table_exists = true;
			return array();
		} );

		$this->assertSame( 'installed', $result['status'] );
		$this->assertStringNotContainsString( 'SET card_no', implode( "\n", $wpdb->queries ) );
	}

	public function test_current_version_recreates_a_missing_refund_table(): void {
		$wpdb               = new NicePayInstallerWpdbFake();
		$wpdb->table_exists = true;
		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		$calls = 0;

		$result = NicePay_Installer::maybe_install(
			$wpdb,
			function () use ( &$calls, $wpdb ) {
				++$calls;
				$wpdb->refund_table_exists = true;
				return array();
			}
		);

		$this->assertSame( 'installed', $result['status'] );
		$this->assertSame( 2, $calls );
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
}
