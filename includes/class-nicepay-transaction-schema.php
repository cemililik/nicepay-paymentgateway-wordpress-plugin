<?php
/**
 * NicePay transaction table schema definition.
 *
 * This file deliberately has no WordPress bootstrap side effects. It provides a
 * deterministic schema definition that can be consumed by dbDelta and by a
 * future transaction repository.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the transaction table independently from installation orchestration.
 */
final class NicePay_Transaction_Schema {

	/**
	 * Schema version. This is intentionally independent from the plugin version.
	 */
	const VERSION = '2026.08.24.7';
	const COLUMN_VARCHAR_100 = "varchar(100) NOT NULL DEFAULT ''";
	const COLUMN_VARCHAR_64  = "varchar(64) NOT NULL DEFAULT ''";
	const COLUMN_VARCHAR_50  = "varchar(50) NOT NULL DEFAULT ''";
	const COLUMN_VARCHAR_20  = "varchar(20) NOT NULL DEFAULT ''";
	const COLUMN_VARCHAR_5   = "varchar(5) NOT NULL DEFAULT ''";
	const COLUMN_CHAR_64     = "char(64) NOT NULL DEFAULT ''";
	const COLUMN_DATETIME_NULL = 'datetime DEFAULT NULL';
	const COLUMN_DECIMAL_14_2  = 'decimal(14,2) NOT NULL DEFAULT 0';

