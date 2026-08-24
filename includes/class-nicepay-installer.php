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
		if ( (string) get_option( self::VERSION_OPTION, '' ) !== self::schema_version() ) {
			return false;
		}

		if ( null === $wpdb ) {
			global $wpdb;
		}

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return false;
		}

		return self::table_exists( $wpdb, self::table_name( $wpdb ) )
			&& self::table_exists( $wpdb, self::refund_table_name( $wpdb ) );
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
			return new WP_Error( 'nicepay_schema_missing_wpdb', 'NicePay schema installation requires a valid wpdb instance.' );
		}

		$current = (string) get_option( self::VERSION_OPTION, '' );
		$target  = self::schema_version();
		$table   = self::table_name( $wpdb );
		$refund_table = self::refund_table_name( $wpdb );

		$table_exists        = self::table_exists( $wpdb, $table );
		$refund_table_exists = self::table_exists( $wpdb, $refund_table );

		if ( $current === $target && $table_exists && $refund_table_exists ) {
			return array(
				'status'  => 'current',
				'version' => $target,
				'table'   => $table,
				'refund_table' => $refund_table,
				'changes' => array(),
			);
		}

		if ( $table_exists ) {
			$duplicates = (int) $wpdb->get_var( self::duplicate_moid_count_sql( $table ) );
			if ( $duplicates > 0 ) {
				return new WP_Error(
					'nicepay_schema_duplicate_moid',
					'NicePay cannot add the unique Moid index until duplicate Moids are reviewed.',
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
					'NicePay cannot add the unique TID index until duplicate transaction IDs are reviewed.',
					array(
						'table'            => $table,
						'duplicate_groups' => $duplicate_tids,
					)
				);
			}

			$scrubbed = self::scrub_legacy_sensitive_data( $wpdb, $table );
			if ( is_wp_error( $scrubbed ) ) {
				return $scrubbed;
			}
		}

		if ( null === $db_delta ) {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$db_delta = 'dbDelta';
		}

		if ( ! is_callable( $db_delta ) ) {
			return new WP_Error( 'nicepay_schema_missing_dbdelta', 'NicePay schema installation requires dbDelta.' );
		}

		$sql            = NicePay_Transaction_Schema::create_table_sql( $table, $wpdb->get_charset_collate() );
		$refund_sql     = NicePay_Transaction_Schema::create_refund_table_sql( $refund_table, $wpdb->get_charset_collate() );
		$changes        = call_user_func( $db_delta, $sql );
		$refund_changes = call_user_func( $db_delta, $refund_sql );

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error(
				'nicepay_schema_db_error',
				'NicePay transaction schema upgrade failed.',
				array( 'database_error' => $wpdb->last_error )
			);
		}

		if ( ! self::table_exists( $wpdb, $table ) || ! self::table_exists( $wpdb, $refund_table ) ) {
			return new WP_Error(
				'nicepay_schema_table_missing',
				'NicePay transaction schema upgrade did not create all required tables.'
			);
		}

		if ( $current !== $target && false === update_option( self::VERSION_OPTION, $target, false ) ) {
			return new WP_Error( 'nicepay_schema_version_write_failed', 'NicePay could not store the transaction schema version.' );
		}

		return array(
			'status'  => 'installed',
			'version' => $target,
			'table'   => $table,
			'refund_table' => $refund_table,
			'changes' => array_merge(
				is_array( $changes ) ? $changes : array(),
				is_array( $refund_changes ) ? $refund_changes : array()
			),
		);
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
			return new WP_Error( 'nicepay_schema_missing_query', 'NicePay schema migration requires database write access.' );
		}

		$queries = array();
		if ( self::column_exists( $wpdb, $table, 'tid' ) ) {
			$queries[] = "UPDATE {$table} SET tid = NULL WHERE tid = ''";
		}
		if ( self::column_exists( $wpdb, $table, 'card_no' ) ) {
			$queries[] = "UPDATE {$table} SET card_no = '' WHERE card_no <> ''";
		}
		if ( self::column_exists( $wpdb, $table, 'auth_token' ) && self::column_exists( $wpdb, $table, 'status' ) ) {
			$queries[] = "UPDATE {$table} SET auth_token = '' WHERE auth_token <> '' AND status NOT IN ('pending', 'approving', 'needs_reconciliation')";
		}
		if ( self::column_exists( $wpdb, $table, 'payment_data' ) ) {
			$queries[] = "UPDATE {$table} SET payment_data = NULL
			 WHERE payment_data IS NOT NULL AND payment_data <> ''
			   AND (payment_data LIKE '%\"CardNo\"%'
			     OR payment_data LIKE '%\"AuthToken\"%'
			     OR payment_data LIKE '%\"Signature\"%'
			     OR payment_data LIKE '%\"BuyerEmail\"%'
			     OR payment_data LIKE '%\"BuyerTel\"%'
			     OR payment_data LIKE '%\"VbankNum\"%')";
		}

		foreach ( $queries as $query ) {
			$result = $wpdb->query( $query );
			if ( false === $result || ! empty( $wpdb->last_error ) ) {
				return new WP_Error(
					'nicepay_schema_sensitive_data_cleanup_failed',
					'NicePay could not safely clean legacy sensitive transaction data.',
					array( 'database_error' => $wpdb->last_error )
				);
			}
		}

		return true;
	}
}
