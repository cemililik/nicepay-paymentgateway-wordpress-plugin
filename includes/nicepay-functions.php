<?php
/**
 * NicePay Helper Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-nicepay-transaction-schema.php';

/**
 * Log NicePay events for debugging
 *
 * @param string $message Log message.
 * @param mixed  $data    Optional data to log.
 * @param string $level   Logger level; errors are emitted even when WP_DEBUG is off.
 */
function nicepay_log( $message, $data = null, $level = 'debug' ) {
    $level = in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'debug';
    if ( 'debug' === $level && ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) ) {
        return;
    }

    $message   = str_replace( array( "\r", "\n" ), ' ', (string) $message );
    $log_entry = '[NicePay] ' . $message;
    if ( $data !== null ) {
        $data = nicepay_redact_log_data( $data );
        if ( is_array( $data ) || is_object( $data ) ) {
            $log_entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        } else {
            $log_entry .= ' | ' . str_replace( array( "\r", "\n" ), ' ', (string) $data );
        }
    }

    if ( function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        if ( is_callable( array( $logger, $level ) ) ) {
            $logger->{$level}( $log_entry, array( 'source' => 'nicepay' ) );
        } else {
            $logger->log( $level, $log_entry, array( 'source' => 'nicepay' ) );
        }
    } elseif ( 'error' === $level || 'warning' === $level ) {
        error_log( $log_entry );
    }
}

/**
 * Recursively remove credentials, payment identifiers, and buyer PII from logs.
 *
 * @param mixed  $data Data to redact.
 * @param string $key  Parent key for scalar values.
 * @return mixed
 */
function nicepay_redact_log_data( $data, $key = '' ) {
    $sensitive = array(
        'authtoken', 'signdata', 'signature', 'merchantkey',
        'buyername', 'buyeremail', 'buyertel',
        'cardno', 'cardnumber', 'cardtoken', 'vbanknum',
        'refundacctnum', 'refundacctno', 'accountnumber', 'accountno',
        'authcode',
    );
    // Ignore separators and case so AuthToken, auth_token, auth-token and
    // similar vendor/application spellings share one deny list.
    $normalized_key = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $key ) );

    if ( in_array( $normalized_key, $sensitive, true ) ) {
        return '[REDACTED]';
    }

    if ( is_array( $data ) ) {
        $redacted = array();
        foreach ( $data as $child_key => $value ) {
            $redacted[ $child_key ] = nicepay_redact_log_data( $value, (string) $child_key );
        }
        return $redacted;
    }

    if ( is_object( $data ) ) {
        return nicepay_redact_log_data( get_object_vars( $data ) );
    }

    return is_string( $data ) ? str_replace( array( "\r", "\n" ), ' ', $data ) : $data;
}

/**
 * Keep only response fields required for financial audit and reconciliation.
 *
 * Credentials, signatures, account numbers, PAN data, and buyer PII are never
 * persisted in the raw payment payload.
 *
 * @param mixed $data Decoded NicePay response.
 * @return array
 */
function nicepay_filter_payment_data( $data ) {
    if ( ! is_array( $data ) ) {
        return array();
    }

    $allowed = array(
        'ResultCode', 'ResultMsg', 'TID', 'MID', 'Moid', 'Amt', 'PayMethod',
        'AuthCode', 'AuthDate', 'CardCode', 'CardName', 'CardQuota', 'BankCode',
        'BankName', 'CancelAmt', 'CancelNum', 'CancelDate', 'CancelTime', 'CcPartCl',
        'ClickpayCl', 'CardType',
        'ErrorCD', 'ErrorMsg',
    );
    $filtered = array();

    foreach ( $allowed as $key ) {
        if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
            $filtered[ $key ] = nicepay_utf8_byte_cut( str_replace( array( "\r", "\n" ), ' ', (string) $data[ $key ] ), 500 );
        }
    }

    return $filtered;
}

/**
 * Extract only certified card refund-capability flags from a PG response.
 *
 * @param mixed $data Decoded NicePay response.
 * @return array<string,string>
 */
function nicepay_get_refund_capability_fields( $data ) {
    if ( ! is_array( $data ) ) {
        return array();
    }

    $fields = array();
    if ( isset( $data['CcPartCl'] ) && is_scalar( $data['CcPartCl'] ) &&
        in_array( (string) $data['CcPartCl'], array( '0', '1' ), true ) ) {
        $fields['cc_part_cl'] = (string) $data['CcPartCl'];
    }
    if ( isset( $data['ClickpayCl'] ) && is_scalar( $data['ClickpayCl'] ) &&
        preg_match( '/^[0-9]{1,2}$/', (string) $data['ClickpayCl'] ) ) {
        $fields['clickpay_cl'] = (string) $data['ClickpayCl'];
    }
    if ( isset( $data['CardType'] ) && is_scalar( $data['CardType'] ) &&
        in_array( (string) $data['CardType'], array( '01', '02', '03' ), true ) ) {
        $fields['card_type'] = (string) $data['CardType'];
    }

    return $fields;
}

/**
 * Convert an approval failure into a durable network-cancel audit record.
 *
 * An approval error is safe to classify as a normal failure only when the API
 * layer explicitly reports a verified network cancel. Missing audit data is an
 * unknown money outcome and therefore requires reconciliation.
 *
 * @param WP_Error $error Approval error returned by NicePay_API.
 * @return array<string,mixed>
 */
function nicepay_get_approval_error_audit( $error ) {
    $data = is_wp_error( $error ) ? $error->get_error_data() : array();
    $data = is_array( $data ) ? $data : array();

    $status = isset( $data['net_cancel_status'] ) && 'confirmed' === $data['net_cancel_status']
        ? 'confirmed'
        : 'unknown';
    $needs_reconciliation = 'confirmed' !== $status;

    $result_code = isset( $data['net_cancel_result_code'] ) && is_scalar( $data['net_cancel_result_code'] )
        ? sanitize_text_field( (string) $data['net_cancel_result_code'] )
        : ( $needs_reconciliation ? 'not_confirmed' : '' );
    $result_msg = isset( $data['net_cancel_result_msg'] ) && is_scalar( $data['net_cancel_result_msg'] )
        ? nicepay_utf8_byte_cut( sanitize_text_field( (string) $data['net_cancel_result_msg'] ), 500 )
        : '';

    $requested_at = isset( $data['net_cancel_requested_at'] ) && is_string( $data['net_cancel_requested_at'] )
        ? $data['net_cancel_requested_at']
        : null;
    $completed_at = ! $needs_reconciliation && isset( $data['net_cancel_completed_at'] ) && is_string( $data['net_cancel_completed_at'] )
        ? $data['net_cancel_completed_at']
        : null;

    return array(
        'needs_reconciliation'    => $needs_reconciliation,
        'net_cancel_status'       => $status,
        'net_cancel_result_code'  => $result_code,
        'net_cancel_result_msg'   => $result_msg,
        'net_cancel_requested_at' => $requested_at,
        'net_cancel_completed_at' => $completed_at,
    );
}

/**
 * Build a fail-closed audit for an approval response that did not bind.
 *
 * A network cancel can only prove reversal of the authenticated TID/amount.
 * It cannot prove that a different TID or amount found in the approval
 * response was not captured. Therefore every post-approval binding failure
 * remains locked for manual reconciliation, even when that cancel succeeds.
 *
 * @param WP_Error       $binding_error Approval binding error.
 * @param array|WP_Error $net_cancel    Network-cancel result for the original auth context.
 * @param string         $auth_token    Verified auth token retained for reconciliation.
 * @return array<string,mixed> Transaction update fields.
 */
