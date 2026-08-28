<?php
/**
 * Characterization tests for the standalone browser-return state machine.
 *
 * These cases exercise the handler itself. They deliberately assert durable
 * ledger outcomes as well as the rendered result so validation-only tests
 * cannot mask a regression in claim, approval, reversal or persistence logic.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'language_attributes' ) ) {
	function language_attributes() { echo 'lang="en-US"'; }
}

if ( ! function_exists( 'bloginfo' ) ) {
	function bloginfo( $show = '' ) { echo 'charset' === $show ? 'UTF-8' : ''; }
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( $path, '/' ); }
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
		public function init_settings() { $this->settings = array(); }
		public function get_option( $key, $default = null ) {
			return isset( $this->form_fields[ $key ]['default'] ) ? $this->form_fields[ $key ]['default'] : $default;
		}
		public function process_admin_options() { return true; }
			public function get_return_url( $order = null ) {
				unset( $order );
				return 'https://example.com/order-received/';
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

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-inbound-validator.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-return-handler.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-gateway.php';

final class NicePayReturnFlowWpdbFake {
	public $prefix = 'wp_';
	public $row;
	public $claim_result = 1;
	public $update_results = array();
	public $updates = array();
	public $queries = array();

	public function __construct( $row ) {
		$this->row = $row;
	}

	public function prepare( $query, ...$values ) {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
			$query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_row( $query ) {
		$this->queries[] = $query;
		return $this->row;
	}

	public function query( $query ) {
		$this->queries[] = $query;
		if ( false !== strpos( $query, "SET status = 'approving'" ) ||
			false !== strpos( $query, "candidate.status = IF(candidate.id = target.id, 'approving'" ) ) {
			if ( 1 === (int) $this->claim_result ) {
				$this->row->status = 'approving';
				$this->row->approval_state = 'approving';
			}
			return $this->claim_result;
		}
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		unset( $table, $where, $formats, $where_formats );
		$this->updates[] = $data;
		$result = empty( $this->update_results ) ? 1 : array_shift( $this->update_results );
		if ( false !== $result && 0 !== (int) $result ) {
			foreach ( $data as $key => $value ) {
				$this->row->{$key} = $value;
			}
		}
		return $result;
	}
}

final class NicePayWooReturnOrderFake {
	public $status = 'pending';
	public $notes = array();
	public $meta = array();
	public $saved = false;
	public $payment_complete_tid = null;

	public function get_id() { return 42; }
	public function get_total() { return '1000'; }
	public function get_currency() { return 'KRW'; }
	public function get_order_key() { return 'wc_order_key_42'; }
	public function get_payment_method() { return 'nicepay'; }
	public function needs_payment() { return in_array( $this->status, array( 'pending', 'failed' ), true ); }
	public function get_checkout_payment_url( $on_checkout = false ) {
		unset( $on_checkout );
		return 'https://example.com/order-pay/42/';
	}
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function payment_complete( $tid ) {
		$this->payment_complete_tid = $tid;
		$this->status = 'processing';
	}
	public function save() { $this->saved = true; }
}

final class NicePayReturnFlowApiFake extends NicePay_API {
	public $approval_result;
	public $approval_calls = 0;
	public $net_cancel_calls = 0;
	public $net_cancel_result = array( 'ResultCode' => '2001', 'ResultMsg' => 'reversed' );

	public function request_approval( $auth_data ) {
		$this->approval_calls++;
		return $this->approval_result;
	}

	public function request_net_cancel( $auth_data ) {
		$this->net_cancel_calls++;
		return $this->net_cancel_result;
	}
}

final class NicePayReturnFlowTest extends TestCase {
	private $previous_wpdb;
	private $previous_post;
	private $previous_server;
	/** @var NicePayReturnFlowWpdbFake */
	private $wpdb;
	/** @var NicePayReturnFlowApiFake */
	private $api;
	private $binding_token = 'return-binding-secret';

	protected function setUp(): void {
		global $wpdb, $wp_options, $nicepay_refund_test_order, $nicepay_wc_notices_test;
		parent::setUp();
		$this->previous_wpdb   = isset( $wpdb ) ? $wpdb : null;
		$this->previous_post   = $_POST;
		$this->previous_server = $_SERVER;
		$wp_options = array(
			'nicepay_mode'              => 'test',
			'nicepay_test_mid'          => NICEPAY_TEST_MID,
			'nicepay_test_merchant_key' => NICEPAY_TEST_MERCHANT_KEY,
		);
		$this->wpdb = new NicePayReturnFlowWpdbFake( $this->transaction() );
		$wpdb       = $this->wpdb;
		$this->api  = new NicePayReturnFlowApiFake();
		$this->api->approval_result = $this->approval_result();
		$nicepay_refund_test_order = null;
		$nicepay_wc_notices_test   = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $this->auth_payload();
	}

	protected function tearDown(): void {
		global $wpdb, $nicepay_refund_test_order, $nicepay_wc_notices_test;
		$wpdb    = $this->previous_wpdb;
		$_POST   = $this->previous_post;
		$_SERVER = $this->previous_server;
		$nicepay_refund_test_order = null;
		$nicepay_wc_notices_test   = array();
		if ( function_exists( 'header_remove' ) && ! headers_sent() ) {
			header_remove();
		}
		parent::tearDown();
	}

	/**
	 * Correct behavior: every pre-claim identity/integrity failure renders a
	 * generic failure and performs no approval or ledger mutation.
	 *
	 * @dataProvider rejectedAuthReturnProvider
	 */
	public function test_rejected_auth_returns_never_claim_or_approve( array $payload_changes, array $row_changes ): void {
		foreach ( $payload_changes as $key => $value ) {
			$_POST[ $key ] = $value;
		}
		foreach ( $row_changes as $key => $value ) {
			$this->wpdb->row->{$key} = $value;
		}

		$output = $this->run_handler();

		$this->assertStringContainsString( 'Payment Failed', $output );
		$this->assertSame( 0, $this->api->approval_calls );
		$this->assertSame( array(), $this->wpdb->updates );
		$this->assertStringNotContainsString( "SET status = 'approving'", implode( "\n", $this->wpdb->queries ) );
	}

	public static function rejectedAuthReturnProvider(): array {
		return array(
			'provider rejection' => array( array( 'AuthResultCode' => '9999' ), array() ),
			'invalid signature'  => array( array( 'Signature' => str_repeat( '0', 64 ) ), array() ),
			'binding mismatch'   => array( array( 'ReqReserved' => 'attacker-token' ), array() ),
			'replay state'       => array( array(), array( 'status' => 'paid', 'approval_state' => 'approved' ) ),
			'amount mismatch'    => array( array( 'Amt' => '2000' ), array() ),
			'method mismatch'    => array( array( 'PayMethod' => 'BANK' ), array() ),
			'expired offer'      => array( array(), array( 'offer_expires_at' => '2020-01-01 00:00:00' ) ),
		);
	}

	/** Correct behavior: only one concurrent callback can own the claim. */
	public function test_failed_claim_stops_before_approval(): void {
		$this->wpdb->claim_result = 0;

		$output = $this->run_handler();

		$this->assertStringContainsString( 'already being processed', $output );
		$this->assertSame( 0, $this->api->approval_calls );
		$this->assertSame( array(), $this->wpdb->updates );
	}

	/** Correct behavior: an unconfirmed transport outcome is locked for reconciliation. */
	public function test_approval_error_requires_reconciliation(): void {
		$this->api->approval_result = new WP_Error( 'nicepay_approval_reconciliation_required', 'unknown', array() );

		$output = $this->run_handler();

		$this->assertStringContainsString( 'could not confirm the payment outcome', $output );
		$this->assertSame( 'needs_reconciliation', $this->wpdb->row->status );
		$this->assertSame( 'required', $this->wpdb->row->reconciliation_status );
		$this->assertNull( $this->wpdb->row->active_attempt_key );
	}

	/** Correct behavior: a signed response for another Moid never becomes paid. */
	public function test_approval_binding_mismatch_is_reversed_and_escalated(): void {
		$this->api->approval_result = $this->approval_result( array( 'Moid' => 'OTHER_ORDER' ) );

		$this->run_handler();

		$this->assertSame( 1, $this->api->net_cancel_calls );
		$this->assertSame( 'needs_reconciliation', $this->wpdb->row->status );
		$this->assertSame( 'nicepay_approval_moid_mismatch', $this->wpdb->row->reconciliation_note );
	}

	/** Correct behavior: a bound provider decline releases the attempt without marking funds captured. */
	public function test_bound_decline_becomes_failed(): void {
		$this->api->approval_result = $this->approval_result( array( 'ResultCode' => '3999' ) );

		$output = $this->run_handler();

		$this->assertStringContainsString( 'Payment was declined', $output );
		$this->assertSame( 'failed', $this->wpdb->row->status );
		$this->assertNull( $this->wpdb->row->active_attempt_key );
	}

	/** Correct behavior: a bound success durably records capture before rendering success. */
	public function test_bound_success_persists_capture_and_receipt(): void {
		$output = $this->run_handler();

		$this->assertStringContainsString( 'Test payment completed', $output );
		$this->assertSame( 'paid', $this->wpdb->row->status );
		$this->assertSame( '1000', $this->wpdb->row->captured_amount );
		$this->assertSame( '1000', $this->wpdb->row->remaining_amount );
		$this->assertSame( 'not_required', $this->wpdb->row->reconciliation_status );
		$this->assertSame( 64, strlen( $this->wpdb->row->receipt_token_hash ) );
	}

	/** Correct behavior: capture persistence failure attempts reversal and never shows success. */
	public function test_capture_persistence_failure_attempts_network_cancel(): void {
		$this->wpdb->update_results = array( 1, 0, 1 );

		$output = $this->run_handler();

		$this->assertStringContainsString( 'could not confirm the payment outcome', $output );
		$this->assertSame( 1, $this->api->net_cancel_calls );
		$this->assertNotSame( 'paid', $this->wpdb->row->status );
	}

	/** Correct behavior: approval is never attempted when local auth context cannot be stored. */
	public function test_auth_context_persistence_failure_stops_before_approval(): void {
		$this->wpdb->update_results = array( 0, 1 );

		$output = $this->run_handler();

		$this->assertStringContainsString( 'could not confirm the payment outcome', $output );
		$this->assertSame( 0, $this->api->approval_calls );
		$this->assertSame( 'needs_reconciliation', $this->wpdb->row->status );
	}

	/** Correct behavior: an invalid WooCommerce callback cannot mutate or approve a transaction. */
	public function test_woocommerce_rejected_return_redirects_without_ledger_mutation(): void {
		global $nicepay_wc_notices_test;
		$_POST = array( 'Moid' => 'WC_ORDER_17' );

		try {
			$this->run_woocommerce_handler();
			$this->fail( 'Expected the redirect to stop request processing.' );
		} catch ( NicePay_Test_Redirect_Exception $redirect ) {
			$this->assertSame( 'https://example.com/checkout/', $redirect->location );
		}

		$this->assertSame( 0, $this->api->approval_calls );
		$this->assertSame( array(), $this->wpdb->updates );
		$this->assertCount( 1, $nicepay_wc_notices_test );
		$this->assertSame( 'error', $nicepay_wc_notices_test[0]['type'] );
	}

	/** Correct behavior: a bound sandbox capture updates the ledger and holds the WooCommerce order. */
	public function test_woocommerce_bound_test_success_persists_capture_and_holds_order(): void {
		global $nicepay_refund_test_order;
		$order = new NicePayWooReturnOrderFake();
		$nicepay_refund_test_order = $order;
		$this->wpdb->row = $this->woocommerce_transaction();
		$_POST = array_merge( $this->auth_payload(), array( 'Moid' => 'WC_ORDER_17' ) );
		$this->api->approval_result = $this->approval_result( array( 'Moid' => 'WC_ORDER_17' ) );

		try {
			$this->run_woocommerce_handler();
			$this->fail( 'Expected the redirect to stop request processing.' );
		} catch ( NicePay_Test_Redirect_Exception $redirect ) {
			$this->assertSame( 'https://example.com/order-received/', $redirect->location );
		}

		$this->assertSame( 1, $this->api->approval_calls );
		$this->assertSame( 'paid', $this->wpdb->row->status );
		$this->assertSame( 'approved', $this->wpdb->row->approval_state );
		$this->assertSame( '1000', $this->wpdb->row->captured_amount );
		$this->assertSame( '1000', $this->wpdb->row->remaining_amount );
		$this->assertNull( $this->wpdb->row->active_attempt_key );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertSame( 'yes', $order->meta['_nicepay_test_payment'] );
		$this->assertSame( 'nicepay00m0000000000000000017', $order->meta['_nicepay_tid'] );
		$this->assertTrue( $order->saved );
		$this->assertNull( $order->payment_complete_tid );
	}

	private function run_handler(): string {
		$handler = new NicePay_Return_Handler( $this->api );
		ob_start();
		$handler->process();
		return (string) ob_get_clean();
	}

	private function run_woocommerce_handler(): void {
		$gateway = new WC_Gateway_NicePay( $this->api );
		$gateway->handle_return();
	}

	private function transaction(): object {
		return (object) array(
			'id'                   => 17,
			'tid'                  => null,
			'moid'                 => 'STANDALONE_ORDER_17',
			'flow'                 => 'standalone',
			'source_ref'           => 'cfg_test',
			'mid'                  => NICEPAY_TEST_MID,
			'mode'                 => 'test',
			'amount'               => '1000',
			'currency'             => 'KRW',
			'expected_method'      => 'CARD',
			'allowed_methods'      => 'CARD',
			'binding_token_hash'   => hash( 'sha256', $this->binding_token ),
			'status'               => 'pending',
			'approval_state'       => 'pending',
			'offer_expires_at'     => gmdate( 'Y-m-d H:i:s', time() + 600 ),
			'buyer_email'          => '',
			'active_attempt_key'   => null,
			'reconciliation_status'=> 'not_required',
		);
	}

	private function woocommerce_transaction(): object {
		$transaction = $this->transaction();
		$transaction->moid = 'WC_ORDER_17';
		$transaction->flow = 'woocommerce';
		$transaction->source_ref = '42';
		$transaction->wc_order_id = 42;
		$transaction->wc_order_key_hash = hash( 'sha256', 'wc_order_key_42' );
		$transaction->active_attempt_key = 'woocommerce:42';
		return $transaction;
	}

	private function auth_payload(): array {
		$auth_token = 'verified-auth-token';
		$amount     = '1000';
		return array(
			'AuthResultCode' => '0000',
			'AuthResultMsg'  => 'authenticated',
			'AuthToken'      => $auth_token,
			'TxTid'          => 'nicepay00m0000000000000000017',
			'NextAppURL'     => 'https://webapi.nicepay.co.kr/webapi/pay_process.jsp',
			'NetCancelURL'   => 'https://webapi.nicepay.co.kr/webapi/cancel_process.jsp',
			'Moid'           => 'STANDALONE_ORDER_17',
			'Amt'            => $amount,
			'MID'            => NICEPAY_TEST_MID,
			'PayMethod'      => 'CARD',
			'Signature'      => hash( 'sha256', $auth_token . NICEPAY_TEST_MID . $amount . NICEPAY_TEST_MERCHANT_KEY ),
			'ReqReserved'    => $this->binding_token,
		);
	}

	private function approval_result( array $changes = array() ): array {
		$tid    = 'nicepay00m0000000000000000017';
		$amount = '1000';
		$result = array(
			'TID'        => $tid,
			'MID'        => NICEPAY_TEST_MID,
			'Moid'       => 'STANDALONE_ORDER_17',
			'Amt'        => $amount,
			'PayMethod'  => 'CARD',
			'ResultCode' => '3001',
			'ResultMsg'  => 'approved',
			'Signature'  => hash( 'sha256', $tid . NICEPAY_TEST_MID . $amount . NICEPAY_TEST_MERCHANT_KEY ),
		);
		return array_merge( $result, $changes );
	}
}