	/**
	 * Return the ordered column definitions used by dbDelta.
	 *
	 * Existing column names are retained. New lifecycle columns are additive;
	 * legacy-value backfills are intentionally outside this schema definition.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'id'                          => 'bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT',
				'tid'                         => 'varchar(50) DEFAULT NULL',
			'order_id'                    => self::COLUMN_VARCHAR_100,
			'wc_order_id'                 => 'bigint(20) UNSIGNED DEFAULT NULL',
			'moid'                        => self::COLUMN_VARCHAR_64,
			'flow'                        => self::COLUMN_VARCHAR_20,
				'source_ref'                  => "varchar(191) NOT NULL DEFAULT ''",
				'active_attempt_key'          => 'varchar(191) DEFAULT NULL',
			'config_fingerprint'          => self::COLUMN_CHAR_64,
			'expected_method'             => self::COLUMN_VARCHAR_20,
			'allowed_methods'              => self::COLUMN_VARCHAR_100,
			'binding_token_hash'          => self::COLUMN_CHAR_64,
			'wc_order_key_hash'           => self::COLUMN_CHAR_64,
			'edi_date'                     => "char(14) NOT NULL DEFAULT ''",
			'offer_expires_at'            => self::COLUMN_DATETIME_NULL,
			'mid'                         => self::COLUMN_VARCHAR_20,
			'mode'                        => "varchar(10) NOT NULL DEFAULT ''",
			'currency'                    => "char(3) NOT NULL DEFAULT ''",
			'amount'                      => self::COLUMN_DECIMAL_14_2,
			'captured_amount'             => self::COLUMN_DECIMAL_14_2,
			'refunded_amount'             => self::COLUMN_DECIMAL_14_2,
			'remaining_amount'            => self::COLUMN_DECIMAL_14_2,
			'payment_method'              => self::COLUMN_VARCHAR_20,
			'pay_method_name'             => self::COLUMN_VARCHAR_50,
			'status'                      => "varchar(20) NOT NULL DEFAULT 'pending'",
				'result_code'                 => self::COLUMN_VARCHAR_64,
			'result_msg'                  => 'text NOT NULL',
			'auth_token'                  => self::COLUMN_VARCHAR_50,
			'approval_state'              => "varchar(20) NOT NULL DEFAULT 'pending'",
			'approval_attempts'           => 'smallint(5) UNSIGNED NOT NULL DEFAULT 0',
			'approval_started_at'         => self::COLUMN_DATETIME_NULL,
			'approved_at'                 => self::COLUMN_DATETIME_NULL,
			'reconciliation_status'       => "varchar(20) NOT NULL DEFAULT 'unreconciled'",
			'reconciliation_checked_at'   => self::COLUMN_DATETIME_NULL,
			'reconciliation_note'         => 'text',
			'receipt_token_hash'           => self::COLUMN_CHAR_64,
			'receipt_issued_at'            => self::COLUMN_DATETIME_NULL,
			'buyer_name'                  => self::COLUMN_VARCHAR_100,
			'buyer_email'                 => self::COLUMN_VARCHAR_100,
			'buyer_tel'                   => "varchar(30) NOT NULL DEFAULT ''",
			'goods_name'                  => self::COLUMN_VARCHAR_100,
			'card_code'                   => self::COLUMN_VARCHAR_5,
			'card_name'                   => self::COLUMN_VARCHAR_50,
			'card_quota'                  => self::COLUMN_VARCHAR_5,
			'cc_part_cl'                  => "char(1) NOT NULL DEFAULT ''",
			'clickpay_cl'                 => "varchar(2) NOT NULL DEFAULT ''",
			'card_type'                   => "varchar(2) NOT NULL DEFAULT ''",
			'bank_code'                   => self::COLUMN_VARCHAR_5,
			'bank_name'                   => self::COLUMN_VARCHAR_50,
			'vbank_num'                   => "varchar(30) NOT NULL DEFAULT ''",
			'vbank_exp_date'              => self::COLUMN_VARCHAR_20,
			'vbank_issued_at'             => self::COLUMN_DATETIME_NULL,
			'vbank_expires_at'            => self::COLUMN_DATETIME_NULL,
			'vbank_deposited_at'          => self::COLUMN_DATETIME_NULL,
			'otid'                        => self::COLUMN_VARCHAR_50,
			'cancel_moid'                 => self::COLUMN_VARCHAR_64,
			'cancel_status'               => self::COLUMN_VARCHAR_20,
			'cancel_amount'               => self::COLUMN_DECIMAL_14_2,
				'cancel_result_code'          => self::COLUMN_VARCHAR_64,
			'cancel_result_msg'           => 'text',
			'cancel_requested_at'         => self::COLUMN_DATETIME_NULL,
			'cancel_completed_at'         => self::COLUMN_DATETIME_NULL,
			'net_cancel_status'           => self::COLUMN_VARCHAR_20,
				'net_cancel_result_code'      => self::COLUMN_VARCHAR_64,
			'net_cancel_result_msg'       => 'text',
			'net_cancel_requested_at'     => self::COLUMN_DATETIME_NULL,
			'net_cancel_completed_at'     => self::COLUMN_DATETIME_NULL,
			'payment_data'                => 'longtext',
			'created_at'                  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
			'updated_at'                  => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
		);
	}

	/**
	 * Return indexes in dbDelta-compatible syntax.
	 *
	 * @return string[]
	 */
	public static function indexes() {
		return array(
			'PRIMARY KEY  (id)',
				'UNIQUE KEY uniq_moid (moid)',
				'UNIQUE KEY uniq_active_attempt (active_attempt_key)',
				'UNIQUE KEY uniq_tid (tid)',
			'KEY idx_wc_order_id (wc_order_id)',
			'KEY idx_created_at (created_at)',
			'KEY idx_updated_at (updated_at)',
			'KEY idx_status_created (status, created_at)',
			'KEY idx_flow_status (flow, status)',
			'KEY idx_source_ref (flow, source_ref)',
			'KEY idx_reconciliation_status (reconciliation_status)',
			'KEY idx_receipt_token_hash (receipt_token_hash)',
			'KEY idx_vbank_expires_at (vbank_expires_at)',
		);
	}

	/**
	 * Return the append-only refund-attempt ledger columns.
	 *
	 * @return array<string,string>
	 */
	public static function refund_columns() {
		return array(
			'id'               => 'bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT',
			'transaction_id'   => 'bigint(20) UNSIGNED NOT NULL',
			'wc_order_id'      => 'bigint(20) UNSIGNED NOT NULL',
			'tid'              => self::COLUMN_VARCHAR_50,
			'cancel_moid'      => self::COLUMN_VARCHAR_64,
			'requested_amount' => self::COLUMN_DECIMAL_14_2,
			'currency'         => "char(3) NOT NULL DEFAULT 'KRW'",
			'reason'           => self::COLUMN_VARCHAR_100,
			'status'           => "varchar(20) NOT NULL DEFAULT 'requested'",
			'result_code'      => self::COLUMN_VARCHAR_64,
			'result_msg'       => 'text',
			'response_data'    => 'longtext',
			'requested_at'     => 'datetime NOT NULL',
			'completed_at'     => self::COLUMN_DATETIME_NULL,
			'created_at'       => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
		);
	}