function nicepay_get_mismatched_approval_audit( $binding_error, $net_cancel, $auth_token ) {
    $cancel_unknown = is_wp_error( $net_cancel );
    $result_code    = $cancel_unknown
        ? $net_cancel->get_error_code()
        : ( isset( $net_cancel['ResultCode'] ) && is_scalar( $net_cancel['ResultCode'] )
            ? sanitize_text_field( (string) $net_cancel['ResultCode'] )
            : '' );
    $binding_code = is_wp_error( $binding_error )
        ? $binding_error->get_error_code()
        : 'nicepay_approval_binding_failed';

    return array(
        'status'                  => 'needs_reconciliation',
        'approval_state'          => 'needs_reconciliation',
        'reconciliation_status'   => 'required',
        'reconciliation_note'     => sanitize_text_field( (string) $binding_code ),
        'net_cancel_status'       => $cancel_unknown ? 'unknown' : 'confirmed',
        'net_cancel_result_code'  => $result_code,
        'net_cancel_result_msg'   => '',
        'net_cancel_requested_at' => gmdate( 'Y-m-d H:i:s' ),
        'net_cancel_completed_at' => $cancel_unknown ? null : gmdate( 'Y-m-d H:i:s' ),
        'auth_token'              => (string) $auth_token,
    );
}

/**
 * Reverse an authenticated payment after a local post-authentication failure.
 *
 * @param int         $transaction_id Transaction row ID.
 * @param array       $auth_data      Verified authentication context.
 * @param NicePay_API $api            Configured API client.
 * @param string      $reason         Stable internal failure code.
 * @return array<string,mixed> Reversal and persistence outcome.
 */
function nicepay_abort_authenticated_payment( $transaction_id, array $auth_data, $api, $reason ) {
    $requested_at = gmdate( 'Y-m-d H:i:s' );
    $net_cancel   = $api->request_net_cancel( $auth_data );
    $unknown      = is_wp_error( $net_cancel );
    $result_code  = $unknown
        ? $net_cancel->get_error_code()
        : ( isset( $net_cancel['ResultCode'] ) ? sanitize_text_field( (string) $net_cancel['ResultCode'] ) : '' );
    $result_msg   = ! $unknown && isset( $net_cancel['ResultMsg'] )
        ? nicepay_utf8_byte_cut( sanitize_text_field( (string) $net_cancel['ResultMsg'] ), 500 )
        : '';

    $persisted = nicepay_update_transaction(
        $transaction_id,
        array(
            'status'                    => $unknown ? 'needs_reconciliation' : 'failed',
            'approval_state'            => $unknown ? 'needs_reconciliation' : 'failed',
            'reconciliation_status'     => $unknown ? 'required' : 'not_required',
            'reconciliation_note'       => sanitize_text_field( (string) $reason ),
            'net_cancel_status'         => $unknown ? 'unknown' : 'confirmed',
            'net_cancel_result_code'    => $result_code,
            'net_cancel_result_msg'     => $result_msg,
            'net_cancel_requested_at'   => $requested_at,
            'net_cancel_completed_at'   => $unknown ? null : gmdate( 'Y-m-d H:i:s' ),
            'auth_token'                => $unknown && isset( $auth_data['AuthToken'] ) ? $auth_data['AuthToken'] : '',
            'active_attempt_key'        => null,
        ),
        true
    );

    return array(
        'needs_reconciliation' => $unknown,
        'persisted'             => $persisted,
        'net_cancel_status'     => $unknown ? 'unknown' : 'confirmed',
        'net_cancel_result_code'=> $result_code,
    );
}

/**
 * Apply a privacy-preserving fixed-window limit to a public payment action.
 *
 * Only the server-observed REMOTE_ADDR is used by default; forwarded headers
 * are intentionally ignored because their trust boundary is deployment
 * specific. Operators behind a trusted proxy may replace the identity through
 * the documented filter without storing a raw address in WordPress.
 *
 * @param string $scope  Short action identifier.
 * @param int    $limit  Allowed requests per window.
 * @param int    $window Window length in seconds.
 * @return bool Whether the request may continue.
 */
function nicepay_check_public_rate_limit( $scope, $limit = 20, $window = 60 ) {
    $scope  = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $scope ) );
    $limit  = max( 1, (int) $limit );
    $window = max( 1, (int) $window );

    $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
        ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
        : '';
    if ( false === filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
        // A missing server address is an environment/configuration problem.
        // Fail open here rather than creating one shared global denial key.
        return true;
    }

    $identity = hash_hmac( 'sha256', $remote_addr, wp_salt( 'nonce' ) );
    if ( function_exists( 'apply_filters' ) ) {
        $identity = (string) apply_filters( 'nicepay_public_rate_limit_identity', $identity, $scope );
        $limit    = max( 1, (int) apply_filters( 'nicepay_public_rate_limit_max_requests', $limit, $scope ) );
        $window   = max( 1, (int) apply_filters( 'nicepay_public_rate_limit_window', $window, $scope ) );
    }

    if ( '' === $identity || '' === $scope ) {
        return true;
    }

    $key       = 'nicepay_rl_' . substr( hash( 'sha256', $scope . '|' . $identity ), 0, 40 );
    $state     = get_transient( $key );
    $now       = time();
    $reset_at  = is_array( $state ) && isset( $state['reset_at'] ) ? (int) $state['reset_at'] : 0;
    $count     = is_array( $state ) && $reset_at > $now && isset( $state['count'] )
        ? (int) $state['count'] + 1
        : 1;
    $reset_at = $reset_at > $now ? $reset_at : $now + $window;

    set_transient(
        $key,
        array(
            'count'    => $count,
            'reset_at' => $reset_at,
        ),
        max( 1, $reset_at - $now )
    );

    return $count <= $limit;
}

/**
 * Save transaction to database
 *
 * @param array $data Transaction data
 * @return int|false Inserted row ID or false on failure
 */
function nicepay_save_transaction( $data ) {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';

    $defaults = array(
        'tid'             => null,
        'order_id'        => '',
        'wc_order_id'     => null,
        'moid'            => '',
        'amount'          => 0,
        'payment_method'  => '',
        'pay_method_name' => '',
        'status'          => 'pending',
        'result_code'     => '',
        'result_msg'      => '',
        'auth_token'      => '',
        'buyer_name'      => '',
        'buyer_email'     => '',
        'buyer_tel'       => '',
        'goods_name'      => '',
        'card_code'       => '',
        'card_name'       => '',
        'card_quota'      => '',
        'bank_code'       => '',
        'bank_name'       => '',
        'vbank_num'       => '',
        'vbank_exp_date'  => '',
        'payment_data'    => '',
    );

    $data = wp_parse_args( $data, $defaults );

    if ( is_array( $data['payment_data'] ) ) {
        $data['payment_data'] = wp_json_encode( $data['payment_data'], JSON_UNESCAPED_UNICODE );
    }

    $prepared = NicePay_Transaction_Schema::prepare_write( $data );
    if ( empty( $prepared['data'] ) ) {
        return false;
    }

    $result = $wpdb->insert( $table, $prepared['data'], $prepared['formats'] );

    if ( $result === false ) {
        nicepay_log( 'Failed to save transaction', null, 'error' );
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Update transaction in database
 *
 * @param int   $id             Transaction ID.
 * @param array $data           Data to update.
 * @param bool  $require_change Whether exactly one row must be changed.
 * @return bool Success
 */
function nicepay_update_transaction( $id, $data, $require_change = false ) {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';

    if ( isset( $data['payment_data'] ) && is_array( $data['payment_data'] ) ) {
        $data['payment_data'] = wp_json_encode( $data['payment_data'], JSON_UNESCAPED_UNICODE );
    }

    $prepared = NicePay_Transaction_Schema::prepare_write( $data );
    if ( empty( $prepared['data'] ) ) {
        return false;
    }

    $result = $wpdb->update(
        $table,
        $prepared['data'],
        array( 'id' => absint( $id ) ),
        $prepared['formats'],
        array( '%d' )
    );

    $succeeded = $require_change ? 1 === (int) $result : false !== $result;
    if ( ! $succeeded ) {
        nicepay_log(
            $require_change ? 'Strict transaction update did not change exactly one row' : 'Transaction update failed',
            array( 'transaction_id' => absint( $id ) ),
            'error'
        );
    }

    return $succeeded;
}

/**
 * Get transaction by TID
 *
 * @param string $tid Transaction ID
 * @return object|null Transaction row
 */
function nicepay_get_transaction_by_tid( $tid, $wc_order_id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'nicepay_transactions';
    $tid   = (string) $tid;
    if ( '' === $tid ) {
        return null;
    }

    $wc_order_id = absint( $wc_order_id );
    if ( $wc_order_id > 0 ) {
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE tid = %s AND wc_order_id = %d AND flow = 'woocommerce' LIMIT 1",
                $tid,
                $wc_order_id
            )
        );
    }

    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tid = %s LIMIT 1", $tid ) );
}

