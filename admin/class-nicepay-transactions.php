<?php
/**
 * NicePay Transactions Admin Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Shared redaction boundary for transaction exports and admin views. */
final class NicePay_Transaction_Display_Sanitizer {

	const REDACTED_VALUE = '[redacted]';

	/** Return an allowlisted provider result code. */
	public static function result_code( $value ) {
		if ( ! is_scalar( $value ) ) {
			return self::REDACTED_VALUE;
		}
		$value = self::text( $value, 64 );
		return preg_match( '/^[A-Za-z0-9._:\-]{1,64}$/', $value ) ? $value : self::REDACTED_VALUE;
	}

	/** Remove common PAN, contact-data and credential shapes. */
	public static function text( $value, $max_bytes ) {
		$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		$text = preg_replace( '/\s+/u', ' ', $text );
		$patterns = array(
			'/\b(?:auth_?token|signature|sign_?data|merchant_?key|card_?(?:no|number)|pan|buyer_?(?:email|tel)|account_?(?:no|number)|vbank_?num)\b\s*[:=]\s*[^\s,;]+/iu',
			'/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu',
			'/(?<!\d)(?:\d[ .\-]?){9,19}(?!\d)/u',
			'/\b(?:[A-F0-9]{32,}|[A-Za-z0-9+\/_\-]{40,}={0,2})\b/i',
		);
		$text = null === $text ? '' : preg_replace( $patterns, self::REDACTED_VALUE, $text );
		return nicepay_utf8_byte_cut( null === $text ? '' : trim( $text ), max( 1, (int) $max_bytes ) );
	}
}

class NicePay_Transactions_Data {

    const CSV_BATCH_SIZE = 250;
    const CSV_MAX_ROWS   = 10000;
    const REDACTED_VALUE = '[redacted]';
	const DATE_TIME_FORMAT = NICEPAY_DB_DATETIME_FORMAT;

    /** Fixed-shape filter predicates; every request-derived value is bound. */
    const FILTER_SQL = "(%s = '' OR status = %s)
                        AND (%s = '' OR payment_method = %s)
                        AND (%s = '' OR created_at >= %s)
                        AND (%s = '' OR created_at <= %s)
                        AND (%s = '' OR (tid LIKE %s OR moid LIKE %s OR buyer_name LIKE %s OR goods_name LIKE %s))";

    public function __construct() {
        add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );
		add_action( 'wp_ajax_nicepay_resolve_reconciliation', array( $this, 'ajax_resolve_reconciliation' ) );
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

		$filters['status'] = self::parse_enum_filter( $source, 'filter_status', self::allowed_statuses(), $valid );
		$filters['payment_method'] = strtoupper(
			self::parse_enum_filter( $source, 'filter_method', array_keys( NicePay_API::get_available_methods() ), $valid, true )
		);
		$filters['date_from'] = self::parse_date_filter( $source, 'date_from', $valid );
		$filters['date_to']   = self::parse_date_filter( $source, 'date_to', $valid );

        if ( '' !== $filters['date_from'] && '' !== $filters['date_to'] && $filters['date_from'] > $filters['date_to'] ) {
            $valid = false;
        }

		$search = self::request_scalar( $source, 's', $valid );
		$filters['search'] = '' === $search ? '' : nicepay_utf8_byte_cut( sanitize_text_field( $search ), 100 );

