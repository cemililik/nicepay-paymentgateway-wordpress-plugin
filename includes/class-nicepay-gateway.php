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
        $this->method_description = __( 'Accept payments via NicePay payment gateway (Credit Card, Bank Transfer, Virtual Account, Mobile).', 'nicepay-payment-gateway' );
        $this->has_fields         = false;
        $this->supports           = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'NicePay Payment', 'nicepay-payment-gateway' ) );
        $this->description = $this->get_option( 'description', __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

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
                'default' => 'yes',
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
                'default'     => __( 'Pay securely via NicePay (Credit Card, Bank Transfer, Virtual Account, Mobile).', 'nicepay-payment-gateway' ),
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

        if ( ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) {
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
            return array( 'result' => 'failure' );
        }

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url( true ),
        );
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

        $this->generate_payment_form( $order );
    }

    /**
     * Generate NicePay payment form
     */
    private function generate_payment_form( $order ) {
        $edi_date   = $this->api->generate_edi_date();
        $moid       = $this->api->generate_moid( 'WC' . $order->get_id() );
        $amount     = nicepay_get_amount( $order->get_total(), $order->get_currency() );
        $return_url = WC()->api_request_url( 'nicepay_return' );

        // Build goods name from order items
        $items = $order->get_items();
        $goods_name = '';
        if ( count( $items ) > 0 ) {
            $first_item = reset( $items );
            $goods_name = $first_item->get_name();
            $extra = count( $items ) - 1;
            if ( $extra > 0 ) {
                /* translators: %d: number of additional items */
                $goods_name .= sprintf(
                    _n( ' and %d more item', ' and %d more items', $extra, 'nicepay-payment-gateway' ),
                    $extra
                );
            }
        }

        // Truncate goods name to 40 bytes (NicePay limit is byte-based)
        $goods_name = mb_strcut( $goods_name, 0, 40, 'UTF-8' );

        $sign_data = $this->api->create_auth_sign_data( $edi_date, $amount );
        $enabled_methods = get_option( 'nicepay_enabled_methods', array( 'CARD' ) );
        $language = NicePay_API::get_nicepay_lang();
        $currency = $order->get_currency();
        $charset  = get_option( 'nicepay_charset', 'utf-8' );

        // Store moid mapping to order
        $order->update_meta_data( '_nicepay_moid', $moid );
        $order->update_meta_data( '_nicepay_edi_date', $edi_date );
        $order->save();

        // Save initial transaction record
        $tx_id = nicepay_save_transaction( array(
            'order_id'        => $moid,
            'wc_order_id'     => $order->get_id(),
            'moid'            => $moid,
            'amount'          => $amount,
            'status'          => 'pending',
            'buyer_name'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'buyer_email'     => $order->get_billing_email(),
            'buyer_tel'       => $order->get_billing_phone(),
            'goods_name'      => $goods_name,
        ) );

        if ( $tx_id === false ) {
            nicepay_log( 'Failed to save initial transaction for order', $order->get_id() );
            $order->update_status( 'failed', __( 'NicePay: failed to initialize transaction.', 'nicepay-payment-gateway' ) );
            wc_add_notice( __( 'Payment initialization failed. Please try again.', 'nicepay-payment-gateway' ), 'error' );
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
            'ReturnURL'    => $return_url,
            'BuyerName'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'BuyerTel'     => $order->get_billing_phone(),
            'BuyerEmail'   => $order->get_billing_email(),
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
            $form_data['GoodsCl'] = '1'; // Physical goods
        }

        // Culture Cash requires MallUserID
        if ( in_array( 'GIFT_CULT', $enabled_methods, true ) ) {
            $form_data['MallUserID'] = $order->get_billing_email();
        }

        include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';
    }

    /**
     * Handle return from NicePay authentication
     */
    public function handle_return() {
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

        // Find WC order from Moid
        $transaction = nicepay_get_transaction_by_moid( $moid );
        if ( ! $transaction || ! $transaction->wc_order_id ) {
            nicepay_log( 'Return handler: Order not found for moid', $moid );
            wp_die( esc_html__( 'Order not found.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 404 ) );
            return;
        }

        $order = wc_get_order( $transaction->wc_order_id );
        if ( ! $order ) {
            nicepay_log( 'Return handler: WC Order not found', $transaction->wc_order_id );
            wp_die( esc_html__( 'Order not found.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 404 ) );
            return;
        }

        // Authentication failed
        if ( $auth_result_code !== '0000' ) {
            nicepay_log( 'Authentication failed', array( 'code' => $auth_result_code, 'msg' => $auth_result_msg ) );

            nicepay_update_transaction( $transaction->id, array(
                'status'      => 'failed',
                'result_code' => $auth_result_code,
                'result_msg'  => $auth_result_msg,
            ) );

            $order->update_status( 'failed', sprintf(
                /* translators: %1$s: result code, %2$s: result message */
                __( 'NicePay authentication failed. Code: %1$s, Message: %2$s', 'nicepay-payment-gateway' ),
                $auth_result_code,
                $auth_result_msg
            ) );

            wc_add_notice(
                __( 'Payment authentication failed. Please try again.', 'nicepay-payment-gateway' ) . ' (' . $auth_result_msg . ')',
                'error'
            );

            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        // Verify authentication signature (required)
        if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {
            nicepay_log( empty( $signature ) ? 'Auth signature missing' : 'Auth signature invalid' );

            nicepay_update_transaction( $transaction->id, array(
                'status'      => 'failed',
                'result_code' => 'SIG_FAIL',
                'result_msg'  => 'Signature verification failed',
            ) );

            $order->update_status( 'failed', __( 'NicePay signature verification failed.', 'nicepay-payment-gateway' ) );

            wc_add_notice( __( 'Payment verification failed. Please try again.', 'nicepay-payment-gateway' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        // Request approval
        $auth_data = array(
            'AuthToken'    => $auth_token,
            'TxTid'        => $tx_tid,
            'NextAppURL'   => $next_app_url,
            'NetCancelURL' => $net_cancel_url,
            'Amt'          => $amt,
            'MID'          => $mid,
            'Moid'         => $moid,
            'PayMethod'    => $pay_method,
        );

        $result = $this->api->request_approval( $auth_data );

        if ( is_wp_error( $result ) ) {
            nicepay_update_transaction( $transaction->id, array(
                'status'      => 'failed',
                'result_code' => 'NET_ERROR',
                'result_msg'  => $result->get_error_message(),
            ) );

            $order->update_status( 'failed', __( 'NicePay approval request failed.', 'nicepay-payment-gateway' ) . ' ' . $result->get_error_message() );

            wc_add_notice( __( 'Payment processing failed. Please try again.', 'nicepay-payment-gateway' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
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
            'auth_token'      => $auth_token,
            'payment_data'    => $result,
        );

        // Card info
        if ( ! empty( $result['CardCode'] ) ) {
            $update_data['card_code']  = $result['CardCode'];
            $update_data['card_name']  = isset( $result['CardName'] ) ? $result['CardName'] : '';
            $update_data['card_no']    = isset( $result['CardNo'] ) ? $result['CardNo'] : '';
            $update_data['card_quota'] = isset( $result['CardQuota'] ) ? $result['CardQuota'] : '';
        }

        // Bank info
        if ( ! empty( $result['BankCode'] ) ) {
            $update_data['bank_code'] = $result['BankCode'];
            $update_data['bank_name'] = isset( $result['BankName'] ) ? $result['BankName'] : '';
        }

        // VBank info
        if ( ! empty( $result['VbankBankCode'] ) ) {
            $update_data['bank_code']      = $result['VbankBankCode'];
            $update_data['bank_name']      = isset( $result['VbankBankName'] ) ? $result['VbankBankName'] : '';
            $update_data['vbank_num']      = isset( $result['VbankNum'] ) ? $result['VbankNum'] : '';
            $update_data['vbank_exp_date'] = isset( $result['VbankExpDate'] ) ? $result['VbankExpDate'] : '';
        }

        // Check result
        if ( $this->api->is_success_code( $result_code, $result_method ) ) {
            $update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';
            nicepay_update_transaction( $transaction->id, $update_data );

            // Update WC order
            $order->update_meta_data( '_nicepay_tid', $tid );
            $order->update_meta_data( '_nicepay_pay_method', $result_method );

            if ( ! empty( $result['AuthCode'] ) ) {
                $order->update_meta_data( '_nicepay_auth_code', $result['AuthCode'] );
            }

            if ( $result_method === 'VBANK' ) {
                // Virtual account - waiting for deposit
                $order->update_status( 'on-hold', sprintf(
                    /* translators: %1$s: bank name, %2$s: account number, %3$s: expiry date */
                    __( 'Awaiting virtual account deposit. Bank: %1$s, Account: %2$s, Expires: %3$s', 'nicepay-payment-gateway' ),
                    isset( $result['VbankBankName'] ) ? $result['VbankBankName'] : '',
                    isset( $result['VbankNum'] ) ? $result['VbankNum'] : '',
                    isset( $result['VbankExpDate'] ) ? $result['VbankExpDate'] : ''
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

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        }

        // Payment failed
        $update_data['status'] = 'failed';
        nicepay_update_transaction( $transaction->id, $update_data );

        $order->update_status( 'failed', sprintf(
            /* translators: %1$s: result code, %2$s: result message */
            __( 'NicePay payment failed. Code: %1$s, Message: %2$s', 'nicepay-payment-gateway' ),
            $result_code,
            $result_msg
        ) );

        wc_add_notice(
            __( 'Payment failed.', 'nicepay-payment-gateway' ) . ' ' . $result_msg,
            'error'
        );

        wp_safe_redirect( wc_get_checkout_url() );
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

        $tid = $order->get_meta( '_nicepay_tid' );
        if ( ! $tid ) {
            return new WP_Error( 'nicepay_refund_error', __( 'Transaction ID not found.', 'nicepay-payment-gateway' ) );
        }

        $moid = $order->get_meta( '_nicepay_moid' );

        // Null amount means full refund
        if ( is_null( $amount ) ) {
            $amount = $order->get_total();
        }

        $cancel_amt = nicepay_get_amount( $amount, $order->get_currency() );
        $total_amt  = nicepay_get_amount( $order->get_total(), $order->get_currency() );
        $is_partial = ( (float) $cancel_amt < (float) $total_amt );

        if ( ! $reason ) {
            $reason = __( 'Refund requested by merchant', 'nicepay-payment-gateway' );
        }

        $result = $this->api->request_cancel( $tid, $cancel_amt, $reason, $moid, $is_partial );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $result_code = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';

        if ( $this->api->is_cancel_success( $result_code ) ) {
            $order->add_order_note( sprintf(
                /* translators: %1$s: amount, %2$s: reason */
                __( 'NicePay refund processed. Amount: %1$s, Reason: %2$s', 'nicepay-payment-gateway' ),
                nicepay_format_amount( $amount, $order->get_currency() ),
                $reason
            ) );

            // Update transaction
            $transaction = nicepay_get_transaction_by_tid( $tid );
            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, array(
                    'status' => $is_partial ? 'refunded' : 'cancelled',
                ) );
            }

            return true;
        }

        $error_msg = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : __( 'Unknown error', 'nicepay-payment-gateway' );
        return new WP_Error( 'nicepay_refund_error', $error_msg );
    }
}
