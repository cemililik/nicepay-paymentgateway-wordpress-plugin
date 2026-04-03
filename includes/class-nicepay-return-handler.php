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
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
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

        // Find existing transaction
        $transaction = nicepay_get_transaction_by_moid( $moid );

        // Authentication failed
        if ( $auth_result_code !== '0000' ) {
            nicepay_log( 'Standalone auth failed', array( 'code' => $auth_result_code, 'msg' => $auth_result_msg ) );

            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, array(
                    'status'      => 'failed',
                    'result_code' => $auth_result_code,
                    'result_msg'  => $auth_result_msg,
                ) );
            }

            $this->render_result_page( false, $auth_result_msg );
            return;
        }

        // Verify auth signature (required)
        if ( empty( $signature ) || ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {
            nicepay_log( empty( $signature ) ? 'Standalone auth signature missing' : 'Standalone auth signature invalid' );

            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, array(
                    'status'      => 'failed',
                    'result_code' => 'SIG_FAIL',
                    'result_msg'  => 'Signature verification failed',
                ) );
            }

            $this->render_result_page( false, __( 'Payment verification failed.', 'nicepay-payment-gateway' ) );
            return;
        }

        // Request approval
        $auth_data = array(
            'AuthToken'    => $auth_token,
            'TxTid'        => $tx_tid,
            'NextAppURL'   => $next_app_url,
            'NetCancelURL' => $net_cancel_url,
            'Amt'          => $amt,
            'MID'          => $mid,
            'Moid'         => $moid,
            'PayMethod'    => $pay_method,
        );

        $result = $this->api->request_approval( $auth_data );

        if ( is_wp_error( $result ) ) {
            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, array(
                    'status'      => 'failed',
                    'result_code' => 'NET_ERROR',
                    'result_msg'  => $result->get_error_message(),
                ) );
            }

            $this->render_result_page( false, $result->get_error_message() );
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
            'auth_token'      => $auth_token,
            'payment_data'    => $result,
        );

        if ( ! empty( $result['CardCode'] ) ) {
            $update_data['card_code']  = $result['CardCode'];
            $update_data['card_name']  = isset( $result['CardName'] ) ? $result['CardName'] : '';
            $update_data['card_no']    = isset( $result['CardNo'] ) ? $result['CardNo'] : '';
            $update_data['card_quota'] = isset( $result['CardQuota'] ) ? $result['CardQuota'] : '';
        }

        if ( ! empty( $result['VbankBankCode'] ) ) {
            $update_data['bank_code']      = $result['VbankBankCode'];
            $update_data['bank_name']      = isset( $result['VbankBankName'] ) ? $result['VbankBankName'] : '';
            $update_data['vbank_num']      = isset( $result['VbankNum'] ) ? $result['VbankNum'] : '';
            $update_data['vbank_exp_date'] = isset( $result['VbankExpDate'] ) ? $result['VbankExpDate'] : '';
        }

        if ( $this->api->is_success_code( $result_code, $result_method ) ) {
            $update_data['status'] = ( $result_method === 'VBANK' ) ? 'waiting' : 'paid';

            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, $update_data );
            }

            $this->render_result_page( true, $result_msg, $result );
        } else {
            $update_data['status'] = 'failed';

            if ( $transaction ) {
                nicepay_update_transaction( $transaction->id, $update_data );
            }

            $this->render_result_page( false, $result_msg );
        }
    }

    /**
     * Render result page
     */
    private function render_result_page( $success, $message, $data = array() ) {
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
