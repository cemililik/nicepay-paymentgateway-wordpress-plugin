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

abstract class NicePay_API_Configuration {

    protected $mid;
    protected $merchant_key;
    protected $mode;
    protected $is_test_mode;
    protected $charset;

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
    protected function validate_nicepay_url( $url ) {
        $parsed = wp_parse_url( $url );
        $valid  = is_array( $parsed ) && ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] );
        $valid  = $valid && ! isset( $parsed['user'] ) && ! isset( $parsed['pass'] ) &&
            ! isset( $parsed['query'] ) && ! isset( $parsed['fragment'] );
        if ( $valid ) {
            $valid = 'https' === strtolower( $parsed['scheme'] ) &&
                ( ! isset( $parsed['port'] ) || 443 === (int) $parsed['port'] );
        }
        if ( $valid ) {
            $host  = strtolower( $parsed['host'] );
            $path  = isset( $parsed['path'] ) ? $parsed['path'] : '/';
            $valid = in_array( $host, self::$allowed_hosts, true ) &&
                in_array( $path, self::$allowed_paths, true );
        }
        return $valid;
    }

    /**
     * Build consistent WordPress HTTP options for NicePay POST requests.
     *
     * @param array $body Request body.
     * @return array
     */
    protected function request_args( array $body, $context = 'generic' ) {
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
    protected function post_to_nicepay( $url, array $body, $context ) {
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
    protected function decode_response( $response, $context ) {
        $result = $response;
        if ( ! is_wp_error( $result ) ) {
            $status = wp_remote_retrieve_response_code( $response );
            if ( $status < 200 || $status >= 300 ) {
                $result = new WP_Error(
                'nicepay_' . $context . '_http_error',
                __( 'NicePay returned an unexpected HTTP status.', 'nicepay-payment-gateway' ),
                array( 'http_status' => $status )
                );
            } else {
                $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
                $result  = is_array( $decoded )
                    ? $decoded
                    : new WP_Error(
                        'nicepay_' . $context . '_parse_error',
                        __( 'NicePay returned an unreadable response.', 'nicepay-payment-gateway' )
                    );
            }
        }
        return $result;
    }

    /**
     * Redact sensitive fields from data before logging
     */
    protected function redact_for_log( $data ) {
        return nicepay_redact_log_data( $data );
    }
}

/** Handles the authenticated approval and emergency reversal protocol. */
abstract class NicePay_API_Approval extends NicePay_API_Configuration {

    /**
     * Send approval request to NicePay
     *
     * @param array $auth_data Authentication response data
     * @return array|WP_Error Approval response or error
     */
    public function request_approval( $auth_data ) {
        $result = $this->validate_approval_context( $auth_data );
        if ( ! is_wp_error( $result ) ) {
            if ( ! $this->validate_nicepay_url( $auth_data['NextAppURL'] ) ) {
                nicepay_log( 'Approval URL validation failed', null, 'error' );
                $result = new WP_Error( 'nicepay_url_error', __( 'Invalid approval URL.', 'nicepay-payment-gateway' ) );
            } else {
                $result = $this->perform_approval_request( $auth_data );
            }

            if ( is_wp_error( $result ) ) {
                $result = $this->abort_approval( $result, $auth_data );
            }
        }

        return $result;
    }

    /** @return true|WP_Error */
    private function validate_approval_context( $auth_data ) {
        $result = true;
        foreach ( array( 'NextAppURL', 'NetCancelURL', 'TxTid', 'AuthToken', 'Amt' ) as $field ) {
            if ( ! isset( $auth_data[ $field ] ) || ! is_string( $auth_data[ $field ] ) || '' === $auth_data[ $field ] ) {
                $result = new WP_Error( 'nicepay_approval_request_invalid', __( 'Approval request context is incomplete.', 'nicepay-payment-gateway' ) );
                break;
            }
        }
        if ( ! is_wp_error( $result ) && ( '' === $this->mid || '' === $this->merchant_key ) ) {
            $result = new WP_Error( 'nicepay_credentials_missing', __( 'NicePay credentials are not configured.', 'nicepay-payment-gateway' ) );
        }
        return $result;
    }

    /** @return array */
    private function approval_params( array $auth_data ) {
        $edi_date = $this->generate_edi_date();
        return array(
            'TID'       => $auth_data['TxTid'],
            'AuthToken' => $auth_data['AuthToken'],
            'MID'       => $this->mid,
            'Amt'       => $auth_data['Amt'],
            'EdiDate'   => $edi_date,
            'SignData'  => $this->create_approval_sign_data( $auth_data['AuthToken'], $auth_data['Amt'], $edi_date ),
            'CharSet'   => $this->charset,
            'EdiType'   => 'JSON',
        );
    }

    /** @return array|WP_Error */
    private function perform_approval_request( array $auth_data ) {
        nicepay_log( 'Approval request', array(
            'url' => $auth_data['NextAppURL'],
            'TID' => $auth_data['TxTid'],
            'Amt' => $auth_data['Amt'],
        ) );

        $result = $this->post_to_nicepay( $auth_data['NextAppURL'], $this->approval_params( $auth_data ), 'approval' );
        if ( is_wp_error( $result ) ) {
            nicepay_log( 'Approval request failed', $result->get_error_message(), 'error' );
        } else {
            $result = $this->decode_response( $result, 'approval' );
            if ( is_wp_error( $result ) ) {
                nicepay_log( 'Approval response rejected', $result->get_error_code(), 'error' );
            } else {
                nicepay_log( 'Approval response', $this->redact_for_log( $result ) );
                $result = $this->validate_approval_response( $result );
            }
        }
        return $result;
    }

    /** @return array|WP_Error */
    private function validate_approval_response( array $result ) {
        $validation = $result;
        foreach ( array( 'Signature', 'TID', 'Amt', 'ResultCode' ) as $field ) {
            if ( ! isset( $result[ $field ] ) || ! is_string( $result[ $field ] ) || '' === $result[ $field ] ) {
                nicepay_log( 'Approval response missing Signature or TID', null, 'error' );
                $validation = new WP_Error( 'nicepay_signature_error', __( 'Missing signature in approval response.', 'nicepay-payment-gateway' ) );
                break;
            }
        }
        if ( ! is_wp_error( $validation ) && ! $this->verify_approval_signature( $result['TID'], $result['Amt'], $result['Signature'] ) ) {
            nicepay_log( 'Approval signature verification failed', null, 'error' );
            $validation = new WP_Error( 'nicepay_signature_error', __( 'Signature verification failed.', 'nicepay-payment-gateway' ) );
        }
        return $validation;
    }

    /**
     * Abort an ambiguous approval and surface an unconfirmed reversal.
     *
     * @param WP_Error $error     Original approval error.
     * @param array    $auth_data Authentication context.
     * @return WP_Error
     */
    private function abort_approval( $error, array $auth_data ) {
        $net_cancel_requested_at = gmdate( NICEPAY_DB_DATETIME_FORMAT );

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
                'net_cancel_completed_at'   => gmdate( NICEPAY_DB_DATETIME_FORMAT ),
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
        $result = $this->validate_net_cancel_context( $auth_data );
        if ( is_wp_error( $result ) ) {
            return $result;
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

        $response = $this->post_to_nicepay( $auth_data['NetCancelURL'], $params, 'net_cancel' );
        $result   = $this->decode_response( $response, 'net_cancel' );
        if ( is_wp_error( $result ) ) {
            nicepay_log( 'Network cancel failed', $result->get_error_message(), 'error' );
        } else {
            nicepay_log( 'Network cancel response', $this->redact_for_log( $result ) );
            $result = $this->validate_net_cancel_response( $auth_data, $result );
        }
        return $result;
    }

    /** @return true|WP_Error */
    private function validate_net_cancel_context( $auth_data ) {
        $result = true;
        foreach ( array( 'NetCancelURL', 'TxTid', 'AuthToken', 'Amt' ) as $field ) {
            if ( ! isset( $auth_data[ $field ] ) || ! is_string( $auth_data[ $field ] ) || '' === $auth_data[ $field ] ) {
                $result = new WP_Error( 'nicepay_net_cancel_request_invalid', __( 'Network cancel context is incomplete.', 'nicepay-payment-gateway' ) );
                break;
            }
        }
        if ( ! is_wp_error( $result ) && ( '' === $this->mid || '' === $this->merchant_key ) ) {
            $result = new WP_Error( 'nicepay_credentials_missing', __( 'NicePay credentials are not configured.', 'nicepay-payment-gateway' ) );
        }
        if ( ! is_wp_error( $result ) && ! $this->validate_nicepay_url( $auth_data['NetCancelURL'] ) ) {
            nicepay_log( 'Net cancel URL validation failed', null, 'error' );
            $result = new WP_Error( 'nicepay_url_error', __( 'Invalid cancel URL.', 'nicepay-payment-gateway' ) );
        }
        return $result;
    }

    /** @return array|WP_Error */
    private function validate_net_cancel_response( array $auth_data, array $result ) {
        $validation = $result;
        $required   = array( 'TID', 'CancelAmt', 'Signature', 'ResultCode' );
        foreach ( $required as $field ) {
            if ( ! isset( $result[ $field ] ) || ! is_string( $result[ $field ] ) || '' === $result[ $field ] ) {
                $validation = new WP_Error( 'nicepay_net_cancel_signature_error', __( 'Network cancel response could not be verified.', 'nicepay-payment-gateway' ) );
                break;
            }
        }

        if ( ! is_wp_error( $validation ) && ! $this->verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] ) ) {
            $validation = new WP_Error( 'nicepay_net_cancel_signature_error', __( 'Network cancel response signature is invalid.', 'nicepay-payment-gateway' ) );
        }
        if ( ! is_wp_error( $validation ) && ! $this->net_cancel_binding_matches( $auth_data, $result ) ) {
            $validation = new WP_Error( 'nicepay_net_cancel_binding_error', __( 'Network cancel response did not match the payment.', 'nicepay-payment-gateway' ) );
        }
        if ( ! is_wp_error( $validation ) && '2001' !== $result['ResultCode'] ) {
            $validation = new WP_Error( 'nicepay_net_cancel_rejected', __( 'NicePay did not confirm the network cancel.', 'nicepay-payment-gateway' ) );
        }
        return $validation;
    }

    /** @return bool */
    private function net_cancel_binding_matches( array $auth_data, array $result ) {
        $requested = nicepay_normalize_amount( $auth_data['Amt'], 'KRW' );
        $cancelled = nicepay_normalize_response_amount( $result['CancelAmt'], 'KRW' );
        return hash_equals( $auth_data['TxTid'], $result['TID'] ) && false !== $requested &&
            false !== $cancelled && hash_equals( $requested, $cancelled );
    }
}

