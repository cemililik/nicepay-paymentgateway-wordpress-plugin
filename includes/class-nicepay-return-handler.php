<?php
/**
 * NicePay Payment Return Handler
 *
 * Handles payment return callbacks for standalone (non-WooCommerce) payments.
 * Mobile payments use ReturnURL redirect, PC payments use nicepaySubmit() callback.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Return_Handler {

    /** @var NicePay_API */
    private $api;

    public function __construct( $api = null ) {
		$this->api = $api instanceof NicePay_API ? $api : new NicePay_API();
    }

    /**
     * Process the payment return
     */
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
			$auth_data   = $this->auth_data();
			$transaction = NicePay_Inbound_Validator::validate_auth_return( 'standalone', $auth_data, $this->api );
			nicepay_log( 'Standalone return handler', array(
				'AuthResultCode' => $auth_data['AuthResultCode'],
				'Moid'           => $auth_data['Moid'],
				'PayMethod'      => $auth_data['PayMethod'],
			) );

			if ( is_wp_error( $transaction ) ) {
				$this->auth_failure( $transaction );
			} elseif ( ! nicepay_claim_transaction_for_approval( $transaction->id, 'standalone' ) ) {
				nicepay_log( 'Standalone approval replay or concurrent claim rejected', $auth_data['Moid'], 'warning' );
				$this->render_result_page( false, __( 'This payment attempt is already being processed.', 'nicepay-payment-gateway' ) );
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

	/** Render an authentication rejection without leaking provider detail. */
	private function auth_failure( $transaction ) {
		nicepay_log( 'Standalone auth return rejected', $transaction->get_error_code(), 'warning' );
		$message = __( 'We could not verify this payment attempt.', 'nicepay-payment-gateway' );
		if ( 'nicepay_inbound_auth_failed' === $transaction->get_error_code() ) {
			$message = __( 'Payment authentication was not completed. You may try again.', 'nicepay-payment-gateway' );
		}
		$this->render_result_page( false, $message );
	}

	/** Reverse authentication if its local context was not durably stored. */
	private function persistence_failure( $transaction ) {
		$abort = nicepay_abort_authenticated_payment(
			$transaction->id,
			$this->api,
			'auth_context_persistence_failed_before_approval'
		);
		nicepay_log( 'Standalone auth context could not be persisted before approval', $transaction->id, 'error' );
		if ( ! $abort['persisted'] ) {
			nicepay_log( 'Standalone pre-approval abort audit could not be persisted', $transaction->id, 'error' );
		}
		$message = __( 'Payment approval was not started and the authorization hold was reversed. You may try again.', 'nicepay-payment-gateway' );
		if ( $abort['needs_reconciliation'] ) {
			$message = __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' );
		}
		$this->render_result_page( false, $message );
	}

	/** Reload durable authentication context before contacting the provider. */
	private function process_claimed( $transaction ) {
		$auth_data = nicepay_get_authenticated_payment_context( $transaction->id );
		if ( is_wp_error( $auth_data ) ) {
			nicepay_abort_authenticated_payment( $transaction->id, $this->api, $auth_data->get_error_code() );
			nicepay_log( 'Standalone local auth context could not be reloaded', $transaction->id, 'error' );
			$this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
		} else {
			$transaction->tid = $auth_data['TxTid'];
			$this->process_approval( $transaction, $auth_data );
		}
	}

	/** Approve and validate the response against the claimed offer. */
	private function process_approval( $transaction, $auth_data ) {
		$result = $this->api->request_approval( $auth_data );
		if ( is_wp_error( $result ) ) {
			$this->approval_error( $transaction, $auth_data, $result );
		} else {
			$binding = NicePay_Inbound_Validator::validate_approval_response(
				$transaction,
				$auth_data['PayMethod'],
				$result,
				$this->api
			);
			if ( is_wp_error( $binding ) ) {
				$this->binding_failure( $transaction, $auth_data, $binding );
			} else {
				$update = $this->approval_update( $auth_data, $result );
				if ( $this->api->is_success_code( $update['result_code'], $update['payment_method'] ) ) {
					$this->approved( $transaction, $result, $update );
				} else {
					$this->declined( $transaction, $update );
				}
			}
		}
	}

	/** Persist a transport failure as failed or reconciliation-required. */
	private function approval_error( $transaction, $auth_data, $result ) {
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
		$this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
	}

	/** Reverse and quarantine an approval that does not match the offer. */
	private function binding_failure( $transaction, $auth_data, $binding ) {
		$net_cancel = $this->api->request_net_cancel( $auth_data );
		nicepay_update_transaction(
			$transaction->id,
			nicepay_get_mismatched_approval_audit( $binding, $net_cancel, $auth_data['AuthToken'] ),
			true
		);
		nicepay_log( 'Standalone approval binding failed', $binding->get_error_code(), 'error' );
		$this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
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
		return $update;
	}

	/** Persist a successful capture before issuing a receipt. */
	private function approved( $transaction, $result, $update ) {
		$update['status']                = 'paid';
		$update['approval_state']        = 'approved';
		$update['approved_at']           = gmdate( NICEPAY_DB_DATETIME_FORMAT );
		$update['captured_amount']       = $transaction->amount;
		$update['remaining_amount']      = $transaction->amount;
		$update['reconciliation_status'] = 'not_required';
		$update['active_attempt_key']    = null;

		if ( ! nicepay_update_transaction( $transaction->id, $update, true ) ) {
			$abort = nicepay_abort_authenticated_payment( $transaction->id, $this->api, 'approval_persistence_failed_after_capture' );
			nicepay_log( 'Standalone approval persistence failed after capture', $abort['net_cancel_result_code'], 'error' );
			$this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
		} else {
			$receipt      = nicepay_issue_standalone_receipt( $transaction->id );
			$receipt_data = nicepay_filter_payment_data( $result );
			if ( is_array( $receipt ) ) {
				$receipt_data['ReceiptURL'] = $receipt['url'];
				if ( ! nicepay_send_standalone_receipt_email( $transaction, $receipt['url'] ) ) {
					nicepay_log( 'Standalone receipt email could not be sent', $transaction->id, 'warning' );
				}
			}
			$message = __( 'Payment completed successfully.', 'nicepay-payment-gateway' );
			if ( 'test' === $this->api->get_mode() ) {
				$message = __( 'Test payment completed. No real payment was collected.', 'nicepay-payment-gateway' );
			}
			$this->render_result_page( true, $message, $receipt_data );
		}
	}

	/** Persist a bound provider decline. */
	private function declined( $transaction, $update ) {
		$update['status']             = 'failed';
		$update['approval_state']     = 'failed';
		$update['active_attempt_key'] = null;
		nicepay_update_transaction( $transaction->id, $update );
		$this->render_result_page( false, __( 'Payment was declined. Please try another payment method.', 'nicepay-payment-gateway' ) );
	}

    /**
     * Render a previously issued standalone receipt without exposing PII.
     *
     * @param object $transaction Safe receipt projection from the repository.
     */
    public function render_saved_receipt( $transaction ) {
        $this->render_result_page(
            true,
            __( 'Payment completed successfully.', 'nicepay-payment-gateway' ),
            array(
                'TID'       => isset( $transaction->tid ) ? $transaction->tid : '',
                'Moid'      => isset( $transaction->moid ) ? $transaction->moid : '',
                'Amt'       => isset( $transaction->amount ) ? $transaction->amount : '',
                'Currency'  => isset( $transaction->currency ) ? $transaction->currency : 'KRW',
                'PayMethod' => isset( $transaction->payment_method ) ? $transaction->payment_method : '',
            )
        );
    }

    /**
     * Render result page
     */
    private function render_result_page( $success, $message, $data = array() ) {
		$message = (string) $message;
        if ( function_exists( 'status_header' ) ) {
            status_header( $success ? 200 : 400 );
        }
        $page_title = $success
            ? __( 'Payment Successful', 'nicepay-payment-gateway' )
            : __( 'Payment Failed', 'nicepay-payment-gateway' );

        $is_vbank = ! empty( $data['VbankBankName'] ) || ! empty( $data['VbankNum'] );
		include_once NICEPAY_PLUGIN_DIR . 'templates/payment-result.php';
		nicepay_render_payment_result_template( $success, $message, $page_title, $is_vbank, $data );
    }

}
