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
     * Allowed NicePay API hostnames for SSRF protection
     */
    private static $allowed_hosts = array(
        'dc1-api.nicepay.co.kr',
        'dc2-api.nicepay.co.kr',
        'pg-api.nicepay.co.kr',
        'pg-web.nicepay.co.kr',
    );

    /**
     * Validate that a URL points to an allowed NicePay host (SSRF protection)
     */
    private function validate_nicepay_url( $url ) {
        $parsed = wp_parse_url( $url );

        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return false;
        }

        if ( $parsed['scheme'] !== 'https' ) {
            return false;
        }

        return in_array( $parsed['host'], self::$allowed_hosts, true );
    }

    /**
     * Redact sensitive fields from data before logging
     */
    private function redact_for_log( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $sensitive_keys = array(
            'AuthToken', 'SignData', 'Signature', 'MerchantKey',
            'CardNo', 'CardNumber', 'VbankNum',
        );

        foreach ( $sensitive_keys as $key ) {
            if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && strlen( $data[ $key ] ) > 8 ) {
                $data[ $key ] = substr( $data[ $key ], 0, 4 ) . '****' . substr( $data[ $key ], -4 );
            }
        }

        return $data;
    }

    /**
     * Send approval request to NicePay
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Approval response or error
     */
    public function request_approval( $auth_data ) {
        $next_app_url = $auth_data['NextAppURL'];

        // SSRF protection: validate URL host
        if ( ! $this->validate_nicepay_url( $next_app_url ) ) {
            nicepay_log( 'Approval URL validation failed', $next_app_url );
            return new WP_Error( 'nicepay_url_error', __( 'Invalid approval URL.', 'nicepay-payment-gateway' ) );
        }

        $edi_date = $this->generate_edi_date();

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
            'url' => $next_app_url,
            'TID' => $auth_data['TxTid'],
            'Amt' => $auth_data['Amt'],
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

            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );

        if ( ! $result ) {
            nicepay_log( 'Approval response parse error' );

            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return new WP_Error( 'nicepay_parse_error', __( 'Failed to parse approval response.', 'nicepay-payment-gateway' ) );
        }

        nicepay_log( 'Approval response', $this->redact_for_log( $result ) );

        // Require and verify signature
        if ( empty( $result['Signature'] ) || empty( $result['TID'] ) ) {
            nicepay_log( 'Approval response missing Signature or TID' );

            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return new WP_Error( 'nicepay_signature_error', __( 'Missing signature in approval response.', 'nicepay-payment-gateway' ) );
        }

        $amt = isset( $result['Amt'] ) ? $result['Amt'] : $auth_data['Amt'];
        if ( ! $this->verify_approval_signature( $result['TID'], $amt, $result['Signature'] ) ) {
            nicepay_log( 'Approval signature verification failed' );

            if ( ! empty( $auth_data['NetCancelURL'] ) ) {
                $this->request_net_cancel( $auth_data );
            }

            return new WP_Error( 'nicepay_signature_error', __( 'Signature verification failed.', 'nicepay-payment-gateway' ) );
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

        if ( ! $this->validate_nicepay_url( $net_cancel_url ) ) {
            nicepay_log( 'Net cancel URL validation failed', $net_cancel_url );
            return new WP_Error( 'nicepay_url_error', __( 'Invalid cancel URL.', 'nicepay-payment-gateway' ) );
        }

        $edi_date = $this->generate_edi_date();

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

        nicepay_log( 'Network cancel request', array( 'TID' => $auth_data['TxTid'] ) );

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

        nicepay_log( 'Network cancel response', $result ? $this->redact_for_log( $result ) : 'parse_error' );
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
            nicepay_log( 'Cancel response parse error' );
            return new WP_Error( 'nicepay_parse_error', __( 'Failed to parse cancel response.', 'nicepay-payment-gateway' ) );
        }

        nicepay_log( 'Cancel response', $this->redact_for_log( $result ) );

        // Verify cancel response signature
        if ( ! empty( $result['TID'] ) && ! empty( $result['Signature'] ) ) {
            $resp_cancel_amt = isset( $result['CancelAmt'] ) ? $result['CancelAmt'] : $cancel_amt;
            if ( ! $this->verify_cancel_signature( $result['TID'], $resp_cancel_amt, $result['Signature'] ) ) {
                nicepay_log( 'Cancel response signature verification failed' );
                return new WP_Error( 'nicepay_signature_error', __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
            }
        }

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
