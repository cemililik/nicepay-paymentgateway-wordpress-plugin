<?php
/**
 * NicePay Transactions Admin Page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Transactions {

    public function __construct() {
        add_action( 'wp_ajax_nicepay_cancel_transaction', array( $this, 'ajax_cancel_transaction' ) );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $per_page = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        $args = array(
            'per_page'       => $per_page,
            'page'           => $current_page,
            'status'         => isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '',
            'payment_method' => isset( $_GET['filter_method'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_method'] ) ) : '',
            'date_from'      => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
            'date_to'        => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
            'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
        );

        $result     = nicepay_get_transactions( $args );
        $items      = $result['items'];
        $total      = $result['total'];
        $total_pages = ceil( $total / $per_page );
        ?>
        <div class="wrap nicepay-admin">
            <h1><?php esc_html_e( 'NicePay Transactions', 'nicepay-payment-gateway' ); ?></h1>

            <form method="get" class="nicepay-filters">
                <input type="hidden" name="page" value="nicepay-transactions">

                <select name="filter_status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'nicepay-payment-gateway' ); ?></option>
                    <?php foreach ( array( 'pending', 'paid', 'failed', 'cancelled', 'refunded', 'waiting' ) as $s ) : ?>
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
            </form>

            <p class="nicepay-total-count">
                <?php
                /* translators: %d: total number of transactions */
                printf( esc_html__( 'Total: %d transactions', 'nicepay-payment-gateway' ), $total );
                ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'TID', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Order', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Method', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Buyer', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'nicepay-payment-gateway' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'nicepay-payment-gateway' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e( 'No transactions found.', 'nicepay-payment-gateway' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $items as $item ) : ?>
                            <tr>
                                <td><?php echo esc_html( $item->id ); ?></td>
                                <td><code style="font-size:11px;"><?php echo esc_html( $item->tid ); ?></code></td>
                                <td>
                                    <?php if ( $item->wc_order_id ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $item->wc_order_id . '&action=edit' ) ); ?>">
                                            #<?php echo esc_html( $item->wc_order_id ); ?>
                                        </a>
                                    <?php else : ?>
                                        <?php echo esc_html( $item->moid ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( nicepay_format_amount( $item->amount ) ); ?></td>
                                <td><?php echo esc_html( $item->pay_method_name ?: $item->payment_method ); ?></td>
                                <td>
                                    <span class="nicepay-status nicepay-status-<?php echo esc_attr( $item->status ); ?>">
                                        <?php echo esc_html( nicepay_get_status_label( $item->status ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $item->buyer_name ); ?></td>
                                <td><?php echo esc_html( $item->created_at ); ?></td>
                                <td>
                                    <?php if ( in_array( $item->status, array( 'paid', 'waiting' ), true ) && $item->tid ) : ?>
                                        <button type="button" class="button button-small nicepay-cancel-btn"
                                                data-tid="<?php echo esc_attr( $item->tid ); ?>"
                                                data-amount="<?php echo esc_attr( $item->amount ); ?>"
                                                data-id="<?php echo esc_attr( $item->id ); ?>"
                                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'nicepay_cancel_' . $item->id ) ); ?>">
                                            <?php esc_html_e( 'Cancel', 'nicepay-payment-gateway' ); ?>
                                        </button>
                                    <?php endif; ?>
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

        <script>
        jQuery(function($) {
            $('.nicepay-cancel-btn').on('click', function() {
                var btn = $(this);
                var reason = prompt('<?php echo esc_js( __( 'Enter cancellation reason:', 'nicepay-payment-gateway' ) ); ?>');
                if (!reason) return;

                btn.prop('disabled', true).text('<?php echo esc_js( __( 'Processing...', 'nicepay-payment-gateway' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'nicepay_cancel_transaction',
                    tid: btn.data('tid'),
                    amount: btn.data('amount'),
                    id: btn.data('id'),
                    reason: reason,
                    nonce: btn.data('nonce')
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message || '<?php echo esc_js( __( 'Cancel failed.', 'nicepay-payment-gateway' ) ); ?>');
                        btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel', 'nicepay-payment-gateway' ) ); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js( __( 'Request failed.', 'nicepay-payment-gateway' ) ); ?>');
                    btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel', 'nicepay-payment-gateway' ) ); ?>');
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_cancel_transaction() {
        $id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $tid    = isset( $_POST['tid'] ) ? sanitize_text_field( wp_unslash( $_POST['tid'] ) ) : '';
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        $nonce  = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nicepay_cancel_' . $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        $transaction = nicepay_get_transaction_by_tid( $tid );
        if ( ! $transaction ) {
            wp_send_json_error( array( 'message' => __( 'Transaction not found.', 'nicepay-payment-gateway' ) ) );
            return;
        }

        // Use stored transaction amount (not client-supplied) for full cancel
        $cancel_amount = nicepay_get_amount( $transaction->amount );

        $api    = new NicePay_API();
        $result = $api->request_cancel( $tid, $cancel_amount, $reason, $transaction->moid );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            return;
        }

        $result_code = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';

        if ( $api->is_cancel_success( $result_code ) ) {
            nicepay_update_transaction( $transaction->id, array(
                'status'      => 'cancelled',
                'result_code' => $result_code,
                'result_msg'  => isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '',
            ) );

            // Update WC order if linked
            if ( $transaction->wc_order_id ) {
                $order = wc_get_order( $transaction->wc_order_id );
                if ( $order ) {
                    $order->update_status( 'cancelled', __( 'Cancelled via NicePay admin.', 'nicepay-payment-gateway' ) . ' ' . $reason );
                }
            }

            wp_send_json_success( array( 'message' => __( 'Transaction cancelled successfully.', 'nicepay-payment-gateway' ) ) );
        } else {
            $error_msg = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : __( 'Cancel failed.', 'nicepay-payment-gateway' );
            wp_send_json_error( array( 'message' => $error_msg ) );
        }
    }
}

new NicePay_Transactions();
