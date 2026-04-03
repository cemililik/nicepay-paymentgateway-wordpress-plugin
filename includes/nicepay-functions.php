<?php
/**
 * NicePay Helper Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Log NicePay events for debugging
 *
 * @param string $message Log message
 * @param mixed  $data    Optional data to log
 */
function nicepay_log( $message, $data = null ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }

    $log_entry = '[NicePay] ' . $message;
    if ( $data !== null ) {
        if ( is_array( $data ) || is_object( $data ) ) {
            $log_entry .= ' | ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        } else {
            $log_entry .= ' | ' . $data;
        }
    }

    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->debug( $log_entry, array( 'source' => 'nicepay' ) );
    } else {
        error_log( $log_entry );
    }
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
        'tid'             => '',
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
        'card_no'         => '',
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

    $result = $wpdb->insert( $table, $data );

    if ( $result === false ) {
        nicepay_log( 'Failed to save transaction', $wpdb->last_error );
        return false;
    }

    return $wpdb->insert_id;
}

/**
 * Update transaction in database
 *
 * @param int   $id   Transaction ID
 * @param array $data Data to update
 * @return bool Success
 */
function nicepay_update_transaction( $id, $data ) {
    global $wpdb;

    $table = $wpdb->prefix . 'nicepay_transactions';

    if ( isset( $data['payment_data'] ) && is_array( $data['payment_data'] ) ) {
        $data['payment_data'] = wp_json_encode( $data['payment_data'], JSON_UNESCAPED_UNICODE );
    }

    $result = $wpdb->update( $table, $data, array( 'id' => $id ) );

    return $result !== false;
}

/**
 * Get transaction by TID
 *
 * @param string $tid Transaction ID
 * @return object|null Transaction row
 */
function nicepay_get_transaction_by_tid( $tid ) {
    global $wpdb;
    $table = $wpdb->prefix . 'nicepay_transactions';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tid = %s", $tid ) );
}

/**
 * Get transaction by Moid
 *
 * @param string $moid Merchant Order ID
 * @return object|null Transaction row
 */
function nicepay_get_transaction_by_moid( $moid ) {
    global $wpdb;
    $table = $wpdb->prefix . 'nicepay_transactions';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE moid = %s", $moid ) );
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
    $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
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
        return number_format( (int) $amount ) . ' ' . $currency;
    }

    return number_format( (float) $amount, 2 ) . ' ' . $currency;
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

    if ( $currency === 'KRW' ) {
        return (string) (int) $amount;
    }

    return number_format( (float) $amount, 2, '.', '' );
}

/**
 * Get status label for display
 *
 * @param string $status Status code
 * @return string Display label
 */
function nicepay_get_status_label( $status ) {
    $labels = array(
        'pending'   => __( 'Pending', 'nicepay-payment-gateway' ),
        'paid'      => __( 'Paid', 'nicepay-payment-gateway' ),
        'failed'    => __( 'Failed', 'nicepay-payment-gateway' ),
        'cancelled' => __( 'Cancelled', 'nicepay-payment-gateway' ),
        'refunded'  => __( 'Refunded', 'nicepay-payment-gateway' ),
        'waiting'   => __( 'Waiting for Deposit', 'nicepay-payment-gateway' ),
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
            'name'         => __( 'Quick Payment', 'nicepay-payment-gateway' ),
            'display_mode' => 'inline',
            'amount'       => '10000',
            'goods_name'   => __( 'Quick Payment', 'nicepay-payment-gateway' ),
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => __( 'Pay Now', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#2563eb',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array(
            'id'           => 'donation',
            'name'         => __( 'Donation', 'nicepay-payment-gateway' ),
            'display_mode' => 'inline',
            'amount'       => '5000',
            'goods_name'   => __( 'Donation', 'nicepay-payment-gateway' ),
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => __( 'Donate', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#16a34a',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array(
            'id'           => 'product-purchase',
            'name'         => __( 'Product Purchase', 'nicepay-payment-gateway' ),
            'display_mode' => 'modal',
            'amount'       => '50000',
            'goods_name'   => __( 'Product Purchase', 'nicepay-payment-gateway' ),
            'pay_method'   => '',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => __( 'Buy Now', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#111827',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array(
            'id'           => 'subscription',
            'name'         => __( 'Subscription', 'nicepay-payment-gateway' ),
            'display_mode' => 'modal',
            'amount'       => '29900',
            'goods_name'   => __( 'Monthly Subscription', 'nicepay-payment-gateway' ),
            'pay_method'   => 'CARD',
            'buyer_name'   => '',
            'buyer_email'  => '',
            'buyer_tel'    => '',
            'button_text'  => __( 'Subscribe', 'nicepay-payment-gateway' ),
            'button_class' => 'nicepay-pay-button',
            'button_color' => '#9333ea',
            'currency'     => 'KRW',
            'language'     => '',
            'is_preset'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
    );
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
    return $shortcodes;
}
