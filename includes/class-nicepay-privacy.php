<?php
/**
 * WordPress personal-data export and erasure integration.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes buyer contact data while retaining the minimum financial ledger.
 */
final class NicePay_Privacy {

	/** @param array $exporters Registered WordPress exporters. @return array */
	public static function register_exporter( $exporters ) {
		$exporters['nicepay-transactions'] = array(
			'exporter_friendly_name' => __( 'NicePay payment transactions', 'nicepay-payment-gateway' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/** @param array $erasers Registered WordPress erasers. @return array */
	public static function register_eraser( $erasers ) {
		$erasers['nicepay-transactions'] = array(
			'eraser_friendly_name' => __( 'NicePay payment transactions', 'nicepay-payment-gateway' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export transaction data belonging to an email address.
	 *
	 * Secrets, signatures, raw payloads and receipt tokens are excluded.
	 *
	 * @param string $email_address Data-subject email.
	 * @param int    $page          One-based page.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		$email = is_string( $email_address ) ? sanitize_email( $email_address ) : '';
		$result = array( 'data' => array(), 'done' => true );
		if ( self::is_valid_email( $email ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) {
			$limit  = 100;
			$offset = ( max( 1, (int) $page ) - 1 ) * $limit;
			$rows   = self::export_transaction_rows( $wpdb, $email, $limit, $offset );
			$data   = array_map( array( __CLASS__, 'format_transaction_export' ), (array) $rows );
			$ids    = self::export_transaction_ids( $rows );
			$refunds = self::export_refund_rows( $wpdb, $ids );
			$data    = array_merge( $data, array_map( array( __CLASS__, 'format_refund_export' ), $refunds ) );
			$result  = array( 'data' => $data, 'done' => count( (array) $rows ) < $limit );
		}
		return $result;
	}

	/** @return object[] */
	private static function export_transaction_rows( $wpdb, $email, $limit, $offset ) {
		$table = $wpdb->prefix . 'nicepay_transactions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, tid, moid, wc_order_id, amount, currency, payment_method,
				        status, buyer_name, buyer_email, buyer_tel, created_at
				 FROM {$table}
				 WHERE buyer_email = %s
				 ORDER BY id ASC LIMIT %d OFFSET %d",
				$email,
				$limit,
				$offset
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return int[] */
	private static function export_transaction_ids( $rows ) {
		$ids = array_map(
			static function ( $row ) {
				return is_object( $row ) && isset( $row->id ) ? absint( $row->id ) : 0;
			},
			(array) $rows
		);
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** @return object[] */
	private static function export_refund_rows( $wpdb, array $transaction_ids ) {
		$rows = array();
		if ( ! empty( $transaction_ids ) ) {
			$id_list = implode( ',', array_map( 'absint', $transaction_ids ) );
			$table   = $wpdb->prefix . 'nicepay_refund_attempts';
			$query   = "SELECT id, transaction_id, requested_amount, currency, reason, status, requested_at
				FROM {$table} WHERE transaction_id IN ({$id_list}) ORDER BY id ASC";
			$result  = $wpdb->get_results( $query );
			$rows    = is_array( $result ) ? $result : array();
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	private static function format_transaction_export( $row ) {
		$id       = is_object( $row ) && isset( $row->id ) ? absint( $row->id ) : 0;
		$currency = is_object( $row ) && isset( $row->currency ) ? (string) $row->currency : 'KRW';
		return self::export_item(
			'nicepay-transaction-' . $id,
			array(
				array( 'name' => __( 'Buyer name', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'buyer_name' ) ),
				array( 'name' => __( 'Buyer email', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'buyer_email' ) ),
				array( 'name' => __( 'Buyer phone', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'buyer_tel' ) ),
				array( 'name' => __( 'Merchant reference', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'moid' ) ),
				array( 'name' => __( 'Provider transaction ID', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'tid' ) ),
				array( 'name' => __( 'WooCommerce order ID', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'wc_order_id' ) ),
				array( 'name' => __( 'Amount', 'nicepay-payment-gateway' ), 'value' => nicepay_format_amount( self::row_value( $row, 'amount', 0 ), $currency ) ),
				array( 'name' => __( 'Payment method', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'payment_method' ) ),
				array( 'name' => __( 'Payment status', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'status' ) ),
				array( 'name' => __( 'Created at', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'created_at' ) ),
			)
		);
	}

	/** @return array<string,mixed> */
	private static function format_refund_export( $row ) {
		$id       = is_object( $row ) && isset( $row->id ) ? absint( $row->id ) : 0;
		$currency = is_object( $row ) && isset( $row->currency ) ? (string) $row->currency : 'KRW';
		return self::export_item(
			'nicepay-refund-attempt-' . $id,
			array(
				array( 'name' => __( 'Transaction ID', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'transaction_id' ) ),
				array( 'name' => __( 'Amount', 'nicepay-payment-gateway' ), 'value' => nicepay_format_amount( self::row_value( $row, 'requested_amount', 0 ), $currency ) ),
				array( 'name' => __( 'Refund reason', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'reason' ) ),
				array( 'name' => __( 'Status', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'status' ) ),
				array( 'name' => __( 'Date', 'nicepay-payment-gateway' ), 'value' => self::row_value( $row, 'requested_at' ) ),
			)
		);
	}

	/** @return array<string,mixed> */
	private static function export_item( $item_id, array $data ) {
		return array(
			'group_id'    => 'nicepay-transactions',
			'group_label' => __( 'NicePay payment transactions', 'nicepay-payment-gateway' ),
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}

	/** @return mixed */
	private static function row_value( $row, $property, $default = '' ) {
		return is_object( $row ) && isset( $row->{$property} ) ? $row->{$property} : $default;
	}

	/**
	 * Erase contact/presentation data while preserving the accounting ledger.
	 *
	 * @param string $email_address Data-subject email.
	 * @param int    $page          Ignored; each pass removes the next batch.
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		unset( $page );

		$email = is_string( $email_address ) ? sanitize_email( $email_address ) : '';
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		if ( self::is_valid_email( $email ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) {
			$ids     = self::erasable_transaction_ids( $wpdb, $email );
			$updated = self::erase_ledger_contacts( $wpdb, $ids );
			if ( is_wp_error( $updated ) ) {
				$result['messages'][] = $updated->get_error_message();
				$result['done']       = false;
			} elseif ( ! empty( $ids ) ) {
				$result['items_removed']  = 0 < $updated;
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'Financial transaction references and amounts were retained for accounting and reconciliation.', 'nicepay-payment-gateway' );
				$result['done']           = count( $ids ) < 100;
			}
			if ( $result['done'] ) {
				$result['items_removed'] = self::erase_saved_offer_contact( $email ) || $result['items_removed'];
			}
		}
		return $result;
	}

	/** @return int[] */
	private static function erasable_transaction_ids( $wpdb, $email ) {
		$table = $wpdb->prefix . 'nicepay_transactions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE buyer_email = %s
				   AND status NOT IN ('pending', 'approving', 'needs_reconciliation')
				 ORDER BY id ASC LIMIT %d",
				$email,
				100
			)
		);
		return self::export_transaction_ids( $rows );
	}

	/** @return int|WP_Error */
	private static function erase_ledger_contacts( $wpdb, array $ids ) {
		$result = 0;
		if ( ! empty( $ids ) ) {
			$id_list = implode( ',', array_map( 'absint', $ids ) );
			$table   = $wpdb->prefix . 'nicepay_transactions';
			$updated = $wpdb->query(
				"UPDATE {$table}
				 SET buyer_name = '', buyer_email = '', buyer_tel = '',
				     receipt_token_hash = '', receipt_issued_at = NULL, payment_data = NULL,
				     updated_at = UTC_TIMESTAMP()
				 WHERE id IN ({$id_list})"
			);
			$refund_table   = $wpdb->prefix . 'nicepay_refund_attempts';
			$refund_updated = false === $updated
				? false
				: $wpdb->query( "UPDATE {$refund_table} SET reason = '' WHERE transaction_id IN ({$id_list})" );
			$result = false === $updated || false === $refund_updated
				? new WP_Error( 'nicepay_privacy_erase_failed', __( 'NicePay buyer data could not be erased from the transaction ledger.', 'nicepay-payment-gateway' ) )
				: (int) $updated;
		}
		return $result;
	}

	/** @param string $email Email. @return bool */
	private static function is_valid_email( $email ) {
		return function_exists( 'is_email' )
			? (bool) is_email( $email )
			: false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/** @param string $email Email. @return bool Whether a saved offer changed. */
	private static function erase_saved_offer_contact( $email ) {
		$shortcodes = get_option( 'nicepay_saved_shortcodes', array() );
		if ( ! is_array( $shortcodes ) ) {
			return false;
		}

		$changed = false;
		foreach ( $shortcodes as &$shortcode ) {
			if ( ! is_array( $shortcode ) || ! isset( $shortcode['buyer_email'] ) ||
				! is_string( $shortcode['buyer_email'] ) ||
				! hash_equals( strtolower( $email ), strtolower( $shortcode['buyer_email'] ) ) ) {
				continue;
			}
			$shortcode['buyer_name']  = '';
			$shortcode['buyer_email'] = '';
			$shortcode['buyer_tel']   = '';
			$shortcode['is_preset']   = false;
			unset( $shortcode['preset_version'] );
			$changed = true;
		}
		unset( $shortcode );

		if ( $changed ) {
			update_option( 'nicepay_saved_shortcodes', nicepay_prepare_shortcodes_for_storage( $shortcodes ) );
		}
		return $changed;
	}
}
