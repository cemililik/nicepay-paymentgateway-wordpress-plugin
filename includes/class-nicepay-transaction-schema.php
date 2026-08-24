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
			'order_id'                    => "varchar(100) NOT NULL DEFAULT ''",
			'wc_order_id'                 => 'bigint(20) UNSIGNED DEFAULT NULL',
			'moid'                        => "varchar(64) NOT NULL DEFAULT ''",
			'flow'                        => "varchar(20) NOT NULL DEFAULT ''",
				'source_ref'                  => "varchar(191) NOT NULL DEFAULT ''",
				'active_attempt_key'          => 'varchar(191) DEFAULT NULL',
			'config_fingerprint'          => "char(64) NOT NULL DEFAULT ''",
			'expected_method'             => "varchar(20) NOT NULL DEFAULT ''",
			'allowed_methods'              => "varchar(100) NOT NULL DEFAULT ''",
			'binding_token_hash'          => "char(64) NOT NULL DEFAULT ''",
			'wc_order_key_hash'           => "char(64) NOT NULL DEFAULT ''",
			'edi_date'                     => "char(14) NOT NULL DEFAULT ''",
			'offer_expires_at'            => 'datetime DEFAULT NULL',
			'mid'                         => "varchar(20) NOT NULL DEFAULT ''",
			'mode'                        => "varchar(10) NOT NULL DEFAULT ''",
			'currency'                    => "char(3) NOT NULL DEFAULT ''",
			'amount'                      => 'decimal(14,2) NOT NULL DEFAULT 0',
			'captured_amount'             => 'decimal(14,2) NOT NULL DEFAULT 0',
			'refunded_amount'             => 'decimal(14,2) NOT NULL DEFAULT 0',
			'remaining_amount'            => 'decimal(14,2) NOT NULL DEFAULT 0',
			'payment_method'              => "varchar(20) NOT NULL DEFAULT ''",
			'pay_method_name'             => "varchar(50) NOT NULL DEFAULT ''",
			'status'                      => "varchar(20) NOT NULL DEFAULT 'pending'",
				'result_code'                 => "varchar(64) NOT NULL DEFAULT ''",
			'result_msg'                  => 'text NOT NULL',
			'auth_token'                  => "varchar(50) NOT NULL DEFAULT ''",
			'approval_state'              => "varchar(20) NOT NULL DEFAULT 'pending'",
			'approval_attempts'           => 'smallint(5) UNSIGNED NOT NULL DEFAULT 0',
			'approval_started_at'         => 'datetime DEFAULT NULL',
			'approved_at'                 => 'datetime DEFAULT NULL',
			'reconciliation_status'       => "varchar(20) NOT NULL DEFAULT 'unreconciled'",
			'reconciliation_checked_at'   => 'datetime DEFAULT NULL',
			'reconciliation_note'         => 'text',
			'receipt_token_hash'           => "char(64) NOT NULL DEFAULT ''",
			'receipt_issued_at'            => 'datetime DEFAULT NULL',
			'buyer_name'                  => "varchar(100) NOT NULL DEFAULT ''",
			'buyer_email'                 => "varchar(100) NOT NULL DEFAULT ''",
			'buyer_tel'                   => "varchar(30) NOT NULL DEFAULT ''",
			'goods_name'                  => "varchar(100) NOT NULL DEFAULT ''",
			'card_code'                   => "varchar(5) NOT NULL DEFAULT ''",
			'card_name'                   => "varchar(50) NOT NULL DEFAULT ''",
			'card_quota'                  => "varchar(5) NOT NULL DEFAULT ''",
			'cc_part_cl'                  => "char(1) NOT NULL DEFAULT ''",
			'clickpay_cl'                 => "varchar(2) NOT NULL DEFAULT ''",
			'card_type'                   => "varchar(2) NOT NULL DEFAULT ''",
			'bank_code'                   => "varchar(5) NOT NULL DEFAULT ''",
			'bank_name'                   => "varchar(50) NOT NULL DEFAULT ''",
			'vbank_num'                   => "varchar(30) NOT NULL DEFAULT ''",
			'vbank_exp_date'              => "varchar(20) NOT NULL DEFAULT ''",
			'vbank_issued_at'             => 'datetime DEFAULT NULL',
			'vbank_expires_at'            => 'datetime DEFAULT NULL',
			'vbank_deposited_at'          => 'datetime DEFAULT NULL',
			'otid'                        => "varchar(50) NOT NULL DEFAULT ''",
			'cancel_moid'                 => "varchar(64) NOT NULL DEFAULT ''",
			'cancel_status'               => "varchar(20) NOT NULL DEFAULT ''",
			'cancel_amount'               => 'decimal(14,2) NOT NULL DEFAULT 0',
				'cancel_result_code'          => "varchar(64) NOT NULL DEFAULT ''",
			'cancel_result_msg'           => 'text',
			'cancel_requested_at'         => 'datetime DEFAULT NULL',
			'cancel_completed_at'         => 'datetime DEFAULT NULL',
			'net_cancel_status'           => "varchar(20) NOT NULL DEFAULT ''",
				'net_cancel_result_code'      => "varchar(64) NOT NULL DEFAULT ''",
			'net_cancel_result_msg'       => 'text',
			'net_cancel_requested_at'     => 'datetime DEFAULT NULL',
			'net_cancel_completed_at'     => 'datetime DEFAULT NULL',
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
			'tid'              => "varchar(50) NOT NULL DEFAULT ''",
			'cancel_moid'      => "varchar(64) NOT NULL DEFAULT ''",
			'requested_amount' => 'decimal(14,2) NOT NULL DEFAULT 0',
			'currency'         => "char(3) NOT NULL DEFAULT 'KRW'",
			'reason'           => 'varchar(100) NOT NULL DEFAULT \'\'',
			'status'           => "varchar(20) NOT NULL DEFAULT 'requested'",
			'result_code'      => "varchar(64) NOT NULL DEFAULT ''",
			'result_msg'       => 'text',
			'response_data'    => 'longtext',
			'requested_at'     => 'datetime NOT NULL',
			'completed_at'     => 'datetime DEFAULT NULL',
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
