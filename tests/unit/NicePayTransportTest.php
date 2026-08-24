<?php
/**
 * Contract tests for NicePay's WordPress HTTP transport boundary.
 */

use PHPUnit\Framework\TestCase;

class NicePayTransportTest extends TestCase {

    private NicePay_API $api;

    protected function setUp(): void {
        global $wp_options, $wp_remote_post_test_callback, $wp_remote_post_test_queue, $wp_remote_post_test_requests;
        global $wp_test_hooks, $wp_http_api_curl_test_invocations;

        $wp_options = array();
        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );

        $wp_remote_post_test_callback = null;
        $wp_remote_post_test_queue = array();
        $wp_remote_post_test_requests = array();
        $wp_test_hooks = array();
        $wp_http_api_curl_test_invocations = 0;

        $this->api = new NicePay_API();
    }

    protected function tearDown(): void {
        global $wp_options, $wp_remote_post_test_callback, $wp_remote_post_test_queue, $wp_remote_post_test_requests;
        global $wp_test_hooks, $wp_http_api_curl_test_invocations;

        $wp_options = array();
        $wp_remote_post_test_callback = null;
        $wp_remote_post_test_queue = array();
        $wp_remote_post_test_requests = array();
        $wp_test_hooks = array();
        $wp_http_api_curl_test_invocations = 0;
    }

    public function test_transport_stub_remains_fail_closed_without_callback_or_queue(): void {
        global $wp_remote_post_test_requests;

        $result = wp_remote_post( 'https://example.com/', array( 'body' => array() ) );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'stub', $result->get_error_code() );
        $this->assertCount( 1, $wp_remote_post_test_requests );
    }

    public function test_transport_stub_callback_receives_and_captures_url_and_args(): void {
        global $wp_remote_post_test_callback, $wp_remote_post_test_requests;

        $received = array();
        $wp_remote_post_test_callback = function ( $url, $args ) use ( &$received ) {
            $received = array( 'url' => $url, 'args' => $args );
            return $this->response( array( 'ok' => true ) );
        };

        $args   = array( 'body' => array( 'MID' => NICEPAY_TEST_MID ) );
        $result = wp_remote_post( 'https://example.com/callback', $args );

        $this->assertSame( 'https://example.com/callback', $received['url'] );
        $this->assertSame( $args, $received['args'] );
        $this->assertSame( $received, $wp_remote_post_test_requests[0] );
        $this->assertSame( 200, wp_remote_retrieve_response_code( $result ) );
    }

    public function test_approval_post_uses_hardened_utf8_transport_policy(): void {
        global $wp_remote_post_test_requests;
        $this->queue( $this->valid_approval_response() );

        $result = $this->api->request_approval( $this->auth_context() );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $wp_remote_post_test_requests );
        $request = $wp_remote_post_test_requests[0];
        $this->assertSame( 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp', $request['url'] );
        $this->assertTransportPolicy( $request['args'] );
        $this->assertSame( 'nicepay00m01012006221311045107', $request['args']['body']['TID'] );
        $this->assertSame( '1004', $request['args']['body']['Amt'] );
        $this->assertSame( 'utf-8', $request['args']['body']['CharSet'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $request['args']['body']['SignData'] );
    }

    public function test_transport_scopes_independent_connect_timeout_and_removes_hook(): void {
        global $wp_test_hooks, $wp_http_api_curl_test_invocations;

        $seen = array();
        add_filter(
            'nicepay_http_connect_timeout',
            static function ( $timeout, $context ) use ( &$seen ) {
                $seen = array( $timeout, $context );
                return 4;
            },
            10,
            2
        );
        $this->queue( $this->valid_approval_response() );

        $result = $this->api->request_approval( $this->auth_context() );

        $this->assertIsArray( $result );
        $this->assertSame( array( 5, 'approval' ), $seen );
        $this->assertSame( 1, $wp_http_api_curl_test_invocations );
        $this->assertArrayNotHasKey( 'http_api_curl', $wp_test_hooks );
    }

    public function test_total_timeout_is_bounded_and_filterable_by_operation(): void {
        global $wp_remote_post_test_requests;

        add_filter(
            'nicepay_http_timeout',
            static function ( $timeout, $context ) {
                return 'cancel' === $context ? 12 : $timeout;
            },
            10,
            2
        );
        $this->queue( $this->valid_cancel_response() );

        $result = $this->invokeOperation( 'cancel' );

        $this->assertIsArray( $result );
        $this->assertSame( 12, $wp_remote_post_test_requests[0]['args']['timeout'] );
    }

    public function test_net_cancel_post_uses_hardened_utf8_transport_policy(): void {
        global $wp_remote_post_test_requests;
        $this->queue( $this->valid_cancel_response() );

        $result = $this->api->request_net_cancel( $this->auth_context() );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $wp_remote_post_test_requests );
        $request = $wp_remote_post_test_requests[0];
        $this->assertSame( 'https://dc1-api.nicepay.co.kr/webapi/cancel_process.jsp', $request['url'] );
        $this->assertTransportPolicy( $request['args'] );
        $this->assertSame( '1', $request['args']['body']['NetCancel'] );
        $this->assertSame( '1004', $request['args']['body']['Amt'] );
        $this->assertSame( 'utf-8', $request['args']['body']['CharSet'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $request['args']['body']['SignData'] );
    }

    public function test_cancel_post_uses_hardened_utf8_transport_policy(): void {
        global $wp_remote_post_test_requests;
        $this->queue( $this->valid_cancel_response() );

        $result = $this->api->request_cancel(
            'nicepay00m01012006221311045107',
            '1004',
            'Customer request',
            'SP_ORDER_1'
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $wp_remote_post_test_requests );
        $request = $wp_remote_post_test_requests[0];
        $this->assertSame( NICEPAY_CANCEL_URL, $request['url'] );
        $this->assertTransportPolicy( $request['args'] );
        $this->assertSame( '1004', $request['args']['body']['CancelAmt'] );
        $this->assertSame( 'SP_ORDER_1', $request['args']['body']['Moid'] );
        $this->assertSame( 'utf-8', $request['args']['body']['CharSet'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $request['args']['body']['SignData'] );
    }

    /**
     * @dataProvider operationProvider
     */
    public function test_non_2xx_response_fails_closed( string $operation, string $expected_code ): void {
        $this->queueRaw( 502, '{"ResultCode":"2001"}' );
        if ( 'approval' === $operation ) {
            $this->queue( $this->valid_cancel_response() );
        }

        $result = $this->invokeOperation( $operation );

        $this->assertWpErrorCode( $expected_code, $result );
    }

    /**
     * @dataProvider operationProvider
     */
    public function test_invalid_json_response_fails_closed( string $operation, string $expected_code ): void {
        $this->queueRaw( 200, '<html>not json</html>' );
        if ( 'approval' === $operation ) {
            $this->queue( $this->valid_cancel_response() );
        }

        $result = $this->invokeOperation( $operation );

        $expected_parse_code = str_replace( '_http_error', '_parse_error', $expected_code );
        $this->assertWpErrorCode( $expected_parse_code, $result );
    }

    public static function operationProvider(): array {
        return array(
            'approval'   => array( 'approval', 'nicepay_approval_http_error' ),
            'net cancel' => array( 'net_cancel', 'nicepay_net_cancel_http_error' ),
            'cancel'     => array( 'cancel', 'nicepay_cancel_http_error' ),
        );
    }

    /**
     * @dataProvider incompleteContextProvider
     */
    public function test_incomplete_request_context_fails_before_transport( string $operation, string $expected_code ): void {
        global $wp_remote_post_test_requests;

        if ( 'approval' === $operation ) {
            $context = $this->auth_context();
            unset( $context['AuthToken'] );
            $result = $this->api->request_approval( $context );
        } elseif ( 'net_cancel' === $operation ) {
            $context = $this->auth_context();
            $context['TxTid'] = '';
            $result = $this->api->request_net_cancel( $context );
        } else {
            $result = $this->api->request_cancel( '', '1004', 'reason', 'SP_ORDER_1' );
        }

        $this->assertWpErrorCode( $expected_code, $result );
        $this->assertSame( array(), $wp_remote_post_test_requests );
    }

    public static function incompleteContextProvider(): array {
        return array(
            'approval'   => array( 'approval', 'nicepay_approval_request_invalid' ),
            'net cancel' => array( 'net_cancel', 'nicepay_net_cancel_request_invalid' ),
            'cancel'     => array( 'cancel', 'nicepay_cancel_request_invalid' ),
        );
    }

    /**
     * @dataProvider missingCancelSignatureProvider
     */
    public function test_cancel_response_requires_tid_amount_and_signature( string $field ): void {
        $response = $this->valid_cancel_response();
        unset( $response[ $field ] );
        $this->queue( $response );

        $result = $this->invokeOperation( 'cancel' );

        $this->assertWpErrorCode( 'nicepay_signature_error', $result );
    }

    public static function missingCancelSignatureProvider(): array {
        return array(
            'TID'       => array( 'TID' ),
            'CancelAmt' => array( 'CancelAmt' ),
            'Signature' => array( 'Signature' ),
        );
    }

    public function test_cancel_response_rejects_bad_signature(): void {
        $this->queue(
            $this->valid_cancel_response(
                array( 'Signature' => str_repeat( '0', 64 ) )
            )
        );

        $result = $this->invokeOperation( 'cancel' );

        $this->assertWpErrorCode( 'nicepay_signature_error', $result );
    }

    public function test_cancel_response_accepts_literal_signed_success_fixture(): void {
        $fixture = $this->valid_cancel_response();
        $this->queue( $fixture );

        $result = $this->invokeOperation( 'cancel' );

        $this->assertSame( $fixture, $result );
        $this->assertSame( '2001', $result['ResultCode'] );
    }

    public function test_cancel_response_accepts_signed_fixed_width_amount_and_binds_numerically(): void {
        $fixture = $this->valid_cancel_response(
            array(
                'CancelAmt' => '000000001004',
                'Signature' => '59c36831e223d8d7c96e248123816a31a1a4510088e4bd63862455fe91851de5',
            )
        );
        $this->queue( $fixture );

        $this->assertSame( $fixture, $this->invokeOperation( 'cancel' ) );
    }

    public function test_cancel_response_rejects_a_validly_signed_different_amount(): void {
        $this->queue(
            $this->valid_cancel_response(
                array(
                    'CancelAmt' => '1005',
                    'Signature' => hash(
                        'sha256',
                        'nicepay00m01012006221311045107' . NICEPAY_TEST_MID . '1005' . NICEPAY_TEST_MERCHANT_KEY
                    ),
                )
            )
        );

        $this->assertWpErrorCode( 'nicepay_cancel_binding_error', $this->invokeOperation( 'cancel' ) );
    }

    public function test_net_cancel_accepts_only_literal_signed_2001_fixture(): void {
        $fixture = $this->valid_cancel_response();
        $this->queue( $fixture );

        $result = $this->invokeOperation( 'net_cancel' );

        $this->assertSame( $fixture, $result );

        $this->resetTransport();
        $this->queue( $this->valid_cancel_response( array( 'ResultCode' => '2211' ) ) );
        $rejected = $this->invokeOperation( 'net_cancel' );
        $this->assertWpErrorCode( 'nicepay_net_cancel_rejected', $rejected );

        $this->resetTransport();
        $this->queue( $this->valid_cancel_response( array( 'Signature' => str_repeat( '0', 64 ) ) ) );
        $bad_signature = $this->invokeOperation( 'net_cancel' );
        $this->assertWpErrorCode( 'nicepay_net_cancel_signature_error', $bad_signature );
    }

    public function test_net_cancel_response_is_bound_to_requested_tid_and_amount(): void {
        $context          = $this->auth_context();
        $context['TxTid'] = 'different-authenticated-tid';
        $this->queue( $this->valid_cancel_response() );

        $tid_mismatch = $this->api->request_net_cancel( $context );

        $this->assertWpErrorCode( 'nicepay_net_cancel_binding_error', $tid_mismatch );

        $this->resetTransport();
        $context        = $this->auth_context();
        $context['Amt'] = '1005';
        $this->queue( $this->valid_cancel_response() );

        $amount_mismatch = $this->api->request_net_cancel( $context );

        $this->assertWpErrorCode( 'nicepay_net_cancel_binding_error', $amount_mismatch );
    }

    public function test_approval_failure_preserves_verified_net_cancel_audit(): void {
        global $wp_remote_post_test_requests;
        $this->queueRaw( 502, '{"ResultCode":"3001"}' );
        $this->queue( $this->valid_cancel_response( array( 'ResultMsg' => 'cancelled' ) ) );

        $result = $this->api->request_approval( $this->auth_context() );

        $this->assertWpErrorCode( 'nicepay_approval_http_error', $result );
        $this->assertCount( 2, $wp_remote_post_test_requests );
        $this->assertSame(
            array(
                'needs_reconciliation'    => false,
                'net_cancel_status'       => 'confirmed',
                'net_cancel_result_code'  => '2001',
                'net_cancel_result_msg'   => 'cancelled',
                'net_cancel_requested_at' => $result->get_error_data()['net_cancel_requested_at'],
                'net_cancel_completed_at' => $result->get_error_data()['net_cancel_completed_at'],
            ),
            nicepay_get_approval_error_audit( $result )
        );
    }

    public function test_unconfirmed_net_cancel_requires_reconciliation(): void {
        $this->queueRaw( 502, '{"ResultCode":"3001"}' );
        $this->queueRaw( 503, '{"ResultCode":"2001"}' );

        $result = $this->api->request_approval( $this->auth_context() );
        $audit  = nicepay_get_approval_error_audit( $result );

        $this->assertWpErrorCode( 'nicepay_approval_reconciliation_required', $result );
        $this->assertTrue( $audit['needs_reconciliation'] );
        $this->assertSame( 'unknown', $audit['net_cancel_status'] );
        $this->assertSame( 'nicepay_net_cancel_http_error', $audit['net_cancel_result_code'] );
        $this->assertNull( $audit['net_cancel_completed_at'] );
    }

    public function test_invalid_approval_url_attempts_only_the_valid_network_cancel(): void {
        global $wp_remote_post_test_requests;
        $context               = $this->auth_context();
        $context['NextAppURL'] = 'https://evil.example/webapi/pay_process.jsp';
        $this->queue( $this->valid_cancel_response() );

        $result = $this->api->request_approval( $context );

        $this->assertWpErrorCode( 'nicepay_url_error', $result );
        $this->assertCount( 1, $wp_remote_post_test_requests );
        $this->assertSame( $context['NetCancelURL'], $wp_remote_post_test_requests[0]['url'] );
        $this->assertFalse( nicepay_get_approval_error_audit( $result )['needs_reconciliation'] );
    }

    public function test_wrongly_typed_signed_response_fields_fail_closed_without_type_errors(): void {
        $this->queue( $this->valid_approval_response( array( 'Signature' => array( 'not-a-string' ) ) ) );
        $this->queue( $this->valid_cancel_response() );
        $approval = $this->api->request_approval( $this->auth_context() );
        $this->assertWpErrorCode( 'nicepay_signature_error', $approval );

        $this->resetTransport();
        $this->queue( $this->valid_cancel_response( array( 'CancelAmt' => array( '1004' ) ) ) );
        $net_cancel = $this->api->request_net_cancel( $this->auth_context() );
        $this->assertWpErrorCode( 'nicepay_net_cancel_signature_error', $net_cancel );

        $this->resetTransport();
        $this->queue( $this->valid_cancel_response( array( 'ResultCode' => array( '2001' ) ) ) );
        $cancel = $this->invokeOperation( 'cancel' );
        $this->assertWpErrorCode( 'nicepay_signature_error', $cancel );
    }

    public function test_cancel_extra_params_cannot_override_identity_money_or_signature_fields(): void {
        global $wp_remote_post_test_requests;
        $this->queue( $this->valid_cancel_response() );

        $result = $this->api->request_cancel(
            'nicepay00m01012006221311045107',
            '1004',
            'Customer request',
            'SP_ORDER_1',
            false,
            array(
                'TID'          => 'attackerTid',
                'MID'          => 'attackerMID',
                'Moid'         => 'ATTACKER_ORDER',
                'CancelAmt'    => '1',
                'SignData'     => str_repeat( '0', 64 ),
                'RefundAcctNo' => '1234567890',
            )
        );

        $this->assertIsArray( $result );
        $body = $wp_remote_post_test_requests[0]['args']['body'];
        $this->assertSame( 'nicepay00m01012006221311045107', $body['TID'] );
        $this->assertSame( NICEPAY_TEST_MID, $body['MID'] );
        $this->assertSame( 'SP_ORDER_1', $body['Moid'] );
        $this->assertSame( '1004', $body['CancelAmt'] );
        $this->assertNotSame( str_repeat( '0', 64 ), $body['SignData'] );
        $this->assertArrayNotHasKey( 'RefundAcctNo', $body );
    }

    private function invokeOperation( string $operation ) {
        if ( 'approval' === $operation ) {
            return $this->api->request_approval( $this->auth_context() );
        }

        if ( 'net_cancel' === $operation ) {
            return $this->api->request_net_cancel( $this->auth_context() );
        }

        return $this->api->request_cancel(
            'nicepay00m01012006221311045107',
            '1004',
            'Customer request',
            'SP_ORDER_1'
        );
    }

    private function auth_context(): array {
        return array(
            'NextAppURL'   => 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp',
            'NetCancelURL' => 'https://dc1-api.nicepay.co.kr/webapi/cancel_process.jsp',
            'TxTid'        => 'nicepay00m01012006221311045107',
            'AuthToken'    => 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369',
            'Amt'          => '1004',
        );
    }

    private function valid_approval_response( array $changes = array() ): array {
        return array_merge(
            array(
                'TID'        => 'nicepay00m01012006221311045107',
                'Amt'        => '1004',
                'Signature'  => '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd',
                'ResultCode' => '3001',
            ),
            $changes
        );
    }

    private function valid_cancel_response( array $changes = array() ): array {
        return array_merge(
            array(
                'TID'        => 'nicepay00m01012006221311045107',
                'CancelAmt'  => '1004',
                'Signature'  => '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd',
                'ResultCode' => '2001',
            ),
            $changes
        );
    }

    private function queue( array $body ): void {
        $this->queueRaw( 200, wp_json_encode( $body ) );
    }

    private function queueRaw( int $status, string $body ): void {
        global $wp_remote_post_test_queue;
        $wp_remote_post_test_queue[] = array(
            'response' => array( 'code' => $status ),
            'body'     => $body,
        );
    }

    private function resetTransport(): void {
        global $wp_remote_post_test_queue, $wp_remote_post_test_requests;
        $wp_remote_post_test_queue = array();
        $wp_remote_post_test_requests = array();
    }

    private function response( array $body ): array {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $body ),
        );
    }

    private function assertTransportPolicy( array $args ): void {
        $this->assertSame( 30, $args['timeout'] );
        $this->assertSame( 0, $args['redirection'] );
        $this->assertTrue( $args['sslverify'] );
        $this->assertSame(
            'application/x-www-form-urlencoded; charset=utf-8',
            $args['headers']['Content-Type']
        );
    }

    private function assertWpErrorCode( string $expected, $actual ): void {
        $this->assertInstanceOf( WP_Error::class, $actual );
        $this->assertSame( $expected, $actual->get_error_code() );
    }
}
