<?php
/**
 * Standalone NicePay payment result view.
 *
 * @package NicePay_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;

/** Render the complete standalone payment result document. */
function nicepay_render_payment_result_template( $success, $message, $page_title, $is_vbank, array $data ) {
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
                    <?php nicepay_render_vbank_result_details( $data ); ?>
                <?php elseif ( $success && ! empty( $data ) ) : ?>
                    <?php nicepay_render_transaction_result_details( $data ); ?>
                <?php endif; ?>

                <div class="result-actions">
                    <?php if ( $success && ! empty( $data['ReceiptURL'] ) ) : ?>
                        <?php printf( '<a href="%s" class="result-btn result-btn-secondary">', esc_url( $data['ReceiptURL'] ) ); ?>
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

/** Render virtual-bank deposit details. */
function nicepay_render_vbank_result_details( array $data ) {
    ?>
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
    <?php
}

/** Render captured transaction details. */
function nicepay_render_transaction_result_details( array $data ) {
    ?>
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
    <?php
}
