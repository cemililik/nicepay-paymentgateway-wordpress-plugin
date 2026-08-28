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
 * Database inspection and sensitive-data cleanup used by the installer.
 *
 * Kept separate from the schema orchestrator so installation flow and low-level
 * database operations can evolve independently.
 */
final class NicePay_Installer_Database {

	/** @return bool */
	public static function table_exists( $wpdb, $table ) {
		if ( ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		return $table === $wpdb->get_var( $query );
	}

	/** @return bool */
	public static function column_exists( $wpdb, $table, $column ) {
		if ( ! preg_match( NicePay_Transaction_Schema::IDENTIFIER_PATTERN, $table ) ||
			! preg_match( NicePay_Transaction_Schema::IDENTIFIER_PATTERN, $column ) ||
			! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$query = $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column );
		return $column === $wpdb->get_var( $query );
	}

	/** @return true|WP_Error */
	public static function scrub_legacy_sensitive_data( $wpdb, $table ) {
		$result = true;
		if ( ! method_exists( $wpdb, 'query' ) ) {
			$result = new WP_Error( 'nicepay_schema_missing_query', __( 'NicePay schema migration requires database write access.', 'nicepay-payment-gateway' ) );
		} else {
			$queries = array();
			if ( self::column_exists( $wpdb, $table, 'card_no' ) ) {
				$queries[] = "UPDATE {$table} SET card_no = '' WHERE card_no <> ''";
			}
			if ( self::column_exists( $wpdb, $table, 'auth_token' ) && self::column_exists( $wpdb, $table, 'status' ) ) {
				$queries[] = "UPDATE {$table} SET auth_token = '' WHERE auth_token <> '' AND status NOT IN ('pending', 'approving', 'needs_reconciliation')";
			}
			foreach ( $queries as $query ) {
				$changed = $wpdb->query( $query );
				if ( false === $changed || ! empty( $wpdb->last_error ) ) {
					$result = new WP_Error(
						'nicepay_schema_sensitive_data_cleanup_failed',
						__( 'NicePay could not safely clean legacy sensitive transaction data.', 'nicepay-payment-gateway' ),
						array( 'database_error' => $wpdb->last_error )
					);
					break;
				}
			}
		}
		return $result;
	}

	/** @return int|WP_Error */
	public static function scrub_legacy_payment_payloads( $wpdb, $table, $confirmed ) {
		$result = new WP_Error( 'nicepay_schema_scrub_confirmation_required', __( 'Explicit confirmation is required before legacy payment payloads are removed.', 'nicepay-payment-gateway' ) );
		if ( true === $confirmed ) {
			if ( ! self::table_exists( $wpdb, $table ) || ! self::column_exists( $wpdb, $table, 'payment_data' ) ) {
				$result = new WP_Error( 'nicepay_schema_scrub_unavailable', __( 'The NicePay legacy payment payload column is unavailable.', 'nicepay-payment-gateway' ) );
			} else {
				$changed = $wpdb->query(
					"UPDATE {$table} SET payment_data = NULL
					 WHERE payment_data IS NOT NULL AND payment_data <> ''
					   AND (payment_data LIKE '%\"CardNo\"%'
					     OR payment_data LIKE '%\"AuthToken\"%'
					     OR payment_data LIKE '%\"Signature\"%'
					     OR payment_data LIKE '%\"BuyerEmail\"%'
					     OR payment_data LIKE '%\"BuyerTel\"%'
					     OR payment_data LIKE '%\"VbankNum\"%')"
				);
				$result = false === $changed || ! empty( $wpdb->last_error )
					? new WP_Error( 'nicepay_schema_sensitive_data_cleanup_failed', __( 'NicePay could not clean legacy payment payloads.', 'nicepay-payment-gateway' ) )
					: (int) $changed;
			}
		}
		return $result;
	}
}

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