        return array(
            'valid'   => $valid,
            'filters' => $filters,
        );
    }

	/** @return string */
	private static function parse_enum_filter( $source, $key, array $allowed, &$valid, $uppercase = false ) {
		$value = self::request_scalar( $source, $key, $valid );
		$value = $uppercase ? strtoupper( $value ) : sanitize_text_field( $value );
		if ( '' !== $value && ! in_array( $value, $allowed, true ) ) {
			$valid = false;
			$value = '';
		}
		return $value;
	}

	/** @return string */
	private static function parse_date_filter( $source, $key, &$valid ) {
		$value = self::request_scalar( $source, $key, $valid );
		if ( '' !== $value && ! self::is_valid_date( $value ) ) {
			$valid = false;
			$value = '';
		}
		return $value;
	}

    /**
     * Return aggregate captured/refunded/remaining balances grouped by currency.
     *
     * @param array<string,string> $filters Canonical filters from parse_filters().
     * @return array<int,object>
     */
    public static function get_financial_summary( $filters ) {
        global $wpdb;

        $table = NicePay_Installer::table_name( $wpdb );
        if ( ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
            return array();
        }

        $sql = "SELECT currency, COUNT(*) AS transaction_count,
                         COALESCE(SUM(captured_amount), 0) AS total_amount,
                         COALESCE(SUM(refunded_amount), 0) AS refunded_amount,
                         COALESCE(SUM(remaining_amount), 0) AS remaining_amount
                  FROM {$table}
                  WHERE " . self::FILTER_SQL . "
                  GROUP BY currency
                  ORDER BY currency ASC";

        $sql  = $wpdb->prepare( $sql, self::filter_query_values( $filters ) );

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

		$max_rows   = max( 1, min( self::CSV_MAX_ROWS, absint( $max_rows ) ) );
		$batch_size = max( 1, min( self::CSV_BATCH_SIZE, absint( $batch_size ) ) );
		$table      = NicePay_Installer::table_name( $wpdb );
		if ( ! is_resource( $stream ) || ! preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) ) {
			return 0;
		}
		$columns = self::csv_columns();
		fputcsv( $stream, self::csv_headers() );

        $exported      = 0;
        $scanned       = 0;
        $cursor_date   = '';
        $cursor_id     = 0;
        while ( $scanned < $max_rows ) {
			$limit = min( $batch_size, $max_rows - $scanned );
			$rows  = self::fetch_csv_batch( $wpdb, $table, $columns, $filters, $cursor_date, $cursor_id, $limit );
			if ( empty( $rows ) ) {
				break;
			}

			$scanned += count( $rows );
			foreach ( $rows as $row ) {
				$exported += self::write_csv_row( $stream, $columns, $row );
			}

			fflush( $stream );
			$cursor = self::csv_cursor( $rows );
			if ( false === $cursor ) {
				break;
			}
			$cursor_date = $cursor['date'];
			$cursor_id   = $cursor['id'];
			if ( count( $rows ) < $limit ) {
				break;
			}
        }

		return $exported;
    }

	/** @return string[] */
	private static function csv_columns() {
		return array(
			'id', 'tid', 'wc_order_id', 'moid', 'flow', 'currency', 'amount',
			'captured_amount', 'refunded_amount', 'remaining_amount', 'payment_method',
			'status', 'result_code', 'mode', 'reconciliation_status', 'reconciliation_note',
			'net_cancel_status', 'net_cancel_result_code', 'created_at', 'approved_at',
		);
	}

	/** @return string[] */
	private static function csv_headers() {
		return array(
			'ID', 'TID', 'WooCommerce Order ID', 'Merchant Order ID', 'Flow', 'Currency',
			'Requested Amount', 'Captured Amount', 'Refunded Amount', 'Remaining Amount',
			'Payment Method', 'Status', 'Provider Result Code', 'Environment', 'Reconciliation Status',
			'Reconciliation Note', 'Network Cancel Status', 'Network Cancel Result Code',
			'Created At', 'Approved At',
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function fetch_csv_batch( $wpdb, $table, array $columns, $filters, $cursor_date, $cursor_id, $limit ) {
		$values = array_merge(
			self::filter_query_values( $filters ),
			array( $cursor_date, $cursor_date, $cursor_date, $cursor_id, $limit )
		);
		$sql = 'SELECT ' . implode( ', ', $columns ) . " FROM {$table}
			WHERE " . self::FILTER_SQL . "
			AND (%s = '' OR created_at < %s OR (created_at = %s AND id < %d))
			ORDER BY created_at DESC, id DESC LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), 'ARRAY_A' );
		return is_array( $rows ) ? array_slice( $rows, 0, $limit ) : array();
	}

	/** @return int */
	private static function write_csv_row( $stream, array $columns, $row ) {
		$row = is_object( $row ) ? get_object_vars( $row ) : $row;
		if ( ! is_array( $row ) ) {
			return 0;
		}

		$csv_row = array();
		foreach ( $columns as $column ) {
			$value = isset( $row[ $column ] ) ? $row[ $column ] : '';
			$csv_row[] = self::sanitize_csv_cell( self::sanitize_csv_field( $column, $value ) );
		}
		fputcsv( $stream, $csv_row );
		return 1;
	}

	/** @return mixed */
	private static function sanitize_csv_field( $column, $value ) {
		if ( 'result_code' === $column ) {
			if ( ! is_scalar( $value ) ) {
				$value = self::REDACTED_VALUE;
			} elseif ( '' !== (string) $value ) {
				$value = NicePay_Transaction_Display_Sanitizer::result_code( $value );
			}
		} elseif ( 'mode' === $column && ! in_array( $value, array( 'test', 'live', '' ), true ) ) {
			$value = self::REDACTED_VALUE;
		} elseif ( 'reconciliation_note' === $column ) {
			$value = NicePay_Transaction_Display_Sanitizer::text( $value, 500 );
		}
		return $value;
	}

	/** @return array{date:string,id:int}|false */
	private static function csv_cursor( array $rows ) {
		$last = end( $rows );
		$last = is_object( $last ) ? get_object_vars( $last ) : $last;
		$valid = is_array( $last ) && ! empty( $last['created_at'] ) && ! empty( $last['id'] ) &&
			is_scalar( $last['created_at'] ) && is_scalar( $last['id'] ) && absint( $last['id'] ) > 0;
		return $valid ? array( 'date' => (string) $last['created_at'], 'id' => absint( $last['id'] ) ) : false;
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
    protected static function allowed_statuses() {
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
        if ( false === isset( $source[ $key ] ) ) {
            return '';
        }
        if ( false === is_scalar( $source[ $key ] ) ) {
            $valid = false;
            return '';
        }
        return trim( (string) wp_unslash( $source[ $key ] ) );
    }

    /** @return bool */
    private static function is_valid_date( $date ) {
        if ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', (string) $date, $parts ) ) {
            return false;
        }
        return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
    }

    /**
     * Return the values for FILTER_SQL after revalidating the canonical filters.
     *
     * @param array<string,string> $filters Canonical filters.
     * @return array<int,string>
     */
    private static function filter_query_values( $filters ) {
        global $wpdb;

        $filters = is_array( $filters ) ? $filters : array();
        $status  = isset( $filters['status'] ) && in_array( $filters['status'], self::allowed_statuses(), true )
            ? $filters['status']
            : '';
        $method  = isset( $filters['payment_method'] ) && in_array( $filters['payment_method'], array_keys( NicePay_API::get_available_methods() ), true )
            ? $filters['payment_method']
            : '';
        $from    = isset( $filters['date_from'] ) && self::is_valid_date( $filters['date_from'] )
            ? $filters['date_from']
            : '';
        $to      = isset( $filters['date_to'] ) && self::is_valid_date( $filters['date_to'] )
            ? $filters['date_to']
            : '';
		$from_utc = '' !== $from ? get_gmt_from_date( $from . ' 00:00:00', self::DATE_TIME_FORMAT ) : '';
		$to_utc   = '' !== $to ? get_gmt_from_date( $to . ' 23:59:59', self::DATE_TIME_FORMAT ) : '';
        $search  = isset( $filters['search'] ) && is_scalar( $filters['search'] )
            ? nicepay_utf8_byte_cut( sanitize_text_field( (string) $filters['search'] ), 100 )
            : '';
        $like    = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

        return array(
            $status,
            $status,
            $method,
            $method,
            $from,
			$from_utc,
            $to,
			$to_utc,
            $search,
            $like,
            $like,
            $like,
            $like,
        );
    }

}