/** Public NicePay API facade, including merchant-initiated cancellations. */
class NicePay_API extends NicePay_API_Approval {

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
        $context = $this->cancel_request_context( $tid, $cancel_amt, $cancel_msg, $moid, $partial );
        $result  = $context;
        if ( ! is_wp_error( $context ) ) {
            // Contract-specific extension fields stay disabled until NicePay certifies them.
            unset( $extra_params );
            if ( ! $this->validate_nicepay_url( NICEPAY_CANCEL_URL ) ) {
                $result = new WP_Error( 'nicepay_url_error', __( 'Invalid cancel URL.', 'nicepay-payment-gateway' ) );
            } else {
                $result = $this->perform_cancel_request( $context );
            }
        }
        return $result;
    }

    /** @return array|WP_Error */
    private function cancel_request_context( $tid, $cancel_amt, $cancel_msg, $moid, $partial ) {
        $cancel_amt = nicepay_normalize_amount( $cancel_amt, 'KRW' );
        $context    = array(
            'tid'        => $tid,
            'cancel_amt' => $cancel_amt,
            'cancel_msg' => nicepay_utf8_byte_cut( sanitize_text_field( (string) $cancel_msg ), 100 ),
            'moid'       => $moid,
            'partial'    => (bool) $partial,
        );
        if ( false === $cancel_amt || '' === $this->mid || '' === $this->merchant_key ||
            ! is_string( $tid ) || '' === $tid || strlen( $tid ) > 50 ||
            ! is_string( $moid ) || '' === $moid || strlen( $moid ) > 64 ) {
            $context = new WP_Error( 'nicepay_cancel_request_invalid', __( 'Cancel request is invalid.', 'nicepay-payment-gateway' ) );
        }
        return $context;
    }

    /** @return array */
    private function cancel_params( array $context ) {
        $edi_date = $this->generate_edi_date();
        return array(
            'TID'               => $context['tid'],
            'MID'               => $this->mid,
            'Moid'              => $context['moid'],
            'CancelAmt'         => $context['cancel_amt'],
            'CancelMsg'         => $context['cancel_msg'],
            'PartialCancelCode' => $context['partial'] ? '1' : '0',
            'EdiDate'           => $edi_date,
            'SignData'          => $this->create_cancel_sign_data( $context['cancel_amt'], $edi_date ),
            'CharSet'           => $this->charset,
            'EdiType'           => 'JSON',
        );
    }

    /** @return array|WP_Error */
    private function perform_cancel_request( array $context ) {
        nicepay_log( 'Cancel request', array(
            'tid'        => $context['tid'],
            'cancel_amt' => $context['cancel_amt'],
            'partial'    => $context['partial'],
        ) );
        $result = $this->post_to_nicepay( NICEPAY_CANCEL_URL, $this->cancel_params( $context ), 'cancel' );
        if ( is_wp_error( $result ) ) {
            nicepay_log( 'Cancel request failed', $result->get_error_message(), 'error' );
        } else {
            $result = $this->decode_response( $result, 'cancel' );
            if ( is_wp_error( $result ) ) {
                nicepay_log( 'Cancel response rejected', $result->get_error_code(), 'error' );
            } else {
                nicepay_log( 'Cancel response', $this->redact_for_log( $result ) );
                $result = $this->validate_cancel_response( $context, $result );
            }
        }
        return $result;
    }

    /** @return array|WP_Error */
    private function validate_cancel_response( array $context, array $result ) {
        $validation = $result;
        foreach ( array( 'TID', 'CancelAmt', 'Signature', 'ResultCode' ) as $field ) {
            if ( ! isset( $result[ $field ] ) || ! is_string( $result[ $field ] ) || '' === $result[ $field ] ) {
                $validation = new WP_Error( 'nicepay_signature_error', __( 'Cancel response could not be verified.', 'nicepay-payment-gateway' ) );
                break;
            }
        }
        if ( ! is_wp_error( $validation ) && ! $this->verify_cancel_signature( $result['TID'], $result['CancelAmt'], $result['Signature'] ) ) {
            nicepay_log( 'Cancel response signature verification failed', null, 'error' );
            $validation = new WP_Error( 'nicepay_signature_error', __( 'Cancel signature verification failed.', 'nicepay-payment-gateway' ) );
        }
        if ( ! is_wp_error( $validation ) && ! $this->cancel_response_binding_matches( $context, $result ) ) {
            $validation = new WP_Error( 'nicepay_cancel_binding_error', __( 'Cancel response did not match the refund request.', 'nicepay-payment-gateway' ) );
        }
        return $validation;
    }

    /** @return bool */
    private function cancel_response_binding_matches( array $context, array $result ) {
        $response_amount = nicepay_normalize_response_amount( $result['CancelAmt'], 'KRW' );
        return hash_equals( $context['tid'], $result['TID'] ) && false !== $response_amount &&
            hash_equals( $context['cancel_amt'], $response_amount );
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
