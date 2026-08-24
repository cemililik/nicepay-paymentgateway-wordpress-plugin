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

    public function test_constructor_fails_closed_for_unknown_mode(): void {
        update_option( 'nicepay_mode', 'production-typo' );
        update_option( 'nicepay_test_mid', 'testMID' );
        update_option( 'nicepay_test_merchant_key', 'testMerchantKey' );
        update_option( 'nicepay_live_mid', 'liveMID' );
        update_option( 'nicepay_live_merchant_key', 'liveMerchantKey' );

        $api = new NicePay_API();

        $this->assertSame( '', $api->get_mid() );
        $this->assertSame( '', $api->get_merchant_key() );
    }

    // -------------------------------------------------------
    // EdiDate Generation
    // -------------------------------------------------------

    public function test_generate_edi_date_format(): void {
        $edi_date = $this->api->generate_edi_date();

        $this->assertMatchesRegularExpression( '/^\d{14}$/', $edi_date );

        $parsed = \DateTimeImmutable::createFromFormat(
            '!YmdHis',
            $edi_date,
            new \DateTimeZone( 'Asia/Seoul' )
        );
        $this->assertNotFalse( $parsed );
    }

    public function test_generate_edi_date_uses_seoul_timezone_independent_of_php_default(): void {
        $original_timezone = date_default_timezone_get();
        $seoul_timezone    = new \DateTimeZone( 'Asia/Seoul' );

        try {
            date_default_timezone_set( 'Pacific/Honolulu' );
            $before   = new \DateTimeImmutable( 'now', $seoul_timezone );
            $edi_date = $this->api->generate_edi_date();
            $after    = new \DateTimeImmutable( 'now', $seoul_timezone );
        } finally {
            date_default_timezone_set( $original_timezone );
        }

        $parsed = \DateTimeImmutable::createFromFormat( '!YmdHis', $edi_date, $seoul_timezone );
        $this->assertNotFalse( $parsed );

        $minimum = \DateTimeImmutable::createFromFormat( '!YmdHis', $before->format( 'YmdHis' ), $seoul_timezone );
        $maximum = \DateTimeImmutable::createFromFormat( '!YmdHis', $after->format( 'YmdHis' ), $seoul_timezone );
        $this->assertNotFalse( $minimum );
        $this->assertNotFalse( $maximum );

        $this->assertGreaterThanOrEqual( $minimum->getTimestamp(), $parsed->getTimestamp() );
        $this->assertLessThanOrEqual( $maximum->modify( '+1 second' )->getTimestamp(), $parsed->getTimestamp() );
    }

    // -------------------------------------------------------
    // Moid Generation
    // -------------------------------------------------------

    public function test_generate_moid_with_default_prefix(): void {
        $moid = $this->api->generate_moid();

        $this->assertMatchesRegularExpression( '/^WC_\d{14}_[0-9a-f]{16,}$/', $moid );
        $this->assertLessThanOrEqual( 64, strlen( $moid ) );
    }

    public function test_generate_moid_with_custom_prefix(): void {
        $moid = $this->api->generate_moid( 'DONATE' );

        $this->assertMatchesRegularExpression( '/^DONATE_\d{14}_[0-9a-f]{16,}$/', $moid );
        $this->assertLessThanOrEqual( 64, strlen( $moid ) );
    }

    public function test_generate_moid_unique(): void {
        $moids = array();
        for ( $i = 0; $i < 128; $i++ ) {
            $moids[] = $this->api->generate_moid();
        }

        $this->assertCount( 128, array_unique( $moids ) );
    }

    public function test_generate_moid_sanitizes_and_limits_untrusted_prefix(): void {
        $moid = $this->api->generate_moid( '../../PAY ORDER/<script>' . str_repeat( 'X', 100 ) );

        $this->assertLessThanOrEqual( 64, strlen( $moid ) );
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+_\d{14}_[0-9a-f]{16,}$/', $moid );
        $this->assertStringNotContainsString( '..', $moid );
        $this->assertStringNotContainsString( '/', $moid );
        $this->assertStringNotContainsString( '<', $moid );
        $this->assertStringNotContainsString( ' ', $moid );
    }

    public function test_generate_moid_falls_back_to_wc_when_sanitized_prefix_is_empty(): void {
        $moid = $this->api->generate_moid( '../../ !!!' );

        $this->assertMatchesRegularExpression( '/^WC_\d{14}_[0-9a-f]{16,}$/', $moid );
        $this->assertLessThanOrEqual( 64, strlen( $moid ) );
    }

    public function test_generate_moid_uses_cryptographic_random_bytes(): void {
        $method = new \ReflectionMethod( NicePay_API::class, 'generate_moid' );
        $source = file( $method->getFileName() );

        $this->assertIsArray( $source );

        $method_source = implode(
            '',
            array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $this->assertMatchesRegularExpression( '/random_bytes\(\s*(?:[89]|[1-9]\d+)\s*\)/', $method_source );
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

        // Literal signature from the NicePay v2.0.8 worked example for Amt=1004.
        $valid_sig = 'cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe';

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

        // Independent golden digest for the documented test MID/key and section 9.7 values.
        $this->assertSame(
            'e6959c2ff876c64ccf0008828cab0007a81230c589f3cd727d0277acdc3c4cd5',
            $result
        );
    }

    // -------------------------------------------------------
    // Cancel Signature Verification (Cancel Response)
    // Rule: hex(sha256(TID + MID + CancelAmt + MerchantKey))
    // -------------------------------------------------------

    public function test_verify_cancel_signature_valid(): void {
        $tid        = 'nicepay00m01012006221311045107';
        $cancel_amt = '1004';

        // Literal NicePay v2.0.8 response signature fixture.
        $sig = '9439b21e792ee41d1411d7b5e7e34a2062f0f42f19fa3f875f35c388954d4efd';

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

    public function test_is_success_code_without_method_fails_closed(): void {
        $this->assertFalse( $this->api->is_success_code( '3001' ) );
        $this->assertFalse( $this->api->is_success_code( '4000' ) );
        $this->assertFalse( $this->api->is_success_code( '4100' ) );
        $this->assertFalse( $this->api->is_success_code( 'A000' ) );
        $this->assertFalse( $this->api->is_success_code( '0000' ) );
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

    public function test_get_vbank_exp_date_uses_seoul_timezone_and_configured_days(): void {
        update_option( 'nicepay_vbank_expiry_days', 7 );

        $original_timezone = date_default_timezone_get();
        $seoul_timezone    = new \DateTimeZone( 'Asia/Seoul' );

        try {
            date_default_timezone_set( 'Pacific/Honolulu' );
            $before = new \DateTimeImmutable( 'now', $seoul_timezone );
            $expiry = ( new NicePay_API() )->get_vbank_exp_date();
            $after  = new \DateTimeImmutable( 'now', $seoul_timezone );
        } finally {
            date_default_timezone_set( $original_timezone );
        }

        $parsed = \DateTimeImmutable::createFromFormat( '!YmdHi', $expiry, $seoul_timezone );
        $this->assertNotFalse( $parsed );

        $minimum = \DateTimeImmutable::createFromFormat(
            '!YmdHi',
            $before->modify( '+7 days' )->format( 'YmdHi' ),
            $seoul_timezone
        );
        $maximum = \DateTimeImmutable::createFromFormat(
            '!YmdHi',
            $after->modify( '+7 days' )->format( 'YmdHi' ),
            $seoul_timezone
        );
        $this->assertNotFalse( $minimum );
        $this->assertNotFalse( $maximum );

        $this->assertGreaterThanOrEqual( $minimum->getTimestamp(), $parsed->getTimestamp() );
        $this->assertLessThanOrEqual( $maximum->modify( '+1 minute' )->getTimestamp(), $parsed->getTimestamp() );
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

    public function test_signature_comparison_accepts_only_the_exact_literal_digest(): void {
        $auth_token = 'NICETOKNF435F661A2D54ED799BFB9F4B3F7E369';
        $amt        = '1004';
        $valid      = 'cc94db193780ffb83d79845bb001b26da397cb5855dd285a3b85a4acc1fa55fe';

        // Valid
        $this->assertTrue( $this->api->verify_auth_signature( $auth_token, $amt, $valid ) );

        // Off by one character
        $tampered = substr( $valid, 0, -1 ) . ( $valid[-1] === 'a' ? 'b' : 'a' );
        $this->assertFalse( $this->api->verify_auth_signature( $auth_token, $amt, $tampered ) );

        // Empty signature
        $this->assertFalse( $this->api->verify_auth_signature( $auth_token, $amt, '' ) );
    }

    /**
     * A behavioural valid/invalid pair cannot distinguish hash_equals() from ==.
     * Keep an explicit implementation guard for all inbound signature verifiers.
     *
     * @dataProvider signatureVerifierProvider
     */
    public function test_signature_verifiers_use_timing_safe_comparison( string $method_name ): void {
        $method = new \ReflectionMethod( NicePay_API::class, $method_name );
        $source = file( $method->getFileName() );

        $this->assertIsArray( $source );

        $method_source = implode(
            '',
            array_slice(
                $source,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $this->assertStringContainsString( 'hash_equals(', $method_source );
    }

    public static function signatureVerifierProvider(): array {
        return array(
            'authentication response' => array( 'verify_auth_signature' ),
            'approval response'       => array( 'verify_approval_signature' ),
            'cancel response'         => array( 'verify_cancel_signature' ),
        );
    }
}