/**
 * Get one transaction by its internal row ID.
 *
 * @param int $id Transaction row ID.
 * @return object|null
 */
function nicepay_get_transaction( $id ) {
    global $wpdb;

    $id = absint( $id );
    if ( $id < 1 ) {
        return null;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );
}

/**
 * Check the filterable WooCommerce operations capability while preserving
 * WordPress administrators.
 *
 * @return bool
 */
function nicepay_current_user_can_manage_payments() {
    return current_user_can( 'manage_options' ) || current_user_can( nicepay_manage_transactions_capability() );
}

/**
 * Return site-wide configuration warnings without exposing credentials.
 *
 * @param NicePay_API|null $api Optional configured API instance.
 * @return array<int,array{type:string,code:string,message:string}>
 */
function nicepay_get_configuration_warnings( $api = null ) {
    $api = null === $api ? new NicePay_API() : $api;
    if ( ! is_object( $api ) || ! method_exists( $api, 'get_mode' ) ||
        ! method_exists( $api, 'get_mid' ) || ! method_exists( $api, 'get_merchant_key' ) ) {
        return array();
    }

    $warnings         = array();
    $gateway_settings = get_option( 'woocommerce_nicepay_settings', array() );
    $payments_enabled = ( is_array( $gateway_settings ) && 'yes' === ( isset( $gateway_settings['enabled'] ) ? $gateway_settings['enabled'] : '' ) ) ||
        'yes' === get_option( 'nicepay_standalone_enabled', 'no' );
    $mode = (string) $api->get_mode();

    if ( 'test' === $mode && $payments_enabled ) {
        $warnings[] = array(
            'type'    => 'warning',
            'code'    => 'test_mode_enabled',
            'message' => __( 'NicePay test mode is enabled on a public payment surface. Test approvals collect no real funds; do not fulfil them as paid orders.', 'nicepay-payment-gateway' ),
        );
    }

    if ( 'live' === $mode && ( '' === (string) $api->get_mid() || '' === (string) $api->get_merchant_key() ) ) {
        $warnings[] = array(
            'type'    => 'error',
            'code'    => 'live_credentials_missing',
            'message' => __( 'NicePay live mode is selected, but the active MID or merchant key is missing. Payment forms remain unavailable.', 'nicepay-payment-gateway' ),
        );
    }

    if ( '' === $mode ) {
        $warnings[] = array(
            'type'    => 'error',
            'code'    => 'mode_invalid',
            'message' => __( 'NicePay has an invalid operating mode. Payment forms remain unavailable until the setting is corrected.', 'nicepay-payment-gateway' ),
        );
    }

    return $warnings;
}

/**
 * Return the capability used for operational transaction screens/actions.
 *
 * @return string
 */
function nicepay_manage_transactions_capability() {
    return (string) apply_filters( 'nicepay_manage_transactions_capability', 'manage_woocommerce' );
}

/**
 * Build the standalone return URL for both pretty and plain permalinks.
 *
 * @return string
 */
function nicepay_get_standalone_return_url() {
    if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
        return home_url( '/nicepay-return/' );
    }

    return add_query_arg( 'nicepay_return', '1', home_url( '/' ) );
}

/**
 * Derive the carrier goods classification from WooCommerce order items.
 *
 * NICEPAY requires 0 for digital content and 1 for physical goods when the
 * CELLPHONE method is used. Missing/unknown products fail conservatively to
 * physical goods; an invalid filtered value fails closed.
 *
 * @param object $order WooCommerce order.
 * @return string|false `0`, `1`, or false for an invalid override.
 */
function nicepay_get_woocommerce_goods_class( $order ) {
    if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
        return false;
    }

    $items       = $order->get_items();
    $all_virtual = is_array( $items ) && ! empty( $items );
    foreach ( (array) $items as $item ) {
        $product = is_object( $item ) && method_exists( $item, 'get_product' ) ? $item->get_product() : null;
        if ( ! $product || ! method_exists( $product, 'is_virtual' ) || ! $product->is_virtual() ) {
            $all_virtual = false;
            break;
        }
    }

    $goods_class = $all_virtual ? '0' : '1';
    if ( function_exists( 'apply_filters' ) ) {
        $goods_class = (string) apply_filters( 'nicepay_goods_cl', $goods_class, $order );
    }

    return in_array( $goods_class, array( '0', '1' ), true ) ? $goods_class : false;
}

/**
 * Get transaction by Moid
 *
 * @param string $moid Merchant Order ID.
 * @param string $flow Optional transaction flow constraint.
 * @return object|null Transaction row
 */
function nicepay_get_transaction_by_moid( $moid, $flow = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'nicepay_transactions';

    if ( '' === $moid ) {
        return null;
    }

    if ( '' !== $flow ) {
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE moid = %s AND flow = %s LIMIT 1",
                $moid,
                $flow
            )
        );
    }

    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE moid = %s LIMIT 1", $moid ) );
}

/**
 * Return the single active WooCommerce attempt lock for an order.
 *
 * @param int $order_id WooCommerce order ID.
 * @return object|null
 */
function nicepay_get_active_woocommerce_transaction( $order_id ) {
    global $wpdb;

    $order_id = absint( $order_id );
    if ( $order_id < 1 ) {
        return null;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE active_attempt_key = %s AND flow = 'woocommerce' AND wc_order_id = %d
             LIMIT 1",
            'woocommerce:' . (string) $order_id,
            $order_id
        )
    );
}

/**
 * Atomically claim a pending transaction before calling the approval API.
 *
 * Exactly one concurrent/replayed callback can change the state from pending
 * to approving. Callers must treat every other return value as a no-op.
 *
 * @param int    $id   Transaction row ID.
 * @param string $flow Expected flow.
 * @return bool Whether this request acquired the claim.
 */
