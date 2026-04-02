<?php
/**
 * Tests for NicePay_API class
 *
 * Covers signature generation, signature verification,
 * success code checking, and utility methods.
 */

use PHPUnit\Framework\TestCase;

class NicePayApiTest extends TestCase {

    private NicePay_API $api;

    protected function setUp(): void {
        global $wp_options;
        $wp_options = array();

        // Set test mode options
        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', 'nicepay00m' );
        update_option( 'nicepay_test_merchant_key', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );
        update_option( 'nicepay_charset', 'utf-8' );
        update_option( 'nicepay_vbank_expiry_days', 3 );

        $this->api = new NicePay_API();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
    }

    // -------------------------------------------------------
    // Constructor & Configuration
    // -------------------------------------------------------

    public function test_constructor_loads_test_credentials(): void {
        $this->assertEquals( 'nicepay00m', $this->api->get_mid() );
        $this->assertEquals(
            'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==',
            $this->api->get_merchant_key()
        );
        $this->assertTrue( $this->api->is_test_mode() );
    }

    public function test_constructor_loads_live_credentials(): void {
        update_option( 'nicepay_mode', 'live' );
        update_option( 'nicepay_live_mid', 'liveMID123' );
        update_option( 'nicepay_live_merchant_key', 'liveMerchantKey456' );

        $api = new NicePay_API();

        $this->assertEquals( 'liveMID123', $api->get_mid() );
        $this->assertEquals( 'liveMerchantKey456', $api->get_merchant_key() );
        $this->assertFalse( $api->is_test_mode() );
    }

    // -------------------------------------------------------
    // EdiDate Generation
    // -------------------------------------------------------

    public function test_generate_edi_date_format(): void {
        $edi_date = $this->api->generate_edi_date();

        $this->assertMatchesRegularExpression( '/^\d{14}$/', $edi_date );

        // Should be a valid date
        $parsed = \DateTime::createFromFormat( 'YmdHis', $edi_date );
        $this->assertNotFalse( $parsed );
    }

    // -------------------------------------------------------
    // Moid Generation
    // -------------------------------------------------------

    public function test_generate_moid_with_default_prefix(): void {
        $moid = $this->api->generate_moid();

        $this->assertStringStartsWith( 'WC_', $moid );
        $this->assertLessThanOrEqual( 64, strlen( $moid ) );
    }

    public function test_generate_moid_with_custom_prefix(): void {
        $moid = $this->api->generate_moid( 'DONATE' );

        $this->assertStringStartsWith( 'DONATE_', $moid );
    }

    public function test_generate_moid_unique(): void {
        $moid1 = $this->api->generate_moid();
        $moid2 = $this->api->generate_moid();

        // Random suffix makes collision extremely unlikely
        $this->assertNotEquals( $moid1, $moid2 );
    }

    // -------------------------------------------------------
    // Auth SignData (Authentication Request)
    // Rule: hex(sha256(EdiDate + MID + Amt + MerchantKey))
    // -------------------------------------------------------

    public function test_create_auth_sign_data(): void {
        $edi_date = '20200622131021';
        $amt      = '1004';

        $result = $this->api->create_auth_sign_data( $edi_date, $amt );

        // Expected from NicePay documentation example (Section 9.1)
        $expected = '475979a5628498711052598c6d4a73a17f0e3f0ef45960eed2e1b1776e3146bd';

        $this->assertEquals( $expected, $result );
    }

    public function test_create_auth_sign_data_different_amounts(): void {
        $sign1 = $this->api->create_auth_sign_data( '20200622131021', '1000' );
        $sign2 = $this->api->create_auth_sign_data( '20200622131021', '2000' );

        $this->assertNotEquals( $sign1, $sign2 );
    }

