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
		if ( ! self::is_valid_email( $email ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return array( 'data' => array(), 'done' => true );
		}

		$limit  = 100;
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * $limit;
		$table  = $wpdb->prefix . 'nicepay_transactions';
		$rows   = $wpdb->get_results(
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

		$data = array();
		foreach ( (array) $rows as $row ) {
			$id       = isset( $row->id ) ? absint( $row->id ) : 0;
			$currency = isset( $row->currency ) ? (string) $row->currency : 'KRW';
			$data[]   = array(
				'group_id'    => 'nicepay-transactions',
				'group_label' => __( 'NicePay payment transactions', 'nicepay-payment-gateway' ),
				'item_id'     => 'nicepay-transaction-' . $id,
				'data'        => array(
					array( 'name' => __( 'Buyer name', 'nicepay-payment-gateway' ), 'value' => isset( $row->buyer_name ) ? (string) $row->buyer_name : '' ),
					array( 'name' => __( 'Buyer email', 'nicepay-payment-gateway' ), 'value' => isset( $row->buyer_email ) ? (string) $row->buyer_email : '' ),
					array( 'name' => __( 'Buyer phone', 'nicepay-payment-gateway' ), 'value' => isset( $row->buyer_tel ) ? (string) $row->buyer_tel : '' ),
					array( 'name' => __( 'Merchant reference', 'nicepay-payment-gateway' ), 'value' => isset( $row->moid ) ? (string) $row->moid : '' ),
					array( 'name' => __( 'Provider transaction ID', 'nicepay-payment-gateway' ), 'value' => isset( $row->tid ) ? (string) $row->tid : '' ),
					array( 'name' => __( 'WooCommerce order ID', 'nicepay-payment-gateway' ), 'value' => isset( $row->wc_order_id ) ? (string) $row->wc_order_id : '' ),
					array( 'name' => __( 'Amount', 'nicepay-payment-gateway' ), 'value' => nicepay_format_amount( isset( $row->amount ) ? $row->amount : 0, $currency ) ),
					array( 'name' => __( 'Payment method', 'nicepay-payment-gateway' ), 'value' => isset( $row->payment_method ) ? (string) $row->payment_method : '' ),
					array( 'name' => __( 'Payment status', 'nicepay-payment-gateway' ), 'value' => isset( $row->status ) ? (string) $row->status : '' ),
					array( 'name' => __( 'Created at', 'nicepay-payment-gateway' ), 'value' => isset( $row->created_at ) ? (string) $row->created_at : '' ),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( (array) $rows ) < $limit,
		);
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
		if ( ! self::is_valid_email( $email ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return $result;
		}

		$table = $wpdb->prefix . 'nicepay_transactions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE buyer_email = %s ORDER BY id ASC LIMIT %d",
				$email,
				100
			)
		);
		$ids = array_values(
			array_filter(
				array_map(
					static function ( $row ) {
						return isset( $row->id ) ? absint( $row->id ) : 0;
					},
					(array) $rows
				)
			)
		);

		if ( ! empty( $ids ) ) {
			$id_list = implode( ',', array_map( 'absint', $ids ) );
			$updated = $wpdb->query(
				"UPDATE {$table}
				 SET buyer_name = '', buyer_email = '', buyer_tel = '',
				     receipt_token_hash = '', receipt_issued_at = NULL, payment_data = NULL
				 WHERE id IN ({$id_list})"
			);
			if ( false === $updated ) {
				$result['messages'][] = __( 'NicePay buyer data could not be erased from the transaction ledger.', 'nicepay-payment-gateway' );
				$result['done'] = false;
				return $result;
			}
			$result['items_removed']  = 0 < (int) $updated;
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Financial transaction references and amounts were retained for accounting and reconciliation.', 'nicepay-payment-gateway' );
			$result['done']           = count( $ids ) < 100;
		}

		if ( $result['done'] ) {
			$result['items_removed'] = self::erase_saved_offer_contact( $email ) || $result['items_removed'];
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