function nicepay_claim_transaction_for_approval( $id, $flow ) {
    global $wpdb;

    $id   = absint( $id );
    $flow = (string) $flow;
    if ( $id < 1 || ! in_array( $flow, array( 'woocommerce', 'standalone' ), true ) ) {
        return false;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';

    if ( 'woocommerce' === $flow ) {
        $sql = $wpdb->prepare(
            "UPDATE {$table} AS candidate
             INNER JOIN {$table} AS target
                ON target.id = %d
               AND target.flow = %s
               AND target.source_ref <> ''
               AND target.status = 'pending'
               AND target.approval_state = 'pending'
               AND candidate.flow = target.flow
               AND candidate.source_ref = target.source_ref
             SET candidate.status = IF(candidate.id = target.id, 'approving', 'abandoned'),
                 candidate.approval_state = IF(candidate.id = target.id, 'approving', 'abandoned'),
                 candidate.approval_attempts = candidate.approval_attempts + IF(candidate.id = target.id, 1, 0),
                 candidate.approval_started_at = IF(candidate.id = target.id, UTC_TIMESTAMP(), candidate.approval_started_at),
                 candidate.active_attempt_key = IF(candidate.id = target.id, candidate.active_attempt_key, NULL)
             WHERE candidate.status = 'pending'
               AND candidate.approval_state = 'pending'",
            $id,
            $flow
        );

        $result = $wpdb->query( $sql );
        if ( false === $result ) {
            nicepay_log( 'WooCommerce approval claim query failed', array( 'transaction_id' => $id ), 'error' );
        }
        return 0 < (int) $result;
    }

    $sql   = $wpdb->prepare(
        "UPDATE {$table}
         SET status = 'approving', approval_state = 'approving',
             approval_attempts = approval_attempts + 1,
             approval_started_at = UTC_TIMESTAMP()
         WHERE id = %d AND flow = %s
           AND status = 'pending' AND approval_state = 'pending'",
        $id,
        $flow
    );

    $result = $wpdb->query( $sql );
    if ( false === $result ) {
        nicepay_log( 'Standalone approval claim query failed', array( 'transaction_id' => $id ), 'error' );
    }
    return 1 === (int) $result;
}

/**
 * Atomically reserve the remaining balance for one WooCommerce refund call.
 *
 * @param int    $id            Transaction row ID.
 * @param string $cancel_moid   Unique merchant cancel identifier.
 * @param string $cancel_amount Canonical positive KRW amount.
 * @return bool Whether the refund reservation was acquired.
 */
function nicepay_claim_transaction_for_refund( $id, $cancel_moid, $cancel_amount ) {
    global $wpdb;

    $id            = absint( $id );
    $cancel_moid   = (string) $cancel_moid;
    $cancel_amount = nicepay_normalize_amount( $cancel_amount, 'KRW' );
    if ( $id < 1 || false === $cancel_amount || '' === $cancel_moid || strlen( $cancel_moid ) > 64 ) {
        return false;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';
    $sql   = $wpdb->prepare(
        "UPDATE {$table}
         SET cancel_moid = %s, cancel_status = 'requested', cancel_amount = %s,
             cancel_requested_at = UTC_TIMESTAMP()
         WHERE id = %d AND (flow = 'woocommerce' OR (flow = '' AND wc_order_id IS NOT NULL))
           AND status IN ('paid', 'partially_refunded')
           AND reconciliation_status <> 'required'
           AND cancel_status NOT IN ('requested', 'unknown')
           AND (
                (remaining_amount > 0 AND remaining_amount >= %s)
                OR
                (status = 'paid' AND remaining_amount = 0 AND refunded_amount = 0 AND amount >= %s)
           )",
        $cancel_moid,
        $cancel_amount,
        $id,
        $cancel_amount,
        $cancel_amount
    );

    $result = $wpdb->query( $sql );
    if ( false === $result ) {
        nicepay_log( 'Refund claim query failed', array( 'transaction_id' => $id ), 'error' );
    }
    return 1 === (int) $result;
}

/**
 * Persist a refund request before any cancel request leaves the server.
 *
 * @param array $data Refund attempt fields.
 * @return int|false
 */
function nicepay_save_refund_attempt( array $data ) {
    global $wpdb;

    if ( empty( $data['transaction_id'] ) || empty( $data['wc_order_id'] ) ||
        empty( $data['tid'] ) || empty( $data['cancel_moid'] ) ||
        false === nicepay_normalize_amount( isset( $data['requested_amount'] ) ? $data['requested_amount'] : '', 'KRW' ) ) {
        return false;
    }

    $data['requested_amount'] = nicepay_normalize_amount( $data['requested_amount'], 'KRW' );
    $data['currency']         = 'KRW';
    $data['status']           = 'requested';
    $data['requested_at']     = isset( $data['requested_at'] ) ? $data['requested_at'] : gmdate( 'Y-m-d H:i:s' );
    $prepared                 = NicePay_Transaction_Schema::prepare_refund_write( $data );
    $table                    = $wpdb->prefix . 'nicepay_refund_attempts';
    $result                   = $wpdb->insert( $table, $prepared['data'], $prepared['formats'] );

    if ( false === $result ) {
        nicepay_log( 'Failed to persist NicePay refund attempt before transport', null, 'error' );
        return false;
    }

    return (int) $wpdb->insert_id;
}

/**
 * Complete one immutable refund-attempt record.
 *
 * @param int    $attempt_id  Refund-attempt row ID.
 * @param string $cancel_moid Merchant cancel ID.
 * @param array  $data        Verified or outcome-unknown fields.
 * @return bool
 */
function nicepay_complete_refund_attempt( $attempt_id, $cancel_moid, array $data ) {
    global $wpdb;

    $attempt_id  = absint( $attempt_id );
    $cancel_moid = (string) $cancel_moid;
    if ( $attempt_id < 1 || '' === $cancel_moid ) {
        return false;
    }

    if ( isset( $data['response_data'] ) && is_array( $data['response_data'] ) ) {
        $data['response_data'] = wp_json_encode( nicepay_filter_payment_data( $data['response_data'] ), JSON_UNESCAPED_UNICODE );
    }
    $prepared = NicePay_Transaction_Schema::prepare_refund_write( $data );
    if ( empty( $prepared['data'] ) ) {
        return false;
    }

    $table  = $wpdb->prefix . 'nicepay_refund_attempts';
    $result = $wpdb->update(
        $table,
        $prepared['data'],
        array( 'id' => $attempt_id, 'cancel_moid' => $cancel_moid, 'status' => 'requested' ),
        $prepared['formats'],
        array( '%d', '%s', '%s' )
    );

    if ( 1 !== (int) $result ) {
        nicepay_log( 'Refund attempt completion did not update exactly one row', array( 'refund_attempt_id' => $attempt_id ), 'error' );
        return false;
    }

    return true;
}

/**
 * Release a refund claim when no PG request was sent because local audit
 * persistence failed.
 *
 * @param int    $transaction_id Transaction row ID.
 * @param string $cancel_moid    Reserved cancel ID.
 * @return bool
 */
function nicepay_release_unsent_refund_claim( $transaction_id, $cancel_moid ) {
    global $wpdb;

    $table  = $wpdb->prefix . 'nicepay_transactions';
    $result = $wpdb->update(
        $table,
        array(
            'cancel_moid'         => '',
            'cancel_status'       => '',
            'cancel_amount'       => 0,
            'cancel_result_code'  => 'LOCAL_AUDIT_WRITE_FAILED',
            'cancel_result_msg'   => '',
            'cancel_requested_at' => null,
        ),
        array(
            'id'            => absint( $transaction_id ),
            'cancel_moid'   => (string) $cancel_moid,
            'cancel_status' => 'requested',
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s' ),
        array( '%d', '%s', '%s' )
    );

    if ( 1 !== (int) $result ) {
        nicepay_log( 'Unsent refund claim could not be released', array( 'transaction_id' => absint( $transaction_id ) ), 'error' );
        return false;
    }

    return true;
}

/**
 * Complete exactly the refund reservation that produced a verified PG result.
 *
 * @param int    $id          Transaction row ID.
 * @param string $cancel_moid Reserved cancel identifier.
 * @param array  $data        Verified ledger fields.
 * @return bool Whether exactly one reserved row was updated.
 */
function nicepay_complete_transaction_refund( $id, $cancel_moid, array $data ) {
    global $wpdb;

    $id          = absint( $id );
    $cancel_moid = (string) $cancel_moid;
    if ( $id < 1 || '' === $cancel_moid ) {
        return false;
    }

    if ( isset( $data['payment_data'] ) && is_array( $data['payment_data'] ) ) {
        $data['payment_data'] = wp_json_encode( $data['payment_data'], JSON_UNESCAPED_UNICODE );
    }

    $prepared = NicePay_Transaction_Schema::prepare_write( $data );
    if ( empty( $prepared['data'] ) ) {
        return false;
    }

    $table  = $wpdb->prefix . 'nicepay_transactions';
    $result = $wpdb->update(
        $table,
        $prepared['data'],
        array(
            'id'            => $id,
            'cancel_moid'   => $cancel_moid,
            'cancel_status' => 'requested',
        ),
        $prepared['formats'],
        array( '%d', '%s', '%s' )
    );

    if ( 1 !== (int) $result ) {
        nicepay_log( 'Confirmed refund could not update its reserved ledger row', array( 'transaction_id' => $id ), 'error' );
        return false;
    }

    return true;
}

/**
 * Mark older pending attempts for the same merchant-owned source as replaced.
 *
 * @param string $flow       Transaction flow.
 * @param string $source_ref Server-owned source reference.
 * @return bool
 */
function nicepay_abandon_pending_transactions( $flow, $source_ref ) {
    global $wpdb;

    if ( ! in_array( $flow, array( 'woocommerce', 'standalone' ), true ) || '' === (string) $source_ref ) {
        return false;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';
    $sql   = $wpdb->prepare(
        "UPDATE {$table}
         SET status = 'abandoned', approval_state = 'abandoned', active_attempt_key = NULL
         WHERE flow = %s AND source_ref = %s
           AND status = 'pending' AND approval_state = 'pending'",
        $flow,
        (string) $source_ref
    );

    $result = $wpdb->query( $sql );
    if ( false === $result ) {
        nicepay_log( 'Pending payment attempts could not be abandoned', array( 'flow' => $flow ), 'error' );
        return false;
    }

    return true;
}

/**
 * Expire stale, never-claimed payment attempts without touching money states.
 *
 * @return int Number of affected rows, or zero on failure.
 */
function nicepay_expire_pending_transactions() {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';
    $sql   = "UPDATE {$table}
              SET status = 'expired', approval_state = 'expired', auth_token = '', active_attempt_key = NULL
              WHERE status = 'pending' AND approval_state = 'pending'
                AND offer_expires_at IS NOT NULL
                AND offer_expires_at < UTC_TIMESTAMP()";
    $count = $wpdb->query( $sql );

    if ( false === $count ) {
        nicepay_log( 'Pending payment expiry job failed', null, 'error' );
        $count = 0;
    }

    $stale_sql = "UPDATE {$table}
                  SET status = 'needs_reconciliation',
                      approval_state = 'needs_reconciliation',
                      reconciliation_status = 'required',
                      reconciliation_note = 'stale_approval_attempt'
                  WHERE status = 'approving' AND approval_state = 'approving'
                    AND approval_started_at IS NOT NULL
                    AND approval_started_at < (UTC_TIMESTAMP() - INTERVAL 30 MINUTE)";
    $stale_count = $wpdb->query( $stale_sql );
    if ( false === $stale_count ) {
        nicepay_log( 'Stale NicePay approval recovery job failed', null, 'error' );
        $stale_count = 0;
    }

    return (int) $count + (int) $stale_count;
}

/**
 * Warn when a merchant cancels a WooCommerce order whose NicePay funds may
 * still be captured. This deliberately does not initiate an automatic refund.
 *
 * @param int $order_id WooCommerce order ID.
 * @return void
 */
function nicepay_warn_cancelled_order_with_captured_funds( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || 'nicepay' !== $order->get_payment_method() ||
        'yes' === $order->get_meta( '_nicepay_cancelled_funds_warning' ) ) {
        return;
    }

    $tid = (string) $order->get_meta( '_nicepay_tid' );
    if ( '' === $tid ) {
        return;
    }

    $transaction = nicepay_get_transaction_by_tid( $tid, $order->get_id() );
    if ( $transaction && 'refunded' === (string) $transaction->status ) {
        return;
    }

    if ( $transaction && isset( $transaction->remaining_amount ) &&
        '0' === nicepay_normalize_ledger_amount( $transaction->remaining_amount ) ) {
        return;
    }

    $order->add_order_note(
        __( 'Warning: cancelling the WooCommerce order does not refund captured NicePay funds. Verify the remaining balance and create an explicit WooCommerce refund if money must be returned.', 'nicepay-payment-gateway' )
    );
    $order->update_meta_data( '_nicepay_cancelled_funds_warning', 'yes' );
    $order->save();
}

/**
 * Count transactions whose money outcome requires merchant review.
 *
 * @return int
 */
function nicepay_get_reconciliation_count() {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';
    $sql   = "SELECT COUNT(*) FROM {$table}
              WHERE status = 'needs_reconciliation'
                 OR reconciliation_status = 'required'
                 OR cancel_status = 'unknown'
                 OR net_cancel_status = 'unknown'";

    return max( 0, (int) $wpdb->get_var( $sql ) );
}

/**
 * Issue a high-entropy, database-backed receipt URL for a standalone payment.
 * Only the token hash is persisted.
 *
 * @param int $transaction_id Transaction row ID.
 * @return array<string,string>|false
 */
function nicepay_issue_standalone_receipt( $transaction_id ) {
    $transaction_id = absint( $transaction_id );
    if ( $transaction_id < 1 ) {
        return false;
    }

    try {
        $token = bin2hex( random_bytes( 32 ) );
    } catch ( Throwable $throwable ) {
        nicepay_log( 'Standalone receipt token generation failed', $transaction_id, 'error' );
        return false;
    }

    if ( ! nicepay_update_transaction(
        $transaction_id,
        array(
            'receipt_token_hash' => hash( 'sha256', $token ),
            'receipt_issued_at'  => gmdate( 'Y-m-d H:i:s' ),
        ),
        true
    ) ) {
        nicepay_log( 'Standalone receipt token could not be persisted', $transaction_id, 'error' );
        return false;
    }

    return array(
        'token' => $token,
        'url'   => add_query_arg( 'nicepay_receipt', $token, home_url( '/' ) ),
    );
}

/**
 * Resolve a public standalone receipt from its unguessable bearer token.
 *
 * @param mixed $token Receipt bearer token.
 * @return object|null
 */
function nicepay_get_standalone_receipt( $token ) {
    global $wpdb;

    if ( ! is_string( $token ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $token ) ) {
        return null;
    }

    $table = $wpdb->prefix . 'nicepay_transactions';
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, tid, moid, amount, currency, payment_method, status, created_at, approved_at
             FROM {$table}
             WHERE receipt_token_hash = %s AND flow = 'standalone'
               AND status IN ('paid', 'partially_refunded', 'refunded')
             LIMIT 1",
            hash( 'sha256', $token )
        )
    );
}

/**
 * Load refund-attempt history for a page of transaction IDs in one query.
 *
 * @param array $transaction_ids Transaction row IDs.
 * @return array<int,array<int,object>> Attempts grouped by transaction ID.
 */
function nicepay_get_refund_attempts_for_transactions( array $transaction_ids ) {
    global $wpdb;

    $transaction_ids = array_values( array_unique( array_filter( array_map( 'absint', $transaction_ids ) ) ) );
    if ( empty( $transaction_ids ) ) {
        return array();
    }
    $transaction_ids = array_slice( $transaction_ids, 0, 100 );
    $placeholders     = implode( ', ', array_fill( 0, count( $transaction_ids ), '%d' ) );
    $table            = $wpdb->prefix . 'nicepay_refund_attempts';
    $sql              = $wpdb->prepare(
        "SELECT id, transaction_id, cancel_moid, requested_amount, currency, status,
                result_code, result_msg, requested_at, completed_at
         FROM {$table}
         WHERE transaction_id IN ({$placeholders})
         ORDER BY created_at DESC, id DESC",
        ...$transaction_ids
    );
    $rows = $wpdb->get_results( $sql );
    $grouped = array();
    foreach ( (array) $rows as $row ) {
        $transaction_id = isset( $row->transaction_id ) ? absint( $row->transaction_id ) : 0;
        if ( $transaction_id > 0 ) {
            $grouped[ $transaction_id ][] = $row;
        }
    }

    return $grouped;
}

/**
 * Email a safe standalone receipt link without including buyer or card data.
 *
 * @param object $transaction Standalone transaction row.
 * @param string $receipt_url Receipt URL.
 * @return bool
 */
function nicepay_send_standalone_receipt_email( $transaction, $receipt_url ) {
    if ( ! is_object( $transaction ) || ! function_exists( 'wp_mail' ) ||
        empty( $transaction->buyer_email ) || ! is_string( $receipt_url ) || '' === $receipt_url ||
        ( function_exists( 'is_email' ) && ! is_email( $transaction->buyer_email ) ) ) {
        return false;
    }

    $subject = __( 'Your NicePay payment receipt', 'nicepay-payment-gateway' );
    $body    = sprintf(
        /* translators: %1$s: merchant payment reference, %2$s: formatted amount, %3$s: secure receipt URL */
        __( "Payment reference: %1\$s\nAmount: %2\$s\nReceipt: %3\$s", 'nicepay-payment-gateway' ),
        isset( $transaction->moid ) ? (string) $transaction->moid : '',
        nicepay_format_amount(
            isset( $transaction->amount ) ? $transaction->amount : 0,
            isset( $transaction->currency ) ? $transaction->currency : 'KRW'
        ),
        $receipt_url
    );

    return (bool) wp_mail( sanitize_email( $transaction->buyer_email ), $subject, $body );
}

/**
 * Get transactions list with pagination
 *
 * @param array $args Query arguments
 * @return array Array with 'items' and 'total' keys
 */
function nicepay_get_transactions( $args = array() ) {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';

    $defaults = array(
        'status'         => '',
        'payment_method' => '',
        'date_from'      => '',
        'date_to'        => '',
        'search'         => '',
        'per_page'       => 20,
        'page'           => 1,
        'orderby'        => 'created_at',
        'order'          => 'DESC',
    );

    $args = wp_parse_args( $args, $defaults );

    $where = array( '1=1' );
    $values = array();

    if ( $args['status'] ) {
        $where[]  = 'status = %s';
        $values[] = $args['status'];
    }

    if ( $args['payment_method'] ) {
        $where[]  = 'payment_method = %s';
        $values[] = $args['payment_method'];
    }

    if ( $args['date_from'] ) {
        $where[]  = 'created_at >= %s';
        $values[] = $args['date_from'] . ' 00:00:00';
    }

    if ( $args['date_to'] ) {
        $where[]  = 'created_at <= %s';
        $values[] = $args['date_to'] . ' 23:59:59';
    }

    if ( $args['search'] ) {
        $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[]  = '(tid LIKE %s OR moid LIKE %s OR buyer_name LIKE %s OR goods_name LIKE %s)';
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
        $values[] = $like;
    }

    $where_clause = implode( ' AND ', $where );

    $allowed_orderby = array( 'created_at', 'amount', 'status', 'payment_method' );
    $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
    $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

    $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
    $limit  = (int) $args['per_page'];

    // Count total
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
    if ( ! empty( $values ) ) {
        $count_sql = $wpdb->prepare( $count_sql, $values );
    }
    $total = (int) $wpdb->get_var( $count_sql );

    // Get items
    $columns = 'id, tid, wc_order_id, moid, currency, amount, captured_amount, refunded_amount, remaining_amount, '
        . 'payment_method, pay_method_name, status, reconciliation_status, reconciliation_note, cancel_status, buyer_name, created_at';
    $sql = "SELECT {$columns} FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $values[] = $limit;
    $values[] = $offset;

    $items = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

    return array(
        'items' => $items ? $items : array(),
        'total' => $total,
    );
}

/**
 * Format amount for display
 *
 * @param float  $amount   Amount
 * @param string $currency Currency code
 * @return string Formatted amount
 */
function nicepay_format_amount( $amount, $currency = '' ) {
    if ( ! $currency ) {
        $currency = get_option( 'nicepay_currency', 'KRW' );
    }

    if ( $currency === 'KRW' ) {
        return number_format( round( (float) $amount, 0, PHP_ROUND_HALF_UP ) ) . ' ' . $currency;
    }

    return number_format( (float) $amount, 2 ) . ' ' . $currency;
}

/**
 * Pick the higher-contrast text color for a hexadecimal background color.
 *
 * The two candidates are the frontend design system's normal dark text and
 * white. Invalid or absent colors retain the existing white-button default.
 *
 * @param string $background Hexadecimal background color.
 * @return string Hexadecimal text color.
 */
function nicepay_get_contrast_color( $background ) {
    $background = (string) $background;
    if ( ! preg_match( '/^#(?:[A-Fa-f0-9]{3}){1,2}$/', $background ) ) {
        return '#ffffff';
    }

    $hex = ltrim( $background, '#' );
    if ( 3 === strlen( $hex ) ) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $components = array(
        hexdec( substr( $hex, 0, 2 ) ) / 255,
        hexdec( substr( $hex, 2, 2 ) ) / 255,
        hexdec( substr( $hex, 4, 2 ) ) / 255,
    );
    $components = array_map(
        static function ( $component ) {
            return $component <= 0.04045
                ? $component / 12.92
                : pow( ( $component + 0.055 ) / 1.055, 2.4 );
        },
        $components
    );

    $background_luminance = ( 0.2126 * $components[0] ) + ( 0.7152 * $components[1] ) + ( 0.0722 * $components[2] );
    $dark_luminance       = 0.0099; // Relative luminance of #111827.
    $white_contrast       = 1.05 / ( $background_luminance + 0.05 );
    $dark_contrast        = ( $background_luminance + 0.05 ) / ( $dark_luminance + 0.05 );

    return $dark_contrast > $white_contrast ? '#111827' : '#ffffff';
}

/**
 * Check whether a currency is currently certified for payment requests.
 *
 * USD remains visible in historical records, but new payments fail closed until
 * its amount representation is confirmed with a vendor fixture.
 *
 * @param string $currency ISO 4217 currency code.
 * @return bool
 */
function nicepay_is_supported_currency( $currency ) {
    return strtoupper( (string) $currency ) === 'KRW';
}

/**
 * Return the conservative default payment-method set.
 *
 * @return string[]
 */
function nicepay_default_enabled_methods() {
    return array( 'CARD' );
}

/**
 * Return configured payment methods after applying the certification gate.
 *
 * Historical option values are preserved in storage, but methods without a
 * complete, tested lifecycle cannot reach a new payment request.
 *
 * @return string[]
 */
function nicepay_get_enabled_methods() {
    $configured = get_option( 'nicepay_enabled_methods', nicepay_default_enabled_methods() );
    if ( ! is_array( $configured ) ) {
        return array();
    }

    $certified = array( 'CARD', 'BANK', 'CELLPHONE' );
    $configured = array_filter( $configured, 'is_string' );
    return array_values( array_unique( array_intersect( $configured, $certified ) ) );
}

/**
 * Cut valid UTF-8 text to a byte limit without splitting a character.
 *
 * @param string $value     Input text.
 * @param int    $max_bytes Maximum byte length.
 * @return string
 */
function nicepay_utf8_byte_cut( $value, $max_bytes ) {
    $value     = (string) $value;
    $max_bytes = max( 0, (int) $max_bytes );

    if ( strlen( $value ) <= $max_bytes ) {
        return $value;
    }

    if ( function_exists( 'mb_strcut' ) ) {
        return mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
    }

    $cut = substr( $value, 0, $max_bytes );
    while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
        $cut = substr( $cut, 0, -1 );
    }

    return $cut;
}

