<?php
/**
 * NicePay Payment Return Handler
 *
 * Handles payment return callbacks for standalone (non-WooCommerce) payments.
 * Mobile payments use ReturnURL redirect, PC payments use nicepaySubmit() callback.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NicePay_Return_Handler {

    /** @var NicePay_API */
    private $api;

    public function __construct() {
        $this->api = new NicePay_API();
    }

    /**
     * Process the payment return
     */
    public function process() {
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Referrer-Policy: no-referrer', true );
        header( 'X-Frame-Options: DENY', true );

        $request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';
        if ( 'POST' !== $request_method ) {
            wp_die( esc_html__( 'Invalid request method.', 'nicepay-payment-gateway' ), 'NicePay Error', array( 'response' => 405 ) );
            return;
        }

        // Collect POST data
        $auth_result_code = isset( $_POST['AuthResultCode'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthResultCode'] ) ) : '';
        $auth_result_msg  = isset( $_POST['AuthResultMsg'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthResultMsg'] ) ) : '';
        $auth_token       = isset( $_POST['AuthToken'] ) ? sanitize_text_field( wp_unslash( $_POST['AuthToken'] ) ) : '';
        $tx_tid           = isset( $_POST['TxTid'] ) ? sanitize_text_field( wp_unslash( $_POST['TxTid'] ) ) : '';
        $next_app_url     = isset( $_POST['NextAppURL'] ) ? esc_url_raw( wp_unslash( $_POST['NextAppURL'] ) ) : '';
        $net_cancel_url   = isset( $_POST['NetCancelURL'] ) ? esc_url_raw( wp_unslash( $_POST['NetCancelURL'] ) ) : '';
        $moid             = isset( $_POST['Moid'] ) ? sanitize_text_field( wp_unslash( $_POST['Moid'] ) ) : '';
        $amt              = isset( $_POST['Amt'] ) ? sanitize_text_field( wp_unslash( $_POST['Amt'] ) ) : '';
        $mid              = isset( $_POST['MID'] ) ? sanitize_text_field( wp_unslash( $_POST['MID'] ) ) : '';
        $pay_method       = isset( $_POST['PayMethod'] ) ? sanitize_text_field( wp_unslash( $_POST['PayMethod'] ) ) : '';
        $signature        = isset( $_POST['Signature'] ) ? sanitize_text_field( wp_unslash( $_POST['Signature'] ) ) : '';

        nicepay_log( 'Standalone return handler', array(
            'AuthResultCode' => $auth_result_code,
            'Moid'           => $moid,
            'PayMethod'      => $pay_method,
        ) );

        $auth_data = array(
            'AuthResultCode' => $auth_result_code,
            'AuthToken'    => $auth_token,
            'TxTid'        => $tx_tid,
            'NextAppURL'   => $next_app_url,
            'NetCancelURL' => $net_cancel_url,
            'Amt'          => $amt,
            'MID'          => $mid,
            'Moid'         => $moid,
            'PayMethod'    => $pay_method,
            'Signature'    => $signature,
        );

        $transaction = NicePay_Inbound_Validator::validate_auth_return(
            'standalone',
            $auth_data,
            $this->api
        );
        if ( is_wp_error( $transaction ) ) {
            nicepay_log( 'Standalone auth return rejected', $transaction->get_error_code(), 'warning' );
            $message = 'nicepay_inbound_auth_failed' === $transaction->get_error_code()
                ? __( 'Payment authentication was not completed. You may try again.', 'nicepay-payment-gateway' )
                : __( 'We could not verify this payment attempt.', 'nicepay-payment-gateway' );
            $this->render_result_page( false, $message );
            return;
        }

        if ( ! nicepay_claim_transaction_for_approval( $transaction->id, 'standalone' ) ) {
            nicepay_log( 'Standalone approval replay or concurrent claim rejected', $moid, 'warning' );
            $this->render_result_page( false, __( 'This payment attempt is already being processed.', 'nicepay-payment-gateway' ) );
            return;
        }

        if ( ! nicepay_update_transaction( $transaction->id, array(
            'tid'        => $tx_tid,
            'auth_token' => $auth_token,
        ), true ) ) {
            $abort = nicepay_abort_authenticated_payment(
                $transaction->id,
                $auth_data,
                $this->api,
                'auth_context_persistence_failed_before_approval'
            );
            nicepay_log( 'Standalone auth context could not be persisted before approval', $transaction->id, 'error' );
            if ( ! $abort['persisted'] ) {
                nicepay_log( 'Standalone pre-approval abort audit could not be persisted', $transaction->id, 'error' );
            }
            $message = $abort['needs_reconciliation']
                ? __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' )
                : __( 'Payment approval was not started and the authorization hold was reversed. You may try again.', 'nicepay-payment-gateway' );
            $this->render_result_page( false, $message );
            return;
        }

        // Bind the approval response to this claimed transaction.
        $transaction->tid = $tx_tid;

        $result = $this->api->request_approval( $auth_data );

        if ( is_wp_error( $result ) ) {
            $abort_audit          = nicepay_get_approval_error_audit( $result );
            $needs_reconciliation = $abort_audit['needs_reconciliation'];
            nicepay_update_transaction( $transaction->id, array(
                'status'                => $needs_reconciliation ? 'needs_reconciliation' : 'failed',
                'approval_state'        => $needs_reconciliation ? 'needs_reconciliation' : 'failed',
                'reconciliation_status' => $needs_reconciliation ? 'required' : 'not_required',
                'reconciliation_note'   => $result->get_error_code(),
                'result_code'           => 'APPROVAL_ERROR',
                'result_msg'            => '',
                'net_cancel_status'         => $abort_audit['net_cancel_status'],
                'net_cancel_result_code'    => $abort_audit['net_cancel_result_code'],
                'net_cancel_result_msg'     => $abort_audit['net_cancel_result_msg'],
                'net_cancel_requested_at'   => $abort_audit['net_cancel_requested_at'],
                'net_cancel_completed_at'   => $abort_audit['net_cancel_completed_at'],
                'active_attempt_key'        => null,
                'auth_token'            => $needs_reconciliation ? $auth_token : '',
            ) );

            $this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
            return;
        }

        $approval_binding = NicePay_Inbound_Validator::validate_approval_response(
            $transaction,
            $pay_method,
            $result,
            $this->api
        );
        if ( is_wp_error( $approval_binding ) ) {
            $net_cancel = $this->api->request_net_cancel( $auth_data );
            nicepay_update_transaction(
                $transaction->id,
                nicepay_get_mismatched_approval_audit( $approval_binding, $net_cancel, $auth_token ),
                true
            );

            nicepay_log( 'Standalone approval binding failed', $approval_binding->get_error_code(), 'error' );
            $this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
            return;
        }

        $result_code   = isset( $result['ResultCode'] ) ? $result['ResultCode'] : '';
        $result_msg    = isset( $result['ResultMsg'] ) ? $result['ResultMsg'] : '';
        $tid           = isset( $result['TID'] ) ? $result['TID'] : $tx_tid;
        $result_method = isset( $result['PayMethod'] ) ? $result['PayMethod'] : $pay_method;

        // Update transaction
        $update_data = array(
            'tid'             => $tid,
            'payment_method'  => $result_method,
            'pay_method_name' => NicePay_API::get_payment_method_name( $result_method ),
            'result_code'     => $result_code,
            'result_msg'      => $result_msg,
            'auth_token'      => '',
            'payment_data'    => nicepay_filter_payment_data( $result ),
        );
        $update_data = array_merge( $update_data, nicepay_get_refund_capability_fields( $result ) );

        if ( ! empty( $result['CardCode'] ) ) {
            $update_data['card_code']  = $result['CardCode'];
            $update_data['card_name']  = isset( $result['CardName'] ) ? $result['CardName'] : '';
            $update_data['card_quota'] = isset( $result['CardQuota'] ) ? $result['CardQuota'] : '';
        }

        if ( $this->api->is_success_code( $result_code, $result_method ) ) {
            $update_data['status']                = 'paid';
            $update_data['approval_state']        = 'approved';
            $update_data['approved_at']           = gmdate( 'Y-m-d H:i:s' );
            $update_data['captured_amount']       = $transaction->amount;
            $update_data['remaining_amount']      = $transaction->amount;
            $update_data['reconciliation_status'] = 'not_required';
            $update_data['active_attempt_key']    = null;

            if ( ! nicepay_update_transaction( $transaction->id, $update_data, true ) ) {
                $abort = nicepay_abort_authenticated_payment(
                    $transaction->id,
                    $auth_data,
                    $this->api,
                    'approval_persistence_failed_after_capture'
                );
                nicepay_log(
                    'Standalone approval persistence failed after capture',
                    $abort['net_cancel_result_code'],
                    'error'
                );
                $this->render_result_page( false, __( 'We could not confirm the payment outcome. Please contact the merchant before retrying.', 'nicepay-payment-gateway' ) );
                return;
            }

            $receipt      = nicepay_issue_standalone_receipt( $transaction->id );
            $receipt_data = nicepay_filter_payment_data( $result );
            if ( is_array( $receipt ) ) {
                $receipt_data['ReceiptURL'] = $receipt['url'];
                if ( ! nicepay_send_standalone_receipt_email( $transaction, $receipt['url'] ) ) {
                    nicepay_log( 'Standalone receipt email could not be sent', $transaction->id, 'warning' );
                }
            }

            $this->render_result_page( true, __( 'Payment completed successfully.', 'nicepay-payment-gateway' ), $receipt_data );
        } else {
            $update_data['status']         = 'failed';
            $update_data['approval_state'] = 'failed';
            $update_data['active_attempt_key'] = null;
            nicepay_update_transaction( $transaction->id, $update_data );

            $this->render_result_page( false, __( 'Payment was declined. Please try another payment method.', 'nicepay-payment-gateway' ) );
        }
    }

    /**
     * Render a previously issued standalone receipt without exposing PII.
     *
     * @param object $transaction Safe receipt projection from the repository.
     */
    public function render_saved_receipt( $transaction ) {
        $this->render_result_page(
            true,
            __( 'Payment completed successfully.', 'nicepay-payment-gateway' ),
            array(
                'TID'       => isset( $transaction->tid ) ? $transaction->tid : '',
                'Moid'      => isset( $transaction->moid ) ? $transaction->moid : '',
                'Amt'       => isset( $transaction->amount ) ? $transaction->amount : '',
                'Currency'  => isset( $transaction->currency ) ? $transaction->currency : 'KRW',
                'PayMethod' => isset( $transaction->payment_method ) ? $transaction->payment_method : '',
            )
        );
    }

    /**
     * Render result page
     */
    private function render_result_page( $success, $message, $data = array() ) {
        if ( function_exists( 'status_header' ) ) {
            status_header( $success ? 200 : 400 );
        }
        $page_title = $success
            ? __( 'Payment Successful', 'nicepay-payment-gateway' )
            : __( 'Payment Failed', 'nicepay-payment-gateway' );

        $is_vbank = ! empty( $data['VbankBankName'] ) || ! empty( $data['VbankNum'] );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $page_title ); ?></title>
            <style>
                * { box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0f2f5; padding: 16px; }
                .result-box { background: #fff; padding: 48px 40px 40px; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); text-align: center; max-width: 500px; width: 100%; animation: box-appear 0.5s ease both; }
                .result-icon { margin: 0 auto 20px; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; animation: icon-pop 0.4s ease 0.3s both; }
                .result-icon svg { width: 32px; height: 32px; }
                .success .result-icon { background: #dcfce7; color: #16a34a; }
                .failure .result-icon { background: #fee2e2; color: #dc2626; }
                .result-title { font-size: 22px; font-weight: 700; margin: 0 0 8px; color: #111827; }
                .result-message { color: #6b7280; margin: 0 0 28px; font-size: 15px; line-height: 1.5; }
                .result-details { text-align: left; background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 28px; font-size: 14px; border: 1px solid #e2e8f0; }
                .result-details dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
                .result-details dt { font-weight: 600; color: #374151; white-space: nowrap; }
                .result-details dd { margin: 0; color: #6b7280; word-break: break-all; }
                .result-vbank { background: #eff6ff; border: 1px solid #bfdbfe; padding: 20px; border-radius: 12px; margin-bottom: 28px; text-align: left; }
                .result-vbank-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #2563eb; margin: 0 0 12px; }
                .result-vbank dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; }
                .result-vbank dt { font-weight: 500; color: #374151; font-size: 13px; }
                .result-vbank dd { margin: 0; color: #1e40af; font-weight: 600; font-size: 15px; }
                .result-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
                .result-btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 32px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 15px; transition: all 0.2s; border: none; cursor: pointer; }
                .result-btn:hover { background: #1d4ed8; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
                .result-btn-secondary { background: #f3f4f6; color: #374151; }
                .result-btn-secondary:hover { background: #e5e7eb; box-shadow: none; }
                @keyframes box-appear { from { opacity: 0; transform: translateY(16px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
                @keyframes icon-pop { from { transform: scale(0); } 60% { transform: scale(1.15); } to { transform: scale(1); } }
                @media (max-width: 480px) { .result-box { padding: 32px 24px 28px; } .result-details dl, .result-vbank dl { grid-template-columns: 1fr; } }
                @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; } }
            </style>
        </head>
        <body>
            <div class="result-box <?php echo $success ? 'success' : 'failure'; ?>">
                <div class="result-icon">
                    <?php if ( $success ) : ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else : ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php endif; ?>
                </div>
                <h1 class="result-title"><?php echo esc_html( $page_title ); ?></h1>
                <p class="result-message"><?php echo esc_html( $message ); ?></p>

                <?php if ( $success && $is_vbank ) : ?>
                    <div class="result-vbank">
                        <p class="result-vbank-title"><?php esc_html_e( 'Deposit Information', 'nicepay-payment-gateway' ); ?></p>
                        <dl>
                            <?php if ( ! empty( $data['VbankBankName'] ) ) : ?>
                                <dt><?php esc_html_e( 'Bank', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( $data['VbankBankName'] ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['VbankNum'] ) ) : ?>
                                <dt><?php esc_html_e( 'Account', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( $data['VbankNum'] ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['Amt'] ) ) : ?>
                                <dt><?php esc_html_e( 'Amount', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( nicepay_format_amount( $data['Amt'] ) ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['VbankExpDate'] ) ) : ?>
                                <dt><?php esc_html_e( 'Deadline', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php elseif ( $success && ! empty( $data ) ) : ?>
                    <div class="result-details">
                        <dl>
                            <?php if ( ! empty( $data['TID'] ) ) : ?>
                                <dt><?php esc_html_e( 'Transaction ID', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( $data['TID'] ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['Moid'] ) ) : ?>
                                <dt><?php esc_html_e( 'Payment Reference', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( $data['Moid'] ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['Amt'] ) ) : ?>
                                <dt><?php esc_html_e( 'Amount', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( nicepay_format_amount( $data['Amt'] ) ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $data['PayMethod'] ) ) : ?>
                                <dt><?php esc_html_e( 'Method', 'nicepay-payment-gateway' ); ?></dt>
                                <dd><?php echo esc_html( NicePay_API::get_payment_method_name( $data['PayMethod'] ) ); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                <?php endif; ?>

                <div class="result-actions">
                    <?php if ( $success && ! empty( $data['ReceiptURL'] ) ) : ?>
                        <a href="<?php echo esc_url( $data['ReceiptURL'] ); ?>" class="result-btn result-btn-secondary">
                            <?php esc_html_e( 'Open Saved Receipt', 'nicepay-payment-gateway' ); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ( $success ) : ?>
                        <button type="button" class="result-btn result-btn-secondary" onclick="window.print()">
                            <?php esc_html_e( 'Print Receipt', 'nicepay-payment-gateway' ); ?>
                        </button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( home_url() ); ?>" class="result-btn">
                        <?php esc_html_e( 'Return to Home', 'nicepay-payment-gateway' ); ?>
                    </a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