		return NicePay_Installer_Database::table_exists( $wpdb, self::table_name( $wpdb ) )
			&& NicePay_Installer_Database::table_exists( $wpdb, self::refund_table_name( $wpdb ) )
			&& NicePay_Installer_Database::table_exists( $wpdb, self::reconciliation_audit_table_name( $wpdb ) );
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
		if ( ! preg_match( NicePay_Transaction_Schema::IDENTIFIER_PATTERN, $table_name ) ) {
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
		if ( ! preg_match( NicePay_Transaction_Schema::IDENTIFIER_PATTERN, $table_name ) ) {
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

		$context = self::prepare_install_context( $wpdb );
		if ( isset( $context['result'] ) ) {
			return $context['result'];
		}

		if ( ! self::acquire_install_lock() ) {
			return new WP_Error( 'nicepay_schema_upgrade_locked', __( 'Another NicePay schema upgrade is already running.', 'nicepay-payment-gateway' ) );
		}

		try {
			return self::install_schema( $wpdb, $db_delta, $context );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** @return array<string,mixed> */
	private static function prepare_install_context( $wpdb ) {
		$result = null;
		$target = self::schema_version();
		$current = (string) get_option( self::VERSION_OPTION, '' );

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			$result = new WP_Error( 'nicepay_schema_missing_wpdb', __( 'NicePay schema installation requires a valid wpdb instance.', 'nicepay-payment-gateway' ) );
		} elseif ( '' !== $current && version_compare( $current, $target, '>' ) ) {
			$result = new WP_Error(
				'nicepay_schema_downgrade_blocked',
				__( 'NicePay will not run an older schema over a newer database.', 'nicepay-payment-gateway' ),
				array( 'current' => $current, 'target' => $target )
			);
		}

		if ( null !== $result ) {
			return array( 'result' => $result );
		}

		$context = array(
			'current'             => $current,
			'target'              => $target,
			'table'               => self::table_name( $wpdb ),
			'refund_table'        => self::refund_table_name( $wpdb ),
			'audit_table'         => self::reconciliation_audit_table_name( $wpdb ),
		);
		$context['table_exists']        = NicePay_Installer_Database::table_exists( $wpdb, $context['table'] );
		$context['refund_table_exists'] = NicePay_Installer_Database::table_exists( $wpdb, $context['refund_table'] );
		$context['audit_table_exists']  = NicePay_Installer_Database::table_exists( $wpdb, $context['audit_table'] );

		if ( $current === $target &&
			(string) get_option( self::VERIFIED_VERSION_OPTION, '' ) === $target &&
			$context['table_exists'] && $context['refund_table_exists'] && $context['audit_table_exists'] ) {
			$context['result'] = self::install_response( 'current', $context, array() );
		}

		return $context;
	}

	/** @return bool */
	private static function acquire_install_lock() {
		$acquired = add_option( self::LOCK_OPTION, time(), '', false );
		if ( ! $acquired ) {
			$lock_time = (int) get_option( self::LOCK_OPTION, 0 );
			if ( $lock_time > 0 && $lock_time < time() - self::LOCK_TTL ) {
				delete_option( self::LOCK_OPTION );
				$acquired = add_option( self::LOCK_OPTION, time(), '', false );
			}
		}
		return (bool) $acquired;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function install_schema( $wpdb, $db_delta, array $context ) {
		$result = self::prepare_legacy_table( $wpdb, $context['table'], $context['table_exists'] );
		if ( ! is_wp_error( $result ) ) {
			$result = self::run_db_delta( $wpdb, $db_delta, $context );
		}
		if ( ! is_wp_error( $result ) ) {
			$changes = $result;
			$result  = self::finalize_install( $wpdb, $context );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = self::install_response( 'installed', $context, $changes );
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function prepare_legacy_table( $wpdb, $table, $table_exists ) {
		$result = true;
		if ( $table_exists ) {
			$duplicates = (int) $wpdb->get_var( self::duplicate_moid_count_sql( $table ) );
			$duplicate_tids = (int) $wpdb->get_var( self::duplicate_tid_count_sql( $table ) );
			if ( $duplicates > 0 ) {
				$result = new WP_Error(
					'nicepay_schema_duplicate_moid',
					__( 'NicePay cannot add the unique Moid index until duplicate Moids are reviewed.', 'nicepay-payment-gateway' ),
					array( 'table' => $table, 'duplicate_groups' => $duplicates )
				);
			} elseif ( $duplicate_tids > 0 ) {
				$result = new WP_Error(
					'nicepay_schema_duplicate_tid',
					__( 'NicePay cannot add the unique TID index until duplicate transaction IDs are reviewed.', 'nicepay-payment-gateway' ),
					array( 'table' => $table, 'duplicate_groups' => $duplicate_tids )
				);
			} elseif ( NicePay_Installer_Database::column_exists( $wpdb, $table, 'tid' ) ) {
				$result = self::normalize_legacy_tid( $wpdb, $table );
			}
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function normalize_legacy_tid( $wpdb, $table ) {
		$result  = true;
		$altered = $wpdb->query( "ALTER TABLE {$table} MODIFY tid varchar(50) NULL" );
		if ( false === $altered || ! empty( $wpdb->last_error ) ) {
			$result = new WP_Error(
				'nicepay_schema_tid_nullable_failed',
				__( 'NicePay could not make the legacy transaction ID nullable.', 'nicepay-payment-gateway' ),
				array( 'database_error' => $wpdb->last_error )
			);
		} else {
			$normalized = $wpdb->query( "UPDATE {$table} SET tid = NULL WHERE tid = ''" );
			if ( false === $normalized || ! empty( $wpdb->last_error ) ) {
				$result = new WP_Error( 'nicepay_schema_tid_normalization_failed', __( 'NicePay could not normalize legacy transaction IDs.', 'nicepay-payment-gateway' ) );
			}
		}
		return $result;
	}

	/** @return array<int,mixed>|WP_Error */
	private static function run_db_delta( $wpdb, $db_delta, array $context ) {
		if ( null === $db_delta ) {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$db_delta = 'dbDelta';
		}

		$result = new WP_Error( 'nicepay_schema_missing_dbdelta', __( 'NicePay schema installation requires dbDelta.', 'nicepay-payment-gateway' ) );
		if ( is_callable( $db_delta ) ) {
			$sql = array(
				NicePay_Transaction_Schema::create_table_sql( $context['table'], $wpdb->get_charset_collate() ),
				NicePay_Transaction_Schema::create_refund_table_sql( $context['refund_table'], $wpdb->get_charset_collate() ),
				NicePay_Transaction_Schema::create_reconciliation_audit_table_sql( $context['audit_table'], $wpdb->get_charset_collate() ),
			);
			$changes = array();
			foreach ( $sql as $statement ) {
				$statement_changes = call_user_func( $db_delta, $statement );
				$changes = array_merge( $changes, is_array( $statement_changes ) ? $statement_changes : array() );
			}
			$result = empty( $wpdb->last_error )
				? $changes
				: new WP_Error(
					'nicepay_schema_db_error',
					__( 'NicePay transaction schema upgrade failed.', 'nicepay-payment-gateway' ),
					array( 'database_error' => $wpdb->last_error )
				);
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function finalize_install( $wpdb, array $context ) {
		$tables_exist = NicePay_Installer_Database::table_exists( $wpdb, $context['table'] ) &&
			NicePay_Installer_Database::table_exists( $wpdb, $context['refund_table'] ) &&
			NicePay_Installer_Database::table_exists( $wpdb, $context['audit_table'] );
		$result = $tables_exist
			? self::verify_schema( $wpdb, $context['table'], $context['refund_table'], $context['audit_table'] )
			: new WP_Error( 'nicepay_schema_table_missing', __( 'NicePay transaction schema upgrade did not create all required tables.', 'nicepay-payment-gateway' ) );

		if ( ! is_wp_error( $result ) ) {
			$result = self::backfill_legacy_rows( $wpdb, $context['table'] );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = NicePay_Installer_Database::scrub_legacy_sensitive_data( $wpdb, $context['table'] );
		}

		if ( ! is_wp_error( $result ) && $context['current'] !== $context['target'] &&
			false === update_option( self::VERSION_OPTION, $context['target'], false ) ) {
			$result = new WP_Error( 'nicepay_schema_version_write_failed', __( 'NicePay could not store the transaction schema version.', 'nicepay-payment-gateway' ) );
		}
		if ( ! is_wp_error( $result ) &&
			(string) get_option( self::VERIFIED_VERSION_OPTION, '' ) !== $context['target'] &&
			false === update_option( self::VERIFIED_VERSION_OPTION, $context['target'], false ) ) {
			$result = new WP_Error( 'nicepay_schema_verification_write_failed', __( 'NicePay could not store the verified schema version.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private static function install_response( $status, array $context, array $changes ) {
		return array(
			'status'       => $status,
			'version'      => $context['target'],
			'table'        => $context['table'],
			'refund_table' => $context['refund_table'],
			'audit_table'  => $context['audit_table'],
			'changes'      => $changes,
		);
	}

	/**
	 * Prove that dbDelta created the money-safety columns and indexes.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_schema( $wpdb, $table, $refund_table, $audit_table ) {
		$result = true;
		if ( ! method_exists( $wpdb, 'get_results' ) ) {
			$result = new WP_Error( 'nicepay_schema_inspection_unavailable', __( 'NicePay cannot verify the upgraded schema.', 'nicepay-payment-gateway' ) );
		} else {
			$required_indexes = array(
				'uniq_moid', 'uniq_tid', 'uniq_active_attempt', 'idx_source_ref',
				'idx_status_offer_expiry', 'idx_status_approval_started',
			);
			$columns         = self::schema_names( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
			$indexes         = self::schema_names( $wpdb->get_results( "SHOW INDEX FROM {$table}" ), 'Key_name' );
			$missing_columns = array_values( array_diff( array_keys( NicePay_Transaction_Schema::columns() ), $columns ) );
			$missing_indexes = array_values( array_diff( $required_indexes, $indexes ) );

			if ( ! empty( $missing_columns ) || ! empty( $missing_indexes ) ) {
				$result = new WP_Error(
					'nicepay_schema_verification_failed',
					__( 'NicePay transaction schema is incomplete after dbDelta.', 'nicepay-payment-gateway' ),
					array( 'missing_columns' => $missing_columns, 'missing_indexes' => $missing_indexes )
				);
			} elseif ( ! in_array( 'uniq_cancel_moid', self::schema_names( $wpdb->get_results( "SHOW INDEX FROM {$refund_table}" ), 'Key_name' ), true ) ) {
				$result = new WP_Error( 'nicepay_schema_verification_failed', __( 'NicePay refund ledger is missing its identity index.', 'nicepay-payment-gateway' ) );
			} elseif ( ! in_array( 'idx_reconciliation_transaction', self::schema_names( $wpdb->get_results( "SHOW INDEX FROM {$audit_table}" ), 'Key_name' ), true ) ) {
				$result = new WP_Error( 'nicepay_schema_verification_failed', __( 'NicePay reconciliation audit ledger is missing its transaction index.', 'nicepay-payment-gateway' ) );
			}
		}
		return $result;
	}

	/** @return string[] */
	private static function schema_names( $rows, $property ) {
		$names = array();
		foreach ( (array) $rows as $row ) {
			if ( is_object( $row ) && isset( $row->{$property} ) ) {
				$names[] = (string) $row->{$property};
			}
		}
		return array_values( array_unique( $names ) );
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
	 * Irreversibly remove legacy response payloads after explicit confirmation.
	 *
	 * @param object $wpdb      WordPress database object.
	 * @param bool   $confirmed Explicit operator confirmation.
	 * @return int|WP_Error Number of scrubbed rows.
	 */
	public static function scrub_legacy_payment_payloads( $wpdb, $confirmed = false ) {
		$table = self::table_name( $wpdb );
		return NicePay_Installer_Database::scrub_legacy_payment_payloads( $wpdb, $table, $confirmed );
	}
}
