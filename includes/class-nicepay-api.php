<?php
/**
 * NicePay API Handler
 *
 * Handles all communication with NicePay payment gateway API.
 * Implements authentication, approval, network cancel, and payment cancel flows.
 *
 * @see NicePay Documentation v2.0.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_API {

    private $mid;
    private $merchant_key;
    private $is_test_mode;
    private $charset;

    public function __construct() {
        $this->is_test_mode = ( get_option( 'nicepay_mode', 'test' ) === 'test' );
        $this->charset      = get_option( 'nicepay_charset', 'utf-8' );

        if ( $this->is_test_mode ) {
            $this->mid          = get_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
            $this->merchant_key = get_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        } else {
            $this->mid          = get_option( 'nicepay_live_mid', '' );
            $this->merchant_key = get_option( 'nicepay_live_merchant_key', '' );
        }
    }

    /**
     * Get current MID
     */
    public function get_mid() {
        return $this->mid;
    }

    /**
     * Get current merchant key
     */
    public function get_merchant_key() {
        return $this->merchant_key;
    }

    /**
     * Check if in test mode
     */
    public function is_test_mode() {
        return $this->is_test_mode;
    }

    /**
     * Generate EdiDate (YYYYMMDDHHMISS format)
     */
    public function generate_edi_date() {
        return date( 'YmdHis' );
    }

    /**
     * Generate unique Moid (Merchant Order ID)
     */
    public function generate_moid( $prefix = 'WC' ) {
        return $prefix . '_' . date( 'YmdHis' ) . '_' . wp_rand( 1000, 9999 );
    }

    /**
     * Create SignData for authentication request
     * Rule: hex(sha256(EdiDate + MID + Amt + MerchantKey))
     */
    public function create_auth_sign_data( $edi_date, $amt ) {
        $plain = $edi_date . $this->mid . $amt . $this->merchant_key;
        return hash( 'sha256', $plain );
    }

    /**
     * Verify authentication response Signature
     * Rule: hex(sha256(AuthToken + MID + Amt + MerchantKey))
     */
    public function verify_auth_signature( $auth_token, $amt, $received_signature ) {
        $plain = $auth_token . $this->mid . $amt . $this->merchant_key;
        $expected = hash( 'sha256', $plain );
        return hash_equals( $expected, $received_signature );
    }

    /**
     * Create SignData for approval request
     * Rule: hex(sha256(AuthToken + MID + Amt + EdiDate + MerchantKey))
     */
    public function create_approval_sign_data( $auth_token, $amt, $edi_date ) {
        $plain = $auth_token . $this->mid . $amt . $edi_date . $this->merchant_key;
        return hash( 'sha256', $plain );
    }

    /**
     * Verify approval response Signature
     * Rule: hex(sha256(TID + MID + Amt + MerchantKey))
     */
    public function verify_approval_signature( $tid, $amt, $received_signature ) {
        $plain = $tid . $this->mid . $amt . $this->merchant_key;
        $expected = hash( 'sha256', $plain );
        return hash_equals( $expected, $received_signature );
    }

    /**
     * Create SignData for cancel request
     * Rule: hex(sha256(MID + CancelAmt + EdiDate + MerchantKey))
     */
    public function create_cancel_sign_data( $cancel_amt, $edi_date ) {
        $plain = $this->mid . $cancel_amt . $edi_date . $this->merchant_key;
        return hash( 'sha256', $plain );
    }

    /**
     * Verify cancel response Signature
     * Rule: hex(sha256(TID + MID + CancelAmt + MerchantKey))
     */
    public function verify_cancel_signature( $tid, $cancel_amt, $received_signature ) {
        $plain = $tid . $this->mid . $cancel_amt . $this->merchant_key;
        $expected = hash( 'sha256', $plain );
        return hash_equals( $expected, $received_signature );
    }

    /**
     * Send approval request to NicePay
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Approval response or error
     */
    public function request_approval( $auth_data ) {
        $next_app_url = $auth_data['NextAppURL'];
        $edi_date     = $this->generate_edi_date();

        $params = array(
            'TID'       => $auth_data['TxTid'],
            'AuthToken' => $auth_data['AuthToken'],
            'MID'       => $this->mid,
            'Amt'       => $auth_data['Amt'],
            'EdiDate'   => $edi_date,
            'SignData'  => $this->create_approval_sign_data(
                $auth_data['AuthToken'],
                $auth_data['Amt'],
                $edi_date
            ),
            'CharSet'   => $this->charset,
        );

        nicepay_log( 'Approval request', array(
            'url'    => $next_app_url,
            'params' => array_diff_key( $params, array( 'SignData' => '' ) ),
        ) );

        $response = wp_remote_post( $next_app_url, array(
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
            ),
            'body'      => $params,
        ) );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Approval request failed', $response->get_error_message() );

            // Attempt network cancel on connection failure
            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( ! $result ) {
            nicepay_log( 'Approval response parse error', $body );

            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return new WP_Error( 'nicepay_parse_error', __( 'Failed to parse approval response.', 'nicepay-payment-gateway' ) );
        }

        nicepay_log( 'Approval response', $result );

        // Verify signature
        if ( ! empty( $result['Signature'] ) && ! empty( $result['TID'] ) ) {
            $amt = isset( $result['Amt'] ) ? $result['Amt'] : $auth_data['Amt'];
            if ( ! $this->verify_approval_signature( $result['TID'], $amt, $result['Signature'] ) ) {
                nicepay_log( 'Approval signature verification failed' );

                if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                    $this->request_net_cancel( $auth_data );
                }

                return new WP_Error( 'nicepay_signature_error', __( 'Signature verification failed.', 'nicepay-payment-gateway' ) );
            }
        }

        return $result;
    }

    /**
     * Send network cancel request
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Cancel response or error
     */
    public function request_net_cancel( $auth_data ) {
        $net_cancel_url = $auth_data['NetCancelURL'];
        $edi_date       = $this->generate_edi_date();

        $params = array(
            'TID'       => $auth_data['TxTid'],
            'AuthToken' => $auth_data['AuthToken'],
            'MID'       => $this->mid,
            'Amt'       => $auth_data['Amt'],
            'EdiDate'   => $edi_date,
            'NetCancel' => '1',
            'SignData'  => $this->create_approval_sign_data(
                $auth_data['AuthToken'],
                $auth_data['Amt'],
                $edi_date
            ),
            'CharSet'   => $this->charset,
        );

        nicepay_log( 'Network cancel request', array( 'url' => $net_cancel_url ) );

        $response = wp_remote_post( $net_cancel_url, array(
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
            ),
            'body'      => $params,
        ) );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Network cancel failed', $response->get_error_message() );
            return $response;
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        nicepay_log( 'Network cancel response', $result );
        return $result ? $result : new WP_Error( 'nicepay_parse_error', __( 'Failed to parse cancel response.', 'nicepay-payment-gateway' ) );
    }

    /**
     * Send payment cancel request
     *
     * @param string $tid           Transaction ID
     * @param string $cancel_amt    Amount to cancel
     * @param string $cancel_msg    Cancel reason
     * @param string $moid          Merchant order ID
     * @param bool   $partial       Whether this is partial cancel
     * @param array  $extra_params  Additional parameters
     * @return array|WP_Error Cancel response or error
     */
    public function request_cancel( $tid, $cancel_amt, $cancel_msg, $moid, $partial = false, $extra_params = array() ) {
        $edi_date = $this->generate_edi_date();

        $params = array(
            'TID'                => $tid,
            'MID'                => $this->mid,
            'Moid'               => $moid,
            'CancelAmt'          => $cancel_amt,
            'CancelMsg'          => $cancel_msg,
            'PartialCancelCode'  => $partial ? '1' : '0',
            'EdiDate'            => $edi_date,
            'SignData'           => $this->create_cancel_sign_data( $cancel_amt, $edi_date ),
            'CharSet'            => $this->charset,
        );

        $params = array_merge( $params, $extra_params );

        nicepay_log( 'Cancel request', array(
            'tid'        => $tid,
            'cancel_amt' => $cancel_amt,
            'partial'    => $partial,
        ) );

        $response = wp_remote_post( NICEPAY_CANCEL_URL, array(
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type' => 'application/x-www-form-urlencoded; charset=' . $this->charset,
            ),
            'body'      => $params,
        ) );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Cancel request failed', $response->get_error_message() );
            return $response;
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( ! $result ) {
            nicepay_log( 'Cancel response parse error', $body );
            return new WP_Error( 'nicepay_parse_error', __( 'Failed to parse cancel response.', 'nicepay-payment-gateway' ) );
        }

        nicepay_log( 'Cancel response', $result );
        return $result;
    }

    /**
     * Check if a result code indicates success
     */
    public function is_success_code( $code, $payment_method = '' ) {
        $success_codes = array(
            'CARD'      => '3001',
            'BANK'      => '4000',
            'VBANK'     => '4100',
            'CELLPHONE' => 'A000',
            'SSG_BANK'  => '0000',
            'GIFT_CULT' => '0000',
        );

        if ( $payment_method && isset( $success_codes[ $payment_method ] ) ) {
            return $code === $success_codes[ $payment_method ];
        }

        return in_array( $code, array_values( $success_codes ), true );
    }

    /**
     * Check if cancel result code indicates success
     */
    public function is_cancel_success( $code ) {
        return in_array( $code, array( '2001', '2211' ), true );
    }

    /**
     * Get VBank expiry date
     */
    public function get_vbank_exp_date() {
        $days = (int) get_option( 'nicepay_vbank_expiry_days', 3 );
        return date( 'YmdHi', strtotime( '+' . $days . ' days' ) );
    }

    /**
     * Get payment method display name
     */
    public static function get_payment_method_name( $code ) {
        $methods = array(
            'CARD'      => __( 'Credit Card', 'nicepay-payment-gateway' ),
            'BANK'      => __( 'Bank Transfer', 'nicepay-payment-gateway' ),
            'VBANK'     => __( 'Virtual Account', 'nicepay-payment-gateway' ),
            'CELLPHONE' => __( 'Mobile Payment', 'nicepay-payment-gateway' ),
            'SSG_BANK'  => __( 'SSG Bank Account', 'nicepay-payment-gateway' ),
            'GIFT_CULT' => __( 'Culture Cash', 'nicepay-payment-gateway' ),
        );

        return isset( $methods[ $code ] ) ? $methods[ $code ] : $code;
    }

    /**
     * Get all available payment methods
     */
    public static function get_available_methods() {
        return array(
            'CARD'      => __( 'Credit Card', 'nicepay-payment-gateway' ),
            'BANK'      => __( 'Bank Transfer', 'nicepay-payment-gateway' ),
            'VBANK'     => __( 'Virtual Account', 'nicepay-payment-gateway' ),
            'CELLPHONE' => __( 'Mobile Payment', 'nicepay-payment-gateway' ),
            'SSG_BANK'  => __( 'SSG Bank Account', 'nicepay-payment-gateway' ),
            'GIFT_CULT' => __( 'Culture Cash', 'nicepay-payment-gateway' ),
        );
    }

    /**
     * Get NicePay language code
     */
    public static function get_nicepay_lang( $lang = '' ) {
        if ( ! $lang ) {
            $lang = get_option( 'nicepay_language', 'KO' );
        }

        $map = array(
            'KO' => 'KO',
            'KR' => 'KO',
            'EN' => 'EN',
            'CN' => 'CN',
            'ZH' => 'CN',
        );

        $lang = strtoupper( $lang );
        return isset( $map[ $lang ] ) ? $map[ $lang ] : 'KO';
    }
}
