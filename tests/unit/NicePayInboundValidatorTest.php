<?php
/**
 * Tests for side-effect-free inbound NicePay transaction binding.
 */

use PHPUnit\Framework\TestCase;

require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-inbound-validator.php';

class NicePayInboundValidatorWpdbFake {
	public $prefix = 'wp_';
	public $rows = array();
	public $reads = array();

	public function prepare( $query, ...$values ) {
		return array(
			'query'  => $query,
			'values' => $values,
		);
	}

	public function get_row( $prepared ) {
		$this->reads[] = $prepared;
		$moid          = isset( $prepared['values'][0] ) ? $prepared['values'][0] : '';
		$flow          = isset( $prepared['values'][1] ) ? $prepared['values'][1] : null;

		foreach ( $this->rows as $row ) {
			if ( $row->moid === $moid && ( null === $flow || $row->flow === $flow ) ) {
				return $row;
			}
		}

		return null;
	}
}

class NicePayInboundValidatorTest extends TestCase {

	private $previous_wpdb;
	private NicePayInboundValidatorWpdbFake $wpdb;
	private NicePay_API $api;

	protected function setUp(): void {
		global $wpdb, $wp_options;

		$this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
		$this->wpdb          = new NicePayInboundValidatorWpdbFake();
		$wpdb                = $this->wpdb;
		$wp_options          = array();

		update_option( 'nicepay_mode', 'test' );
		update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
		update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );
		$this->api = new NicePay_API();
	}

	protected function tearDown(): void {
		global $wpdb, $wp_options;

		$wpdb       = $this->previous_wpdb;
		$wp_options = array();
	}

	public function test_auth_return_binds_and_returns_the_pending_transaction_without_writes(): void {
		$transaction    = $this->transaction();
		$this->wpdb->rows = array( $transaction );

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertSame( $transaction, $result );
		$this->assertCount( 1, $this->wpdb->reads );
		$this->assertSame( array( 'SP_ORDER_1', 'standalone' ), $this->wpdb->reads[0]['values'] );
		$this->assertSame( 'pending', $transaction->status );
		$this->assertSame( 'pending', $transaction->approval_state );
	}

	public function test_auth_return_accepts_signed_fixed_width_amount_for_numeric_binding(): void {
		$transaction      = $this->transaction();
		$this->wpdb->rows = array( $transaction );
		$amount           = '000000001004';
		$token            = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
		$payload          = $this->auth_payload(
			array(
				'Amt'       => $amount,
				'Signature' => hash( 'sha256', $token . NICEPAY_TEST_MID . $amount . NICEPAY_TEST_MERCHANT_KEY ),
			)
		);

		$this->assertSame(
			$transaction,
			NicePay_Inbound_Validator::validate_auth_return( 'standalone', $payload, $this->api )
		);
	}

	public function test_auth_return_rejects_cheap_signature_repointed_to_expensive_order(): void {
		$this->wpdb->rows = array( $this->transaction( array( 'amount' => '50000' ) ) );

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_amount_mismatch', $result );
	}

	public function test_auth_return_rejects_order_swap_even_when_amount_is_equal(): void {
		$this->wpdb->rows = array( $this->transaction( array( 'moid' => 'OTHER_ORDER' ) ) );

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_transaction_not_found', $result );
	}

	public function test_auth_return_rejects_valid_same_amount_authentication_from_another_attempt(): void {
		$this->wpdb->rows = array(
			$this->transaction( array( 'binding_token_hash' => hash( 'sha256', 'victim-binding-token' ) ) ),
		);

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload( array( 'ReqReserved' => 'attacker-binding-token' ) ),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_binding_mismatch', $result );
	}

	public function test_auth_return_distinguishes_wrong_flow_from_unknown_moid(): void {
		$this->wpdb->rows = array( $this->transaction() );

		$wrong_flow = NicePay_Inbound_Validator::validate_auth_return(
			'woocommerce',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_flow_mismatch', $wrong_flow );

		$this->wpdb->rows = array();
		$unknown = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_transaction_not_found', $unknown );
	}

	public function test_auth_return_rejects_invalid_requested_flow_before_database_lookup(): void {
		$result = NicePay_Inbound_Validator::validate_auth_return(
			'admin',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_invalid_flow', $result );
		$this->assertSame( array(), $this->wpdb->reads );
	}

	public function test_auth_return_rejects_api_mid_that_differs_from_stored_and_posted_mid(): void {
		$this->wpdb->rows = array( $this->transaction() );
		update_option( 'nicepay_test_mid', 'differentMID' );
		$other_api = new NicePay_API();

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$other_api
		);

		$this->assertErrorCode( 'nicepay_inbound_mid_mismatch', $result );
	}

	/**
	 * @dataProvider authBindingMismatchProvider
	 */
	public function test_auth_return_rejects_binding_mismatches( array $transaction_changes, array $payload_changes, string $code ): void {
		$this->wpdb->rows = array( $this->transaction( $transaction_changes ) );
		$payload          = array_merge( $this->auth_payload(), $payload_changes );

		$result = NicePay_Inbound_Validator::validate_auth_return( 'standalone', $payload, $this->api );

		$this->assertErrorCode( $code, $result );
	}

	public static function authBindingMismatchProvider(): array {
		return array(
			'posted MID'          => array( array(), array( 'MID' => 'attackerMID' ), 'nicepay_inbound_mid_mismatch' ),
			'stored MID missing'  => array( array( 'mid' => '' ), array(), 'nicepay_inbound_mid_mismatch' ),
			'wrong method'        => array( array(), array( 'PayMethod' => 'BANK' ), 'nicepay_inbound_method_mismatch' ),
			'unsupported currency'=> array( array( 'currency' => 'USD' ), array(), 'nicepay_inbound_currency_mismatch' ),
			'auth rejected'       => array( array(), array( 'AuthResultCode' => '3001' ), 'nicepay_inbound_auth_failed' ),
			'invalid signature'   => array( array(), array( 'Signature' => str_repeat( '0', 64 ) ), 'nicepay_inbound_signature_invalid' ),
		);
	}

	/**
	 * @dataProvider replayStateProvider
	 */
	public function test_auth_return_rejects_replay_states( string $status, string $approval_state ): void {
		$this->wpdb->rows = array(
			$this->transaction(
				array(
					'status'         => $status,
					'approval_state' => $approval_state,
				)
			),
		);

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertErrorCode( 'nicepay_inbound_replay', $result );
	}

	public static function replayStateProvider(): array {
		return array(
			'already approving' => array( 'approving', 'approving' ),
			'already paid'      => array( 'paid', 'paid' ),
			'status drift'      => array( 'pending', 'approving' ),
			'approval drift'    => array( 'failed', 'pending' ),
		);
	}

	public function test_auth_return_rejects_expired_and_invalid_offer_expiry(): void {
		$this->wpdb->rows = array(
			$this->transaction( array( 'offer_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ),
		);

		$expired = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);
		$this->assertErrorCode( 'nicepay_inbound_offer_expired', $expired );

		$this->wpdb->rows = array( $this->transaction( array( 'offer_expires_at' => 'not-a-date' ) ) );
		$invalid = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);
		$this->assertErrorCode( 'nicepay_inbound_invalid_expiry', $invalid );
	}

	public function test_auth_return_accepts_method_from_csv_allowlist_when_no_single_method_is_bound(): void {
		$this->wpdb->rows = array(
			$this->transaction(
				array(
					'expected_method' => '',
					'allowed_methods'  => 'CARD,BANK,CELLPHONE',
				)
			),
		);

		$result = NicePay_Inbound_Validator::validate_auth_return(
			'standalone',
			$this->auth_payload(),
			$this->api
		);

		$this->assertSame( $this->wpdb->rows[0], $result );
	}

	/**
	 * @dataProvider requiredAuthFieldProvider
	 */
	public function test_auth_return_requires_every_protocol_field( string $field ): void {
		$this->wpdb->rows = array( $this->transaction() );
		$payload          = $this->auth_payload();
		$payload[ $field ] = '';

		$result = NicePay_Inbound_Validator::validate_auth_return( 'standalone', $payload, $this->api );

		$this->assertErrorCode( 'nicepay_inbound_missing_field', $result );
		$this->assertSame( $field, $result->get_error_data()['field'] );
	}

	public static function requiredAuthFieldProvider(): array {
		return array_map(
			function ( $field ) {
				return array( $field );
			},
			array( 'Moid', 'MID', 'Amt', 'PayMethod', 'AuthResultCode', 'AuthToken', 'TxTid', 'Signature', 'NextAppURL', 'NetCancelURL', 'ReqReserved' )
		);
	}

	public function test_approval_response_returns_transaction_without_interpreting_result_code(): void {
		$transaction = $this->transaction( array( 'tid' => 'nicepay00m01012006221311045107' ) );
		$result       = $this->approval_result( array( 'ResultCode' => 'DECLINED' ) );

		$this->assertSame(
			$transaction,
			NicePay_Inbound_Validator::validate_approval_response( $transaction, 'CARD', $result, $this->api )
		);
	}

	/**
	 * @dataProvider approvalMismatchProvider
	 */
	public function test_approval_response_rejects_binding_mismatches( array $transaction_changes, string $request_method, array $result_changes, string $code ): void {
		$transaction = $this->transaction(
			array_merge(
				array( 'tid' => 'nicepay00m01012006221311045107' ),
				$transaction_changes
			)
		);
		$result = array_merge( $this->approval_result(), $result_changes );

		$validated = NicePay_Inbound_Validator::validate_approval_response(
			$transaction,
			$request_method,
			$result,
			$this->api
		);

		$this->assertErrorCode( $code, $validated );
	}

	public static function approvalMismatchProvider(): array {
		return array(
			'MID'       => array( array(), 'CARD', array( 'MID' => 'otherMID' ), 'nicepay_approval_mid_mismatch' ),
			'Moid'      => array( array(), 'CARD', array( 'Moid' => 'OTHER_ORDER' ), 'nicepay_approval_moid_mismatch' ),
			'amount'    => array( array(), 'CARD', array( 'Amt' => '50000' ), 'nicepay_approval_amount_mismatch' ),
			'method'    => array( array(), 'CARD', array( 'PayMethod' => 'BANK' ), 'nicepay_approval_method_mismatch' ),
			'request'   => array( array(), 'BANK', array(), 'nicepay_approval_method_mismatch' ),
			'TID'       => array( array(), 'CARD', array( 'TID' => 'otherTid' ), 'nicepay_approval_tid_mismatch' ),
			'signature' => array( array(), 'CARD', array( 'Signature' => str_repeat( '0', 64 ) ), 'nicepay_approval_signature_invalid' ),
			'currency'  => array( array( 'currency' => 'USD' ), 'CARD', array(), 'nicepay_approval_currency_mismatch' ),
		);
	}

	public function test_approval_response_binds_optional_auth_txtid_shape(): void {
		$transaction = $this->transaction(
			array(
				'tid'   => '',
				'TxTid' => 'nicepay00m01012006221311045107',
			)
		);

		$this->assertSame(
			$transaction,
			NicePay_Inbound_Validator::validate_approval_response(
				$transaction,
				'CARD',
				$this->approval_result(),
				$this->api
			)
		);
	}

	public function test_approval_response_accepts_signed_fixed_width_amount_for_numeric_binding(): void {
		$transaction = $this->transaction();
		$result = $this->approval_result(
			array(
				'Amt'       => '000000001004',
				'Signature' => '59c36831e223d8d7c96e248123816a31a1a4510088e4bd63862455fe91851de5',
			)
		);

		$this->assertSame(
			$transaction,
			NicePay_Inbound_Validator::validate_approval_response(
				$transaction,
				'CARD',
				$result,
				$this->api
			)
		);
	}

	/**
	 * @dataProvider requiredApprovalFieldProvider
	 */
	public function test_approval_response_requires_every_binding_field( string $field ): void {
		$result           = $this->approval_result();
		$result[ $field ] = '';

		$validated = NicePay_Inbound_Validator::validate_approval_response(
			$this->transaction(),
			'CARD',
			$result,
			$this->api
		);

		$this->assertErrorCode( 'nicepay_approval_missing_field', $validated );
		$this->assertSame( $field, $validated->get_error_data()['field'] );
	}

	public static function requiredApprovalFieldProvider(): array {
		return array_map(
			function ( $field ) {
				return array( $field );
			},
			array( 'TID', 'MID', 'Moid', 'Amt', 'PayMethod', 'Signature' )
		);
	}

	private function transaction( array $changes = array() ): object {
		return (object) array_merge(
			array(
				'id'               => 17,
				'tid'              => '',
				'moid'             => 'SP_ORDER_1',
				'flow'             => 'standalone',
				'mid'              => NICEPAY_TEST_MID,
				'currency'         => 'KRW',
				'amount'           => '1004.00',
				'expected_method'  => 'CARD',
				'allowed_methods'   => 'CARD',
				'binding_token_hash'=> hash( 'sha256', 'payment-binding-token' ),
				'payment_method'    => '',
				'status'            => 'pending',
				'approval_state'    => 'pending',
				'offer_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			$changes
		);
	}

	private function auth_payload( array $changes = array() ): array {
		return array_merge(
			array(
				'Moid'          => 'SP_ORDER_1',
				'MID'           => NICEPAY_TEST_MID,
				'Amt'           => '1004',
				'PayMethod'     => 'CARD',
				'AuthResultCode'=> '0000',
				'AuthToken'     => 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369',
				'TxTid'         => 'nicepay00m01012006221311045107',
				'Signature'     => 'cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe',
				'NextAppURL'    => 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp',
				'NetCancelURL'  => 'https://dc1-api.nicepay.co.kr/webapi/cancel_process.jsp',
				'ReqReserved'   => 'payment-binding-token',
			),
			$changes
		);
	}

	private function approval_result( array $changes = array() ): array {
		return array_merge(
			array(
				'TID'        => 'nicepay00m01012006221311045107',
				'MID'        => NICEPAY_TEST_MID,
				'Moid'       => 'SP_ORDER_1',
				'Amt'        => '1004',
				'PayMethod'  => 'CARD',
				'Signature'  => '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd',
				'ResultCode' => '3001',
			),
			$changes
		);
	}

	private function assertErrorCode( string $expected, $actual ): void {
		$this->assertInstanceOf( WP_Error::class, $actual );
		$this->assertSame( $expected, $actual->get_error_code() );
	}
}