/**
 * Return NICEPAY's byte limits for buyer identity fields.
 *
 * @return array<string,int>
 */
function nicepay_buyer_field_limits() {
    return array(
        'buyer_name'  => 30,
        'buyer_tel'   => 20,
        'buyer_email' => 60,
    );
}

/**
 * Sanitize and validate buyer fields against the protocol byte contract.
 *
 * HTML maxlength counts characters, while NICEPAY limits these fields in
 * encoded UTF-8 bytes. This server-side check therefore remains authoritative.
 *
 * @param mixed $buyer_name  Buyer name.
 * @param mixed $buyer_email Buyer email.
 * @param mixed $buyer_tel   Buyer telephone.
 * @param bool  $required    Whether all three values are required.
 * @return array<string,string>|WP_Error
 */
function nicepay_validate_buyer_fields( $buyer_name, $buyer_email, $buyer_tel, $required = true ) {
    if ( ! is_string( $buyer_name ) || ! is_string( $buyer_email ) || ! is_string( $buyer_tel ) ) {
        return new WP_Error( 'nicepay_buyer_fields_invalid', __( 'Buyer information is invalid.', 'nicepay-payment-gateway' ) );
    }

    $values = array(
        'buyer_name'  => sanitize_text_field( $buyer_name ),
        'buyer_email' => sanitize_email( $buyer_email ),
        'buyer_tel'   => sanitize_text_field( $buyer_tel ),
    );
    $limits = nicepay_buyer_field_limits();

    foreach ( $values as $field => $value ) {
        if ( $required && '' === $value ) {
            return new WP_Error( 'nicepay_buyer_fields_required', __( 'Buyer information is required.', 'nicepay-payment-gateway' ) );
        }
        if ( '' !== $value && ( 1 !== preg_match( '//u', $value ) || strlen( $value ) > $limits[ $field ] ) ) {
            return new WP_Error( 'nicepay_buyer_fields_too_long', __( 'Buyer information exceeds the payment provider field limits.', 'nicepay-payment-gateway' ) );
        }
    }

    if ( '' !== $values['buyer_email'] ) {
        $valid_email = function_exists( 'is_email' )
            ? is_email( $values['buyer_email'] )
            : filter_var( $values['buyer_email'], FILTER_VALIDATE_EMAIL );
        if ( ! $valid_email ) {
            return new WP_Error( 'nicepay_buyer_email_invalid', __( 'Buyer email address is invalid.', 'nicepay-payment-gateway' ) );
        }
    }

    if ( '' !== $values['buyer_tel'] && ! preg_match( '/^[0-9+() -]{7,20}$/', $values['buyer_tel'] ) ) {
        return new WP_Error( 'nicepay_buyer_phone_invalid', __( 'Buyer phone number is invalid.', 'nicepay-payment-gateway' ) );
    }

    return $values;
}

