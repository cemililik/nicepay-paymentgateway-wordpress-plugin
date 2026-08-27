<?php
/**
 * Versioned NicePay schema installer.
 *
 * The plugin bootstrap calls maybe_install() during normal loading as well as
 * activation so an ordinary update cannot leave the runtime on a stale table.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-nicepay-transaction-schema.php';

/**
 * Installs and upgrades the NicePay transactions table without deleting data.
 */
final class NicePay_Installer {

	/** Schema version option, independent from NICEPAY_VERSION. */
	const VERSION_OPTION = 'nicepay_transactions_schema_version';
	const VERIFIED_VERSION_OPTION = 'nicepay_transactions_schema_verified_version';
	const LOCK_OPTION = 'nicepay_transactions_schema_lock';
	const LOCK_TTL = 60;

	/**
	 * Return the target schema version.
	 *
	 * @return string
	 */
	public static function schema_version() {
		return NicePay_Transaction_Schema::VERSION;
	}

	/**
	 * Determine whether the current site's transaction schema is ready.
	 *
	 * @return bool
	 */
	public static function is_current( $wpdb = null ) {
		if ( (string) get_option( self::VERSION_OPTION, '' ) !== self::schema_version() ||
			(string) get_option( self::VERIFIED_VERSION_OPTION, '' ) !== self::schema_version() ) {
			return false;
		}

		if ( null === $wpdb ) {
			global $wpdb;
		}

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return false;
		}

