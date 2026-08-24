<?php
/**
 * NicePay Transactions Admin Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Transactions {

    const CSV_BATCH_SIZE = 250;
    const CSV_MAX_ROWS   = 10000;

    public function __construct() {
        add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );
        add_action( 'admin_post_nicepay_export_transactions', array( $this, 'handle_csv_export' ) );
    }

    /**
     * Validate and canonicalize transaction filters shared by list, totals and CSV.
     *
     * @param array $source Request-like input.
     * @return array{valid:bool,filters:array<string,string>}
     */
    public static function parse_filters( $source ) {
        $source = is_array( $source ) ? $source : array();
        $valid  = true;
        $filters = array(
            'status'         => '',
            'payment_method' => '',
            'date_from'      => '',
            'date_to'        => '',
            'search'         => '',
        );

        $status = self::request_scalar( $source, 'filter_status', $valid );
        if ( '' !== $status ) {
            $status = sanitize_text_field( $status );
            if ( in_array( $status, self::allowed_statuses(), true ) ) {
                $filters['status'] = $status;
            } else {
                $valid = false;
            }
        }

        $method = strtoupper( self::request_scalar( $source, 'filter_method', $valid ) );
        if ( '' !== $method ) {
            if ( in_array( $method, array_keys( NicePay_API::get_available_methods() ), true ) ) {
                $filters['payment_method'] = $method;
            } else {
                $valid = false;
            }
        }

        foreach ( array( 'date_from', 'date_to' ) as $date_key ) {
            $date = self::request_scalar( $source, $date_key, $valid );
            if ( '' !== $date ) {
                if ( self::is_valid_date( $date ) ) {
                    $filters[ $date_key ] = $date;
                } else {
                    $valid = false;
                }
            }
        }

        if ( '' !== $filters['date_from'] && '' !== $filters['date_to'] && $filters['date_from'] > $filters['date_to'] ) {
            $valid = false;
        }

        $search = self::request_scalar( $source, 's', $valid );
        if ( '' !== $search ) {
            $filters['search'] = nicepay_utf8_byte_cut( sanitize_text_field( $search ), 100 );
        }

        return array(
            'valid'   => $valid,
            'filters' => $filters,
        );
    }

    /**
     * Return aggregate captured/refunded/remaining balances grouped by currency.
     *
     * @param array<string,string> $filters Canonical filters from parse_filters().
     * @return array<int,object>
     */
    public static function get_financial_summary( $filters ) {
        global $wpdb;

        $where = self::build_where_clause( $filters );
        $table = $wpdb->prefix . 'nicepay_transactions';
        $sql   = "SELECT currency, COUNT(*) AS transaction_count,
                         COALESCE(SUM(captured_amount), 0) AS total_amount,
                         COALESCE(SUM(refunded_amount), 0) AS refunded_amount,
                         COALESCE(SUM(remaining_amount), 0) AS remaining_amount
                  FROM {$table}
                  WHERE {$where['sql']}
                  GROUP BY currency
                  ORDER BY currency ASC";

        if ( ! empty( $where['values'] ) ) {
            $sql = $wpdb->prepare( $sql, $where['values'] );
        }

        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Stream a bounded, allowlisted CSV export to an already-open resource.
     *
     * @param resource             $stream     Writable stream.
     * @param array<string,string> $filters    Canonical filters.
     * @param int                  $max_rows   Test seam; capped at CSV_MAX_ROWS.
     * @param int                  $batch_size Test seam; capped at CSV_BATCH_SIZE.
     * @return int Number of exported data rows.
     */
    public static function write_csv_export( $stream, $filters, $max_rows = self::CSV_MAX_ROWS, $batch_size = self::CSV_BATCH_SIZE ) {
        global $wpdb;

        if ( ! is_resource( $stream ) ) {
            return 0;
        }

        $max_rows   = max( 1, min( self::CSV_MAX_ROWS, absint( $max_rows ) ) );
        $batch_size = max( 1, min( self::CSV_BATCH_SIZE, absint( $batch_size ) ) );
        $where      = self::build_where_clause( $filters );
        $table      = $wpdb->prefix . 'nicepay_transactions';
        $columns    = array(
            'id', 'tid', 'wc_order_id', 'moid', 'flow', 'currency', 'amount',
            'captured_amount', 'refunded_amount', 'remaining_amount',
            'payment_method', 'status', 'result_code', 'mode', 'created_at', 'approved_at',
        );
        $headers = array(
            'ID', 'TID', 'WooCommerce Order ID', 'Merchant Order ID', 'Flow', 'Currency',
            'Requested Amount', 'Captured Amount', 'Refunded Amount', 'Remaining Amount',
            'Payment Method', 'Status', 'Provider Result Code', 'Environment', 'Created At', 'Approved At',
        );
        fputcsv( $stream, $headers );

        $exported      = 0;
        $scanned       = 0;
        $cursor_date   = '';
        $cursor_id     = 0;
        while ( $scanned < $max_rows ) {
            $limit  = min( $batch_size, $max_rows - $scanned );
            $values = $where['values'];
            $cursor_sql = '';
            if ( '' !== $cursor_date && $cursor_id > 0 ) {
                $cursor_sql = ' AND (created_at < %s OR (created_at = %s AND id < %d))';
                $values[] = $cursor_date;
                $values[] = $cursor_date;
                $values[] = $cursor_id;
            }
            $values[] = $limit;
            $sql = 'SELECT ' . implode( ', ', $columns ) . " FROM {$table}
                    WHERE {$where['sql']}{$cursor_sql}
                    ORDER BY created_at DESC, id DESC
                    LIMIT %d";
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), 'ARRAY_A' );
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                break;
            }

            $rows = array_slice( $rows, 0, $limit );
            $scanned += count( $rows );
            foreach ( $rows as $row ) {
                $row = is_object( $row ) ? get_object_vars( $row ) : $row;
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $csv_row = array();
                foreach ( $columns as $column ) {
                    $value = isset( $row[ $column ] ) ? $row[ $column ] : '';
                    if ( 'result_code' === $column && is_scalar( $value ) && '' !== (string) $value ) {
                        $value = self::sanitize_result_code( $value );
                    } elseif ( 'result_code' === $column && ! is_scalar( $value ) ) {
                        $value = '[redacted]';
                    } elseif ( 'mode' === $column && ! in_array( $value, array( 'test', 'live', '' ), true ) ) {
                        $value = '[redacted]';
                    }
                    $csv_row[] = self::sanitize_csv_cell( $value );
                }
                fputcsv( $stream, $csv_row );
                $exported++;
            }

            fflush( $stream );
            $last_row = end( $rows );
            $last_row = is_object( $last_row ) ? get_object_vars( $last_row ) : $last_row;
            if ( ! is_array( $last_row ) || empty( $last_row['created_at'] ) || empty( $last_row['id'] ) ||
                ! is_scalar( $last_row['created_at'] ) || ! is_scalar( $last_row['id'] ) ) {
                break;
            }
            $cursor_date = (string) $last_row['created_at'];
            $cursor_id   = absint( $last_row['id'] );
            if ( $cursor_id < 1 ) {
                break;
            }

            if ( count( $rows ) < $limit ) {
                break;
            }
        }

        return $exported;
    }

    /**
     * Prevent spreadsheet formula execution while preserving a readable value.
     *
     * @param mixed $value CSV cell value.
     * @return string
     */
    public static function sanitize_csv_cell( $value ) {
        if ( ! is_scalar( $value ) && null !== $value ) {
            return '';
        }

        $value = str_replace( array( "\r", "\n" ), ' ', (string) $value );
        if ( preg_match( '/^[\p{Z}\x00-\x20]*[=+\-@]/u', $value ) ) {
            $value = "'" . $value;
        }
        return nicepay_utf8_byte_cut( $value, 500 );
    }

    /**
     * Handle the authenticated transaction CSV download.
     */
    public function handle_csv_export() {
        if ( ! nicepay_current_user_can_manage_payments() ) {
            wp_die( esc_html__( 'You are not allowed to export NicePay transactions.', 'nicepay-payment-gateway' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( 'nicepay_export_transactions' );

        if ( ! NicePay_Installer::is_current() ) {
            wp_die( esc_html__( 'NicePay transaction storage is not ready.', 'nicepay-payment-gateway' ), '', array( 'response' => 503 ) );
        }

        $filter_state = self::parse_filters( $_GET );
        if ( ! $filter_state['valid'] ) {
            wp_die( esc_html__( 'The transaction export filters are invalid.', 'nicepay-payment-gateway' ), '', array( 'response' => 400 ) );
        }

        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="nicepay-transactions-' . gmdate( 'Y-m-d-His' ) . '.csv"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-NicePay-Export-Row-Limit: ' . self::CSV_MAX_ROWS );

        $stream = fopen( 'php://output', 'w' );
        if ( false === $stream ) {
            wp_die( esc_html__( 'The CSV output stream could not be opened.', 'nicepay-payment-gateway' ), '', array( 'response' => 500 ) );
        }

        self::write_csv_export( $stream, $filter_state['filters'] );
        fclose( $stream );
        exit;
    }

    /** @return string[] */
    private static function allowed_statuses() {
        return array( 'pending', 'approving', 'paid', 'failed', 'partially_refunded', 'refunded', 'cancelled', 'needs_reconciliation', 'abandoned', 'expired', 'waiting' );
    }

    /**
     * Extract a scalar request value while retaining validation state.
     *
     * @param array  $source Input array.
     * @param string $key    Input key.
     * @param bool   $valid  Validation accumulator.
     * @return string
     */
    private static function request_scalar( $source, $key, &$valid ) {
        if ( ! isset( $source[ $key ] ) ) {
            return '';
        }
        if ( ! is_scalar( $source[ $key ] ) ) {
            $valid = false;
            return '';
        }
        return trim( (string) wp_unslash( $source[ $key ] ) );
    }

    /** @return bool */
    private static function is_valid_date( $date ) {
        if ( ! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', (string) $date, $parts ) ) {
            return false;
        }
        return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
    }

    /**
     * Reproduce nicepay_get_transactions() WHERE semantics for aggregate/export queries.
     *
     * @param array<string,string> $filters Canonical filters.
     * @return array{sql:string,values:array<int,string>}
     */
    private static function build_where_clause( $filters ) {
        global $wpdb;

        $filters = is_array( $filters ) ? $filters : array();
        $where   = array( '1=1' );
        $values  = array();

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $filters['status'];
        }
        if ( ! empty( $filters['payment_method'] ) ) {
            $where[]  = 'payment_method = %s';
            $values[] = $filters['payment_method'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }
        if ( ! empty( $filters['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $where[]  = '(tid LIKE %s OR moid LIKE %s OR buyer_name LIKE %s OR goods_name LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        return array(
            'sql'    => implode( ' AND ', $where ),
            'values' => $values,
        );
    }

    /**
     * Build the privacy-safe fields displayed in an administrative transaction detail.
     *
     * The allowlist is intentionally narrow. Authentication tokens, raw payment
     * payloads, buyer contact fields and account/card numbers are never read here.
     *
     * @param object $transaction Persisted transaction row.
     * @return array<int,array{label:string,value:string}>
     */
    public static function get_safe_detail_fields( $transaction ) {
        if ( ! is_object( $transaction ) ) {
            return array();
        }

        $fields = array();

        if ( isset( $transaction->result_code ) && is_scalar( $transaction->result_code ) && '' !== (string) $transaction->result_code ) {
            $fields[] = array(
                'label' => __( 'Provider result code', 'nicepay-payment-gateway' ),
                'value' => self::sanitize_result_code( $transaction->result_code ),
            );
        }

        if ( isset( $transaction->result_msg ) && is_scalar( $transaction->result_msg ) && '' !== (string) $transaction->result_msg ) {
            $fields[] = array(
                'label' => __( 'Provider result message', 'nicepay-payment-gateway' ),
                'value' => self::redact_sensitive_text( $transaction->result_msg, 500 ),
            );
        }

        $mode = isset( $transaction->mode ) && is_scalar( $transaction->mode )
            ? strtolower( (string) $transaction->mode )
            : '';
        if ( in_array( $mode, array( 'test', 'live' ), true ) ) {
            $fields[] = array(
                'label' => __( 'Environment', 'nicepay-payment-gateway' ),
                'value' => 'test' === $mode
                    ? __( 'Test', 'nicepay-payment-gateway' )
                    : __( 'Live', 'nicepay-payment-gateway' ),
            );
        }

        $instrument = self::get_safe_instrument_summary( $transaction );
        if ( '' !== $instrument ) {
            $fields[] = array(
                'label' => __( 'Payment instrument', 'nicepay-payment-gateway' ),
                'value' => $instrument,
            );
        }

        return $fields;
    }

    /**
     * Reduce an instrument to non-sensitive provider metadata.
     *
     * @param object $transaction Persisted transaction row.
     * @return string
     */
    private static function get_safe_instrument_summary( $transaction ) {
        $method = isset( $transaction->payment_method ) && is_scalar( $transaction->payment_method )
            ? strtoupper( (string) $transaction->payment_method )
            : '';
        $method = in_array( $method, array( 'CARD', 'BANK', 'VBANK', 'CELLPHONE', 'SSG_BANK', 'GIFT_CULT' ), true )
            ? $method
            : '';

        $stored_name = isset( $transaction->pay_method_name )
            ? self::redact_sensitive_text( $transaction->pay_method_name, 50 )
            : '';
        $summary = '' !== $stored_name ? $stored_name : $method;

        if ( 'CARD' === $method ) {
            $summary = '' !== $summary ? $summary : __( 'Card', 'nicepay-payment-gateway' );
            $issuer  = isset( $transaction->card_name )
                ? self::redact_sensitive_text( $transaction->card_name, 50 )
                : '';
            $code    = isset( $transaction->card_code )
                ? self::sanitize_instrument_code( $transaction->card_code )
                : '';
            $quota   = isset( $transaction->card_quota ) && is_scalar( $transaction->card_quota ) && preg_match( '/^\d{1,3}$/', (string) $transaction->card_quota )
                ? (string) $transaction->card_quota
                : '';

            if ( '' !== $issuer ) {
                $summary .= ' · ' . $issuer;
            }
            if ( '' !== $code ) {
                $summary .= ' [' . $code . ']';
            }
            if ( '' !== $quota ) {
                $summary .= ' · ' . sprintf(
                    /* translators: %s: stored card installment quota */
                    __( 'installment %s', 'nicepay-payment-gateway' ),
                    $quota
                );
            }
        } elseif ( 'BANK' === $method ) {
            $summary = '' !== $summary ? $summary : __( 'Bank transfer', 'nicepay-payment-gateway' );
            $bank    = isset( $transaction->bank_name )
                ? self::redact_sensitive_text( $transaction->bank_name, 50 )
                : '';
            $code    = isset( $transaction->bank_code )
                ? self::sanitize_instrument_code( $transaction->bank_code )
                : '';

            if ( '' !== $bank ) {
                $summary .= ' · ' . $bank;
            }
            if ( '' !== $code ) {
                $summary .= ' [' . $code . ']';
            }
        }

        return self::redact_sensitive_text( $summary, 180 );
    }

    /**
     * Keep a provider/institution code within a printable non-sensitive alphabet.
     *
     * @param mixed $value Stored code.
     * @return string
     */
    private static function sanitize_instrument_code( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = strtoupper( trim( (string) $value ) );
        return preg_match( '/^[A-Z0-9_\-]{1,10}$/', $value ) ? $value : '';
    }

    /**
     * Sanitize the provider status code without echoing attacker-controlled text.
     *
     * @param mixed $value Stored result code.
     * @return string
     */
    private static function sanitize_result_code( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '[redacted]';
        }

        $value = self::redact_sensitive_text( $value, 64 );
        return preg_match( '/^[A-Za-z0-9._:\-]{1,64}$/', $value ) ? $value : '[redacted]';
    }

    /**
     * Remove common PAN, contact-data and credential shapes from display text.
     *
     * This is defense in depth for provider-owned labels/messages. The caller
     * must still escape the returned value at the HTML output boundary.
     *
     * @param mixed $value     Stored display text.
     * @param int   $max_bytes Maximum UTF-8 byte length.
     * @return string
     */
    private static function redact_sensitive_text( $value, $max_bytes ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $text = sanitize_text_field( (string) $value );
        $text = preg_replace( '/\s+/u', ' ', $text );
        if ( null === $text ) {
            return '';
        }

        $patterns = array(
            '/\b(?:auth_?token|signature|sign_?data|merchant_?key|card_?(?:no|number)|pan|buyer_?(?:email|tel)|account_?(?:no|number)|vbank_?num)\b\s*[:=]\s*[^\s,;]+/iu',
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',
            '/(?<!\d)(?:\d[ .\-]?){9,19}(?!\d)/u',
            '/\b(?:[A-F0-9]{32,}|[A-Za-z0-9+\/_\-]{40,}={0,2})\b/i',
        );

        $text = preg_replace( $patterns, '[redacted]', $text );
        if ( null === $text ) {
            return '';
        }

        return nicepay_utf8_byte_cut( trim( $text ), max( 1, (int) $max_bytes ) );
    }

    public function render() {
        if ( ! nicepay_current_user_can_manage_payments() ) {
            return;
        }

        if ( ! NicePay_Installer::is_current() ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'NicePay transaction storage is not ready. Resolve the database migration error before using this screen.', 'nicepay-payment-gateway' ) . '</p></div>';
            return;
        }

        $per_page     = 20;
        $current_page = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] )
            ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) )
            : 1;
        $filter_state = self::parse_filters( $_GET );
        $args = array_merge(
            $filter_state['filters'],
            array(
                'per_page' => $per_page,
                'page'     => $current_page,
            )
        );

        $result = $filter_state['valid']
            ? nicepay_get_transactions( $args )
            : array( 'items' => array(), 'total' => 0 );
        $items      = $result['items'];
        $total      = $result['total'];
        $total_pages = ceil( $total / $per_page );
        $financial_summary = $filter_state['valid']
            ? self::get_financial_summary( $filter_state['filters'] )
            : array();
        $reconciliation_count = nicepay_get_reconciliation_count();
        $refund_attempts = nicepay_get_refund_attempts_for_transactions( array_map( function ( $item ) {
            return isset( $item->id ) ? $item->id : 0;
        }, $items ) );
        $selected_transaction_id = isset( $_GET['transaction_id'] ) && is_scalar( $_GET['transaction_id'] )
            ? absint( wp_unslash( $_GET['transaction_id'] ) )
            : 0;
        $selected_transaction    = $selected_transaction_id > 0
            ? nicepay_get_transaction( $selected_transaction_id )
            : null;
        $selected_detail_fields  = self::get_safe_detail_fields( $selected_transaction );
        $export_args = array( 'action' => 'nicepay_export_transactions' );
        if ( '' !== $args['status'] ) {
            $export_args['filter_status'] = $args['status'];
        }
        if ( '' !== $args['payment_method'] ) {
            $export_args['filter_method'] = $args['payment_method'];
        }
        if ( '' !== $args['date_from'] ) {
            $export_args['date_from'] = $args['date_from'];
        }
        if ( '' !== $args['date_to'] ) {
            $export_args['date_to'] = $args['date_to'];
        }
        if ( '' !== $args['search'] ) {
            $export_args['s'] = $args['search'];
        }
        $export_url = wp_nonce_url(
            add_query_arg( $export_args, admin_url( 'admin-post.php' ) ),
            'nicepay_export_transactions'
        );
        ?>
        <div class="wrap nicepay-admin">
            <h1><?php esc_html_e( 'NicePay Transactions', 'nicepay-payment-gateway' ); ?></h1>

            <?php if ( $selected_transaction_id > 0 && ! $selected_transaction ) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e( 'The requested transaction detail is unavailable.', 'nicepay-payment-gateway' ); ?></p>
                </div>
            <?php elseif ( $selected_transaction ) : ?>
                <section class="card" aria-labelledby="nicepay-transaction-detail-title">
                    <h2 id="nicepay-transaction-detail-title">
                        <?php
                        printf(
                            /* translators: %d: internal NicePay transaction row ID */
                            esc_html__( 'Transaction details #%d', 'nicepay-payment-gateway' ),
                            (int) $selected_transaction->id
                        );
                        ?>
                    </h2>
                    <?php if ( ! empty( $selected_detail_fields ) ) : ?>
                        <dl>
                            <?php foreach ( $selected_detail_fields as $detail ) : ?>
                                <dt><strong><?php echo esc_html( $detail['label'] ); ?></strong></dt>
                                <dd><?php echo esc_html( $detail['value'] ); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No safe provider diagnostics were recorded for this transaction.', 'nicepay-payment-gateway' ); ?></p>
                    <?php endif; ?>
                    <p>
                        <a href="<?php echo esc_url( add_query_arg( 'page', 'nicepay-transactions', admin_url( 'admin.php' ) ) ); ?>">
                            <?php esc_html_e( 'Close details', 'nicepay-payment-gateway' ); ?>
                        </a>
                    </p>
                </section>
            <?php endif; ?>

            <?php if ( $reconciliation_count > 0 ) : ?>
                <div class="notice notice-error inline">
                    <p>
                        <?php
                        printf(
                            /* translators: %d: number of transactions requiring manual reconciliation */
                            esc_html( _n( '%d transaction requires manual reconciliation before retrying any payment or refund.', '%d transactions require manual reconciliation before retrying any payment or refund.', $reconciliation_count, 'nicepay-payment-gateway' ) ),
                            (int) $reconciliation_count
                        );
                        ?>
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'nicepay-transactions', 'filter_status' => 'needs_reconciliation' ), admin_url( 'admin.php' ) ) ); ?>">
                            <?php esc_html_e( 'Review now', 'nicepay-payment-gateway' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! $filter_state['valid'] ) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e( 'One or more transaction filters are invalid. No transactions or totals were loaded.', 'nicepay-payment-gateway' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" class="nicepay-filters">
                <input type="hidden" name="page" value="nicepay-transactions">

                <select name="filter_status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'nicepay-payment-gateway' ); ?></option>
                    <?php foreach ( self::allowed_statuses() as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $args['status'], $s ); ?>>
                            <?php echo esc_html( nicepay_get_status_label( $s ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="filter_method">
                    <option value=""><?php esc_html_e( 'All Methods', 'nicepay-payment-gateway' ); ?></option>
                    <?php foreach ( NicePay_API::get_available_methods() as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $args['payment_method'], $code ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>" placeholder="<?php esc_attr_e( 'From', 'nicepay-payment-gateway' ); ?>">
                <input type="date" name="date_to" value="<?php echo esc_attr( $args['date_to'] ); ?>" placeholder="<?php esc_attr_e( 'To', 'nicepay-payment-gateway' ); ?>">
                <input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'nicepay-payment-gateway' ); ?>">

                <?php submit_button( __( 'Filter', 'nicepay-payment-gateway' ), 'secondary', 'filter', false ); ?>
                <?php if ( $filter_state['valid'] ) : ?>
                    <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
                        <?php esc_html_e( 'Export filtered CSV', 'nicepay-payment-gateway' ); ?>
                    </a>
                    <span class="description">
                        <?php
                        printf(
                            /* translators: %s: maximum number of rows in one CSV export */
                            esc_html__( 'Exports up to %s matching rows.', 'nicepay-payment-gateway' ),
                            esc_html( number_format_i18n( self::CSV_MAX_ROWS ) )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </form>

            <p class="nicepay-total-count">
                <?php
                printf(
                    esc_html(
                        /* translators: %d: total number of transactions */
                        _n( 'Total: %d transaction', 'Total: %d transactions', $total, 'nicepay-payment-gateway' )
                    ),
                    $total
                );
                ?>
            </p>

            <?php if ( ! empty( $financial_summary ) ) : ?>
                <table class="widefat striped">
                    <caption class="screen-reader-text"><?php esc_html_e( 'Filtered NicePay financial totals by currency', 'nicepay-payment-gateway' ); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Transactions', 'nicepay-payment-gateway' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Captured total', 'nicepay-payment-gateway' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Refunded total', 'nicepay-payment-gateway' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Remaining total', 'nicepay-payment-gateway' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $financial_summary as $summary ) : ?>
                            <?php
                            $summary_currency = isset( $summary->currency ) && is_scalar( $summary->currency )
                                ? strtoupper( (string) $summary->currency )
                                : '';
                            $summary_currency = preg_match( '/^[A-Z]{3}$/', $summary_currency ) ? $summary_currency : '';
                            $format_currency  = '' !== $summary_currency ? $summary_currency : '---';
                            ?>
                            <tr>
                                <td><?php echo esc_html( '' !== $summary_currency ? $summary_currency : __( 'Not recorded', 'nicepay-payment-gateway' ) ); ?></td>
                                <td><?php echo esc_html( (int) $summary->transaction_count ); ?></td>
                                <td><?php echo esc_html( nicepay_format_amount( $summary->total_amount, $format_currency ) ); ?></td>
                                <td><?php echo esc_html( nicepay_format_amount( $summary->refunded_amount, $format_currency ) ); ?></td>
                                <td><?php echo esc_html( nicepay_format_amount( $summary->remaining_amount, $format_currency ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <caption class="screen-reader-text"><?php esc_html_e( 'NicePay transaction ledger', 'nicepay-payment-gateway' ); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'ID', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'TID', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Order', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Balance', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Method', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Status', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Buyer', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Date', 'nicepay-payment-gateway' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Actions', 'nicepay-payment-gateway' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr>
                            <td colspan="9" class="nicepay-empty-state">
                                <div class="nicepay-empty-state-inner">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M9 12h6M9 16h6M17 21H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="nicepay-empty-title"><?php esc_html_e( 'No transactions found', 'nicepay-payment-gateway' ); ?></p>
                                    <p class="nicepay-empty-desc"><?php esc_html_e( 'Transactions will appear here once payments are made.', 'nicepay-payment-gateway' ); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $items as $item ) : ?>
                            <?php
                            $order = ! empty( $item->wc_order_id ) && function_exists( 'wc_get_order' )
                                ? wc_get_order( $item->wc_order_id )
                                : null;
                            $order_edit_url = $order && method_exists( $order, 'get_edit_order_url' )
                                ? $order->get_edit_order_url()
                                : ( $order ? admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) : '' );
                            $order_number = $order && method_exists( $order, 'get_order_number' )
                                ? $order->get_order_number()
                                : ( $order ? $order->get_id() : '' );
                            $created_at = function_exists( 'get_date_from_gmt' )
                                ? get_date_from_gmt( $item->created_at, 'Y-m-d H:i:s' )
                                : $item->created_at;
                            $currency = ! empty( $item->currency ) ? $item->currency : 'KRW';
                            $captured = ! empty( $item->captured_amount ) ? $item->captured_amount : $item->amount;
                            $refunded = isset( $item->refunded_amount ) ? $item->refunded_amount : 0;
                            $remaining = isset( $item->remaining_amount ) ? $item->remaining_amount : $item->amount;
                            $requires_reconciliation = 'needs_reconciliation' === (string) $item->status ||
                                ( isset( $item->reconciliation_status ) && 'required' === (string) $item->reconciliation_status );
                            $can_refund = $order && ! empty( $item->tid ) && ! $requires_reconciliation &&
                                in_array( (string) $item->status, array( 'paid', 'partially_refunded' ), true ) &&
                                ( ! isset( $item->cancel_status ) || ! in_array( (string) $item->cancel_status, array( 'requested', 'unknown' ), true ) ) &&
                                '0' !== nicepay_normalize_ledger_amount( $remaining );
                            $item_refunds = isset( $refund_attempts[ (int) $item->id ] )
                                ? $refund_attempts[ (int) $item->id ]
                                : array();
                            $copy_tid_label = ! empty( $item->tid )
                                ? sprintf(
                                    /* translators: %s: NicePay transaction ID */
                                    __( 'Copy TID %s', 'nicepay-payment-gateway' ),
                                    $item->tid
                                )
                                : '';
                            $cancel_transaction_label = $can_refund
                                ? sprintf(
                                    /* translators: %s: NicePay transaction ID */
                                    __( 'Cancel transaction %s', 'nicepay-payment-gateway' ),
                                    $item->tid
                                )
                                : '';
                            $view_details_label = sprintf(
                                /* translators: %d: internal NicePay transaction row ID */
                                __( 'View details for transaction %d', 'nicepay-payment-gateway' ),
                                (int) $item->id
                            );
                            $view_details_url = add_query_arg(
                                array(
                                    'page'           => 'nicepay-transactions',
                                    'transaction_id' => (int) $item->id,
                                ),
                                admin_url( 'admin.php' )
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $item->id ); ?></td>
                                <td>
                                    <?php if ( $item->tid ) : ?>
                                    <div class="nicepay-tid-cell">
                                        <code class="nicepay-tid"><?php echo esc_html( $item->tid ); ?></code>
                                        <button type="button" class="nicepay-copy-btn" data-copy="<?php echo esc_attr( $item->tid ); ?>"
                                                aria-label="<?php echo esc_attr( $copy_tid_label ); ?>">
                                            <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                        </button>
                                    </div>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $order ) : ?>
                                        <a href="<?php echo esc_url( $order_edit_url ); ?>">
                                            #<?php echo esc_html( $order_number ); ?>
                                        </a>
                                    <?php elseif ( $item->wc_order_id ) : ?>
                                        <?php
                                        printf(
                                            /* translators: %d: deleted or unavailable WooCommerce order ID */
                                            esc_html__( 'Order #%d unavailable', 'nicepay-payment-gateway' ),
                                            (int) $item->wc_order_id
                                        );
                                        ?>
                                    <?php else : ?>
                                        <?php echo esc_html( $item->moid ); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="nicepay-amount">
                                    <strong><?php echo esc_html( nicepay_format_amount( $captured, $currency ) ); ?></strong><br>
                                    <small>
                                        <?php
                                        printf(
                                            /* translators: %1$s: refunded amount, %2$s: remaining amount */
                                            esc_html__( 'Refunded: %1$s · Remaining: %2$s', 'nicepay-payment-gateway' ),
                                            esc_html( nicepay_format_amount( $refunded, $currency ) ),
                                            esc_html( nicepay_format_amount( $remaining, $currency ) )
                                        );
                                        ?>
                                    </small>
                                    <?php if ( ! empty( $item_refunds ) ) : ?>
                                        <details>
                                            <summary>
                                                <?php
                                                printf(
                                                    esc_html(
                                                        /* translators: %d: number of refund attempts */
                                                        _n( '%d refund attempt', '%d refund attempts', count( $item_refunds ), 'nicepay-payment-gateway' )
                                                    ),
                                                    count( $item_refunds )
                                                );
                                                ?>
                                            </summary>
                                            <ul>
                                                <?php foreach ( $item_refunds as $attempt ) : ?>
                                                    <li>
                                                        <?php
                                                        printf(
                                                            /* translators: %1$s: refund status, %2$s: amount, %3$s: result code */
                                                            esc_html__( '%1$s — %2$s — code %3$s', 'nicepay-payment-gateway' ),
                                                            esc_html( (string) $attempt->status ),
                                                            esc_html( nicepay_format_amount( $attempt->requested_amount, $attempt->currency ) ),
                                                            esc_html( $attempt->result_code ? $attempt->result_code : '—' )
                                                        );
                                                        ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $item->pay_method_name ?: $item->payment_method ); ?></td>
                                <td>
                                    <span class="nicepay-status nicepay-status-<?php echo esc_attr( $item->status ); ?>">
                                        <?php echo esc_html( nicepay_get_status_label( $item->status ) ); ?>
                                    </span>
                                    <?php if ( $requires_reconciliation ) : ?>
                                        <br><small><?php echo esc_html( isset( $item->reconciliation_note ) ? $item->reconciliation_note : __( 'Manual review required', 'nicepay-payment-gateway' ) ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $item->buyer_name ); ?></td>
                                <td><?php echo esc_html( $created_at ); ?></td>
                                <td>
                                    <?php if ( $can_refund ) : ?>
                                        <button type="button" class="button button-small nicepay-cancel-btn"
                                                data-tid="<?php echo esc_attr( $item->tid ); ?>"
                                                data-id="<?php echo esc_attr( $item->id ); ?>"
                                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_cancel_' . $item->id ) ); ?>"
                                                aria-label="<?php echo esc_attr( $cancel_transaction_label ); ?>">
                                            <?php esc_html_e( 'Refund', 'nicepay-payment-gateway' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <a class="button button-small" href="<?php echo esc_url( $view_details_url ); ?>"
                                       aria-label="<?php echo esc_attr( $view_details_label ); ?>">
                                        <?php esc_html_e( 'Details', 'nicepay-payment-gateway' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ) );
                        echo wp_kses_post( $page_links );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_cancel_transaction() {
        $id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        $nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! nicepay_current_user_can_manage_payments() || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $transaction = nicepay_get_transaction( $id );
        if ( ! $transaction ) {
            wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        if ( empty( $transaction->wc_order_id ) || ! function_exists( 'wc_create_refund' ) ) {
            wp_send_json_error( array( 'message' => __( 'Standalone cancellation is unavailable until its reconciliation workflow is configured.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $order = wc_get_order( $transaction->wc_order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'WooCommerce order not found.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $order_tid = (string) $order->get_meta( '_nicepay_tid' );
        if ( empty( $transaction->tid ) || ! hash_equals( (string) $transaction->tid, $order_tid ) ||
            ! in_array( (string) $transaction->status, array( 'paid', 'partially_refunded' ), true ) ||
            ( isset( $transaction->reconciliation_status ) && 'required' === (string) $transaction->reconciliation_status ) ||
            ( isset( $transaction->cancel_status ) && in_array( (string) $transaction->cancel_status, array( 'requested', 'unknown' ), true ) ) ) {
            wp_send_json_error( array( 'message' => __( 'This transaction is not in a safe state for refund.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $remaining = nicepay_normalize_ledger_amount( isset( $transaction->remaining_amount ) ? $transaction->remaining_amount : '0' );
        if ( false === $remaining || '0' === $remaining ) {
            wp_send_json_error( array( 'message' => __( 'This payment has no refundable balance.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $amount = (float) $remaining;

        $refund = wc_create_refund( array(
            'order_id'       => $order->get_id(),
            'amount'         => $amount,
            'reason'         => $reason,
            'refund_payment' => true,
            'restock_items'  => false,
        ) );

        if ( is_wp_error( $refund ) ) {
            wp_send_json_error( array( 'message' => __( 'Refund could not be completed. Review the order notes before retrying.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        wp_send_json_success( array( 'message' => __( 'Refund completed successfully.', 'nicepay-payment-gateway' ) ) );
    }
}

new NicePay_Transactions();
