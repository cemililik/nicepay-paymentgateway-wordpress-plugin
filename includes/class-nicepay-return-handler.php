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

        // Verify auth signature
        if ( $signature && ! $this->api->verify_auth_signature( $auth_token, $amt, $signature ) ) {
            nicepay_log( 'Standalone auth signature verification failed' );

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

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $page_title ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0f2f5; }
                .result-box { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); text-align: center; max-width: 480px; width: 90%; }
                .result-icon { font-size: 48px; margin-bottom: 16px; }
                .result-title { font-size: 24px; font-weight: 600; margin: 0 0 12px; }
                .result-message { color: #666; margin: 0 0 24px; }
                .result-details { text-align: left; background: #f8f9fa; padding: 16px; border-radius: 8px; margin-bottom: 24px; font-size: 14px; }
                .result-details dt { font-weight: 600; color: #333; margin-top: 8px; }
                .result-details dd { margin: 4px 0 0 0; color: #555; }
                .result-btn { display: inline-block; padding: 12px 32px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 500; }
                .result-btn:hover { background: #1d4ed8; }
                .success .result-title { color: #16a34a; }
                .failure .result-title { color: #dc2626; }
            </style>
        </head>
        <body>
            <div class="result-box <?php echo $success ? 'success' : 'failure'; ?>">
                <div class="result-icon"><?php echo $success ? '&#10004;' : '&#10008;'; ?></div>
                <h1 class="result-title"><?php echo esc_html( $page_title ); ?></h1>
                <p class="result-message"><?php echo esc_html( $message ); ?></p>
                <?php if ( $success && ! empty( $data ) ) : ?>
                    <dl class="result-details">
                        <?php if ( ! empty( $data['TID'] ) ) : ?>
                            <dt><?php esc_html_e( 'Transaction ID', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( $data['TID'] ); ?></dd>
                        <?php endif; ?>
                        <?php if ( ! empty( $data['Amt'] ) ) : ?>
                            <dt><?php esc_html_e( 'Amount', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( nicepay_format_amount( $data['Amt'] ) ); ?></dd>
                        <?php endif; ?>
                        <?php if ( ! empty( $data['PayMethod'] ) ) : ?>
                            <dt><?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( NicePay_API::get_payment_method_name( $data['PayMethod'] ) ); ?></dd>
                        <?php endif; ?>
                        <?php if ( ! empty( $data['VbankBankName'] ) ) : ?>
                            <dt><?php esc_html_e( 'Bank', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( $data['VbankBankName'] ); ?></dd>
                            <dt><?php esc_html_e( 'Account Number', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( $data['VbankNum'] ); ?></dd>
                            <dt><?php esc_html_e( 'Deposit Deadline', 'nicepay-payment-gateway' ); ?></dt>
                            <dd><?php echo esc_html( $data['VbankExpDate'] ); ?></dd>
                        <?php endif; ?>
                    </dl>
                <?php endif; ?>
                <a href="<?php echo esc_url( home_url() ); ?>" class="result-btn">
                    <?php esc_html_e( 'Return to Home', 'nicepay-payment-gateway' ); ?>
                </a>
            </div>
        </body>
        </html>
        <?php
    }
}