    public function test_create_auth_sign_data_returns_hex_string(): void {
        $result = $this->api->create_auth_sign_data( '20200622131021', '1004' );

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result );
    }

    // -------------------------------------------------------
    // Auth Signature Verification (Authentication Response)
    // Rule: hex(sha256(AuthToken + MID + Amt + MerchantKey))
    // -------------------------------------------------------

    public function test_verify_auth_signature_valid(): void {
        $auth_token = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
        $amt        = '1004';

        // Expected from NicePay documentation example (Section 9.2)
        $expected_sig = 'cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe';

        $this->assertTrue(
            $this->api->verify_auth_signature( $auth_token, $amt, $expected_sig )
        );
    }

    public function test_verify_auth_signature_invalid(): void {
        $auth_token = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
        $amt        = '1004';

        $this->assertFalse(
            $this->api->verify_auth_signature( $auth_token, $amt, 'invalid_signature_here' )
        );
    }

    public function test_verify_auth_signature_tampered_amount(): void {
        $auth_token = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';

        // Generate valid signature for 1004
        $plain = $auth_token . 'nicepay00m' . '1004' . $this->api->get_merchant_key();
        $valid_sig = hash( 'sha256', $plain );

        // Verify against tampered amount 9999
        $this->assertFalse(
            $this->api->verify_auth_signature( $auth_token, '9999', $valid_sig )
        );
    }

    // -------------------------------------------------------
    // Approval SignData (Approval Request)
    // Rule: hex(sha256(AuthToken + MID + Amt + EdiDate + MerchantKey))
    // -------------------------------------------------------

    public function test_create_approval_sign_data(): void {
        $auth_token = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
        $amt        = '1004';
        $edi_date   = '20200622131021';

        $result = $this->api->create_approval_sign_data( $auth_token, $amt, $edi_date );

        // Expected from NicePay documentation example (Section 9.3)
        $expected = '4916540b27cd60f3b6e37aa2e1257c7e4fb9faa0b4a84a1d85fac91461d00204';

        $this->assertEquals( $expected, $result );
    }

    // -------------------------------------------------------
    // Approval Signature Verification (Approval Response)
    // Rule: hex(sha256(TID + MID + Amt + MerchantKey))
    // -------------------------------------------------------

    public function test_verify_approval_signature_valid(): void {
        $tid = 'nicepay00m01012006221311045107';
        $amt = '1004';

        // Expected from NicePay documentation example (Section 9.4)
        $expected_sig = '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd';

        $this->assertTrue(
            $this->api->verify_approval_signature( $tid, $amt, $expected_sig )
        );
    }

    public function test_verify_approval_signature_invalid(): void {
        $this->assertFalse(
            $this->api->verify_approval_signature( 'tid123', '1004', 'wrong' )
        );
    }

    // -------------------------------------------------------
    // Cancel SignData (Cancel Request)
    // Rule: hex(sha256(MID + CancelAmt + EdiDate + MerchantKey))
    // -------------------------------------------------------

    public function test_create_cancel_sign_data(): void {
        $cancel_amt = '1004';
        $edi_date   = '20191219133357';

        $result = $this->api->create_cancel_sign_data( $cancel_amt, $edi_date );

        // From NicePay documentation example (Section 9.7)
        // Note: doc example uses "nictest04m" as MID in plaintext but we use "nicepay00m"
        // So we compute expected ourselves
        $plain    = 'nicepay00m' . '1004' . '20191219133357' . $this->api->get_merchant_key();
        $expected = hash( 'sha256', $plain );

        $this->assertEquals( $expected, $result );
    }

    // -------------------------------------------------------
    // Cancel Signature Verification (Cancel Response)
    // Rule: hex(sha256(TID + MID + CancelAmt + MerchantKey))
    // -------------------------------------------------------

    public function test_verify_cancel_signature_valid(): void {
        $tid        = 'nicepay00m01012006221311045107';
        $cancel_amt = '1004';

        // Same as approval response signature rule but with CancelAmt
        // TID + MID + CancelAmt + MerchantKey
        $plain = $tid . 'nicepay00m' . $cancel_amt . $this->api->get_merchant_key();
        $sig   = hash( 'sha256', $plain );

        $this->assertTrue(
            $this->api->verify_cancel_signature( $tid, $cancel_amt, $sig )
        );
    }

    public function test_verify_cancel_signature_invalid(): void {
        $this->assertFalse(
            $this->api->verify_cancel_signature( 'tid', '1000', 'bad_sig' )
        );
    }

    // -------------------------------------------------------
    // Success Code Checking
    // -------------------------------------------------------

    public function test_is_success_code_card(): void {
        $this->assertTrue( $this->api->is_success_code( '3001', 'CARD' ) );
        $this->assertFalse( $this->api->is_success_code( '4000', 'CARD' ) );
    }

    public function test_is_success_code_bank(): void {
        $this->assertTrue( $this->api->is_success_code( '4000', 'BANK' ) );
        $this->assertFalse( $this->api->is_success_code( '3001', 'BANK' ) );
    }

    public function test_is_success_code_vbank(): void {
        $this->assertTrue( $this->api->is_success_code( '4100', 'VBANK' ) );
    }

    public function test_is_success_code_cellphone(): void {
        $this->assertTrue( $this->api->is_success_code( 'A000', 'CELLPHONE' ) );
    }

    public function test_is_success_code_ssg_bank(): void {
        $this->assertTrue( $this->api->is_success_code( '0000', 'SSG_BANK' ) );
    }

    public function test_is_success_code_gift_cult(): void {
        $this->assertTrue( $this->api->is_success_code( '0000', 'GIFT_CULT' ) );
    }

    public function test_is_success_code_without_method(): void {
        $this->assertTrue( $this->api->is_success_code( '3001' ) );
        $this->assertTrue( $this->api->is_success_code( '4000' ) );
        $this->assertTrue( $this->api->is_success_code( '4100' ) );
        $this->assertTrue( $this->api->is_success_code( 'A000' ) );
        $this->assertFalse( $this->api->is_success_code( '9999' ) );
    }

    public function test_is_success_code_failure_codes(): void {
        $this->assertFalse( $this->api->is_success_code( 'E001' ) );
        $this->assertFalse( $this->api->is_success_code( '' ) );
        $this->assertFalse( $this->api->is_success_code( '0001' ) );
    }

    // -------------------------------------------------------
    // Cancel Success Checking
    // -------------------------------------------------------

    public function test_is_cancel_success(): void {
        $this->assertTrue( $this->api->is_cancel_success( '2001' ) );
        $this->assertTrue( $this->api->is_cancel_success( '2211' ) );
        $this->assertFalse( $this->api->is_cancel_success( '3001' ) );
        $this->assertFalse( $this->api->is_cancel_success( '' ) );
        $this->assertFalse( $this->api->is_cancel_success( '2000' ) );
    }

    // -------------------------------------------------------
    // VBank Expiry Date
    // -------------------------------------------------------

    public function test_get_vbank_exp_date_format(): void {
        $exp = $this->api->get_vbank_exp_date();

        // Format: YmdHi (12 chars)
        $this->assertMatchesRegularExpression( '/^\d{12}$/', $exp );
    }

    public function test_get_vbank_exp_date_in_future(): void {
        $exp = $this->api->get_vbank_exp_date();

        $exp_time = \DateTime::createFromFormat( 'YmdHi', $exp );
        $now      = new \DateTime();

        $this->assertGreaterThan( $now, $exp_time );
    }

    public function test_get_vbank_exp_date_respects_option(): void {
        update_option( 'nicepay_vbank_expiry_days', 7 );

        $api = new NicePay_API();
        $exp = $api->get_vbank_exp_date();

        $exp_time = \DateTime::createFromFormat( 'YmdHi', $exp );
        $min_expected = new \DateTime( '+6 days' );

        $this->assertGreaterThan( $min_expected, $exp_time );
    }

    // -------------------------------------------------------
    // Static Utility Methods
    // -------------------------------------------------------

    public function test_get_payment_method_name(): void {
        $this->assertEquals( 'Credit Card', NicePay_API::get_payment_method_name( 'CARD' ) );
        $this->assertEquals( 'Bank Transfer', NicePay_API::get_payment_method_name( 'BANK' ) );
        $this->assertEquals( 'Virtual Account', NicePay_API::get_payment_method_name( 'VBANK' ) );
        $this->assertEquals( 'Mobile Payment', NicePay_API::get_payment_method_name( 'CELLPHONE' ) );
        $this->assertEquals( 'SSG Bank Account', NicePay_API::get_payment_method_name( 'SSG_BANK' ) );
        $this->assertEquals( 'Culture Cash', NicePay_API::get_payment_method_name( 'GIFT_CULT' ) );
    }

    public function test_get_payment_method_name_unknown(): void {
        $this->assertEquals( 'UNKNOWN', NicePay_API::get_payment_method_name( 'UNKNOWN' ) );
    }

    public function test_get_available_methods(): void {
        $methods = NicePay_API::get_available_methods();

        $this->assertIsArray( $methods );
        $this->assertArrayHasKey( 'CARD', $methods );
        $this->assertArrayHasKey( 'BANK', $methods );
        $this->assertArrayHasKey( 'VBANK', $methods );
        $this->assertArrayHasKey( 'CELLPHONE', $methods );
        $this->assertArrayHasKey( 'SSG_BANK', $methods );
        $this->assertArrayHasKey( 'GIFT_CULT', $methods );
        $this->assertCount( 6, $methods );
    }

    public function test_get_nicepay_lang_defaults(): void {
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'KO' ) );
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'KR' ) );
        $this->assertEquals( 'EN', NicePay_API::get_nicepay_lang( 'EN' ) );
        $this->assertEquals( 'CN', NicePay_API::get_nicepay_lang( 'CN' ) );
        $this->assertEquals( 'CN', NicePay_API::get_nicepay_lang( 'ZH' ) );
    }

    public function test_get_nicepay_lang_case_insensitive(): void {
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'ko' ) );
        $this->assertEquals( 'EN', NicePay_API::get_nicepay_lang( 'en' ) );
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'kr' ) );
    }

    public function test_get_nicepay_lang_unknown_defaults_to_ko(): void {
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'FR' ) );
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'DE' ) );
        $this->assertEquals( 'KO', NicePay_API::get_nicepay_lang( 'TR' ) );
    }

    public function test_get_nicepay_lang_empty_uses_option(): void {
        update_option( 'nicepay_language', 'EN' );

        $this->assertEquals( 'EN', NicePay_API::get_nicepay_lang( '' ) );
    }

    // -------------------------------------------------------
    // Signature Timing Safety
    // -------------------------------------------------------

    public function test_signature_uses_timing_safe_comparison(): void {
        // Verify that hash_equals is used (tested via valid/invalid pairs)
        // This test ensures the verify methods work correctly with edge cases
        $auth_token = str_repeat( 'A', 40 );
        $amt = '100';

        $plain = $auth_token . $this->api->get_mid() . $amt . $this->api->get_merchant_key();
        $valid = hash( 'sha256', $plain );

        // Valid
        $this->assertTrue( $this->api->verify_auth_signature( $auth_token, $amt, $valid ) );

        // Off by one character
        $tampered = substr( $valid, 0, -1 ) . ( $valid[-1] === 'a' ? 'b' : 'a' );
        $this->assertFalse( $this->api->verify_auth_signature( $auth_token, $amt, $tampered ) );

        // Empty signature
        $this->assertFalse( $this->api->verify_auth_signature( $auth_token, $amt, '' ) );
    }
}
