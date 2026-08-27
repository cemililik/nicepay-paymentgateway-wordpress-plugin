<?php
/**
 * WooCommerce NicePay Payment Gateway
 *
 * Integrates NicePay payment gateway with WooCommerce checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_NicePay extends WC_Payment_Gateway {

    /** @var NicePay_API */
    private $api;

    public function __construct() {
        $this->id                 = 'nicepay';
        $this->method_title       = __( 'NicePay', 'nicepay-payment-gateway' );
        $this->method_description = __( 'Configure NicePay card, bank transfer, and mobile payment flows. Validate the merchant account in the sandbox before enabling payments.', 'nicepay-payment-gateway' );
        $this->has_fields         = false;
        $this->supports           = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'NicePay Payment', 'nicepay-payment-gateway' ) );
        $this->description = $this->get_option( 'description', __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' ) );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

        $this->api = new NicePay_API();

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_api_nicepay_return', array( $this, 'handle_return' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'nicepay-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable NicePay Payment', 'nicepay-payment-gateway' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'nicepay-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Payment method title shown at checkout.', 'nicepay-payment-gateway' ),
                'default'     => __( 'NicePay Payment', 'nicepay-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'nicepay-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description shown at checkout.', 'nicepay-payment-gateway' ),
                'default'     => __( 'Pay securely via NicePay (Credit Card, Bank Transfer, or Mobile).', 'nicepay-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'settings_notice' => array(
                'title'       => __( 'API Settings', 'nicepay-payment-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: settings page URL */
                    __( 'Configure MID, Merchant Key, and other API settings on the <a href="%s">NicePay Settings</a> page.', 'nicepay-payment-gateway' ),
                    admin_url( 'admin.php?page=nicepay-settings' )
                ),
            ),
        );
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ( $this->enabled !== 'yes' ) {
            return false;
        }

        if ( ! NicePay_Installer::is_current() ) {
            return false;
        }

        if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) {
            return false;
        }

		if ( 'test' === $this->api->get_mode() &&
			! (bool) apply_filters( 'nicepay_allow_test_mode_checkout', false ) ) {
			return false;
		}

        if ( empty( nicepay_get_enabled_methods() ) ) {
            return false;
        }

		if ( ! nicepay_is_supported_currency( get_woocommerce_currency() ) ) {
            return false;
        }

		if ( 0 !== (int) wc_get_price_decimals() ) {
			return false;
		}

		if ( ! is_ssl() ) {
            return false;
        }

        return true;
    }

    /**
     * Process payment - redirect to receipt page for NicePay form
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'The order could not be loaded. Please return to checkout and try again.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment could not load order', array( 'order_id' => absint( $order_id ) ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'nicepay' !== $order->get_payment_method() ) {
			wc_add_notice( __( 'NicePay is not selected for this order.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment rejected a payment-method mismatch', array( 'order_id' => $order->get_id() ), 'warning' );
			return array( 'result' => 'failure' );
		}

		if ( ! nicepay_is_supported_currency( $order->get_currency() ) ) {
			wc_add_notice( __( 'NicePay supports KRW orders only.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment rejected unsupported order currency', array( 'order_id' => $order->get_id(), 'currency' => $order->get_currency() ), 'warning' );
			return array( 'result' => 'failure' );
		}

		if ( '' === nicepay_get_amount( $order->get_total(), $order->get_currency() ) ) {
			wc_add_notice( __( 'The order total must be a positive whole KRW amount.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment rejected a non-integer KRW total', array( 'order_id' => $order->get_id() ), 'warning' );
			return array( 'result' => 'failure' );
		}

		if ( ! $this->is_available() ) {
			wc_add_notice( __( 'NicePay is not currently available. Please choose another payment method or contact the merchant.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment rejected an unavailable gateway', array( 'order_id' => $order->get_id() ), 'warning' );
            return array( 'result' => 'failure' );
        }

        $active = nicepay_get_active_woocommerce_transaction( $order->get_id() );
        if ( $active && $this->recover_paid_order_from_ledger( $order, $active ) ) {
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }

        if ( ! $order->needs_payment() ) {
			wc_add_notice( __( 'This order no longer requires payment.', 'nicepay-payment-gateway' ), 'error' );
			nicepay_log( 'NicePay process_payment rejected a non-payable order', array( 'order_id' => $order->get_id() ), 'warning' );
            return array( 'result' => 'failure' );
        }

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url( true ),
        );
    }

	/**
	 * Validate buyer fields during checkout, before the receipt page is reached.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
		$last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
		$email      = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		$phone      = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$buyer      = nicepay_validate_buyer_fields( trim( $first_name . ' ' . $last_name ), $email, $phone );

		if ( is_wp_error( $buyer ) ) {
			wc_add_notice( $buyer->get_error_message(), 'error' );
			return false;
		}

		return true;
	}

    /**
     * Display payment form on receipt page
     */
    public function receipt_page( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        $request_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
        if ( '' === $request_key || ! hash_equals( (string) $order->get_order_key(), (string) $request_key ) ) {
            echo '<p>' . esc_html__( 'This payment link is invalid or expired.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        if ( 'nicepay' !== $order->get_payment_method() || ! $order->needs_payment() ) {
            echo '<p>' . esc_html__( 'This order is not eligible for a new NicePay payment.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        $active = nicepay_get_active_woocommerce_transaction( $order->get_id() );
        if ( $active && $this->recover_paid_order_from_ledger( $order, $active ) ) {
            echo '<p>' . esc_html__( 'This NicePay payment was already confirmed and the order has been recovered.', 'nicepay-payment-gateway' ) . '</p>';
            echo '<a href="' . esc_url( $this->get_return_url( $order ) ) . '">' . esc_html__( 'View Order', 'nicepay-payment-gateway' ) . '</a>';
            return;
        }

        $this->generate_payment_form( $order );
    }

    /**
     * Complete a WooCommerce order from a verified paid ledger row after a
     * previous request stopped between capture persistence and payment_complete.
     *
     * @param WC_Order $order       WooCommerce order.
     * @param object   $transaction NicePay transaction row.
     * @return bool
     */
    private function recover_paid_order_from_ledger( $order, $transaction ) {
        if ( ! is_object( $transaction ) || 'paid' !== (string) $transaction->status ||
            'approved' !== (string) $transaction->approval_state || empty( $transaction->tid ) ||
            (int) $transaction->wc_order_id !== (int) $order->get_id() ) {
            return false;
        }

        $stored_amount = nicepay_normalize_amount( $transaction->captured_amount, (string) $transaction->currency );
        $order_amount  = nicepay_normalize_amount( $order->get_total(), $order->get_currency() );
        if ( false === $stored_amount || false === $order_amount || ! hash_equals( $stored_amount, $order_amount ) ||
            ! hash_equals( (string) $transaction->currency, (string) $order->get_currency() ) ) {
            return false;
        }

        try {
            $order->update_meta_data( '_nicepay_tid', (string) $transaction->tid );
            $order->update_meta_data( '_nicepay_pay_method', (string) $transaction->payment_method );
            $order->payment_complete( (string) $transaction->tid );
            $order->add_order_note( __( 'WooCommerce order completion was recovered from the verified NicePay payment ledger.', 'nicepay-payment-gateway' ) );
            $order->save();
        } catch ( Throwable $throwable ) {
            nicepay_update_transaction( $transaction->id, array(
                'status'                => 'needs_reconciliation',
                'reconciliation_status' => 'required',
                'reconciliation_note'   => 'woocommerce_payment_recovery_failed',
            ) );
            nicepay_log( 'WooCommerce payment recovery failed', $transaction->id, 'error' );
            return false;
        }

        nicepay_update_transaction( $transaction->id, array( 'active_attempt_key' => null ) );
        return true;
    }

    /**
     * Generate NicePay payment form
     */
    private function generate_payment_form( $order ) {
        $edi_date   = $this->api->generate_edi_date();
        $moid       = $this->api->generate_moid( 'WC' . $order->get_id() );
        $amount     = nicepay_get_amount( $order->get_total(), $order->get_currency() );
        $return_url = WC()->api_request_url( 'nicepay_return' );

        if ( '' === $amount || ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) {
            echo '<p>' . esc_html__( 'NicePay is not configured for this order.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        // Build goods name from order items
        $items = $order->get_items();
        $goods_name = '';
        if ( count( $items ) > 0 ) {
            $first_item = reset( $items );
            $goods_name = $first_item->get_name();
            $extra = count( $items ) - 1;
            if ( $extra > 0 ) {
                $goods_name .= sprintf(
                    /* translators: %d: number of additional items */
                    _n( ' and %d more item', ' and %d more items', $extra, 'nicepay-payment-gateway' ),
                    $extra
                );
            }
        }

        // Truncate goods name to 40 bytes (NicePay limit is byte-based)
        $goods_name = nicepay_utf8_byte_cut( sanitize_text_field( $goods_name ), 40 );

        $sign_data = $this->api->create_auth_sign_data( $edi_date, $amount );
		$binding_token = nicepay_generate_payment_binding_token();
		if ( false === $binding_token ) {
			echo '<p>' . esc_html__( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ) . '</p>';
			echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return to Checkout', 'nicepay-payment-gateway' ) . '</a>';
			return;
		}
        $enabled_methods = nicepay_get_enabled_methods();
        if ( empty( $enabled_methods ) ) {
            echo '<p>' . esc_html__( 'No certified NicePay payment method is enabled.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        $language = NicePay_API::get_nicepay_lang();
        $currency = $order->get_currency();
        $charset  = 'utf-8';
        $is_test_mode = $this->api->is_test_mode();
        $buyer = nicepay_validate_buyer_fields(
            (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name(),
            (string) $order->get_billing_email(),
            (string) $order->get_billing_phone()
        );
        if ( is_wp_error( $buyer ) ) {
            echo '<p>' . esc_html( $buyer->get_error_message() ) . '</p>';
            echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return to Checkout', 'nicepay-payment-gateway' ) . '</a>';
            return;
        }

        if ( ! nicepay_abandon_pending_transactions( 'woocommerce', (string) $order->get_id() ) ) {
            nicepay_log( 'Could not retire an older WooCommerce payment attempt', $order->get_id(), 'error' );
            echo '<p>' . esc_html__( 'NicePay could not prepare a unique payment attempt.', 'nicepay-payment-gateway' ) . '</p>';
            return;
        }

        // Save initial transaction record
        $tx_id = nicepay_save_transaction( array(
            'order_id'        => $moid,
            'wc_order_id'     => $order->get_id(),
            'moid'            => $moid,
            'flow'            => 'woocommerce',
            'source_ref'      => (string) $order->get_id(),
            'active_attempt_key' => 'woocommerce:' . (string) $order->get_id(),
            'config_fingerprint' => hash(
                'sha256',
                wp_json_encode( array( $order->get_id(), $amount, $currency, $enabled_methods ) )
            ),
            'allowed_methods' => implode( ',', $enabled_methods ),
			'binding_token_hash' => hash( 'sha256', $binding_token ),
            'wc_order_key_hash' => hash( 'sha256', (string) $order->get_order_key() ),
            'edi_date'        => $edi_date,
            'offer_expires_at'=> gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS ),
            'mid'             => $this->api->get_mid(),
            'mode'            => $this->api->get_mode(),
            'currency'        => $currency,
            'amount'          => $amount,
            'status'          => 'pending',
            'approval_state'  => 'pending',
            'buyer_name'      => $buyer['buyer_name'],
            'buyer_email'     => $buyer['buyer_email'],
            'buyer_tel'       => $buyer['buyer_tel'],
            'goods_name'      => $goods_name,
        ) );

        if ( $tx_id === false ) {
            nicepay_log( 'Failed to save initial transaction for order', $order->get_id(), 'error' );
            $order->add_order_note( __( 'NicePay could not initialize a payment attempt. The order remains payable.', 'nicepay-payment-gateway' ) );
            wc_add_notice( __( 'Payment initialization failed. Please try again.', 'nicepay-payment-gateway' ), 'error' );
            echo '<p>' . esc_html__( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ) . '</p>';
            echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return to Checkout', 'nicepay-payment-gateway' ) . '</a>';
            return;
        }

        // Persist presentation metadata only after this request owns the
        // unique active-attempt row. A concurrent receipt refresh that loses
        // the database race must not overwrite the winning Moid on the order.
        try {
            $order->update_meta_data( '_nicepay_moid', $moid );
            $order->update_meta_data( '_nicepay_edi_date', $edi_date );
            $order->save();
        } catch ( Throwable $throwable ) {
            nicepay_update_transaction(
                $tx_id,
                array(
                    'status'             => 'failed',
                    'approval_state'     => 'failed',
                    'active_attempt_key' => null,
                    'result_code'        => 'ORDER_METADATA_ERROR',
                ),
                true
            );
            nicepay_log( 'WooCommerce order metadata could not be persisted before payment', $order->get_id(), 'error' );
            echo '<p>' . esc_html__( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ) . '</p>';
            echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return to Checkout', 'nicepay-payment-gateway' ) . '</a>';
            return;
        }

        $form_data = array(
            'GoodsName'    => $goods_name,
            'Amt'          => $amount,
            'MID'          => $this->api->get_mid(),
            'EdiDate'      => $edi_date,
            'Moid'         => $moid,
            'SignData'     => $sign_data,
			'ReqReserved'  => $binding_token,
            'ReturnURL'    => $return_url,
            'BuyerName'    => $buyer['buyer_name'],
            'BuyerTel'     => $buyer['buyer_tel'],
            'BuyerEmail'   => $buyer['buyer_email'],
            'NpLang'       => $language,
            'CurrencyCode' => $currency,
            'CharSet'      => $charset,
        );

        // VBank expiry
        if ( in_array( 'VBANK', $enabled_methods, true ) ) {
            $form_data['VbankExpDate'] = $this->api->get_vbank_exp_date();
        }

        // Cellphone goods class
        if ( in_array( 'CELLPHONE', $enabled_methods, true ) ) {
            $goods_class = nicepay_get_woocommerce_goods_class( $order );
            if ( false === $goods_class ) {
                nicepay_update_transaction(
                    $tx_id,
                    array(
                        'status'             => 'failed',
                        'approval_state'     => 'failed',
                        'active_attempt_key' => null,
                        'result_code'        => 'INVALID_GOODS_CLASS',
                    ),
                    true
                );
                echo '<p>' . esc_html__( 'The mobile-payment goods classification is invalid.', 'nicepay-payment-gateway' ) . '</p>';
                return;
            }
            $form_data['GoodsCl'] = $goods_class;
        }

        include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';
    }

    /**
     * Handle return from NicePay authentication
     */
    public function handle_return() {
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Referrer-Policy: no-referrer', true );
			header( 'X-Frame-Options: DENY', true );
		}

        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';
        if ( 'POST' !== $request_method ) {
            wp_die( esc_html__( 'Invalid request method.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 405 ) );
            return;
        }

        nicepay_log( 'WooCommerce return handler called' );

        // Collect POST data
        $auth_result_code = isset( $_POST['AuthResultCode'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthResultCode'] ) ) : '';
        $auth_result_msg  = isset( $_POST['AuthResultMsg'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthResultMsg'] ) ) : '';
        $auth_token       = isset( $_POST['AuthToken'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthToken'] ) ) : '';
        $tx_tid           = isset( $_POST['TxTid'] ) ? sanitize_text_field( wp_unslash( $_POST['TxTid'] ) ) : '';
        $next_app_url     = isset( $_POST['NextAppURL'] ) ? esc_url_raw( wp_unslash( $_POST['NextAppURL'] ) ) : '';
        $net_cancel_url   = isset( $_POST['NetCancelURL'] ) ? esc_url_raw( wp_unslash( $_POST['NetCancelURL'] ) ) : '';
        $moid             = isset( $_POST['Moid'] ) ? sanitize_text_field( wp_unslash( $_POST['Moid'] ) ) : '';
        $amt              = isset( $_POST['Amt'] ) ? sanitize_text_field( wp_unslash( $_POST['Amt'] ) ) : '';
        $mid              = isset( $_POST['MID'] ) ? sanitize_text_field( wp_unslash( $_POST['MID'] ) ) : '';
        $pay_method       = isset( $_POST['PayMethod'] ) ? sanitize_text_field( wp_unslash( $_POST['PayMethod'] ) ) : '';
        $signature        = isset( $_POST['Signature'] ) ? sanitize_text_field( wp_unslash( $_POST['Signature'] ) ) : '';
        $req_reserved     = isset( $_POST['ReqReserved'] ) ? sanitize_text_field( wp_unslash( $_POST['ReqReserved'] ) ) : '';

        $auth_data = array(
            'AuthResultCode' => $auth_result_code,
            'AuthToken'    => $auth_token,
            'TxTid'        => $tx_tid,
            'NextAppURL'   => $next_app_url,
            'NetCancelURL' => $net_cancel_url,
            'Amt'          => $amt,
            'MID'          => $mid,
            'Moid'         => $moid,
            'PayMethod'    => $pay_method,
            'Signature'    => $signature,
			'ReqReserved'  => $req_reserved,
        );

        $transaction = NicePay_Inbound_Validator::validate_auth_return(
            'woocommerce',
            $auth_data,
            $this->api
        );
        if ( is_wp_error( $transaction ) || empty( $transaction->wc_order_id ) ) {
            $error_code = is_wp_error( $transaction ) ? $transaction->get_error_code() : 'nicepay_inbound_order_missing';
            nicepay_log( 'WooCommerce auth return rejected', $error_code, 'warning' );
            wc_add_notice( __( 'We could not verify this payment attempt. Please try again.', 'nicepay-payment-gateway' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        if ( ! nicepay_claim_transaction_for_approval( $transaction->id, 'woocommerce' ) ) {
            nicepay_log( 'WooCommerce approval replay or concurrent claim rejected', $moid, 'warning' );
            $claimed_order = wc_get_order( $transaction->wc_order_id );
            wp_safe_redirect(
                $claimed_order && ! $claimed_order->needs_payment()
                    ? $this->get_return_url( $claimed_order )
                    : ( $claimed_order ? $claimed_order->get_checkout_payment_url( true ) : wc_get_checkout_url() )
            );
            exit;
        }

        if ( ! nicepay_update_transaction( $transaction->id, array(
			'tid'            => $tx_tid,
			'auth_token'     => $auth_token,
			'next_app_url'   => $next_app_url,
			'net_cancel_url' => $net_cancel_url,
        ), true ) ) {
			$order = wc_get_order( $transaction->wc_order_id );
            $abort = nicepay_abort_authenticated_payment(
                $transaction->id,
                $auth_data,
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
			wp_safe_redirect(
				$order
					? ( $abort['needs_reconciliation'] ? $this->get_return_url( $order ) : $order->get_checkout_payment_url( true ) )
					: wc_get_checkout_url()
			);
            exit;
        }

		$order = wc_get_order( $transaction->wc_order_id );
		if ( ! $order ) {
			$abort = nicepay_abort_authenticated_payment(
				$transaction->id,
				array(),
				$this->api,
				'woocommerce_order_missing_after_auth'
			);
			nicepay_log( 'Return handler: WC order not found', $transaction->wc_order_id, 'error' );
			if ( ! $abort['persisted'] ) {
				nicepay_log( 'Missing-order auth abort audit could not be persisted', $transaction->id, 'error' );
			}
			wp_die( esc_html__( 'Order not found.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 404 ) );
			return;
		}

		$auth_data = nicepay_get_authenticated_payment_context( $transaction->id );
		if ( is_wp_error( $auth_data ) ) {
			$abort = nicepay_abort_authenticated_payment( $transaction->id, array(), $this->api, $auth_data->get_error_code() );
			$order->update_status( 'on-hold', __( 'NicePay authentication succeeded, but its locally stored reversal context is incomplete. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
			$order->save();
			nicepay_log( 'WooCommerce local auth context could not be reloaded', $transaction->id, 'error' );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$transaction->tid = $auth_data['TxTid'];

        $order_amount = nicepay_get_amount( $order->get_total(), $order->get_currency() );
        $stored_amount = nicepay_normalize_amount( $transaction->amount, $transaction->currency );
        $current_key_hash = hash( 'sha256', (string) $order->get_order_key() );
        if ( ! $order->needs_payment() || 'nicepay' !== $order->get_payment_method() ||
            '' === $order_amount || false === $stored_amount || ! hash_equals( $stored_amount, $order_amount ) ||
            ! hash_equals( (string) $transaction->currency, (string) $order->get_currency() ) ||
            empty( $transaction->wc_order_key_hash ) ||
            ! hash_equals( (string) $transaction->wc_order_key_hash, $current_key_hash ) ) {
            $abort = nicepay_abort_authenticated_payment(
                $transaction->id,
                $auth_data,
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
            wp_safe_redirect( $abort['needs_reconciliation'] ? $this->get_return_url( $order ) : $order->get_checkout_payment_url( true ) );
            exit;
        }

        $result = $this->api->request_approval( $auth_data );

        if ( is_wp_error( $result ) ) {
            $abort_audit          = nicepay_get_approval_error_audit( $result );
            $needs_reconciliation = $abort_audit['needs_reconciliation'];
            nicepay_update_transaction( $transaction->id, array(
                'status'                => $needs_reconciliation ? 'needs_reconciliation' : 'failed',
                'approval_state'        => $needs_reconciliation ? 'needs_reconciliation' : 'failed',
                'reconciliation_status' => $needs_reconciliation ? 'required' : 'not_required',
                'reconciliation_note'   => $result->get_error_code(),
                'result_code'           => 'APPROVAL_ERROR',
                'result_msg'            => '',
                'net_cancel_status'         => $abort_audit['net_cancel_status'],
                'net_cancel_result_code'    => $abort_audit['net_cancel_result_code'],
                'net_cancel_result_msg'     => $abort_audit['net_cancel_result_msg'],
                'net_cancel_requested_at'   => $abort_audit['net_cancel_requested_at'],
                'net_cancel_completed_at'   => $abort_audit['net_cancel_completed_at'],
                'active_attempt_key'        => null,
                'auth_token'            => $needs_reconciliation ? $auth_token : '',
            ) );

            if ( $needs_reconciliation ) {
                $order->update_status( 'on-hold', __( 'NicePay payment outcome is unknown. Reconcile this order in the NicePay merchant console before retrying or fulfilling it.', 'nicepay-payment-gateway' ) );
            } else {
                $order->add_order_note( __( 'NicePay approval did not complete; the reversal was confirmed. The order remains payable.', 'nicepay-payment-gateway' ) );
            }
            $order->save();

            wc_add_notice( __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ), 'error' );
            wp_safe_redirect( $needs_reconciliation ? $this->get_return_url( $order ) : $order->get_checkout_payment_url( true ) );
            exit;
        }

        $approval_binding = NicePay_Inbound_Validator::validate_approval_response(
            $transaction,
            $pay_method,
            $result,
            $this->api
        );
        if ( is_wp_error( $approval_binding ) ) {
            $net_cancel = $this->api->request_net_cancel( $auth_data );
            nicepay_update_transaction(
                $transaction->id,
                nicepay_get_mismatched_approval_audit( $approval_binding, $net_cancel, $auth_token ),
                true
            );

            $order->update_status( 'on-hold', __( 'NicePay returned an approval that did not match the order snapshot. The original authorization was reversed when possible, but the unmatched approval still requires manual reconciliation.', 'nicepay-payment-gateway' ) );
            $order->save();

            nicepay_log( 'WooCommerce approval binding failed', $approval_binding->get_error_code(), 'error' );
            wc_add_notice( __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ), 'error' );
            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        $result_code   = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';
        $result_msg    = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
        $tid           = isset( $result['TID'] ) ? $result['TID'] : $tx_tid;
        $result_method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $pay_method;

        // Build transaction update data
        $update_data = array(
            'tid'             => $tid,
            'payment_method'  => $result_method,
            'pay_method_name' => NicePay_API::get_payment_method_name( $result_method ),
            'result_code'     => $result_code,
            'result_msg'      => $result_msg,
            'auth_token'      => '',
            'payment_data'    => nicepay_filter_payment_data( $result ),
        );
        $update_data = array_merge( $update_data, nicepay_get_refund_capability_fields( $result ) );

        // Card info
        if ( ! empty( $result['CardCode'] ) ) {
            $update_data['card_code']  = $result['CardCode'];
            $update_data['card_name']  = isset( $result['CardName'] ) ? $result['CardName'] : '';
            $update_data['card_quota'] = isset( $result['CardQuota'] ) ? $result['CardQuota'] : '';
        }

        // Bank info
        if ( ! empty( $result['BankCode'] ) ) {
            $update_data['bank_code'] = $result['BankCode'];
            $update_data['bank_name'] = isset( $result['BankName'] ) ? $result['BankName'] : '';
        }

        // Check result
        if ( $this->api->is_success_code( $result_code, $result_method ) ) {
            $update_data['status']                = 'paid';
            $update_data['approval_state']        = 'approved';
            $update_data['approved_at']           = gmdate( 'Y-m-d H:i:s' );
            $update_data['captured_amount']       = $transaction->amount;
            $update_data['remaining_amount']      = $transaction->amount;
            $update_data['reconciliation_status'] = 'not_required';

            if ( ! nicepay_update_transaction( $transaction->id, $update_data, true ) ) {
                $abort = nicepay_abort_authenticated_payment(
                    $transaction->id,
                    $auth_data,
                    $this->api,
                    'approval_persistence_failed_after_capture'
                );
                nicepay_log(
                    'WooCommerce approval persistence failed after capture',
                    $abort['net_cancel_result_code'],
                    'error'
                );
                if ( $abort['needs_reconciliation'] || ! $abort['persisted'] ) {
                    $order->update_status( 'on-hold', __( 'NicePay captured the payment but the local ledger and reversal outcome could not be confirmed. Manual reconciliation is required.', 'nicepay-payment-gateway' ) );
                } else {
                    $order->update_status( 'pending', __( 'NicePay reversed the captured payment after a local ledger failure. The order remains payable.', 'nicepay-payment-gateway' ) );
                }
                $order->save();
                wp_safe_redirect( $abort['needs_reconciliation'] ? $this->get_return_url( $order ) : $order->get_checkout_payment_url( true ) );
                exit;
            }

            try {
                // Keep active_attempt_key locked until WooCommerce has durably
                // completed the order. A crash cannot open a second payable
                // attempt for the same captured order.
                $order->update_meta_data( '_nicepay_tid', $tid );
                $order->update_meta_data( '_nicepay_pay_method', $result_method );

                if ( ! empty( $result['AuthCode'] ) ) {
                    $order->update_meta_data( '_nicepay_auth_code', $result['AuthCode'] );
                }

				if ( 'test' === $this->api->get_mode() ) {
					$order->update_meta_data( '_nicepay_test_payment', 'yes' );
					$order->update_status( 'on-hold', __( 'NicePay sandbox payment approved. This is a test transaction; do not fulfill the order.', 'nicepay-payment-gateway' ) );
					$order->add_order_note( sprintf(
						/* translators: %1$s: payment method, %2$s: sandbox TID */
						__( 'NicePay TEST payment approved. Method: %1$s, sandbox TID: %2$s. No real payment was collected.', 'nicepay-payment-gateway' ),
						NicePay_API::get_payment_method_name( $result_method ),
						$tid
					) );
				} else {
					$order->payment_complete( $tid );
					$order->add_order_note( sprintf(
						/* translators: %1$s: payment method, %2$s: TID */
						__( 'NicePay payment completed. Method: %1$s, TID: %2$s', 'nicepay-payment-gateway' ),
						NicePay_API::get_payment_method_name( $result_method ),
						$tid
					) );
				}
                $order->save();
            } catch ( Throwable $throwable ) {
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
                wp_safe_redirect( $this->get_return_url( $order ) );
                exit;
            }

            if ( ! nicepay_update_transaction( $transaction->id, array( 'active_attempt_key' => null ) ) ) {
                nicepay_log( 'WooCommerce active payment lock could not be released', $transaction->id, 'error' );
            }

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        // Payment failed
        $update_data['status']         = 'failed';
        $update_data['approval_state'] = 'failed';
        $update_data['active_attempt_key'] = null;
        nicepay_update_transaction( $transaction->id, $update_data );

        $order->add_order_note( sprintf(
            /* translators: %s: result code */
            __( 'NicePay declined the payment. Result code: %s. The order remains payable.', 'nicepay-payment-gateway' ),
            $result_code
        ) );
        $order->save();

        wc_add_notice( __( 'Payment was declined. Please try another payment method.', 'nicepay-payment-gateway' ), 'error' );

        wp_safe_redirect( $order->get_checkout_payment_url( true ) );
        exit;
    }

    /**
     * Process refund via WooCommerce
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'nicepay_refund_error', __( 'Order not found.', 'nicepay-payment-gateway' ) );
        }

        $tid         = (string) $order->get_meta( '_nicepay_tid' );
        $transaction = $tid ? nicepay_get_transaction_by_tid( $tid, $order->get_id() ) : null;
        if ( ! $transaction || (int) $transaction->wc_order_id !== (int) $order->get_id() ||
            ! in_array( (string) $transaction->flow, array( '', 'woocommerce' ), true ) ) {
            return new WP_Error( 'nicepay_refund_error', __( 'Transaction ID not found.', 'nicepay-payment-gateway' ) );
        }

        $transaction_status = isset( $transaction->status ) ? (string) $transaction->status : '';
        $cancel_status      = isset( $transaction->cancel_status ) ? (string) $transaction->cancel_status : '';
        $reconciliation     = isset( $transaction->reconciliation_status ) ? (string) $transaction->reconciliation_status : '';
        if ( ! in_array( $transaction_status, array( 'paid', 'partially_refunded' ), true ) ||
            'required' === $reconciliation || in_array( $cancel_status, array( 'requested', 'unknown' ), true ) ) {
            return new WP_Error( 'nicepay_refund_state_error', __( 'This transaction is not in a safe state for another refund.', 'nicepay-payment-gateway' ) );
        }

        $transaction_currency = isset( $transaction->currency ) ? strtoupper( (string) $transaction->currency ) : '';
        if ( 'KRW' !== $transaction_currency || ! hash_equals( $transaction_currency, strtoupper( (string) $order->get_currency() ) ) ||
            empty( $transaction->mid ) || ! hash_equals( (string) $transaction->mid, (string) $this->api->get_mid() ) ||
            empty( $transaction->mode ) || ! hash_equals( (string) $transaction->mode, (string) $this->api->get_mode() ) ) {
            return new WP_Error( 'nicepay_refund_context_error', __( 'The original payment context does not match the active NicePay configuration.', 'nicepay-payment-gateway' ) );
        }

		$captured = nicepay_normalize_ledger_amount(
			isset( $transaction->captured_amount ) ? $transaction->captured_amount : '0'
		);
		if ( false === $captured || '0' === $captured ) {
			$captured = nicepay_normalize_amount( $transaction->amount, 'KRW' );
		}
        $refunded = nicepay_normalize_ledger_amount(
            isset( $transaction->refunded_amount ) ? $transaction->refunded_amount : '0'
        );
        $order_total = nicepay_normalize_amount( $order->get_total(), 'KRW' );
        if ( false === $captured || false === $refunded || false === $order_total ||
            ! hash_equals( $captured, $order_total ) ) {
            return new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount is invalid for this currency.', 'nicepay-payment-gateway' ) );
        }

        $remaining_before = nicepay_subtract_integer_amounts( $captured, $refunded );
        if ( false === $remaining_before || '0' === $remaining_before ) {
            return new WP_Error( 'nicepay_refund_amount_error', __( 'No refundable balance remains.', 'nicepay-payment-gateway' ) );
        }

        if ( is_null( $amount ) ) {
            $cancel_amt = $remaining_before;
        } else {
            $cancel_amt = nicepay_normalize_amount( $amount, 'KRW' );
        }
        if ( false === $cancel_amt || nicepay_compare_integer_amounts( $cancel_amt, $remaining_before ) > 0 ) {
            return new WP_Error( 'nicepay_refund_amount_error', __( 'Refund amount exceeds the captured balance.', 'nicepay-payment-gateway' ) );
        }

        // WooCommerce creates the in-flight WC_Order_Refund before invoking
        // the gateway. Its total must therefore equal the NicePay ledger's
        // already-confirmed refunds plus this exact request. This both models
        // the real Woo lifecycle and prevents direct, unrecorded remote refunds.
        $order_refunded          = nicepay_normalize_ledger_amount( (string) $order->get_total_refunded() );
        $expected_order_refunded = nicepay_add_integer_amounts( $refunded, $cancel_amt );
        if ( false === $order_refunded || false === $expected_order_refunded ||
            ! hash_equals( $expected_order_refunded, $order_refunded ) ) {
            return new WP_Error( 'nicepay_refund_amount_error', __( 'WooCommerce refund records do not match this NicePay refund request.', 'nicepay-payment-gateway' ) );
        }

        $remaining_after = nicepay_subtract_integer_amounts( $remaining_before, $cancel_amt );
        $is_partial      = '0' !== $remaining_after;
        if ( $is_partial && 'CELLPHONE' === (string) $transaction->payment_method ) {
            return new WP_Error( 'nicepay_refund_partial_unsupported', __( 'Partial mobile-payment refunds remain disabled until the OTID lifecycle is certified.', 'nicepay-payment-gateway' ) );
        }
        if ( $is_partial && 'CARD' === (string) $transaction->payment_method ) {
            if ( ! isset( $transaction->cc_part_cl ) || '1' !== (string) $transaction->cc_part_cl ) {
                return new WP_Error( 'nicepay_refund_partial_not_allowed', __( 'NICEPAY did not certify partial refunds for this card payment.', 'nicepay-payment-gateway' ) );
            }

            $irreversible_wallets = array( '6', '7', '15', '16', '18', '20', '21', '22', '25' );
            if ( isset( $transaction->clickpay_cl ) &&
                in_array( (string) $transaction->clickpay_cl, $irreversible_wallets, true ) ) {
                return new WP_Error( 'nicepay_refund_partial_wallet_unsupported', __( 'Partial refunds for this simple-pay wallet are disabled because the remaining balance may become irreversible.', 'nicepay-payment-gateway' ) );
            }
        }

        $cancel_moid     = $this->api->generate_moid( 'RF' . $order->get_id() );

        if ( ! $reason ) {
            $reason = __( 'Refund requested by merchant', 'nicepay-payment-gateway' );
        }
        $reason = nicepay_utf8_byte_cut( sanitize_text_field( $reason ), 100 );

        if ( ! nicepay_claim_transaction_for_refund( $transaction->id, $cancel_moid, $cancel_amt ) ) {
            return new WP_Error( 'nicepay_refund_conflict', __( 'Another refund or reconciliation action is already using this balance.', 'nicepay-payment-gateway' ) );
        }

        $attempt_id = nicepay_save_refund_attempt( array(
            'transaction_id'   => $transaction->id,
            'wc_order_id'      => $order->get_id(),
            'tid'              => $tid,
            'cancel_moid'      => $cancel_moid,
            'requested_amount' => $cancel_amt,
            'currency'         => 'KRW',
            'reason'           => $reason,
        ) );
        if ( false === $attempt_id ) {
            if ( ! nicepay_release_unsent_refund_claim( $transaction->id, $cancel_moid ) ) {
                nicepay_log( 'Unsent NicePay refund claim could not be released', $transaction->id, 'error' );
            }
            return new WP_Error( 'nicepay_refund_audit_error', __( 'Refund was not sent because its audit record could not be created.', 'nicepay-payment-gateway' ) );
        }

        $result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $cancel_moid, $is_partial );

        if ( is_wp_error( $result ) ) {
            nicepay_complete_refund_attempt( $attempt_id, $cancel_moid, array(
                'status'       => 'unknown',
                'result_code'  => $result->get_error_code(),
                'result_msg'   => '',
                'completed_at' => gmdate( 'Y-m-d H:i:s' ),
            ) );
            $unknown_persisted = nicepay_update_transaction( $transaction->id, array(
                'status'                => 'needs_reconciliation',
                'reconciliation_status' => 'required',
                'reconciliation_note'   => $result->get_error_code(),
                'cancel_status'         => 'unknown',
                'cancel_result_code'    => $result->get_error_code(),
                'cancel_result_msg'     => '',
            ), true );
            if ( ! $unknown_persisted ) {
                $order->update_meta_data( '_nicepay_refund_reconciliation_required', 'yes' );
            }
            $order->add_order_note( __( 'NicePay refund outcome is unknown. Do not retry until it is reconciled in the merchant console.', 'nicepay-payment-gateway' ) );
            $order->save();
            return new WP_Error( 'nicepay_refund_reconciliation_required', __( 'Refund outcome is unknown. Reconcile it before retrying.', 'nicepay-payment-gateway' ) );
        }

        $result_code   = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';
        $result_amount = isset( $result['CancelAmt'] )
            ? nicepay_normalize_response_amount( $result['CancelAmt'], $order->get_currency() )
            : false;
        $binding_matches = isset( $result['TID'] ) && hash_equals( $tid, (string) $result['TID'] ) &&
            false !== $result_amount && hash_equals( $cancel_amt, $result_amount );

        if ( $this->api->is_cancel_success( $result_code ) && $binding_matches ) {
            $order->add_order_note( sprintf(
                /* translators: %1$s: amount, %2$s: reason */
                __( 'NicePay refund processed. Amount: %1$s, Reason: %2$s', 'nicepay-payment-gateway' ),
                nicepay_format_amount( $cancel_amt, $order->get_currency() ),
                $reason
            ) );

            $attempt_updated = nicepay_complete_refund_attempt( $attempt_id, $cancel_moid, array(
                'status'        => 'confirmed',
                'result_code'   => $result_code,
                'result_msg'    => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
                'response_data' => $result,
                'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
            ) );
            $ledger_updated = nicepay_complete_transaction_refund( $transaction->id, $cancel_moid, array(
                'status'                => $is_partial ? 'partially_refunded' : 'refunded',
                'refunded_amount'       => nicepay_add_integer_amounts( $refunded, $cancel_amt ),
                'remaining_amount'      => $remaining_after,
                'cancel_status'         => 'confirmed',
                'cancel_result_code'    => $result_code,
                'cancel_result_msg'     => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
                'cancel_completed_at'   => gmdate( 'Y-m-d H:i:s' ),
                'otid'                  => isset( $result['OTID'] ) ? $result['OTID'] : '',
                'payment_data'          => nicepay_filter_payment_data( $result ),
                'reconciliation_status' => 'not_required',
            ) );

            if ( ! $ledger_updated || ! $attempt_updated ) {
                nicepay_update_transaction( $transaction->id, array(
                    'status'                => 'needs_reconciliation',
                    'reconciliation_status' => 'required',
                    'reconciliation_note'   => $ledger_updated ? 'refund_confirmed_attempt_audit_failed' : 'refund_confirmed_ledger_update_failed',
                    'cancel_status'         => 'confirmed',
                    'cancel_result_code'    => $result_code,
                ) );
                $order->update_meta_data( '_nicepay_refund_reconciliation_required', 'yes' );
                $order->add_order_note( __( 'NicePay confirmed the refund, but its local audit could not be finalized. The WooCommerce refund is retained; manual ledger reconciliation is required.', 'nicepay-payment-gateway' ) );
                $order->save();
            }

            return true;
        }

        if ( $this->api->is_cancel_success( $result_code ) ) {
            nicepay_complete_refund_attempt( $attempt_id, $cancel_moid, array(
                'status'        => 'unknown',
                'result_code'   => $result_code,
                'result_msg'    => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
                'response_data' => $result,
                'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
            ) );
            nicepay_update_transaction( $transaction->id, array(
                'status'                => 'needs_reconciliation',
                'reconciliation_status' => 'required',
                'reconciliation_note'   => 'nicepay_refund_response_mismatch',
                'cancel_status'         => 'unknown',
                'cancel_result_code'    => $result_code,
                'cancel_result_msg'     => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
            ), true );
            return new WP_Error( 'nicepay_refund_reconciliation_required', __( 'Refund response did not match the request. Reconcile it before retrying.', 'nicepay-payment-gateway' ) );
        }

        nicepay_complete_refund_attempt( $attempt_id, $cancel_moid, array(
            'status'        => 'rejected',
            'result_code'   => $result_code,
            'result_msg'    => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
            'response_data' => $result,
            'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
        ) );
        nicepay_update_transaction( $transaction->id, array(
            'cancel_status'      => 'rejected',
            'cancel_result_code' => $result_code,
            'cancel_result_msg'  => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
            'payment_data'       => nicepay_filter_payment_data( $result ),
        ), true );

        return new WP_Error(
            'nicepay_refund_error',
            __( 'NicePay rejected the refund request. Review the transaction details.', 'nicepay-payment-gateway' )
        );
    }
}