		return self::table_exists( $wpdb, self::table_name( $wpdb ) )
			&& self::table_exists( $wpdb, self::refund_table_name( $wpdb ) )
			&& self::table_exists( $wpdb, self::reconciliation_audit_table_name( $wpdb ) );
	}

	/**
	 * Return the fully-prefixed transactions table name.
	 *
	 * @param object $wpdb WordPress database object.
	 * @return string
	 */
	public static function table_name( $wpdb ) {
		return $wpdb->prefix . 'nicepay_transactions';
	}

	/** @return string */
	public static function refund_table_name( $wpdb ) {
		return $wpdb->prefix . 'nicepay_refund_attempts';
	}

	/** @return string */
	public static function reconciliation_audit_table_name( $wpdb ) {
		return $wpdb->prefix . 'nicepay_reconciliation_audit';
	}

	/**
	 * SQL used to detect values that would block the unique Moid index.
	 *
	 * Empty Moids are included because MySQL uniqueness applies to empty strings.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return string
	 */
	public static function duplicate_moid_count_sql( $table_name ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException( 'Invalid NicePay transaction table name.' );
		}

		return "SELECT COUNT(*) FROM (SELECT moid FROM {$table_name} GROUP BY moid HAVING COUNT(*) > 1) AS nicepay_duplicate_moids";
	}

	/**
	 * SQL used to detect non-empty TIDs that would block the unique TID index.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return string
	 */
	public static function duplicate_tid_count_sql( $table_name ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException( 'Invalid NicePay transaction table name.' );
		}

		return "SELECT COUNT(*) FROM (SELECT tid FROM {$table_name} WHERE tid IS NOT NULL AND tid <> '' GROUP BY tid HAVING COUNT(*) > 1) AS nicepay_duplicate_tids";
	}

	/**
	 * Install or upgrade the table when the stored schema version is stale.
	 *
	 * No duplicate row is deleted or rewritten. A duplicate Moid blocks the
	 * upgrade and returns a recoverable WP_Error for explicit operator review.
	 *
	 * @param object|null   $wpdb     WordPress database object; global by default.
	 * @param callable|null $db_delta Optional dbDelta-compatible callable for tests.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function maybe_install( $wpdb = null, $db_delta = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			return new WP_Error( 'nicepay_schema_missing_wpdb', __( 'NicePay schema installation requires a valid wpdb instance.', 'nicepay-payment-gateway' ) );
		}

		$current = (string) get_option( self::VERSION_OPTION, '' );
		$target  = self::schema_version();
		$table   = self::table_name( $wpdb );
		$refund_table = self::refund_table_name( $wpdb );
		$audit_table  = self::reconciliation_audit_table_name( $wpdb );

		if ( '' !== $current && version_compare( $current, $target, '>' ) ) {
			return new WP_Error(
				'nicepay_schema_downgrade_blocked',
				__( 'NicePay will not run an older schema over a newer database.', 'nicepay-payment-gateway' ),
				array( 'current' => $current, 'target' => $target )
			);
		}

		$table_exists        = self::table_exists( $wpdb, $table );
		$refund_table_exists = self::table_exists( $wpdb, $refund_table );
		$audit_table_exists  = self::table_exists( $wpdb, $audit_table );

		if ( $current === $target &&
			(string) get_option( self::VERIFIED_VERSION_OPTION, '' ) === $target &&
			$table_exists && $refund_table_exists && $audit_table_exists ) {
			return array(
				'status'  => 'current',
				'version' => $target,
				'table'   => $table,
				'refund_table' => $refund_table,
				'audit_table'  => $audit_table,
				'changes' => array(),
			);
		}

		$lock_acquired = add_option( self::LOCK_OPTION, time(), '', false );
		if ( ! $lock_acquired ) {
			$lock_time = (int) get_option( self::LOCK_OPTION, 0 );
			if ( $lock_time > 0 && $lock_time < time() - self::LOCK_TTL ) {
				delete_option( self::LOCK_OPTION );
				$lock_acquired = add_option( self::LOCK_OPTION, time(), '', false );
			}
		}
		if ( ! $lock_acquired ) {
			return new WP_Error( 'nicepay_schema_upgrade_locked', __( 'Another NicePay schema upgrade is already running.', 'nicepay-payment-gateway' ) );
		}

		try {

		if ( $table_exists ) {
			$duplicates = (int) $wpdb->get_var( self::duplicate_moid_count_sql( $table ) );
			if ( $duplicates > 0 ) {
				return new WP_Error(
					'nicepay_schema_duplicate_moid',
					__( 'NicePay cannot add the unique Moid index until duplicate Moids are reviewed.', 'nicepay-payment-gateway' ),
					array(
						'table'            => $table,
						'duplicate_groups' => $duplicates,
					)
				);
			}

			$duplicate_tids = (int) $wpdb->get_var( self::duplicate_tid_count_sql( $table ) );
			if ( $duplicate_tids > 0 ) {
				return new WP_Error(
					'nicepay_schema_duplicate_tid',
					__( 'NicePay cannot add the unique TID index until duplicate transaction IDs are reviewed.', 'nicepay-payment-gateway' ),
					array(
						'table'            => $table,
						'duplicate_groups' => $duplicate_tids,
					)
				);
			}

			// dbDelta does not reliably change nullability. Make the legacy empty
			// TID sentinel nullable before converting it, otherwise MySQL running
			// without strict mode silently writes the empty string back.
			if ( self::column_exists( $wpdb, $table, 'tid' ) ) {
				$altered = $wpdb->query( "ALTER TABLE {$table} MODIFY tid varchar(50) NULL" );
				if ( false === $altered || ! empty( $wpdb->last_error ) ) {
					return new WP_Error(
						'nicepay_schema_tid_nullable_failed',
						__( 'NicePay could not make the legacy transaction ID nullable.', 'nicepay-payment-gateway' ),
						array( 'database_error' => $wpdb->last_error )
					);
				}
				$normalized = $wpdb->query( "UPDATE {$table} SET tid = NULL WHERE tid = ''" );
				if ( false === $normalized || ! empty( $wpdb->last_error ) ) {
					return new WP_Error( 'nicepay_schema_tid_normalization_failed', __( 'NicePay could not normalize legacy transaction IDs.', 'nicepay-payment-gateway' ) );
				}
			}
		}

		if ( null === $db_delta ) {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$db_delta = 'dbDelta';
		}

		if ( ! is_callable( $db_delta ) ) {
			return new WP_Error( 'nicepay_schema_missing_dbdelta', __( 'NicePay schema installation requires dbDelta.', 'nicepay-payment-gateway' ) );
		}

		$sql            = NicePay_Transaction_Schema::create_table_sql( $table, $wpdb->get_charset_collate() );
		$refund_sql     = NicePay_Transaction_Schema::create_refund_table_sql( $refund_table, $wpdb->get_charset_collate() );
		$audit_sql      = NicePay_Transaction_Schema::create_reconciliation_audit_table_sql( $audit_table, $wpdb->get_charset_collate() );
		$changes        = call_user_func( $db_delta, $sql );
		$refund_changes = call_user_func( $db_delta, $refund_sql );
		$audit_changes  = call_user_func( $db_delta, $audit_sql );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error(
				'nicepay_schema_db_error',
				__( 'NicePay transaction schema upgrade failed.', 'nicepay-payment-gateway' ),
				array( 'database_error' => $wpdb->last_error )
			);
		}

		if ( ! self::table_exists( $wpdb, $table ) || ! self::table_exists( $wpdb, $refund_table ) ||
			! self::table_exists( $wpdb, $audit_table ) ) {
			return new WP_Error(
				'nicepay_schema_table_missing',
				__( 'NicePay transaction schema upgrade did not create all required tables.', 'nicepay-payment-gateway' )
			);
		}

		$verified = self::verify_schema( $wpdb, $table, $refund_table, $audit_table );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$backfilled = self::backfill_legacy_rows( $wpdb, $table );
		if ( is_wp_error( $backfilled ) ) {
			return $backfilled;
		}

		// Clean spent credentials only after the physical schema is proven. The
		// legacy payment payload is deliberately retained unless an operator calls
		// scrub_legacy_payment_payloads() with explicit confirmation.
		$scrubbed = self::scrub_legacy_sensitive_data( $wpdb, $table );
		if ( is_wp_error( $scrubbed ) ) {
			return $scrubbed;
		}

		if ( $current !== $target && false === update_option( self::VERSION_OPTION, $target, false ) ) {
			return new WP_Error( 'nicepay_schema_version_write_failed', __( 'NicePay could not store the transaction schema version.', 'nicepay-payment-gateway' ) );
		}
		if ( (string) get_option( self::VERIFIED_VERSION_OPTION, '' ) !== $target &&
			false === update_option( self::VERIFIED_VERSION_OPTION, $target, false ) ) {
			return new WP_Error( 'nicepay_schema_verification_write_failed', __( 'NicePay could not store the verified schema version.', 'nicepay-payment-gateway' ) );
		}

		return array(
			'status'  => 'installed',
			'version' => $target,
			'table'   => $table,
			'refund_table' => $refund_table,
			'audit_table'  => $audit_table,
			'changes' => array_merge(
				is_array( $changes ) ? $changes : array(),
				is_array( $refund_changes ) ? $refund_changes : array(),
				is_array( $audit_changes ) ? $audit_changes : array()
			),
		);
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Prove that dbDelta created the money-safety columns and indexes.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_schema( $wpdb, $table, $refund_table, $audit_table ) {
		if ( ! method_exists( $wpdb, 'get_results' ) ) {
			return new WP_Error( 'nicepay_schema_inspection_unavailable', __( 'NicePay cannot verify the upgraded schema.', 'nicepay-payment-gateway' ) );
		}

		$required_columns = array_keys( NicePay_Transaction_Schema::columns() );
		$column_rows      = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		$columns          = array();
		foreach ( (array) $column_rows as $row ) {
			if ( isset( $row->Field ) ) {
				$columns[] = (string) $row->Field;
			}
		}

		$required_indexes = array(
			'uniq_moid',
			'uniq_tid',
			'uniq_active_attempt',
			'idx_source_ref',
			'idx_status_offer_expiry',
			'idx_status_approval_started',
		);
		$index_rows       = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$indexes          = array();
		foreach ( (array) $index_rows as $row ) {
			if ( isset( $row->Key_name ) ) {
				$indexes[] = (string) $row->Key_name;
			}
		}

		$missing_columns = array_values( array_diff( $required_columns, array_unique( $columns ) ) );
		$missing_indexes = array_values( array_diff( $required_indexes, array_unique( $indexes ) ) );
		if ( ! empty( $missing_columns ) || ! empty( $missing_indexes ) ) {
			return new WP_Error(
				'nicepay_schema_verification_failed',
				__( 'NicePay transaction schema is incomplete after dbDelta.', 'nicepay-payment-gateway' ),
				array( 'missing_columns' => $missing_columns, 'missing_indexes' => $missing_indexes )
			);
		}

		$refund_indexes = $wpdb->get_results( "SHOW INDEX FROM {$refund_table}" );
		$refund_names   = array();
		foreach ( (array) $refund_indexes as $row ) {
			if ( isset( $row->Key_name ) ) {
				$refund_names[] = (string) $row->Key_name;
			}
		}
		if ( ! in_array( 'uniq_cancel_moid', $refund_names, true ) ) {
			return new WP_Error( 'nicepay_schema_verification_failed', __( 'NicePay refund ledger is missing its identity index.', 'nicepay-payment-gateway' ) );
		}

		$audit_indexes = $wpdb->get_results( "SHOW INDEX FROM {$audit_table}" );
		$audit_names   = array();
		foreach ( (array) $audit_indexes as $row ) {
			if ( isset( $row->Key_name ) ) {
				$audit_names[] = (string) $row->Key_name;
			}
		}
		if ( ! in_array( 'idx_reconciliation_transaction', $audit_names, true ) ) {
			return new WP_Error( 'nicepay_schema_verification_failed', __( 'NicePay reconciliation audit ledger is missing its transaction index.', 'nicepay-payment-gateway' ) );
		}

		return true;
	}

	/**
	 * Populate lifecycle context added after the 1.x ledger schema.
	 *
	 * Updates are bounded and idempotent so an interrupted migration is safe to
	 * retry. Context that cannot be derived is left empty and therefore remains
	 * fail-closed at the refund boundary.
	 *
	 * @return true|WP_Error
	 */
	public static function backfill_legacy_rows( $wpdb, $table ) {
		$mode = (string) get_option( 'nicepay_mode', '' );
		$mid  = '';
		if ( in_array( $mode, array( 'test', 'live' ), true ) ) {
			$mid = (string) get_option( 'test' === $mode ? 'nicepay_test_mid' : 'nicepay_live_mid', '' );
		}

		$updates = array(
			"UPDATE {$table} SET flow = 'woocommerce' WHERE (flow = '' OR flow IS NULL) AND wc_order_id IS NOT NULL AND wc_order_id > 0 LIMIT 500",
			"UPDATE {$table} SET currency = 'KRW' WHERE (currency = '' OR currency IS NULL) LIMIT 500",
			"UPDATE {$table} SET captured_amount = amount WHERE status IN ('paid', 'partially_refunded', 'refunded') AND captured_amount = 0 AND amount > 0 LIMIT 500",
			"UPDATE {$table} SET remaining_amount = GREATEST(captured_amount - refunded_amount, 0) WHERE status IN ('paid', 'partially_refunded', 'refunded') AND remaining_amount = 0 AND captured_amount > 0 LIMIT 500",
		);
		if ( '' !== $mid && '' !== $mode ) {
			$updates[] = $wpdb->prepare(
				"UPDATE {$table} SET mid = %s, mode = %s WHERE (mid = '' OR mid IS NULL) AND (mode = '' OR mode IS NULL) LIMIT 500",
				$mid,
				$mode
			);
		}

		foreach ( $updates as $sql ) {
			do {
				$affected = $wpdb->query( $sql );
				if ( false === $affected || ! empty( $wpdb->last_error ) ) {
					return new WP_Error(
						'nicepay_schema_backfill_failed',
						__( 'NicePay could not backfill the legacy transaction ledger.', 'nicepay-payment-gateway' ),
						array( 'database_error' => $wpdb->last_error )
					);
				}
			} while ( 500 === (int) $affected );
		}

		return true;
	}

	/**
	 * Determine whether the table already exists.
	 *
	 * @param object $wpdb WordPress database object.
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( $wpdb, $table ) {
		if ( ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		return $table === $wpdb->get_var( $query );
	}

	/**
	 * Determine whether an existing transaction table contains a column.
	 *
	 * @param object $wpdb   WordPress database object.
	 * @param string $table  Fully-prefixed table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( $wpdb, $table, $column ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ||
			! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$query = $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column );
		return $column === $wpdb->get_var( $query );
	}

	/**
	 * Remove legacy secrets/PAN-bearing response blobs before upgrading indexes.
	 *
	 * Financial identity, amount and result fields remain intact. Only empty TID
	 * sentinels and fields that the old plugin populated with sensitive response
	 * material are normalized.
	 *
	 * @param object $wpdb  WordPress database object.
	 * @param string $table Fully-prefixed table name.
	 * @return true|WP_Error
	 */
	private static function scrub_legacy_sensitive_data( $wpdb, $table ) {
		if ( ! method_exists( $wpdb, 'query' ) ) {
			return new WP_Error( 'nicepay_schema_missing_query', __( 'NicePay schema migration requires database write access.', 'nicepay-payment-gateway' ) );
		}

		$queries = array();
		if ( self::column_exists( $wpdb, $table, 'card_no' ) ) {
			$queries[] = "UPDATE {$table} SET card_no = '' WHERE card_no <> ''";
		}
		if ( self::column_exists( $wpdb, $table, 'auth_token' ) && self::column_exists( $wpdb, $table, 'status' ) ) {
			$queries[] = "UPDATE {$table} SET auth_token = '' WHERE auth_token <> '' AND status NOT IN ('pending', 'approving', 'needs_reconciliation')";
		}
		foreach ( $queries as $query ) {
			$result = $wpdb->query( $query );
			if ( false === $result || ! empty( $wpdb->last_error ) ) {
				return new WP_Error(
					'nicepay_schema_sensitive_data_cleanup_failed',
					__( 'NicePay could not safely clean legacy sensitive transaction data.', 'nicepay-payment-gateway' ),
					array( 'database_error' => $wpdb->last_error )
				);
			}
		}

		return true;
	}

	/**
	 * Irreversibly remove legacy response payloads after explicit confirmation.
	 *
	 * @param object $wpdb      WordPress database object.
	 * @param bool   $confirmed Explicit operator confirmation.
	 * @return int|WP_Error Number of scrubbed rows.
	 */
	public static function scrub_legacy_payment_payloads( $wpdb, $confirmed = false ) {
		if ( true !== $confirmed ) {
			return new WP_Error( 'nicepay_schema_scrub_confirmation_required', __( 'Explicit confirmation is required before legacy payment payloads are removed.', 'nicepay-payment-gateway' ) );
		}
		$table = self::table_name( $wpdb );
		if ( ! self::table_exists( $wpdb, $table ) || ! self::column_exists( $wpdb, $table, 'payment_data' ) ) {
			return new WP_Error( 'nicepay_schema_scrub_unavailable', __( 'The NicePay legacy payment payload column is unavailable.', 'nicepay-payment-gateway' ) );
		}
		$result = $wpdb->query(
			"UPDATE {$table} SET payment_data = NULL
			 WHERE payment_data IS NOT NULL AND payment_data <> ''
			   AND (payment_data LIKE '%\"CardNo\"%'
			     OR payment_data LIKE '%\"AuthToken\"%'
			     OR payment_data LIKE '%\"Signature\"%'
			     OR payment_data LIKE '%\"BuyerEmail\"%'
			     OR payment_data LIKE '%\"BuyerTel\"%'
			     OR payment_data LIKE '%\"VbankNum\"%')"
		);
		if ( false === $result || ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'nicepay_schema_sensitive_data_cleanup_failed', __( 'NicePay could not clean legacy payment payloads.', 'nicepay-payment-gateway' ) );
		}
		return (int) $result;
	}
}
