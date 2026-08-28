<?php
/**
 * Opt-in financial-record retention for NicePay's own ledger tables.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a merchant-selected retention period without touching unresolved
 * money states, WooCommerce orders, backups, or NICEPAY provider records.
 */
final class NicePay_Retention {

	const SETTINGS_OPTION = 'nicepay_retention_settings';
	const LAST_RUN_OPTION = 'nicepay_retention_last_run';
	const LOCK_OPTION     = 'nicepay_retention_lock';
	const CRON_HOOK       = 'nicepay_apply_financial_retention';
	const BATCH_SIZE      = 100;
	const MAX_BATCHES     = 5;
	const MIN_DAYS        = 1;
	const MAX_DAYS        = 36500;
	const LOCK_TTL        = 21600;

	/** @return array<string,mixed> */
	public static function default_settings() {
		return array(
			'mode'         => 'indefinite',
			'days'         => 0,
			'acknowledged' => 'no',
		);
	}

	/** @return array<string,mixed> */
	public static function get_settings() {
		$stored = get_option( self::SETTINGS_OPTION, self::default_settings() );
		if ( ! is_array( $stored ) || 'custom' !== ( isset( $stored['mode'] ) ? $stored['mode'] : '' ) ) {
			return self::default_settings();
		}

		$days = isset( $stored['days'] ) ? self::normalize_integer( $stored['days'] ) : 0;
		if ( $days < self::MIN_DAYS || $days > self::MAX_DAYS ||
			'yes' !== ( isset( $stored['acknowledged'] ) ? $stored['acknowledged'] : 'no' ) ) {
			return self::default_settings();
		}

		return array(
			'mode'         => 'custom',
			'days'         => $days,
			'acknowledged' => 'yes',
		);
	}