/**
 * Increment a non-negative integer represented as a decimal string.
 *
 * Keeping this operation string-based prevents large payment amounts from
 * being changed by floating-point or platform integer conversions.
 *
 * @param string $integer Decimal digits only.
 * @return string
 */
function nicepay_increment_integer_string( $integer ) {
    $digits = str_split( $integer );

    for ( $index = count( $digits ) - 1; $index >= 0; $index-- ) {
        if ( '9' !== $digits[ $index ] ) {
            $digits[ $index ] = (string) ( (int) $digits[ $index ] + 1 );
            return implode( '', $digits );
        }

        $digits[ $index ] = '0';
    }

    return '1' . implode( '', $digits );
}

/**
 * Normalize an amount for a NicePay request.
 *
 * @param mixed  $amount   Raw amount.
 * @param string $currency ISO 4217 currency code.
 * @return string|false Normalized amount, or false when invalid/unsupported.
 */
function nicepay_normalize_amount( $amount, $currency = '' ) {
    if ( ! $currency ) {
        $currency = get_option( 'nicepay_currency', 'KRW' );
    }

    $currency = strtoupper( (string) $currency );
    if ( ! nicepay_is_supported_currency( $currency ) ) {
        return false;
    }

    if ( is_int( $amount ) || is_float( $amount ) ) {
        if ( ! is_finite( (float) $amount ) ) {
            return false;
        }
        $raw = rtrim( rtrim( sprintf( '%.8F', (float) $amount ), '0' ), '.' );
    } elseif ( is_string( $amount ) ) {
        $raw = trim( $amount );
    } else {
        return false;
    }

    if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $raw ) ) {
        return false;
    }

    $parts   = explode( '.', $raw, 2 );
    $integer = $parts[0];
    $decimal = isset( $parts[1] ) ? $parts[1] : '';

    if ( '' !== $decimal && (int) $decimal[0] >= 5 ) {
        $integer = nicepay_increment_integer_string( $integer );
    }

    $integer = ltrim( $integer, '0' );
    if ( '' === $integer ) {
        return false;
    }

    $maximum = '999999999999';
    if ( strlen( $integer ) > strlen( $maximum ) ||
        ( strlen( $integer ) === strlen( $maximum ) && strcmp( $integer, $maximum ) > 0 ) ) {
        return false;
    }

    return $integer;
}