	/** @return string[] */
	public static function refund_indexes() {
		return array(
			'PRIMARY KEY  (id)',
			'UNIQUE KEY uniq_cancel_moid (cancel_moid)',
			'KEY idx_transaction_created (transaction_id, created_at)',
			'KEY idx_order_created (wc_order_id, created_at)',
			'KEY idx_refund_status (status)',
		);
	}

	/**
	 * Build the complete CREATE TABLE statement.
	 *
	 * The statement intentionally omits IF NOT EXISTS so dbDelta can compare and
	 * upgrade an existing table.
	 *
	 * @param string $table_name     Fully-prefixed table name.
	 * @param string $charset_collate Charset/collation suffix from wpdb.
	 * @return string
	 * @throws InvalidArgumentException When the table identifier is unsafe.
	 */
	public static function create_table_sql( $table_name, $charset_collate = '' ) {
		if ( ! is_string( $table_name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException( 'Invalid NicePay transaction table name.' );
		}

		$lines = array();
		foreach ( self::columns() as $name => $definition ) {
			$lines[] = $name . ' ' . $definition;
		}
		$lines = array_merge( $lines, self::indexes() );

		$suffix = trim( (string) $charset_collate );
		if ( '' !== $suffix ) {
			$suffix = ' ' . $suffix;
		}

		return "CREATE TABLE {$table_name} (\n\t" . implode( ",\n\t", $lines ) . "\n){$suffix};";
	}

	/**
	 * Build the refund-attempt ledger CREATE TABLE statement.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @param string $charset_collate Charset/collation suffix.
	 * @return string
	 */
	public static function create_refund_table_sql( $table_name, $charset_collate = '' ) {
		if ( ! is_string( $table_name ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table_name ) ) {
			throw new InvalidArgumentException( 'Invalid NicePay refund table name.' );
		}

		$lines = array();
		foreach ( self::refund_columns() as $name => $definition ) {
			$lines[] = $name . ' ' . $definition;
		}
		$lines  = array_merge( $lines, self::refund_indexes() );
		$suffix = trim( (string) $charset_collate );
		$suffix = '' !== $suffix ? ' ' . $suffix : '';

		return "CREATE TABLE {$table_name} (\n\t" . implode( ",\n\t", $lines ) . "\n){$suffix};";
	}

	/**
	 * Return the wpdb format for a column.
	 *
	 * Decimal values intentionally use %s to avoid floating-point conversion.
	 *
	 * @param string $column Column name.
	 * @return string|null
	 */
	public static function format_for( $column ) {
		if ( ! array_key_exists( $column, self::columns() ) ) {
			return null;
		}

		if ( in_array( $column, array( 'id', 'wc_order_id', 'approval_attempts' ), true ) ) {
			return '%d';
		}

		return '%s';
	}

	/**
	 * Whitelist a repository write and return aligned wpdb formats.
	 *
	 * Database-owned identity/timestamp fields cannot be supplied by callers.
	 *
	 * @param array<string,mixed> $data Candidate transaction data.
	 * @return array{data:array<string,mixed>,formats:string[]}
	 */
	public static function prepare_write( array $data ) {
		$database_owned = array( 'id', 'created_at', 'updated_at' );
		$prepared       = array();
		$formats        = array();

		foreach ( $data as $column => $value ) {
			if ( in_array( $column, $database_owned, true ) || null === self::format_for( $column ) ) {
				continue;
			}

			$prepared[ $column ] = $value;
			$formats[]            = self::format_for( $column );
		}

		return array(
			'data'    => $prepared,
			'formats' => $formats,
		);
	}

	/**
	 * Whitelist a refund-attempt write and align wpdb formats.
	 *
	 * @param array<string,mixed> $data Candidate refund data.
	 * @return array{data:array<string,mixed>,formats:string[]}
	 */
	public static function prepare_refund_write( array $data ) {
		$prepared = array();
		$formats  = array();
		foreach ( $data as $column => $value ) {
			if ( in_array( $column, array( 'id', 'created_at' ), true ) || ! array_key_exists( $column, self::refund_columns() ) ) {
				continue;
			}
			$prepared[ $column ] = $value;
			$formats[] = in_array( $column, array( 'transaction_id', 'wc_order_id' ), true ) ? '%d' : '%s';
		}

		return array( 'data' => $prepared, 'formats' => $formats );
	}
}
