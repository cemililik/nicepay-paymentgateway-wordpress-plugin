<?php
/**
 * Signature Integration Tests
 *
 * Verifies all signature generation and verification pairs match
 * the exact examples from the NicePay official documentation v2.0.8 (Section 9).
 * These tests serve as a contract test against the NicePay spec.
 */

use PHPUnit\Framework\TestCase;

class NicePaySignatureIntegrationTest extends TestCase {

    private NicePay_API $api;

    /**
     * Common test values from NicePay documentation
     */
    private string $mid         = 'nicepay00m';
    private string $merchantKey = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';
    private string $authToken   = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
    private string $ediDate     = '20200622131021';
    private string $amt         = '1004';
    private string $tid         = 'nicepay00m01012006221311045107';

    protected function setUp(): void {
        global $wp_options;
        $wp_options = array();

        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', $this->mid );
        update_option( 'nicepay_test_merchant_key', $this->merchantKey );
        update_option( 'nicepay_charset', 'utf-8' );

        $this->api = new NicePay_API();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
    }

    /**
     * Doc Section 9.1 — Auth Request SignData
     * PlainText: EdiDate + MID + Amt + MerchantKey
     * Expected:  475979a5628498711052598c6d4a73a17f0e3f0ef45960eed2e1b1776e3146bd
     */
    public function test_doc_example_9_1_auth_request_signdata(): void {
        $result = $this->api->create_auth_sign_data( $this->ediDate, $this->amt );

        $this->assertEquals(
            '475979a5628498711052598c6d4a73a17f0e3f0ef45960eed2e1b1776e3146bd',
            $result,
            'Auth request SignData must match NicePay doc example 9.1'
        );
    }

    /**
     * Doc Section 9.2 — Auth Response Signature
     * PlainText: AuthToken + MID + Amt + MerchantKey
     * Expected:  cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe
     */
    public function test_doc_example_9_2_auth_response_signature(): void {
        $expected = 'cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe';

        $this->assertTrue(
            $this->api->verify_auth_signature( $this->authToken, $this->amt, $expected ),
            'Auth response Signature verification must pass for doc example 9.2'
        );
    }

    /**
     * Doc Section 9.3 — Approval Request SignData
     * PlainText: AuthToken + MID + Amt + EdiDate + MerchantKey
     * Expected:  4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204
     */
    public function test_doc_example_9_3_approval_request_signdata(): void {
        $result = $this->api->create_approval_sign_data(
            $this->authToken,
            $this->amt,
            $this->ediDate
        );

        $this->assertEquals(
            '4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204',
            $result,
            'Approval request SignData must match NicePay doc example 9.3'
        );
    }

    /**
     * Doc Section 9.4 — Approval Response Signature
     * PlainText: TID + MID + Amt + MerchantKey
     * Expected:  9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd
     */
    public function test_doc_example_9_4_approval_response_signature(): void {
        $expected = '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd';

        $this->assertTrue(
            $this->api->verify_approval_signature( $this->tid, $this->amt, $expected ),
            'Approval response Signature verification must pass for doc example 9.4'
        );
    }

    /**
     * Doc Section 9.5 — Net Cancel Request SignData
     * Same rule as approval: AuthToken + MID + Amt + EdiDate + MerchantKey
     * Expected:  4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204
     */
    public function test_doc_example_9_5_net_cancel_request_signdata(): void {
        // Net cancel uses same rule as approval request
        $result = $this->api->create_approval_sign_data(
            $this->authToken,
            $this->amt,
            $this->ediDate
        );

        $this->assertEquals(
            '4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204',
            $result,
            'Net cancel request SignData must match NicePay doc example 9.5'
        );
    }

    /**
     * Doc Section 9.6 — Net Cancel Response Signature
     * PlainText: TID + MID + CancelAmt + MerchantKey
     * Expected:  9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd
     */
    public function test_doc_example_9_6_net_cancel_response_signature(): void {
        $expected = '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd';

        $this->assertTrue(
            $this->api->verify_cancel_signature( $this->tid, $this->amt, $expected ),
            'Net cancel response Signature verification must pass for doc example 9.6'
        );
    }

    /**
     * Doc Section 9.8 — Cancel Response Signature
     * PlainText: TID + MID + CancelAmt + MerchantKey
     * Expected:  9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd
     */
    public function test_doc_example_9_8_cancel_response_signature(): void {
        $expected = '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd';

        $this->assertTrue(
            $this->api->verify_cancel_signature( $this->tid, $this->amt, $expected ),
            'Cancel response Signature verification must pass for doc example 9.8'
        );
    }

    // -------------------------------------------------------
    // Cross-validation: generate then verify roundtrip
    // -------------------------------------------------------

    public function test_auth_roundtrip_generate_and_verify(): void {
        // Generate what a response signature should be
        $plain = $this->authToken . $this->mid . $this->amt . $this->merchantKey;
        $sig   = hash( 'sha256', $plain );

        $this->assertTrue( $this->api->verify_auth_signature( $this->authToken, $this->amt, $sig ) );
    }

    public function test_approval_roundtrip_generate_and_verify(): void {
        $plain = $this->tid . $this->mid . $this->amt . $this->merchantKey;
        $sig   = hash( 'sha256', $plain );

        $this->assertTrue( $this->api->verify_approval_signature( $this->tid, $this->amt, $sig ) );
    }

    public function test_cancel_roundtrip_generate_and_verify(): void {
        $cancel_amt = '5000';
        $plain = $this->tid . $this->mid . $cancel_amt . $this->merchantKey;
        $sig   = hash( 'sha256', $plain );

        $this->assertTrue( $this->api->verify_cancel_signature( $this->tid, $cancel_amt, $sig ) );
    }

    // -------------------------------------------------------
    // Negative: cross-type signatures must NOT match
    // -------------------------------------------------------

    public function test_auth_signature_does_not_verify_as_approval(): void {
        // Auth response sig = AuthToken+MID+Amt+Key
        $auth_plain = $this->authToken . $this->mid . $this->amt . $this->merchantKey;
        $auth_sig   = hash( 'sha256', $auth_plain );

        // Should NOT pass approval verification when TID differs from AuthToken
        // Approval sig = TID+MID+Amt+Key (TID is a different value than AuthToken)
        $this->assertFalse(
            $this->api->verify_approval_signature( $this->tid, $this->amt, $auth_sig )
        );
    }

    public function test_different_mid_produces_different_signatures(): void {
        $sig1 = $this->api->create_auth_sign_data( $this->ediDate, $this->amt );

        // Change MID
        update_option( 'nicepay_test_mid', 'differentMID' );
        $api2 = new NicePay_API();
        $sig2 = $api2->create_auth_sign_data( $this->ediDate, $this->amt );

        $this->assertNotEquals( $sig1, $sig2 );
    }

    public function test_different_key_produces_different_signatures(): void {
        $sig1 = $this->api->create_auth_sign_data( $this->ediDate, $this->amt );

        // Change key
        update_option( 'nicepay_test_merchant_key', 'completely_different_key' );
        $api2 = new NicePay_API();
        $sig2 = $api2->create_auth_sign_data( $this->ediDate, $this->amt );

        $this->assertNotEquals( $sig1, $sig2 );
    }
}
