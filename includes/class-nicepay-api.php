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
    private $mode;
    private $is_test_mode;
    private $charset;

    public function __construct() {
        $mode               = (string) get_option( 'nicepay_mode', 'test' );
        $this->mode         = in_array( $mode, array( 'test', 'live' ), true ) ? $mode : '';
        $this->is_test_mode = ( 'test' === $mode );
        $this->charset      = 'utf-8';

        if ( 'test' === $mode ) {
            $this->mid          = get_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
            $this->merchant_key = get_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        } elseif ( 'live' === $mode ) {
			$this->mid          = defined( 'NICEPAY_LIVE_MID' ) ? (string) NICEPAY_LIVE_MID : get_option( 'nicepay_live_mid', '' );
			$this->merchant_key = defined( 'NICEPAY_LIVE_MERCHANT_KEY' ) ? (string) NICEPAY_LIVE_MERCHANT_KEY : get_option( 'nicepay_live_merchant_key', '' );
        } else {
            $this->mid          = '';
            $this->merchant_key = '';
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
     * Return the validated operating mode, or an empty string when invalid.
     *
     * @return string
     */
    public function get_mode() {
        return $this->mode;
    }

    /**
     * Generate EdiDate (YYYYMMDDHHMISS format)
     */
    public function generate_edi_date() {
        $now = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Seoul' ) );
        return $now->format( 'YmdHis' );
    }

    /**
     * Generate unique Moid (Merchant Order ID)
     */
    public function generate_moid( $prefix = 'WC' ) {
        $prefix = preg_replace( '/[^A-Za-z0-9_-]/', '_', (string) $prefix );
        $prefix = trim( preg_replace( '/_+/', '_', $prefix ), '_' );
        $prefix = substr( $prefix, 0, 32 );

        if ( '' === $prefix ) {
            $prefix = 'WC';
        }

        return $prefix . '_' . $this->generate_edi_date() . '_' . bin2hex( random_bytes( 8 ) );
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
        if ( ! is_string( $auth_token ) || ! is_string( $amt ) || ! is_string( $received_signature ) ) {
            return false;
        }
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
        if ( ! is_string( $tid ) || ! is_string( $amt ) || ! is_string( $received_signature ) ) {
            return false;
        }
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
        if ( ! is_string( $tid ) || ! is_string( $cancel_amt ) || ! is_string( $received_signature ) ) {
            return false;
        }
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
    );

    /** Allowed server-to-server paths from the legacy authenticated flow. */
    private static $allowed_paths = array(
        '/webapi/pay_process.jsp',
        '/webapi/cancel_process.jsp',
    );

    /**
     * Validate that a URL points to an allowed NicePay host (SSRF protection)
     */
    private function validate_nicepay_url( $url ) {
        $parsed = wp_parse_url( $url );

        if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ||
            isset( $parsed['user'] ) || isset( $parsed['pass'] ) ||
            isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
            return false;
        }

        if ( 'https' !== strtolower( $parsed['scheme'] ) ) {
            return false;
        }

        if ( isset( $parsed['port'] ) && 443 !== (int) $parsed['port'] ) {
            return false;
        }

        $host = strtolower( $parsed['host'] );
        $path = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        return in_array( $host, self::$allowed_hosts, true ) &&
            in_array( $path, self::$allowed_paths, true );
    }

    /**
     * Build consistent WordPress HTTP options for NicePay POST requests.
     *
     * @param array $body Request body.
     * @return array
     */
    private function request_args( array $body, $context = 'generic' ) {
        $timeout = 30;
        if ( function_exists( 'apply_filters' ) ) {
            $timeout = (int) apply_filters( 'nicepay_http_timeout', $timeout, $context );
        }
        $timeout = max( 1, min( 120, $timeout ) );

        return array(
            'timeout'     => $timeout,
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'NicePay-WooCommerce/' . NICEPAY_VERSION,
            'headers'     => array(
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            ),
            'body'        => $body,
        );
    }

    /**
     * Send a NicePay request with an independently bounded connect timeout.
     *
     * WordPress exposes only one general timeout argument. Its cURL transport
     * otherwise applies that same value while establishing the connection, so
     * a dead endpoint could consume the full response budget before a network
     * cancel is attempted. The temporary hook is scoped to this exact URL and
     * is always removed, including when the transport throws unexpectedly.
     *
     * @param string $url     Validated NicePay URL.
     * @param array  $body    Request body.
     * @param string $context Operation identifier.
     * @return array|WP_Error
     */
    private function post_to_nicepay( $url, array $body, $context ) {
        $args            = $this->request_args( $body, $context );
        $connect_timeout = 5;
        if ( function_exists( 'apply_filters' ) ) {
            $connect_timeout = (int) apply_filters(
                'nicepay_http_connect_timeout',
                $connect_timeout,
                $context
            );
        }
        $connect_timeout = max( 1, min( (int) $args['timeout'], $connect_timeout ) );
        $hook_added      = false;

        $configure_curl = static function ( $handle, $parsed_args, $request_url ) use ( $url, $connect_timeout ) {
            unset( $parsed_args );
            if ( $request_url !== $url ) {
                return;
            }
            curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout );
        };

        if ( function_exists( 'add_action' ) && function_exists( 'remove_action' ) &&
            function_exists( 'curl_setopt' ) && defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
            add_action( 'http_api_curl', $configure_curl, 10, 3 );
            $hook_added = true;
        }

        try {
            return wp_remote_post( $url, $args );
        } finally {
            if ( $hook_added ) {
                remove_action( 'http_api_curl', $configure_curl, 10 );
            }
        }
    }

    /**
     * Require a successful HTTP status before decoding a PG response.
     *
     * @param array|WP_Error $response WordPress HTTP response.
     * @param string         $context  Error-code context.
     * @return array|WP_Error
     */
    private function decode_response( $response, $context ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'nicepay_' . $context . '_http_error',
                __( 'NicePay returned an unexpected HTTP status.', 'nicepay-payment-gateway' ),
                array( 'http_status' => $status )
            );
        }

        $body   = wp_remote_retrieve_body( $response );
        $result = json_decode( $body, true );
        if ( ! is_array( $result ) ) {
            return new WP_Error(
                'nicepay_' . $context . '_parse_error',
                __( 'NicePay returned an unreadable response.', 'nicepay-payment-gateway' )
            );
        }

        return $result;
    }

    /**
     * Redact sensitive fields from data before logging
     */
    private function redact_for_log( $data ) {
        return nicepay_redact_log_data( $data );
    }

    /**
     * Send approval request to NicePay
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Approval response or error
     */
    public function request_approval( $auth_data ) {
        $required = array( 'NextAppURL', 'NetCancelURL', 'TxTid', 'AuthToken', 'Amt' );
        foreach ( $required as $field ) {
            if ( ! isset( $auth_data[ $field ] ) || ! is_string( $auth_data[ $field ] ) || '' === $auth_data[ $field ] ) {
                return new WP_Error( 'nicepay_approval_request_invalid', __( 'Approval request context is incomplete.', 'nicepay-payment-gateway' ) );
            }
        }

        if ( '' === $this->mid || '' === $this->merchant_key ) {
            return new WP_Error( 'nicepay_credentials_missing', __( 'NicePay credentials are not configured.', 'nicepay-payment-gateway' ) );
        }

        $next_app_url = $auth_data['NextAppURL'];

        // SSRF protection: validate URL host
        if ( ! $this->validate_nicepay_url( $next_app_url ) ) {
            nicepay_log( 'Approval URL validation failed', null, 'error' );
            return $this->abort_approval(
                new WP_Error( 'nicepay_url_error', __( 'Invalid approval URL.', 'nicepay-payment-gateway' ) ),
                $auth_data
            );
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
			'EdiType'   => 'JSON',
        );

        nicepay_log( 'Approval request', array(
            'url' => $next_app_url,
            'TID' => $auth_data['TxTid'],
            'Amt' => $auth_data['Amt'],
        ) );

        $response = $this->post_to_nicepay( $next_app_url, $params, 'approval' );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Approval request failed', $response->get_error_message(), 'error' );
            return $this->abort_approval( $response, $auth_data );
        }

        $result = $this->decode_response( $response, 'approval' );
        if ( is_wp_error( $result ) ) {
            nicepay_log( 'Approval response rejected', $result->get_error_code(), 'error' );
            return $this->abort_approval( $result, $auth_data );
        }

        nicepay_log( 'Approval response', $this->redact_for_log( $result ) );

        // Require and verify signature
        if ( ! isset( $result['Signature'], $result['TID'], $result['Amt'], $result['ResultCode'] ) ||
            ! is_string( $result['Signature'] ) || '' === $result['Signature'] ||
            ! is_string( $result['TID'] ) || '' === $result['TID'] ||
            ! is_string( $result['Amt'] ) || '' === $result['Amt'] ||
            ! is_string( $result['ResultCode'] ) || '' === $result['ResultCode'] ) {
            nicepay_log( 'Approval response missing Signature or TID', null, 'error' );

            return $this->abort_approval(
                new WP_Error( 'nicepay_signature_error', __( 'Missing signature in approval response.', 'nicepay-payment-gateway' ) ),
                $auth_data
            );
        }

        $amt = $result['Amt'];
        if ( ! $this->verify_approval_signature( $result['TID'], $amt, $result['Signature'] ) ) {
            nicepay_log( 'Approval signature verification failed', null, 'error' );

            return $this->abort_approval(
                new WP_Error( 'nicepay_signature_error', __( 'Signature verification failed.', 'nicepay-payment-gateway' ) ),
                $auth_data
            );
        }

        return $result;
    }

    /**
     * Abort an ambiguous approval and surface an unconfirmed reversal.
     *
     * @param WP_Error $error     Original approval error.
     * @param array    $auth_data Authentication context.
     * @return WP_Error
     */
    private function abort_approval( $error, array $auth_data ) {
        $net_cancel_requested_at = gmdate( 'Y-m-d H:i:s' );

        if ( empty( $auth_data['NetCancelURL'] ) ) {
            return new WP_Error(
                'nicepay_approval_reconciliation_required',
                __( 'The payment outcome requires merchant reconciliation.', 'nicepay-payment-gateway' ),
                array(
                    'cause'                   => $error->get_error_code(),
                    'cause_data'              => $error->get_error_data(),
                    'net_cancel'              => 'not_available',
                    'net_cancel_status'       => 'unknown',
                    'net_cancel_result_code'  => 'not_available',
                    'net_cancel_requested_at' => $net_cancel_requested_at,
                )
            );
        }

        $net_cancel = $this->request_net_cancel( $auth_data );
        if ( is_wp_error( $net_cancel ) ) {
            return new WP_Error(
                'nicepay_approval_reconciliation_required',
                __( 'The payment outcome requires merchant reconciliation.', 'nicepay-payment-gateway' ),
                array(
                    'cause'                   => $error->get_error_code(),
                    'cause_data'              => $error->get_error_data(),
                    'net_cancel'              => $net_cancel->get_error_code(),
                    'net_cancel_status'       => 'unknown',
                    'net_cancel_result_code'  => $net_cancel->get_error_code(),
                    'net_cancel_requested_at' => $net_cancel_requested_at,
                )
            );
        }

        return new WP_Error(
            $error->get_error_code(),
            $error->get_error_message(),
            array(
                'cause_data'                => $error->get_error_data(),
                'net_cancel_status'         => 'confirmed',
                'net_cancel_result_code'    => isset( $net_cancel['ResultCode'] ) ? $net_cancel['ResultCode'] : '',
                'net_cancel_result_msg'     => isset( $net_cancel['ResultMsg'] ) ? $net_cancel['ResultMsg'] : '',
                'net_cancel_requested_at'   => $net_cancel_requested_at,
                'net_cancel_completed_at'   => gmdate( 'Y-m-d H:i:s' ),
            )
        );
    }

    /**
     * Send network cancel request
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Cancel response or error
     */
    public function request_net_cancel( $auth_data ) {
        $required = array( 'NetCancelURL', 'TxTid', 'AuthToken', 'Amt' );
        foreach ( $required as $field ) {
            if ( ! isset( $auth_data[ $field ] ) || ! is_string( $auth_data[ $field ] ) || '' === $auth_data[ $field ] ) {
                return new WP_Error( 'nicepay_net_cancel_request_invalid', __( 'Network cancel context is incomplete.', 'nicepay-payment-gateway' ) );
            }
        }

        if ( '' === $this->mid || '' === $this->merchant_key ) {
            return new WP_Error( 'nicepay_credentials_missing', __( 'NicePay credentials are not configured.', 'nicepay-payment-gateway' ) );
        }

        $net_cancel_url = $auth_data['NetCancelURL'];

        if ( ! $this->validate_nicepay_url( $net_cancel_url ) ) {
            nicepay_log( 'Net cancel URL validation failed', null, 'error' );
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
			'EdiType'   => 'JSON',
        );

        nicepay_log( 'Network cancel request', array( 'TID' => $auth_data['TxTid'] ) );

        $response = $this->post_to_nicepay( $net_cancel_url, $params, 'net_cancel' );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Network cancel failed', $response->get_error_message(), 'error' );
            return $response;
        }

        $result = $this->decode_response( $response, 'net_cancel' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        nicepay_log( 'Network cancel response', $this->redact_for_log( $result ) );

        if ( ! isset( $result['TID'], $result['CancelAmt'], $result['Signature'], $result['ResultCode'] ) ||
            ! is_string( $result['TID'] ) || '' === $result['TID'] ||
            ! is_string( $result['CancelAmt'] ) || '' === $result['CancelAmt'] ||
            ! is_string( $result['Signature'] ) || '' === $result['Signature'] ||
            ! is_string( $result['ResultCode'] ) || '' === $result['ResultCode'] ) {
            return new WP_Error( 'nicepay_net_cancel_signature_error', __( 'Network cancel response could not be verified.', 'nicepay-payment-gateway' ) );
        }

        if ( ! $this->verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] ) ) {
            return new WP_Error( 'nicepay_net_cancel_signature_error', __( 'Network cancel response signature is invalid.', 'nicepay-payment-gateway' ) );
        }

        $requested_amount = nicepay_normalize_amount( $auth_data['Amt'], 'KRW' );
		$cancelled_amount = nicepay_normalize_response_amount( $result['CancelAmt'], 'KRW' );
        if ( ! hash_equals( $auth_data['TxTid'], (string) $result['TID'] ) ||
            false === $requested_amount || false === $cancelled_amount ||
            ! hash_equals( $requested_amount, $cancelled_amount ) ) {
            return new WP_Error( 'nicepay_net_cancel_binding_error', __( 'Network cancel response did not match the payment.', 'nicepay-payment-gateway' ) );
        }

        if ( ! isset( $result['ResultCode'] ) || '2001' !== $result['ResultCode'] ) {
            return new WP_Error( 'nicepay_net_cancel_rejected', __( 'NicePay did not confirm the network cancel.', 'nicepay-payment-gateway' ) );
        }

        return $result;
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
        $cancel_amt = nicepay_normalize_amount( $cancel_amt, 'KRW' );
        if ( false === $cancel_amt || '' === $this->mid || '' === $this->merchant_key ||
            ! is_string( $tid ) || '' === $tid || strlen( $tid ) > 50 ||
            ! is_string( $moid ) || '' === $moid || strlen( $moid ) > 64 ) {
            return new WP_Error( 'nicepay_cancel_request_invalid', __( 'Cancel request is invalid.', 'nicepay-payment-gateway' ) );
        }

        $cancel_msg = nicepay_utf8_byte_cut( sanitize_text_field( (string) $cancel_msg ), 100 );
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
			'EdiType'            => 'JSON',
        );

        // Extension fields may never replace identity, money, or signature
        // fields. VBANK refund-account parameters remain disabled until their
        // merchant contract is certified.
        $extra_params = is_array( $extra_params ) ? array_intersect_key( $extra_params, array() ) : array();
        $params       = array_merge( $params, $extra_params );

        nicepay_log( 'Cancel request', array(
            'tid'        => $tid,
            'cancel_amt' => $cancel_amt,
            'partial'    => $partial,
        ) );

        if ( ! $this->validate_nicepay_url( NICEPAY_CANCEL_URL ) ) {
            return new WP_Error( 'nicepay_url_error', __( 'Invalid cancel URL.', 'nicepay-payment-gateway' ) );
        }

        $response = $this->post_to_nicepay( NICEPAY_CANCEL_URL, $params, 'cancel' );

        if ( is_wp_error( $response ) ) {
            nicepay_log( 'Cancel request failed', $response->get_error_message(), 'error' );
            return $response;
        }

        $result = $this->decode_response( $response, 'cancel' );
        if ( is_wp_error( $result ) ) {
            nicepay_log( 'Cancel response rejected', $result->get_error_code(), 'error' );
            return $result;
        }

        nicepay_log( 'Cancel response', $this->redact_for_log( $result ) );

        if ( ! isset( $result['TID'], $result['CancelAmt'], $result['Signature'], $result['ResultCode'] ) ||
            ! is_string( $result['TID'] ) || '' === $result['TID'] ||
            ! is_string( $result['CancelAmt'] ) || '' === $result['CancelAmt'] ||
            ! is_string( $result['Signature'] ) || '' === $result['Signature'] ||
            ! is_string( $result['ResultCode'] ) || '' === $result['ResultCode'] ) {
            return new WP_Error( 'nicepay_signature_error', __( 'Cancel response could not be verified.', 'nicepay-payment-gateway' ) );
        }

        if ( ! $this->verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] ) ) {
            nicepay_log( 'Cancel response signature verification failed', null, 'error' );
            return new WP_Error( 'nicepay_signature_error', __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
        }

        $response_amount = nicepay_normalize_response_amount( $result['CancelAmt'], 'KRW' );
        if ( ! hash_equals( $tid, $result['TID'] ) ||
            false === $response_amount || ! hash_equals( $cancel_amt, $response_amount ) ) {
            return new WP_Error( 'nicepay_cancel_binding_error', __( 'Cancel response did not match the refund request.', 'nicepay-payment-gateway' ) );
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
            'CELLPHONE' => 'A000',
        );

        if ( ! isset( $success_codes[ $payment_method ] ) ) {
            return false;
        }

        return $code === $success_codes[ $payment_method ];
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
        $days = max( 1, min( 30, $days ) );
        $now  = new DateTimeImmutable( 'now', new DateTimeZone( 'Asia/Seoul' ) );
        return $now->modify( '+' . $days . ' days' )->format( 'YmdHi' );
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
     * Get methods that may be enabled before vendor certification gates clear.
     *
     * Known protocol labels remain available for historical records, while
     * payment configuration exposes only the currently verified subset.
     *
     * @return array<string,string>
     */
    public static function get_certified_methods() {
        return array(
            'CARD'      => __( 'Credit Card', 'nicepay-payment-gateway' ),
            'BANK'      => __( 'Bank Transfer', 'nicepay-payment-gateway' ),
            'CELLPHONE' => __( 'Mobile Payment', 'nicepay-payment-gateway' ),
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
