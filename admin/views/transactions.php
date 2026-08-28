<?php
/** NicePay transaction administration view. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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

                <label class="screen-reader-text" for="nicepay-filter-status"><?php esc_html_e( 'All Statuses', 'nicepay-payment-gateway' ); ?></label>
                <select id="nicepay-filter-status" name="filter_status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'nicepay-payment-gateway' ); ?></option>
                    <?php foreach ( self::allowed_statuses() as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $args['status'], $s ); ?>>
                            <?php echo esc_html( nicepay_get_status_label( $s ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="screen-reader-text" for="nicepay-filter-method"><?php esc_html_e( 'All Methods', 'nicepay-payment-gateway' ); ?></label>
                <select id="nicepay-filter-method" name="filter_method">
                    <option value=""><?php esc_html_e( 'All Methods', 'nicepay-payment-gateway' ); ?></option>
                    <?php foreach ( NicePay_API::get_available_methods() as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $args['payment_method'], $code ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label class="screen-reader-text" for="nicepay-date-from"><?php esc_html_e( 'From', 'nicepay-payment-gateway' ); ?></label>
                <input type="date" id="nicepay-date-from" name="date_from" value="<?php echo esc_attr( $args['date_from'] ); ?>" placeholder="<?php esc_attr_e( 'From', 'nicepay-payment-gateway' ); ?>">
                <label class="screen-reader-text" for="nicepay-date-to"><?php esc_html_e( 'To', 'nicepay-payment-gateway' ); ?></label>
                <input type="date" id="nicepay-date-to" name="date_to" value="<?php echo esc_attr( $args['date_to'] ); ?>" placeholder="<?php esc_attr_e( 'To', 'nicepay-payment-gateway' ); ?>">
                <label class="screen-reader-text" for="nicepay-filter-search"><?php esc_html_e( 'Search...', 'nicepay-payment-gateway' ); ?></label>
                <input type="search" id="nicepay-filter-search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'nicepay-payment-gateway' ); ?>">

                <?php submit_button( __( 'Filter', 'nicepay-payment-gateway' ), 'secondary', 'filter', false ); ?>
                <?php if ( $filter_state['valid'] ) : ?>
                    <?php printf( '<a class="button button-secondary" href="%s">', esc_url( $export_url ) ); ?>
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
                            $order_edit_url = '';
                            $order_number   = '';
                            if ( $order ) {
                                $order_edit_url = method_exists( $order, 'get_edit_order_url' )
                                    ? $order->get_edit_order_url()
                                    : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
                                $order_number = method_exists( $order, 'get_order_number' )
                                    ? $order->get_order_number()
                                    : $order->get_id();
                            }
                            $created_at = function_exists( 'get_date_from_gmt' )
				? get_date_from_gmt( $item->created_at, self::DATE_TIME_FORMAT )
                                : $item->created_at;
                            $currency = ! empty( $item->currency ) ? $item->currency : 'KRW';
                            $captured = ! empty( $item->captured_amount ) ? $item->captured_amount : $item->amount;
                            $refunded = isset( $item->refunded_amount ) ? $item->refunded_amount : 0;
                            $remaining = isset( $item->remaining_amount ) ? $item->remaining_amount : $item->amount;
                            $requires_reconciliation = 'needs_reconciliation' === (string) $item->status ||
                                ( isset( $item->reconciliation_status ) && 'required' === (string) $item->reconciliation_status );
							$can_refund = current_user_can( 'edit_shop_orders' ) && $order && ! empty( $item->tid ) && ! $requires_reconciliation &&
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
                                        <?php printf( '<a href="%s">', esc_url( $order_edit_url ) ); ?>
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
												data-amount="<?php echo esc_attr( nicepay_format_amount( $remaining, $currency ) ); ?>"
                                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_cancel_' . $item->id ) ); ?>"
												aria-label="<?php /* translators: %s: formatted refundable amount */ echo esc_attr( sprintf( __( 'Refund payment %s', 'nicepay-payment-gateway' ), nicepay_format_amount( $remaining, $currency ) ) ); ?>">
                                            <?php esc_html_e( 'Refund', 'nicepay-payment-gateway' ); ?>
                                        </button>
                                    <?php endif; ?>
									<?php if ( $requires_reconciliation && current_user_can( 'edit_shop_orders' ) ) : ?>
										<button type="button" class="button button-small nicepay-reconcile-btn"
											data-id="<?php echo esc_attr( $item->id ); ?>"
											data-decision="reversed"
											data-amount="<?php echo esc_attr( nicepay_format_amount( $item->amount, $currency ) ); ?>"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_reconcile_' . $item->id ) ); ?>">
											<?php esc_html_e( 'Confirm reversed', 'nicepay-payment-gateway' ); ?>
										</button>
										<button type="button" class="button button-small button-primary nicepay-reconcile-btn"
											data-id="<?php echo esc_attr( $item->id ); ?>"
											data-decision="captured"
											data-amount="<?php echo esc_attr( nicepay_format_amount( $item->amount, $currency ) ); ?>"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_reconcile_' . $item->id ) ); ?>">
											<?php esc_html_e( 'Confirm captured', 'nicepay-payment-gateway' ); ?>
										</button>
									<?php endif; ?>
                                    <?php
                                    printf(
                                        '<a class="button button-small" href="%1$s" aria-label="%2$s">',
                                        esc_url( $view_details_url ),
                                        esc_attr( $view_details_label )
                                    );
                                    ?>
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