/**
 * Normalize a signed amount echoed by the NicePay protocol.
 *
 * Request amounts deliberately reject leading zeroes. Protocol responses may
 * use a fixed-width 12-byte amount, so binding comparisons canonicalize only a
 * strictly digit-only response while signature verification keeps the exact
 * bytes returned by NicePay.
 *
 * @param mixed  $amount   Raw signed response amount.
 * @param string $currency ISO 4217 currency code.
 * @return string|false Canonical positive integer or false.
 */
function nicepay_normalize_response_amount( $amount, $currency = 'KRW' ) {
    if ( 'KRW' !== strtoupper( (string) $currency ) ||
        ! is_string( $amount ) ||
        ! preg_match( '/^[0-9]{1,12}$/', $amount ) ) {
        return false;
    }

    $canonical = ltrim( $amount, '0' );
    if ( '' === $canonical ) {
        return false;
    }

    return nicepay_normalize_amount( $canonical, 'KRW' );
}

/**
 * Normalize a ledger amount while allowing an exact zero balance.
 *
 * @param mixed $amount Raw KRW amount.
 * @return string|false Canonical non-negative integer or false.
 */
function nicepay_normalize_ledger_amount( $amount ) {
    if ( is_int( $amount ) || is_float( $amount ) ) {
        if ( is_finite( (float) $amount ) && 0.0 === (float) $amount ) {
            return '0';
        }
    } elseif ( is_string( $amount ) && preg_match( '/^0+(?:\.0+)?$/', trim( $amount ) ) ) {
        return '0';
    }

    return nicepay_normalize_amount( $amount, 'KRW' );
}

/**
 * Compare two canonical non-negative integer strings.
 *
 * @return int -1, 0, or 1.
 */
function nicepay_compare_integer_amounts( $left, $right ) {
    $left  = ltrim( (string) $left, '0' );
    $right = ltrim( (string) $right, '0' );
    $left  = '' === $left ? '0' : $left;
    $right = '' === $right ? '0' : $right;

    if ( strlen( $left ) !== strlen( $right ) ) {
        return strlen( $left ) < strlen( $right ) ? -1 : 1;
    }

    return strcmp( $left, $right ) <=> 0;
}

/**
 * Add two canonical non-negative integer strings without float conversion.
 *
 * @return string
 */
function nicepay_add_integer_amounts( $left, $right ) {
    $left   = strrev( (string) $left );
    $right  = strrev( (string) $right );
    $length = max( strlen( $left ), strlen( $right ) );
    $carry  = 0;
    $result = '';

    for ( $index = 0; $index < $length; $index++ ) {
        $sum = ( $index < strlen( $left ) ? (int) $left[ $index ] : 0 ) +
            ( $index < strlen( $right ) ? (int) $right[ $index ] : 0 ) + $carry;
        $result .= (string) ( $sum % 10 );
        $carry   = intdiv( $sum, 10 );
    }
    if ( $carry > 0 ) {
        $result .= (string) $carry;
    }

    return strrev( $result );
}

/**
 * Subtract canonical non-negative integer strings when left >= right.
 *
 * @return string|false
 */
