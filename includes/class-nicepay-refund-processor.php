<?php
/**
 * WooCommerce refund orchestration for the NicePay gateway.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NicePay_Refund_Processor {

	/** @var NicePay_API */
	private $api;

	public function __construct( NicePay_API $api ) {
		$this->api = $api;
	}

	/** @return true|WP_Error */
	public function process( $order_id, $amount = null, $reason = '' ) {
		$order  = wc_get_order( $order_id );
		$result = $order
			? $this->refund_context( $order )
			: new WP_Error( 'nicepay_refund_error', __( 'Order not found.', 'nicepay-payment-gateway' ) );

		if ( ! is_wp_error( $result ) ) {
			$context = array_merge( $result, array( 'order' => $order ) );
			$result  = $this->amount_context( $context, $amount );
		}
		if ( ! is_wp_error( $result ) ) {
			$context = array_merge( $context, $result );
			$result  = $this->validate_partial_refund( $context['transaction'], $context['is_partial'] );
		}
		if ( ! is_wp_error( $result ) ) {
			$context['reason']      = $this->refund_reason( $reason );
			$context['cancel_moid'] = $this->api->generate_moid( 'RF' . $order->get_id() );
			$result = $this->reserve_refund( $context );
		}
		if ( ! is_wp_error( $result ) ) {
			$context['attempt_id'] = $result;
			$api_result = $this->api->request_cancel(
				$context['tid'],
				$context['cancel_amount'],
				$context['reason'],
				$context['cancel_moid'],
				$context['is_partial']
			);
			$result = $this->complete_refund( $context, $api_result );
		}
		return $result;
	}

	/** @return array<string,mixed>|WP_Error */
	private function refund_context( $order ) {
		$tid         = (string) $order->get_meta( '_nicepay_tid' );
		$transaction = '' !== $tid ? nicepay_get_transaction_by_tid( $tid, $order->get_id() ) : null;
		$result      = array( 'tid' => $tid, 'transaction' => $transaction );
		if ( ! is_object( $transaction ) || (int) $transaction->wc_order_id !== (int) $order->get_id() ||
			! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true ) ) {
			$result = new WP_Error( 'nicepay_refund_error', __( 'Transaction ID not found.', 'nicepay-payment-gateway' ) );
		} elseif ( ! $this->transaction_is_refundable( $transaction ) ) {
			$result = new WP_Error( 'nicepay_refund_state_error', __( 'This transaction is not in a safe state for another refund.', 'nicepay-payment-gateway' ) );
		} elseif ( ! $this->refund_configuration_matches( $transaction, $order ) ) {
			$result = new WP_Error( 'nicepay_refund_context_error', __( 'The original payment context does not match the active NicePay configuration.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return bool */
	private function transaction_is_refundable( $transaction ) {
		$status         = isset( $transaction->status ) ? (string) $transaction->status : '';
		$cancel_status  = isset( $transaction->cancel_status ) ? (string) $transaction->cancel_status : '';
		$reconciliation = isset( $transaction->reconciliation_status ) ? (string) $transaction->reconciliation_status : '';
		return in_array( $status, array( 'paid', 'partially_refunded' ), true ) &&
			'required' !== $reconciliation && ! in_array( $cancel_status, array( 'requested', 'unknown' ), true );
	}

	/** @return bool */
	private function refund_configuration_matches( $transaction, $order ) {
		$currency = isset( $transaction->currency ) ? strtoupper( (string) $transaction->currency ) : '';
		return 'KRW' === $currency && hash_equals( $currency, strtoupper( (string) $order->get_currency() ) ) &&
			! empty( $transaction->mid ) && hash_equals( (string) $transaction->mid, (string) $this->api->get_mid() ) &&
			! empty( $transaction->mode ) && hash_equals( (string) $transaction->mode, (string) $this->api->get_mode() );
	}

	/** @return array<string,mixed>|WP_Error */
	private function amount_context( array $context, $amount ) {
		$transaction = $context['transaction'];
		$order       = $context['order'];
		$captured    = nicepay_normalize_ledger_amount( isset( $transaction->captured_amount ) ? $transaction->captured_amount : '0' );
		if ( false === $captured || '0' === $captured ) {
			$captured = nicepay_normalize_amount( $transaction->amount, 'KRW' );
		}
		$refunded   = nicepay_normalize_ledger_amount( isset( $transaction->refunded_amount ) ? $transaction->refunded_amount : '0' );
		$order_total = nicepay_normalize_amount( $order->get_total(), 'KRW' );
		$result      = array();
		if ( false === $captured || false === $refunded || false === $order_total || ! hash_equals( $captured, $order_total ) ) {
			$result = new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount is invalid for this currency.', 'nicepay-payment-gateway' ) );
		} else {
			$remaining_before = nicepay_subtract_integer_amounts( $captured, $refunded );
			$cancel_amount    = is_null( $amount ) ? $remaining_before : nicepay_normalize_amount( $amount, 'KRW' );
			$result = $this->validate_refund_amounts( $order, $refunded, $remaining_before, $cancel_amount );
		}
		return $result;
	}

	/** @return array<string,mixed>|WP_Error */
	private function validate_refund_amounts( $order, $refunded, $remaining_before, $cancel_amount ) {
		$result = array();
		if ( false === $remaining_before || '0' === $remaining_before ) {
			$result = new WP_Error( 'nicepay_refund_amount_error', __( 'No refundable balance remains.', 'nicepay-payment-gateway' ) );
		} elseif ( false === $cancel_amount || nicepay_compare_integer_amounts( $cancel_amount, $remaining_before ) > 0 ) {
			$result = new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount exceeds the captured balance.', 'nicepay-payment-gateway' ) );
		} else {
			$order_refunded = nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() );
			$expected       = nicepay_add_integer_amounts( $refunded, $cancel_amount );
			if ( false === $order_refunded || false === $expected || ! hash_equals( $expected, $order_refunded ) ) {
				$result = new WP_Error( 'nicepay_refund_amount_error', __( 'WooCommerce refund records do not match this NicePay refund request.', 'nicepay-payment-gateway' ) );
			} else {
				$remaining_after = nicepay_subtract_integer_amounts( $remaining_before, $cancel_amount );
				$result = array(
					'refunded_before' => $refunded,
					'cancel_amount'   => $cancel_amount,
					'remaining_after' => $remaining_after,
					'is_partial'      => '0' !== $remaining_after,
				);
			}
		}
		return $result;
	}

	/** @return true|WP_Error */
	private function validate_partial_refund( $transaction, $is_partial ) {
		$result = true;
		$method = isset( $transaction->payment_method ) ? (string) $transaction->payment_method : '';
		if ( $is_partial && 'CELLPHONE' === $method ) {
			$result = new WP_Error( 'nicepay_refund_partial_unsupported', __( 'Partial mobile-payment refunds remain disabled until the OTID lifecycle is certified.', 'nicepay-payment-gateway' ) );
		} elseif ( $is_partial && 'CARD' === $method && ( ! isset( $transaction->cc_part_cl ) || '1' !== (string) $transaction->cc_part_cl ) ) {
			$result = new WP_Error( 'nicepay_refund_partial_not_allowed', __( 'NICEPAY did not certify partial refunds for this card payment.', 'nicepay-payment-gateway' ) );
		} elseif ( $is_partial && 'CARD' === $method && $this->is_irreversible_wallet( $transaction ) ) {
			$result = new WP_Error( 'nicepay_refund_partial_wallet_unsupported', __( 'Partial refunds for this simple-pay wallet are disabled because the remaining balance may become irreversible.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return bool */
	private function is_irreversible_wallet( $transaction ) {
		$wallets = array( '6', '7', '15', '16', '18', '20', '21', '22', '25' );
		return isset( $transaction->clickpay_cl ) && in_array( (string) $transaction->clickpay_cl, $wallets, true );
	}

	/** @return string */
	private function refund_reason( $reason ) {
		$reason = $reason ? $reason : __( 'Refund requested by merchant', 'nicepay-payment-gateway' );
		return nicepay_utf8_byte_cut( sanitize_text_field( $reason ), 100 );
	}

	/** @return int|WP_Error */
	private function reserve_refund( array $context ) {
		$transaction = $context['transaction'];
		$result = new WP_Error( 'nicepay_refund_conflict', __( 'Another refund or reconciliation action is already using this balance.', 'nicepay-payment-gateway' ) );
		if ( nicepay_claim_transaction_for_refund( $transaction->id, $context['cancel_moid'], $context['cancel_amount'] ) ) {
			$attempt_id = nicepay_save_refund_attempt(
				array(
					'transaction_id' => $transaction->id,
					'wc_order_id' => $context['order']->get_id(),
					'tid' => $context['tid'],
					'cancel_moid' => $context['cancel_moid'],
					'requested_amount' => $context['cancel_amount'],
					'currency' => 'KRW',
					'reason' => $context['reason'],
				)
			);
			$result = false === $attempt_id ? $this->release_failed_audit_claim( $context ) : $attempt_id;
		}
		return $result;
	}

	/** @return WP_Error */
	private function release_failed_audit_claim( array $context ) {
		if ( ! nicepay_release_unsent_refund_claim( $context['transaction']->id, $context['cancel_moid'] ) ) {
			nicepay_log( 'Unsent NicePay refund claim could not be released', $context['transaction']->id, 'error' );
		}
		return new WP_Error( 'nicepay_refund_audit_error', __( 'Refund was not sent because its audit record could not be created.', 'nicepay-payment-gateway' ) );
	}

	/** @return true|WP_Error */
	private function complete_refund( array $context, $result ) {
		if ( is_wp_error( $result ) ) {
			return $this->record_unknown_refund( $context, $result );
		}

		$result_code = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';
		$success     = $this->api->is_cancel_success( $result_code );
		if ( $success && $this->cancel_binding_matches( $context, $result ) ) {
			$outcome = $this->record_confirmed_refund( $context, $result, $result_code );
		} elseif ( $success ) {
			$outcome = $this->record_mismatched_refund( $context, $result, $result_code );
		} else {
			$outcome = $this->record_rejected_refund( $context, $result, $result_code );
		}
		return $outcome;
	}

	/** @return bool */
	private function cancel_binding_matches( array $context, array $result ) {
		$amount = isset( $result['CancelAmt'] )
			? nicepay_normalize_response_amount( $result['CancelAmt'], $context['order']->get_currency() )
			: false;
		return isset( $result['TID'] ) && hash_equals( $context['tid'], (string) $result['TID'] ) &&
			false !== $amount && hash_equals( $context['cancel_amount'], $amount );
	}

	/** @return WP_Error */
	private function record_unknown_refund( array $context, WP_Error $error ) {
		$this->complete_attempt( $context, 'unknown', $error->get_error_code(), '', array() );
		$persisted = nicepay_update_transaction(
			$context['transaction']->id,
			array(
				'status' => 'needs_reconciliation', 'reconciliation_status' => 'required',
				'reconciliation_note' => $error->get_error_code(), 'cancel_status' => 'unknown',
				'cancel_result_code' => $error->get_error_code(), 'cancel_result_msg' => '',
			),
			true
		);
		if ( ! $persisted ) {
			$context['order']->update_meta_data( '_nicepay_refund_reconciliation_required', 'yes' );
		}
		$context['order']->add_order_note( __( 'NicePay refund outcome is unknown. Do not retry until it is reconciled in the merchant console.', 'nicepay-payment-gateway' ) );
		$context['order']->save();
		return new WP_Error( 'nicepay_refund_reconciliation_required', __( 'Refund outcome is unknown. Reconcile it before retrying.', 'nicepay-payment-gateway' ) );
	}

	/** @return true */
	private function record_confirmed_refund( array $context, array $result, $result_code ) {
		$order          = $context['order'];
		$result_message = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
		$order->add_order_note(
			sprintf(
				/* translators: %1$s: amount, %2$s: reason */
				__( 'NicePay refund processed. Amount: %1$s, Reason: %2$s', 'nicepay-payment-gateway' ),
				nicepay_format_amount( $context['cancel_amount'], $order->get_currency() ),
				$context['reason']
			)
		);
		$attempt_updated = $this->complete_attempt( $context, 'confirmed', $result_code, $result_message, $result );
		$ledger_updated  = nicepay_complete_transaction_refund(
			$context['transaction']->id,
			$context['cancel_moid'],
			array(
				'status' => $context['is_partial'] ? 'partially_refunded' : 'refunded',
				'refunded_amount' => nicepay_add_integer_amounts( $context['refunded_before'], $context['cancel_amount'] ),
				'remaining_amount' => $context['remaining_after'], 'cancel_status' => 'confirmed',
				'cancel_result_code' => $result_code, 'cancel_result_msg' => $result_message,
				'cancel_completed_at' => gmdate( NICEPAY_DB_DATETIME_FORMAT ),
				'otid' => isset( $result['OTID'] ) ? $result['OTID'] : '',
				'payment_data' => nicepay_filter_payment_data( $result ), 'reconciliation_status' => 'not_required',
			)
		);
		if ( ! $ledger_updated || ! $attempt_updated ) {
			$this->record_confirmed_audit_failure( $context, $result_code, $ledger_updated );
		}
		return true;
	}

	/** @return void */
	private function record_confirmed_audit_failure( array $context, $result_code, $ledger_updated ) {
		nicepay_update_transaction(
			$context['transaction']->id,
			array(
				'status' => 'needs_reconciliation', 'reconciliation_status' => 'required',
				'reconciliation_note' => $ledger_updated ? 'refund_confirmed_attempt_audit_failed' : 'refund_confirmed_ledger_update_failed',
				'cancel_status' => 'confirmed', 'cancel_result_code' => $result_code,
			)
		);
		$context['order']->update_meta_data( '_nicepay_refund_reconciliation_required', 'yes' );
		$context['order']->add_order_note( __( 'NicePay confirmed the refund, but its local audit could not be finalized. The WooCommerce refund is retained; manual ledger reconciliation is required.', 'nicepay-payment-gateway' ) );
		$context['order']->save();
	}

	/** @return WP_Error */
	private function record_mismatched_refund( array $context, array $result, $result_code ) {
		$result_message = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
		$this->complete_attempt( $context, 'unknown', $result_code, $result_message, $result );
		nicepay_update_transaction(
			$context['transaction']->id,
			array(
				'status' => 'needs_reconciliation', 'reconciliation_status' => 'required',
				'reconciliation_note' => 'nicepay_refund_response_mismatch', 'cancel_status' => 'unknown',
				'cancel_result_code' => $result_code, 'cancel_result_msg' => $result_message,
			),
			true
		);
		return new WP_Error( 'nicepay_refund_reconciliation_required', __( 'Refund response did not match the request. Reconcile it before retrying.', 'nicepay-payment-gateway' ) );
	}

	/** @return WP_Error */
	private function record_rejected_refund( array $context, array $result, $result_code ) {
		$result_message = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
		$this->complete_attempt( $context, 'rejected', $result_code, $result_message, $result );
		nicepay_update_transaction(
			$context['transaction']->id,
			array(
				'cancel_status' => 'rejected', 'cancel_result_code' => $result_code,
				'cancel_result_msg' => $result_message, 'payment_data' => nicepay_filter_payment_data( $result ),
			),
			true
		);
		return new WP_Error( 'nicepay_refund_error', __( 'NicePay rejected the refund request. Review the transaction details.', 'nicepay-payment-gateway' ) );
	}

	/** @return bool */
	private function complete_attempt( array $context, $status, $result_code, $result_message, array $response ) {
		$data = array(
			'status' => $status, 'result_code' => $result_code, 'result_msg' => $result_message,
			'completed_at' => gmdate( NICEPAY_DB_DATETIME_FORMAT ),
		);
		if ( ! empty( $response ) ) {
			$data['response_data'] = $response;
		}
		return nicepay_complete_refund_attempt( $context['attempt_id'], $context['cancel_moid'], $data );
	}
}
