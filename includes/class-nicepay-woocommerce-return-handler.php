<?php
/**
 * WooCommerce callback state machine for NicePay authentication returns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NicePay_WooCommerce_Return_Handler {

	/** @var NicePay_API */
	private $api;

	/** @var WC_Gateway_NicePay */
	private $gateway;

	public function __construct( $api, $gateway ) {
		$this->api     = $api;
		$this->gateway = $gateway;
	}

	/** Process a provider authentication callback. */
	public function process() {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true );
			header( 'X-Frame-Options: DENY', true );
		}

		$request_method = '';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
			$request_method = strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) );
		}

		if ( 'POST' !== $request_method ) {
			wp_die( esc_html__( 'Invalid request method.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 405 ) );
		} else {
			nicepay_log( 'WooCommerce return handler called' );
			$auth_data  = $this->auth_data();
			$transaction = NicePay_Inbound_Validator::validate_auth_return( 'woocommerce', $auth_data, $this->api );

			if ( is_wp_error( $transaction ) || empty( $transaction->wc_order_id ) ) {
				$this->auth_failure( 'invalid', $transaction, $auth_data );
			} elseif ( ! nicepay_claim_transaction_for_approval( $transaction->id, 'woocommerce' ) ) {
				$this->auth_failure( 'claimed', $transaction, $auth_data );
			} elseif ( ! nicepay_update_transaction( $transaction->id, array(
				'tid'            => $auth_data['TxTid'],
				'auth_token'     => $auth_data['AuthToken'],
				'next_app_url'   => $auth_data['NextAppURL'],
				'net_cancel_url' => $auth_data['NetCancelURL'],
			), true ) ) {
				$this->persistence_failure( $transaction );
			} else {
				$this->process_claimed( $transaction );
			}
		}
	}

	/** Return a sanitized callback projection. */
	private function auth_data() {
		$text_fields = array( 'AuthResultCode', 'AuthToken', 'TxTid', 'Amt', 'MID', 'Moid', 'PayMethod', 'Signature', 'ReqReserved' );
		$data        = array();
		foreach ( $text_fields as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		foreach ( array( 'NextAppURL', 'NetCancelURL' ) as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		return $data;
	}

	/** Stop an invalid or already-claimed callback before provider approval. */
	private function auth_failure( $type, $transaction, $auth_data ) {
		if ( 'invalid' === $type ) {
			$error_code = is_wp_error( $transaction ) ? $transaction->get_error_code() : 'nicepay_inbound_order_missing';
			nicepay_log( 'WooCommerce auth return rejected', $error_code, 'warning' );
			wc_add_notice( __( 'We could not verify this payment attempt. Please try again.', 'nicepay-payment-gateway' ), 'error' );
			$this->redirect( wc_get_checkout_url() );
		} else {
			nicepay_log( 'WooCommerce approval replay or concurrent claim rejected', $auth_data['Moid'], 'warning' );
			$order = wc_get_order( $transaction->wc_order_id );
			$url   = wc_get_checkout_url();
			if ( $order ) {
				$url = $order->needs_payment() ? $order->get_checkout_payment_url( true ) : $this->gateway->get_return_url( $order );
			}
			$this->redirect( $url );
		}
	}

	/** Reverse an authentication whose durable local context could not be saved. */
	private function persistence_failure( $transaction ) {
		$order = wc_get_order( $transaction->wc_order_id );
		$abort = nicepay_abort_authenticated_payment(
			$transaction->id,
			$this->api,
			'auth_context_persistence_failed_before_approval'
		);
		nicepay_log( 'WooCommerce auth context could not be persisted before approval', $transaction->id, 'error' );
		if ( $order && $abort['needs_reconciliation'] ) {
			$order->update_status( 'on-hold', __( 'NicePay authentication succeeded, but its reversal could not be confirmed. Reconcile this order before retrying.', 'nicepay-payment-gateway' ) );
		} elseif ( $order ) {
			$order->add_order_note( __( 'NicePay approval was not attempted and the authentication hold was reversed. The order remains payable.', 'nicepay-payment-gateway' ) );
		}
		if ( $order ) {
			$order->save();
		}
		wc_add_notice( __( 'Payment processing could not be completed safely.', 'nicepay-payment-gateway' ), 'error' );
		$this->redirect_after_abort( $order, $abort['needs_reconciliation'] );
	}

	/** Load durable state and ensure the order still matches the authorized snapshot. */
	private function process_claimed( $transaction ) {
		$order = wc_get_order( $transaction->wc_order_id );
		if ( ! $order ) {
			$abort = nicepay_abort_authenticated_payment( $transaction->id, $this->api, 'woocommerce_order_missing_after_auth' );
			nicepay_log( 'Return handler: WC order not found', $transaction->wc_order_id, 'error' );
			if ( ! $abort['persisted'] ) {
				nicepay_log( 'Missing-order auth abort audit could not be persisted', $transaction->id, 'error' );
			}
			wp_die( esc_html__( 'Order not found.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 404 ) );
		} else {
			$auth_data = nicepay_get_authenticated_payment_context( $transaction->id );
			if ( is_wp_error( $auth_data ) ) {
				nicepay_abort_authenticated_payment( $transaction->id, $this->api, $auth_data->get_error_code() );
				$order->update_status( 'on-hold', __( 'NicePay authentication succeeded, but its locally stored reversal context is incomplete. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
				$order->save();
				nicepay_log( 'WooCommerce local auth context could not be reloaded', $transaction->id, 'error' );
				$this->redirect( $this->gateway->get_return_url( $order ) );
			} else {
				$transaction->tid = $auth_data['TxTid'];
				if ( $this->snapshot_is_valid( $order, $transaction ) ) {
					$this->process_approval( $order, $transaction, $auth_data );
				} else {
					$this->snapshot_failure( $order, $transaction );
				}
			}
		}
	}

	/** Determine whether immutable order fields still match the pending ledger row. */
	private function snapshot_is_valid( $order, $transaction ) {
		$order_amount     = nicepay_get_amount( $order->get_total(), $order->get_currency() );
		$stored_amount    = nicepay_normalize_amount( $transaction->amount, $transaction->currency );
		$current_key_hash = hash( 'sha256', (string) $order->get_order_key() );

		return $order->needs_payment() &&
			'nicepay' === $order->get_payment_method() &&
			'' !== $order_amount &&
			false !== $stored_amount &&
			hash_equals( $stored_amount, $order_amount ) &&
			hash_equals( (string) $transaction->currency, (string) $order->get_currency() ) &&
			! empty( $transaction->wc_order_key_hash ) &&
			hash_equals( (string) $transaction->wc_order_key_hash, $current_key_hash );
	}

	/** Reverse a callback when the WooCommerce order changed after authentication. */
	private function snapshot_failure( $order, $transaction ) {
		$abort = nicepay_abort_authenticated_payment(
			$transaction->id,
			$this->api,
			'woocommerce_order_snapshot_changed_after_auth'
		);
		nicepay_log( 'WooCommerce order snapshot changed before approval', $transaction->id, 'warning' );
		if ( $abort['needs_reconciliation'] ) {
			$order->update_status( 'on-hold', __( 'The order changed after NicePay authentication, and the authorization reversal could not be confirmed. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
		} else {
			$order->add_order_note( __( 'The order changed after NicePay authentication; the authorization hold was reversed before approval.', 'nicepay-payment-gateway' ) );
		}
		$order->save();
		wc_add_notice( __( 'The order changed before payment could be confirmed. Please review the order and try again.', 'nicepay-payment-gateway' ), 'error' );
		$this->redirect_after_abort( $order, $abort['needs_reconciliation'] );
	}

	/** Request provider approval and bind its response to this transaction. */
	private function process_approval( $order, $transaction, $auth_data ) {
		$result = $this->api->request_approval( $auth_data );
		if ( is_wp_error( $result ) ) {
			$this->approval_error( $order, $transaction, $auth_data, $result );
		} else {
			$binding = NicePay_Inbound_Validator::validate_approval_response(
				$transaction,
				$auth_data['PayMethod'],
				$result,
				$this->api
			);
			if ( is_wp_error( $binding ) ) {
				$this->binding_failure( $order, $transaction, $auth_data, $binding );
			} else {
				$update = $this->approval_update( $auth_data, $result );
				if ( $this->api->is_success_code( $update['result_code'], $update['payment_method'] ) ) {
					$this->approved( $order, $transaction, $result, $update );
				} else {
					$this->declined( $order, $transaction, $update );
				}
			}
		}
	}

	/** Persist an unknown or safely reversed transport outcome. */
	private function approval_error( $order, $transaction, $auth_data, $result ) {
		$audit          = nicepay_get_approval_error_audit( $result );
		$reconciliation = $audit['needs_reconciliation'];
		$status         = $reconciliation ? 'needs_reconciliation' : 'failed';
		nicepay_update_transaction( $transaction->id, array(
			'status'                  => $status,
			'approval_state'          => $status,
			'reconciliation_status'   => $reconciliation ? 'required' : 'not_required',
			'reconciliation_note'     => $result->get_error_code(),
			'result_code'             => 'APPROVAL_ERROR',
			'result_msg'              => '',
			'net_cancel_status'       => $audit['net_cancel_status'],
			'net_cancel_result_code'  => $audit['net_cancel_result_code'],
			'net_cancel_result_msg'   => $audit['net_cancel_result_msg'],
			'net_cancel_requested_at' => $audit['net_cancel_requested_at'],
			'net_cancel_completed_at' => $audit['net_cancel_completed_at'],
			'active_attempt_key'      => null,
			'auth_token'              => $reconciliation ? $auth_data['AuthToken'] : '',
		) );
		if ( $reconciliation ) {
			$order->update_status( 'on-hold', __( 'NicePay payment outcome is unknown. Reconcile this order in the NicePay merchant console before retrying or fulfilling it.', 'nicepay-payment-gateway' ) );
		} else {
			$order->add_order_note( __( 'NicePay approval did not complete; the reversal was confirmed. The order remains payable.', 'nicepay-payment-gateway' ) );
		}
		$order->save();
		wc_add_notice( __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ), 'error' );
		$this->redirect_after_abort( $order, $reconciliation );
	}

	/** Reverse and quarantine an approval response that does not match the offer. */
	private function binding_failure( $order, $transaction, $auth_data, $binding ) {
		$net_cancel = $this->api->request_net_cancel( $auth_data );
		nicepay_update_transaction(
			$transaction->id,
			nicepay_get_mismatched_approval_audit( $binding, $net_cancel, $auth_data['AuthToken'] ),
			true
		);
		$order->update_status( 'on-hold', __( 'NicePay returned an approval that did not match the order snapshot. The original authorization was reversed when possible, but the unmatched approval still requires manual reconciliation.', 'nicepay-payment-gateway' ) );
		$order->save();
		nicepay_log( 'WooCommerce approval binding failed', $binding->get_error_code(), 'error' );
		wc_add_notice( __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ), 'error' );
		$this->redirect( $this->gateway->get_return_url( $order ) );
	}

	/** Build the provider-neutral ledger update for a bound response. */
	private function approval_update( $auth_data, $result ) {
		$method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $auth_data['PayMethod'];
		$update = array(
			'tid'             => isset( $result['TID'] ) ? $result['TID'] : $auth_data['TxTid'],
			'payment_method'  => $method,
			'pay_method_name' => NicePay_API::get_payment_method_name( $method ),
			'result_code'     => isset( $result['ResultCode'] ) ? $result['ResultCode'] : '',
			'result_msg'      => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
			'auth_token'      => '',
			'payment_data'    => nicepay_filter_payment_data( $result ),
		);
		$update = array_merge( $update, nicepay_get_refund_capability_fields( $result ) );
		if ( ! empty( $result['CardCode'] ) ) {
			$update['card_code']  = $result['CardCode'];
			$update['card_name']  = isset( $result['CardName'] ) ? $result['CardName'] : '';
			$update['card_quota'] = isset( $result['CardQuota'] ) ? $result['CardQuota'] : '';
		}
		if ( ! empty( $result['BankCode'] ) ) {
			$update['bank_code'] = $result['BankCode'];
			$update['bank_name'] = isset( $result['BankName'] ) ? $result['BankName'] : '';
		}
		return $update;
	}

	/** Persist a capture before completing the WooCommerce order. */
	private function approved( $order, $transaction, $result, $update ) {
		$update['status']                = 'paid';
		$update['approval_state']        = 'approved';
		$update['approved_at']           = gmdate( NICEPAY_DB_DATETIME_FORMAT );
		$update['captured_amount']       = $transaction->amount;
		$update['remaining_amount']      = $transaction->amount;
		$update['reconciliation_status'] = 'not_required';

		if ( ! nicepay_update_transaction( $transaction->id, $update, true ) ) {
			$abort = nicepay_abort_authenticated_payment( $transaction->id, $this->api, 'approval_persistence_failed_after_capture' );
			nicepay_log( 'WooCommerce approval persistence failed after capture', $abort['net_cancel_result_code'], 'error' );
			if ( $abort['needs_reconciliation'] || ! $abort['persisted'] ) {
				$order->update_status( 'on-hold', __( 'NicePay captured the payment but the local ledger and reversal outcome could not be confirmed. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
			} else {
				$order->update_status( 'pending', __( 'NicePay reversed the captured payment after a local ledger failure. The order remains payable.', 'nicepay-payment-gateway' ) );
			}
			$order->save();
			$this->redirect_after_abort( $order, $abort['needs_reconciliation'] );
		} elseif ( $this->complete_order( $order, $transaction, $result, $update ) ) {
			if ( ! nicepay_update_transaction( $transaction->id, array( 'active_attempt_key' => null ) ) ) {
				nicepay_log( 'WooCommerce active payment lock could not be released', $transaction->id, 'error' );
			}
			$this->redirect( $this->gateway->get_return_url( $order ) );
		} else {
			$this->redirect( $this->gateway->get_return_url( $order ) );
		}
	}

	/** Apply a captured payment to WooCommerce and quarantine local failures. */
	private function complete_order( $order, $transaction, $result, $update ) {
		$completed = true;
		try {
			$order->update_meta_data( '_nicepay_tid', $update['tid'] );
			$order->update_meta_data( '_nicepay_pay_method', $update['payment_method'] );
			if ( ! empty( $result['AuthCode'] ) ) {
				$order->update_meta_data( '_nicepay_auth_code', $result['AuthCode'] );
			}
			if ( 'test' === $this->api->get_mode() ) {
				$order->update_meta_data( '_nicepay_test_payment', 'yes' );
				$order->update_status( 'on-hold', __( 'NicePay sandbox payment approved. This is a test transaction; do not fulfill the order.', 'nicepay-payment-gateway' ) );
				$order->add_order_note( sprintf(
					/* translators: %1$s: payment method, %2$s: sandbox TID */
					__( 'NicePay TEST payment approved. Method: %1$s, sandbox TID: %2$s. No real payment was collected.', 'nicepay-payment-gateway' ),
					NicePay_API::get_payment_method_name( $update['payment_method'] ),
					$update['tid']
				) );
			} else {
				$order->payment_complete( $update['tid'] );
				$order->add_order_note( sprintf(
					/* translators: %1$s: payment method, %2$s: TID */
					__( 'NicePay payment completed. Method: %1$s, TID: %2$s', 'nicepay-payment-gateway' ),
					NicePay_API::get_payment_method_name( $update['payment_method'] ),
					$update['tid']
				) );
			}
			$order->save();
		} catch ( Throwable $throwable ) {
			$completed = false;
			nicepay_update_transaction( $transaction->id, array(
				'status'                => 'needs_reconciliation',
				'reconciliation_status' => 'required',
				'reconciliation_note'   => 'woocommerce_payment_complete_failed',
			) );
			nicepay_log( 'WooCommerce payment completion failed after NicePay capture', $transaction->id, 'error' );
			try {
				$order->update_status( 'on-hold', __( 'NicePay captured this payment, but WooCommerce could not finalize the order. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
				$order->save();
			} catch ( Throwable $order_error ) {
				nicepay_log( 'WooCommerce reconciliation status could not be saved', $transaction->id, 'error' );
			}
		}
		return $completed;
	}

	/** Persist a bound provider decline and return the shopper to payment. */
	private function declined( $order, $transaction, $update ) {
		$update['status']             = 'failed';
		$update['approval_state']     = 'failed';
		$update['active_attempt_key'] = null;
		nicepay_update_transaction( $transaction->id, $update );
		$order->add_order_note( sprintf(
			/* translators: %s: result code */
			__( 'NicePay declined the payment. Result code: %s. The order remains payable.', 'nicepay-payment-gateway' ),
			$update['result_code']
		) );
		$order->save();
		wc_add_notice( __( 'Payment was declined. Please try another payment method.', 'nicepay-payment-gateway' ), 'error' );
		$this->redirect( $order->get_checkout_payment_url( true ) );
	}

	/** Select a safe destination after a reversal or unknown outcome. */
	private function redirect_after_abort( $order, $needs_reconciliation ) {
		$url = wc_get_checkout_url();
		if ( $order ) {
			$url = $needs_reconciliation ? $this->gateway->get_return_url( $order ) : $order->get_checkout_payment_url( true );
		}
		$this->redirect( $url );
	}

	/** Redirect and terminate callback processing. */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