function nicepay_subtract_integer_amounts( $left, $right ) {
    if ( nicepay_compare_integer_amounts( $left, $right ) < 0 ) {
        return false;
    }

    $left   = strrev( (string) $left );
    $right  = strrev( (string) $right );
    $borrow = 0;
    $result = '';

    for ( $index = 0; $index < strlen( $left ); $index++ ) {
        $digit = (int) $left[ $index ] - $borrow - ( $index < strlen( $right ) ? (int) $right[ $index ] : 0 );
        if ( $digit < 0 ) {
            $digit += 10;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $result .= (string) $digit;
    }

    $result = ltrim( strrev( $result ), '0' );
    return '' === $result ? '0' : $result;
}

/**
 * Get NicePay amount (integer for KRW)
 *
 * @param float  $amount   Amount
 * @param string $currency Currency code
 * @return string Amount string for NicePay
 */
function nicepay_get_amount( $amount, $currency = '' ) {
    if ( ! $currency ) {
        $currency = get_option( 'nicepay_currency', 'KRW' );
    }

    $normalized = nicepay_normalize_amount( $amount, $currency );
    return $normalized === false ? '' : $normalized;
}

/**
 * Get status label for display
 *
 * @param string $status Status code
 * @return string Display label
 */
function nicepay_get_status_label( $status ) {
    $labels = array(
        'pending'              => __( 'Pending', 'nicepay-payment-gateway' ),
        'paid'                 => __( 'Paid', 'nicepay-payment-gateway' ),
        'failed'               => __( 'Failed', 'nicepay-payment-gateway' ),
        'cancelled'            => __( 'Cancelled', 'nicepay-payment-gateway' ),
        'refunded'             => __( 'Refunded', 'nicepay-payment-gateway' ),
        'waiting'              => __( 'Waiting for Deposit', 'nicepay-payment-gateway' ),
        'approving'            => __( 'Approval in Progress', 'nicepay-payment-gateway' ),
        'partially_refunded'   => __( 'Partially Refunded', 'nicepay-payment-gateway' ),
        'needs_reconciliation' => __( 'Needs Reconciliation', 'nicepay-payment-gateway' ),
        'abandoned'            => __( 'Abandoned', 'nicepay-payment-gateway' ),
        'expired'              => __( 'Expired', 'nicepay-payment-gateway' ),
    );

    return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
}

/**
 * Get card company name by code
 *
 * @param string $code Card code
 * @return string Card company name
 */
function nicepay_get_card_name( $code ) {
    $cards = array(
        '01' => 'BC', '02' => 'KB Kookmin', '03' => 'Hana(KEB)',
        '04' => 'Samsung', '06' => 'Shinhan', '07' => 'Hyundai',
        '08' => 'Lotte', '11' => 'Citi', '12' => 'NH',
        '13' => 'Suhyup', '14' => 'Shinheup', '15' => 'Woori BC',
        '16' => 'Hana', '17' => 'Woori', '21' => 'Gwangju',
        '22' => 'Jeonbuk', '23' => 'Jeju', '37' => 'Kakao Bank',
        '38' => 'K Bank', '44' => 'Toss Bank', '46' => 'Toss Money',
    );

    return isset( $cards[ $code ] ) ? $cards[ $code ] : $code;
}

/**
 * Get bank name by code
 *
 * @param string $code Bank code
 * @return string Bank name
 */
function nicepay_get_bank_name( $code ) {
    $banks = array(
        '002' => 'KDB', '003' => 'IBK', '004' => 'KB Kookmin',
        '007' => 'Suhyup', '011' => 'NH', '020' => 'Woori',
        '023' => 'SC', '027' => 'Citibank', '031' => 'iM Bank(Daegu)',
        '032' => 'Busan', '034' => 'Gwangju', '035' => 'Jeju',
        '037' => 'Jeonbuk', '039' => 'Gyeongnam', '045' => 'Saemaeul',
        '048' => 'Shinheup', '071' => 'Post Office',
        '081' => 'Hana', '088' => 'Shinhan',
        '089' => 'K Bank', '090' => 'Kakao Bank', '092' => 'Toss Bank',
    );

    return isset( $banks[ $code ] ) ? $banks[ $code ] : $code;
}

/**
 * Get default shortcode presets
 *
 * @return array Array of preset shortcode configs
 */
function nicepay_get_default_presets() {
    $now = time();
    return array(
        array(
            'id'           => 'quick-payment',
            'name'         => 'Quick Payment',
            'display_mode' => 'inline',
            'amount'       => '10000',
            'goods_name'   => 'Quick Payment',
            'goods_class'  => '0',
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => 'Pay Now',
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#2563eb',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'preset_version' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array(
            'id'           => 'donation',
            'name'         => 'Donation',
            'display_mode' => 'inline',
            'amount'       => '5000',
            'goods_name'   => 'Donation',
            'goods_class'  => '0',
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => 'Donate',
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#16a34a',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'preset_version' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array(
            'id'           => 'product-purchase',
            'name'         => 'Product Purchase',
            'display_mode' => 'modal',
            'amount'       => '50000',
            'goods_name'   => 'Product Purchase',
            'goods_class'  => '1',
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => 'Buy Now',
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#111827',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'preset_version' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
    );
}

/**
 * Return the canonical and localized labels for a built-in preset.
 *
 * Canonical strings are deliberately stored without translation. WordPress
 * activation can run before the plugin textdomain is loaded, and persisting a
 * translated value would freeze the activation locale in commercial config.
 *
 * @param string $id Preset identifier.
 * @return array|null Preset labels, or null for a non-built-in identifier.
 */
function nicepay_get_preset_labels( $id ) {
    $labels = array(
        'quick-payment' => array(
            'canonical' => array(
                'name'        => 'Quick Payment',
                'goods_name'  => 'Quick Payment',
                'button_text' => 'Pay Now',
            ),
            'localized' => array(
                'name'        => __( 'Quick Payment', 'nicepay-payment-gateway' ),
                'goods_name'  => __( 'Quick Payment', 'nicepay-payment-gateway' ),
                'button_text' => __( 'Pay Now', 'nicepay-payment-gateway' ),
            ),
        ),
        'donation' => array(
            'canonical' => array(
                'name'        => 'Donation',
                'goods_name'  => 'Donation',
                'button_text' => 'Donate',
            ),
            'localized' => array(
                'name'        => __( 'Donation', 'nicepay-payment-gateway' ),
                'goods_name'  => __( 'Donation', 'nicepay-payment-gateway' ),
                'button_text' => __( 'Donate', 'nicepay-payment-gateway' ),
            ),
        ),
        'product-purchase' => array(
            'canonical' => array(
                'name'        => 'Product Purchase',
                'goods_name'  => 'Product Purchase',
                'button_text' => 'Buy Now',
            ),
            'localized' => array(
                'name'        => __( 'Product Purchase', 'nicepay-payment-gateway' ),
                'goods_name'  => __( 'Product Purchase', 'nicepay-payment-gateway' ),
                'button_text' => __( 'Buy Now', 'nicepay-payment-gateway' ),
            ),
        ),
    );

    if ( ! isset( $labels[ $id ] ) ) {
        return null;
    }

    return $labels[ $id ];
}

/**
 * Localize a versioned built-in preset for the current request only.
 *
 * Unversioned legacy presets are intentionally left untouched because older
 * releases retained the preset flag after merchant edits. Treating those rows
 * as pristine could silently overwrite a merchant's saved product details.
 *
 * @param array $shortcode Saved shortcode configuration.
 * @return array Runtime shortcode configuration.
 */
function nicepay_localize_preset( $shortcode ) {
    if ( true !== ( isset( $shortcode['is_preset'] ) ? $shortcode['is_preset'] : false ) ||
        1 !== ( isset( $shortcode['preset_version'] ) ? (int) $shortcode['preset_version'] : 0 ) ) {
        return $shortcode;
    }

    $labels = nicepay_get_preset_labels( isset( $shortcode['id'] ) ? $shortcode['id'] : '' );
    if ( null === $labels ) {
        return $shortcode;
    }

    return array_merge( $shortcode, $labels['localized'] );
}

/**
 * Convert runtime-localized presets back to canonical storage values.
 *
 * @param array $shortcodes Shortcode configurations.
 * @return array Storage-safe shortcode configurations.
 */
function nicepay_prepare_shortcodes_for_storage( $shortcodes ) {
    foreach ( $shortcodes as &$shortcode ) {
        if ( ! is_array( $shortcode ) ||
            true !== ( isset( $shortcode['is_preset'] ) ? $shortcode['is_preset'] : false ) ||
            1 !== ( isset( $shortcode['preset_version'] ) ? (int) $shortcode['preset_version'] : 0 ) ) {
            continue;
        }

        $labels = nicepay_get_preset_labels( isset( $shortcode['id'] ) ? $shortcode['id'] : '' );
        if ( null !== $labels ) {
            $shortcode = array_merge( $shortcode, $labels['canonical'] );
        }
    }
    unset( $shortcode );

    return $shortcodes;
}

/**
 * Get a saved shortcode config by ID
 *
 * @param string $id Shortcode ID (slug)
 * @return array|null Shortcode config or null
 */
function nicepay_get_saved_shortcode( $id ) {
    $shortcodes = nicepay_get_all_shortcodes();
    foreach ( $shortcodes as $sc ) {
        if ( isset( $sc['id'] ) && $sc['id'] === $id ) {
            return $sc;
        }
    }
    return null;
}

/**
 * Get all saved shortcodes, seeding defaults if empty
 *
 * @return array
 */
function nicepay_get_all_shortcodes() {
    $shortcodes = get_option( 'nicepay_saved_shortcodes', null );
    if ( $shortcodes === null ) {
        $shortcodes = nicepay_get_default_presets();
        update_option( 'nicepay_saved_shortcodes', $shortcodes );
    }

    if ( ! is_array( $shortcodes ) ) {
        nicepay_log( 'Invalid saved shortcode option; ignoring malformed value.' );
        return array();
    }

    $shortcodes = array_values(
        array_filter(
            $shortcodes,
            static function ( $shortcode ) {
                return is_array( $shortcode ) &&
                    isset( $shortcode['id'] ) &&
                    is_string( $shortcode['id'] ) &&
                    strlen( $shortcode['id'] ) <= 64 &&
                    1 === preg_match( '/^[A-Za-z0-9_-]+$/', $shortcode['id'] );
            }
        )
    );

    return array_map( 'nicepay_localize_preset', $shortcodes );
}