/** Presentation and admin actions for the transaction ledger. */
class NicePay_Transactions_View extends NicePay_Transactions_Data {

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

		$fields = self::diagnostic_fields( $transaction );
		$mode_field = self::environment_field( $transaction );
		if ( null !== $mode_field ) {
			$fields[] = $mode_field;
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

	/** @return array<int,array{label:string,value:string}> */
	private static function diagnostic_fields( $transaction ) {
		$definitions = array(
			'result_code'             => array( __( 'Provider result code', 'nicepay-payment-gateway' ), 'code', 64 ),
			'result_msg'              => array( __( 'Provider result message', 'nicepay-payment-gateway' ), 'text', 500 ),
			'reconciliation_status'   => array( __( 'Reconciliation status', 'nicepay-payment-gateway' ), 'text', 500 ),
			'reconciliation_note'     => array( __( 'Reconciliation note', 'nicepay-payment-gateway' ), 'text', 500 ),
			'net_cancel_status'        => array( __( 'Network cancel status', 'nicepay-payment-gateway' ), 'text', 500 ),
			'net_cancel_result_code'   => array( __( 'Network cancel result code', 'nicepay-payment-gateway' ), 'code', 64 ),
		);
		$fields = array();
		foreach ( $definitions as $property => $definition ) {
			$value = isset( $transaction->{$property} ) && is_scalar( $transaction->{$property} )
				? (string) $transaction->{$property}
				: '';
			if ( '' !== $value ) {
				$fields[] = array(
					'label' => $definition[0],
					'value' => 'code' === $definition[1]
						? NicePay_Transaction_Display_Sanitizer::result_code( $value )
						: NicePay_Transaction_Display_Sanitizer::text( $value, $definition[2] ),
				);
			}
		}
		return $fields;
	}

	/** @return array{label:string,value:string}|null */
	private static function environment_field( $transaction ) {
		$mode = isset( $transaction->mode ) && is_scalar( $transaction->mode )
			? strtolower( (string) $transaction->mode )
			: '';
		$field = null;
		if ( in_array( $mode, array( 'test', 'live' ), true ) ) {
			$field = array(
				'label' => __( 'Environment', 'nicepay-payment-gateway' ),
				'value' => 'test' === $mode ? __( 'Test', 'nicepay-payment-gateway' ) : __( 'Live', 'nicepay-payment-gateway' ),
			);
		}
		return $field;
	}

    /**
     * Reduce an instrument to non-sensitive provider metadata.
     *
     * @param object $transaction Persisted transaction row.
     * @return string
     */
    private static function get_safe_instrument_summary( $transaction ) {
		$method  = self::safe_payment_method( $transaction );
		$summary = isset( $transaction->pay_method_name )
			? NicePay_Transaction_Display_Sanitizer::text( $transaction->pay_method_name, 50 )
			: '';
		$summary = '' !== $summary ? $summary : $method;
		if ( 'CARD' === $method ) {
			$summary = self::card_summary( $transaction, $summary );
		} elseif ( 'BANK' === $method ) {
			$summary = self::bank_summary( $transaction, $summary );
		}
		return NicePay_Transaction_Display_Sanitizer::text( $summary, 180 );
    }

	/** @return string */
	private static function safe_payment_method( $transaction ) {
		$method = isset( $transaction->payment_method ) && is_scalar( $transaction->payment_method )
			? strtoupper( (string) $transaction->payment_method )
			: '';
		return in_array( $method, array( 'CARD', 'BANK', 'VBANK', 'CELLPHONE', 'SSG_BANK', 'GIFT_CULT' ), true ) ? $method : '';
	}

	/** @return string */
	private static function card_summary( $transaction, $summary ) {
		$summary = '' !== $summary ? $summary : __( 'Card', 'nicepay-payment-gateway' );
		$issuer  = isset( $transaction->card_name ) ? NicePay_Transaction_Display_Sanitizer::text( $transaction->card_name, 50 ) : '';
		$code    = isset( $transaction->card_code ) ? self::sanitize_instrument_code( $transaction->card_code ) : '';
		$quota   = isset( $transaction->card_quota ) && is_scalar( $transaction->card_quota ) && preg_match( '/^\d{1,3}$/', (string) $transaction->card_quota )
			? (string) $transaction->card_quota
			: '';
		$summary .= '' !== $issuer ? ' · ' . $issuer : '';
		$summary .= '' !== $code ? ' [' . $code . ']' : '';
		if ( '' !== $quota ) {
			$summary .= ' · ' . sprintf(
				/* translators: %s: stored card installment quota */
				__( 'installment %s', 'nicepay-payment-gateway' ),
				$quota
			);
		}
		return $summary;
	}

	/** @return string */
	private static function bank_summary( $transaction, $summary ) {
		$summary = '' !== $summary ? $summary : __( 'Bank transfer', 'nicepay-payment-gateway' );
		$bank    = isset( $transaction->bank_name ) ? NicePay_Transaction_Display_Sanitizer::text( $transaction->bank_name, 50 ) : '';
		$code    = isset( $transaction->bank_code ) ? self::sanitize_instrument_code( $transaction->bank_code ) : '';
		$summary .= '' !== $bank ? ' · ' . $bank : '';
		$summary .= '' !== $code ? ' [' . $code . ']' : '';
		return $summary;
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
        include __DIR__ . '/views/transactions.php';
    }

    public function ajax_cancel_transaction() {
		$context = $this->refund_request_context();
		if ( is_wp_error( $context ) ) {
			wp_send_json_error( array( 'message' => $context->get_error_message() ) );
		} else {
			$refund = wc_create_refund( array(
				'order_id'       => $context['order']->get_id(),
				'amount'         => (float) $context['remaining'],
				'reason'         => $context['reason'],
				'refund_payment' => true,
				'restock_items'  => false,
			) );
			if ( is_wp_error( $refund ) ) {
				wp_send_json_error( array( 'message' => __( 'Refund could not be completed. Review the order notes before retrying.', 'nicepay-payment-gateway' ) ) );
			} else {
				$updated             = nicepay_get_transaction( $context['id'] );
				$status              = 'refunded';
				$formatted_remaining = '';
				if ( $updated && isset( $updated->status ) ) {
					$status = (string) $updated->status;
				}
				if ( $updated && isset( $updated->remaining_amount, $updated->currency ) ) {
					$formatted_remaining = nicepay_format_amount( $updated->remaining_amount, $updated->currency );
				}
				wp_send_json_success( array(
					'message'             => __( 'Refund completed successfully.', 'nicepay-payment-gateway' ),
					'status'              => $status,
					'formatted_remaining' => $formatted_remaining,
				) );
			}
		}
    }

	/** Validate an administrative full-refund request. */
	private function refund_request_context() {
		$id          = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$reason      = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$nonce       = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$result      = $this->validate_refund_access( $id, $nonce );
		if ( ! is_wp_error( $result ) ) {
			$result = $this->refund_order_context( $id );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = $this->refundable_balance_context( $result['transaction'], $result['order'] );
		}
		if ( ! is_wp_error( $result ) ) {
			$result = array( 'id' => $id, 'reason' => $reason, 'order' => $result['order'], 'remaining' => $result['remaining'] );
		}
		return $result;
	}

	/** @return true|WP_Error */
	private function validate_refund_access( $id, $nonce ) {
		$result = true;
		if ( ! nicepay_current_user_can_manage_payments() || ! current_user_can( 'edit_shop_orders' ) ||
			! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
			$result = new WP_Error( 'nicepay_refund_unauthorized', __( 'Unauthorized.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return array<string,object>|WP_Error */
	private function refund_order_context( $id ) {
		$transaction = nicepay_get_transaction( $id );
		$result      = array();
		if ( ! $transaction ) {
			$result = new WP_Error( 'nicepay_refund_missing', __( 'Transaction not found.', 'nicepay-payment-gateway' ) );
		} elseif ( empty( $transaction->wc_order_id ) || ! function_exists( 'wc_create_refund' ) ) {
			$result = new WP_Error( 'nicepay_refund_unsupported', __( 'Standalone cancellation is unavailable until its reconciliation workflow is configured.', 'nicepay-payment-gateway' ) );
		} else {
			$order  = wc_get_order( $transaction->wc_order_id );
			$result = $order
				? array( 'transaction' => $transaction, 'order' => $order )
				: new WP_Error( 'nicepay_refund_order', __( 'WooCommerce order not found.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return array<string,mixed>|WP_Error */
	private function refundable_balance_context( $transaction, $order ) {
		$order_tid  = (string) $order->get_meta( '_nicepay_tid' );
		$safe_state = ! empty( $transaction->tid ) && hash_equals( (string) $transaction->tid, $order_tid ) &&
			in_array( (string) $transaction->status, array( 'paid', 'partially_refunded' ), true ) &&
			( ! isset( $transaction->reconciliation_status ) || 'required' !== (string) $transaction->reconciliation_status ) &&
			( ! isset( $transaction->cancel_status ) || ! in_array( (string) $transaction->cancel_status, array( 'requested', 'unknown' ), true ) );
		$result = new WP_Error( 'nicepay_refund_state', __( 'This transaction is not in a safe state for refund.', 'nicepay-payment-gateway' ) );
		if ( $safe_state ) {
			$remaining = nicepay_normalize_ledger_amount( isset( $transaction->remaining_amount ) ? $transaction->remaining_amount : '0' );
			$result = false === $remaining || '0' === $remaining
				? new WP_Error( 'nicepay_refund_balance', __( 'This payment has no refundable balance.', 'nicepay-payment-gateway' ) )
				: array( 'order' => $order, 'remaining' => $remaining );
		}
		return $result;
	}

	/** Resolve a provider-console reconciliation decision with an audit trail. */
	public function ajax_resolve_reconciliation() {
		$id       = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! nicepay_current_user_can_manage_payments() || ! current_user_can( 'edit_shop_orders' ) ||
			! wp_verify_nonce( $nonce, 'nicepay_reconcile_' . $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ), 403 );
			return;
		}

		$result = nicepay_resolve_reconciliation( $id, $decision, $reason, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
			return;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Reconciliation decision recorded with an audit entry.', 'nicepay-payment-gateway' ),
				'status'  => $result['status'],
			)
		);
	}
}

/** Public facade retained for existing hooks and integrations. */
final class NicePay_Transactions extends NicePay_Transactions_View {
}

new NicePay_Transactions();
