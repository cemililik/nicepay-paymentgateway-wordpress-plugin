<?php
/**
 * Focused WooCommerce refund state/CAS tests without a WordPress database.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback ) {
        return true;
    }
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    class WC_Payment_Gateway {
        public $id;
        public $method_title;
        public $method_description;
        public $has_fields;
        public $supports = array();
        public $form_fields = array();
        public $settings = array();
        public $title;
        public $description;
        public $enabled;
        public function init_settings() {}
        public function get_option( $key, $default = null ) {
            return isset( $this->form_fields[ $key ]['default'] ) ? $this->form_fields[ $key ]['default'] : $default;
        }
    }
}

global $nicepay_refund_test_order;
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        global $nicepay_refund_test_order;
        return $nicepay_refund_test_order && $nicepay_refund_test_order->get_id() === (int) $order_id
            ? $nicepay_refund_test_order
            : null;
    }
}

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-gateway.php';

class NicePayRefundOrderFake {
    public $notes = array();
    public $meta = array( '_nicepay_tid' => 'nicepay00m01012006221311045107' );
    public $saved = false;
    public $total_refunded = '500';
    public function get_id() { return 42; }
    public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
    public function get_currency() { return 'KRW'; }
    public function get_payment_method() { return 'nicepay'; }
    public function get_total() { return '1000'; }
    public function get_total_refunded() { return $this->total_refunded; }
    public function add_order_note( $note ) { $this->notes[] = $note; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function save() { $this->saved = true; }
}

class NicePayRefundWpdbFake {
    public $prefix = 'wp_';
    public $last_error = '';
    public $transaction;
    public $query_result = 1;
    public $update_result = 1;
    public $last_query = '';
    public $update_data = array();
    public $update_where = array();
    public $insert_id = 29;
    public $insert_data = array();

    public function insert( $table, $data, $formats ) {
        $this->insert_data = $data;
        return 1;
    }

    public function prepare( $query, ...$values ) {
        foreach ( $values as $value ) {
            $replacement = is_int( $value ) ? (string) $value : "'" . addslashes( $value ) . "'";
            $query       = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }
        return $query;
    }

    public function get_row( $query ) {
        $this->last_query = $query;
        return $this->transaction;
    }

    public function query( $query ) {
        $this->last_query = $query;
        return $this->query_result;
    }

    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        $this->update_data  = $data;
        $this->update_where = $where;
        return $this->update_result;
    }
}

class NicePayRefundTest extends TestCase {

    private $previous_wpdb;
    private WC_Gateway_NicePay $gateway;

    protected function setUp(): void {
        global $wpdb, $wp_options, $wp_remote_post_test_queue, $wp_remote_post_test_requests, $nicepay_refund_test_order;

        $this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
        $wpdb = new NicePayRefundWpdbFake();
        $wpdb->transaction = $this->transaction();
        $nicepay_refund_test_order = new NicePayRefundOrderFake();

        $wp_options = array();
        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
        $wp_remote_post_test_queue    = array();
        $wp_remote_post_test_requests = array();

        $this->gateway = new WC_Gateway_NicePay();
    }

    protected function tearDown(): void {
        global $wpdb, $wp_remote_post_test_queue, $wp_remote_post_test_requests, $nicepay_refund_test_order;
        $wpdb = $this->previous_wpdb;
        $wp_remote_post_test_queue    = array();
        $wp_remote_post_test_requests = array();
        $nicepay_refund_test_order    = null;
    }

    public function test_unsafe_or_unknown_refund_state_is_rejected_before_transport(): void {
        global $wpdb, $wp_remote_post_test_requests;
        $wpdb->transaction->status        = 'needs_reconciliation';
        $wpdb->transaction->cancel_status = 'unknown';

        $result = $this->gateway->process_refund( 42, '500', 'test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_state_error', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public function test_refund_claim_conflict_sends_no_pg_request(): void {
        global $wpdb, $wp_remote_post_test_requests;
        $wpdb->query_result = 0;

        $result = $this->gateway->process_refund( 42, '500', 'test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_conflict', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public function test_verified_partial_refund_completes_the_exact_reserved_attempt(): void {
        global $wpdb, $wp_remote_post_test_queue, $wp_remote_post_test_requests;
        $wp_remote_post_test_queue[] = $this->cancelResponse( '500' );

        $result = $this->gateway->process_refund( 42, '500', 'Customer request' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $wp_remote_post_test_requests );
        $this->assertSame( 'partially_refunded', $wpdb->update_data['status'] );
        $this->assertSame( '500', $wpdb->update_data['refunded_amount'] );
        $this->assertSame( '500', $wpdb->update_data['remaining_amount'] );
        $this->assertSame( 'requested', $wpdb->update_where['cancel_status'] );
        $this->assertSame( $wpdb->update_data['cancel_status'], 'confirmed' );
    }

    public function test_refund_requires_the_inflight_woocommerce_refund_record(): void {
        global $nicepay_refund_test_order, $wp_remote_post_test_requests;
        $nicepay_refund_test_order->total_refunded = '0';

        $result = $this->gateway->process_refund( 42, '500', 'Customer request' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_amount_error', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public function test_followup_refund_matches_prior_ledger_and_current_woocommerce_refund(): void {
        global $wpdb, $nicepay_refund_test_order, $wp_remote_post_test_queue;
        $wpdb->transaction->status           = 'partially_refunded';
        $wpdb->transaction->refunded_amount  = '200.00';
        $wpdb->transaction->remaining_amount = '800.00';
        $nicepay_refund_test_order->total_refunded = '500';
        $wp_remote_post_test_queue[] = $this->cancelResponse( '300' );

        $result = $this->gateway->process_refund( 42, '300', 'Second refund' );

        $this->assertTrue( $result );
        $this->assertSame( '500', $wpdb->update_data['refunded_amount'] );
        $this->assertSame( '500', $wpdb->update_data['remaining_amount'] );
    }

    public function test_partial_cellphone_refund_is_disabled_before_claim_or_transport(): void {
        global $wpdb, $wp_remote_post_test_requests;
        $wpdb->transaction->payment_method = 'CELLPHONE';

        $result = $this->gateway->process_refund( 42, '500', 'test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_partial_unsupported', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
        $this->assertStringStartsWith( 'SELECT', $wpdb->last_query );
    }

    public function test_partial_card_refund_requires_provider_capability_flag(): void {
        global $wpdb, $wp_remote_post_test_requests;
        $wpdb->transaction->cc_part_cl = '0';

        $result = $this->gateway->process_refund( 42, '500', 'test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_partial_not_allowed', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public function test_partial_simple_pay_refund_is_disabled_before_claim_or_transport(): void {
        global $wpdb, $wp_remote_post_test_requests;
        $wpdb->transaction->clickpay_cl = '7';

        $result = $this->gateway->process_refund( 42, '500', 'test' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_refund_partial_wallet_unsupported', $result->get_error_code() );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public function test_manual_order_cancellation_warns_without_issuing_a_refund(): void {
        global $nicepay_refund_test_order, $wp_remote_post_test_requests;

        nicepay_warn_cancelled_order_with_captured_funds( 42 );

        $this->assertSame( 'yes', $nicepay_refund_test_order->meta['_nicepay_cancelled_funds_warning'] );
        $this->assertNotEmpty( $nicepay_refund_test_order->notes );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    private function transaction(): object {
        return (object) array(
            'id'                    => 17,
            'tid'                   => 'nicepay00m01012006221311045107',
            'wc_order_id'           => 42,
            'flow'                  => 'woocommerce',
            'status'                => 'paid',
            'cancel_status'         => '',
            'reconciliation_status' => 'not_required',
            'currency'              => 'KRW',
            'mid'                   => NICEPAY_TEST_MID,
            'mode'                  => 'test',
            'amount'                => '1000.00',
            'captured_amount'       => '1000.00',
            'refunded_amount'       => '0.00',
            'remaining_amount'      => '1000.00',
            'payment_method'        => 'CARD',
            'cc_part_cl'            => '1',
            'clickpay_cl'           => '',
        );
    }

    private function cancelResponse( string $amount ): array {
        $tid       = 'nicepay00m01012006221311045107';
        $signature = hash( 'sha256', $tid . NICEPAY_TEST_MID . $amount . NICEPAY_TEST_MERCHANT_KEY );

        return array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array(
                'TID'        => $tid,
                'CancelAmt'  => $amount,
                'Signature'  => $signature,
                'ResultCode' => '2001',
                'ResultMsg'  => 'cancelled',
                'OTID'       => 'cancel-chain-1',
            ) ),
        );
    }
}