	/**
	 * Validate a destructive retention choice made through the Settings API.
	 *
	 * Invalid custom choices preserve the last valid policy rather than
	 * silently enabling deletion or changing an existing retention period.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$current = self::get_settings();
		$result  = $current;
		if ( ! is_array( $input ) ) {
			self::settings_error( 'invalid', __( 'The financial retention setting was not changed because its value was invalid.', 'nicepay-payment-gateway' ) );
		} else {
			$mode = isset( $input['mode'] ) && is_scalar( $input['mode'] )
				? sanitize_key( (string) $input['mode'] )
				: 'indefinite';
			$days         = isset( $input['days'] ) ? self::normalize_integer( $input['days'] ) : 0;
			$acknowledged = isset( $input['acknowledged'] ) && 'yes' === $input['acknowledged'] ? 'yes' : 'no';
			if ( 'custom' !== $mode ) {
				$result = self::default_settings();
			} elseif ( $days < self::MIN_DAYS || $days > self::MAX_DAYS ) {
				self::settings_error(
					'days',
					sprintf(
						/* translators: %1$d: minimum days, %2$d: maximum days */
						__( 'Enter a financial retention period between %1$d and %2$d days.', 'nicepay-payment-gateway' ),
						self::MIN_DAYS,
						self::MAX_DAYS
					)
				);
			} elseif ( 'yes' !== $acknowledged ) {
				self::settings_error( 'acknowledgement', __( 'Confirm the permanent-deletion warning before enabling a finite financial retention period.', 'nicepay-payment-gateway' ) );
			} else {
				$result = array( 'mode' => 'custom', 'days' => $days, 'acknowledged' => 'yes' );
			}
		}
		return $result;
	}

	/**
	 * Keep the daily cron aligned with the active policy.
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		$settings  = self::get_settings();
		if ( 'custom' === $settings['mode'] && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		} elseif ( 'custom' !== $settings['mode'] && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** @return void */
	public static function settings_updated() {
		self::sync_schedule();
	}

	/**
	 * Run bounded daily cleanup and retain only non-sensitive operational proof.
	 *
	 * @return int Number of deleted transaction rows.
	 */
	public static function run_scheduled() {
		$settings = self::get_settings();
		if ( 'custom' !== $settings['mode'] || ! self::acquire_lock() ) {
			return 0;
		}

		$total      = 0;
		$error_code = '';
		try {
			for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
				$result = self::purge_batch( $settings['days'], self::BATCH_SIZE );
				if ( is_wp_error( $result ) ) {
					$error_code = $result->get_error_code();
					nicepay_log( 'Financial retention purge failed', $error_code, 'error' );
					break;
				}

				$total += $result;
				if ( $result < self::BATCH_SIZE ) {
					break;
				}
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		update_option(
			self::LAST_RUN_OPTION,
			array(
				'completed_at' => gmdate( NICEPAY_DB_DATETIME_FORMAT ),
				'deleted'      => $total,
				'error_code'   => $error_code,
			)
		);

		if ( $total > 0 ) {
			nicepay_log(
				'Financial retention purge completed',
				array( 'deleted' => $total, 'retention_days' => $settings['days'] ),
				'warning'
			);
		}

		return $total;
	}

	/**
	 * Permanently remove one bounded set of eligible ledger rows.
	 *
	 * The transaction rows are locked before deletion. Refund-attempt rows and
	 * their parent transactions are removed in one database transaction. Any
	 * disagreement or write error rolls the operation back.
	 *
	 * @param int $days Retention period.
	 * @param int $limit Maximum rows in this batch.
	 * @return int|WP_Error
	 */
	public static function purge_batch( $days, $limit = self::BATCH_SIZE ) {
		global $wpdb;

		$context = self::retention_context( $wpdb, $days, $limit );
		return is_wp_error( $context ) ? $context : self::execute_purge_batch( $wpdb, $context );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function retention_context( $wpdb, $days, $limit ) {
		$days   = self::normalize_integer( $days );
		$limit  = self::normalize_integer( $limit );
		$result = array();
		if ( $days < self::MIN_DAYS || $days > self::MAX_DAYS || 1 > $limit || self::BATCH_SIZE < $limit ||
			! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			$result = new WP_Error( 'nicepay_retention_invalid_request', __( 'NicePay retention parameters are invalid.', 'nicepay-payment-gateway' ) );
		} else {
			$result = array(
				'days' => $days, 'limit' => $limit,
				'transaction_table' => $wpdb->prefix . 'nicepay_transactions',
				'refund_table' => $wpdb->prefix . 'nicepay_refund_attempts',
				'audit_table' => $wpdb->prefix . 'nicepay_reconciliation_audit',
			);
			if ( ! self::tables_are_transactional( $wpdb, $result ) ) {
				$result = new WP_Error( 'nicepay_retention_engine_unsupported', __( 'NicePay retention requires InnoDB tables for transactional deletion.', 'nicepay-payment-gateway' ) );
			}
		}
		return $result;
	}

	/** @return bool */
	private static function tables_are_transactional( $wpdb, array $context ) {
		$valid = true;
		foreach ( array( 'transaction_table', 'refund_table', 'audit_table' ) as $key ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$context[ $key ]
				)
			);
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				$valid = false;
				break;
			}
		}
		return $valid;
	}

	/** @return int|WP_Error */
	private static function execute_purge_batch( $wpdb, array $context ) {
		$result = new WP_Error( 'nicepay_retention_transaction_start_failed', __( 'NicePay could not start the retention transaction.', 'nicepay-payment-gateway' ) );
		if ( false !== $wpdb->query( 'START TRANSACTION' ) ) {
			$ids = self::select_retention_ids( $wpdb, $context );
			if ( is_wp_error( $ids ) ) {
				$result = $ids;
			} elseif ( empty( $ids ) ) {
				$result = false === $wpdb->query( 'COMMIT' )
					? new WP_Error( 'nicepay_retention_commit_failed', __( 'NicePay could not commit the retention transaction.', 'nicepay-payment-gateway' ) )
					: 0;
			} else {
				$result = self::delete_retention_ids( $wpdb, $context, $ids );
			}
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
		return $result;
	}

	/** @return int[]|WP_Error */
	private static function select_retention_ids( $wpdb, array $context ) {
		$where = self::eligible_where_sql( $context['refund_table'], $context['days'] );
		$rows  = $wpdb->get_col(
			"SELECT ledger.id FROM {$context['transaction_table']} AS ledger
			 WHERE {$where} ORDER BY ledger.created_at ASC, ledger.id ASC
			 LIMIT {$context['limit']} FOR UPDATE"
		);
		return is_array( $rows ) && empty( $wpdb->last_error )
			? array_values( array_unique( array_filter( array_map( 'absint', $rows ) ) ) )
			: new WP_Error( 'nicepay_retention_select_failed', __( 'NicePay could not select retention records safely.', 'nicepay-payment-gateway' ) );
	}

	/** @return int|WP_Error */
	private static function delete_retention_ids( $wpdb, array $context, array $ids ) {
		$id_list = implode( ',', $ids );
		$result  = null;
		if ( false === $wpdb->query( "DELETE FROM {$context['refund_table']} WHERE transaction_id IN ({$id_list})" ) ) {
			$result = new WP_Error( 'nicepay_retention_refund_delete_failed', __( 'NicePay could not delete refund audit records safely.', 'nicepay-payment-gateway' ) );
		} elseif ( false === $wpdb->query( "DELETE FROM {$context['audit_table']} WHERE transaction_id IN ({$id_list})" ) ) {
			$result = new WP_Error( 'nicepay_retention_reconciliation_audit_delete_failed', __( 'NicePay could not delete reconciliation audit records safely.', 'nicepay-payment-gateway' ) );
		} else {
			$deleted = $wpdb->query(
				"DELETE ledger FROM {$context['transaction_table']} AS ledger
				 WHERE ledger.id IN ({$id_list}) AND " . self::eligible_row_predicate( $context['days'] )
			);
			if ( count( $ids ) !== (int) $deleted ) {
				$result = new WP_Error( 'nicepay_retention_delete_mismatch', __( 'NicePay retention records changed before deletion.', 'nicepay-payment-gateway' ) );
			} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
				$result = new WP_Error( 'nicepay_retention_commit_failed', __( 'NicePay could not commit the retention transaction.', 'nicepay-payment-gateway' ) );
			} else {
				$result = (int) $deleted;
			}
		}
		return $result;
	}

	/**
	 * Count rows currently eligible for the selected policy.
	 *
	 * @param int $days Retention period.
	 * @return int|null Count, or null on a database error.
	 */
	public static function count_eligible( $days ) {
		global $wpdb;
		$days = self::normalize_integer( $days );
		if ( $days < self::MIN_DAYS || $days > self::MAX_DAYS || ! is_object( $wpdb ) || empty( $wpdb->prefix ) ) {
			return null;
		}

		$transaction_table = $wpdb->prefix . 'nicepay_transactions';
		$refund_table      = $wpdb->prefix . 'nicepay_refund_attempts';
		$count             = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$transaction_table} AS ledger
			 WHERE " . self::eligible_where_sql( $refund_table, $days )
		);

		return null === $count ? null : (int) $count;
	}

	/** @return bool */
	private static function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$locked_at = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $locked_at > 0 && $locked_at < ( $now - self::LOCK_TTL ) ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, $now, '', false );
		}

		return false;
	}

	/** @return string */
	private static function eligible_where_sql( $refund_table, $days ) {
		return self::eligible_row_predicate( $days ) .
			" AND NOT EXISTS (
				SELECT 1 FROM {$refund_table} AS refund_attempt
				WHERE refund_attempt.transaction_id = ledger.id
				  AND refund_attempt.status IN ('requested', 'unknown')
			)";
	}

	/** @return string */
	private static function eligible_row_predicate( $days ) {
		$days = absint( $days );
			return "ledger.created_at < (UTC_TIMESTAMP() - INTERVAL {$days} DAY)
			AND ledger.status IN ('paid', 'partially_refunded', 'refunded', 'failed', 'abandoned', 'expired', 'cancelled')
			AND ledger.status <> 'needs_reconciliation'
			AND ledger.reconciliation_status <> 'required'
			AND ledger.approval_state NOT IN ('pending', 'approving', 'needs_reconciliation')
			AND ledger.cancel_status NOT IN ('requested', 'unknown')
			AND ledger.net_cancel_status <> 'unknown'
			AND ledger.auth_token = ''
			AND ledger.active_attempt_key IS NULL";
	}

	/**
	 * Accept only canonical non-negative integer input; arrays, signs, decimal
	 * values and other PHP-coercible types must fail closed.
	 *
	 * @param mixed $value Candidate integer.
	 * @return int
	 */
	private static function normalize_integer( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		return preg_match( '/^[0-9]+$/', $value ) ? absint( $value ) : 0;
	}

	/** @return void */
	private static function settings_error( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::SETTINGS_OPTION, 'nicepay_retention_' . $code, $message, 'error' );
		}
	}
}
