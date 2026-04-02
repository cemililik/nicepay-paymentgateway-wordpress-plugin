<?php
/**
 * Tests for nicepay-functions.php helper functions
 *
 * Covers amount formatting, status labels, card/bank code lookups,
 * and transaction database operations (using stubs).
 */

use PHPUnit\Framework\TestCase;

class NicePayFunctionsTest extends TestCase {

    protected function setUp(): void {
        global $wp_options;
        $wp_options = array();

        update_option( 'nicepay_currency', 'KRW' );
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
    }

    // -------------------------------------------------------
    // nicepay_format_amount()
    // -------------------------------------------------------

    public function test_format_amount_krw(): void {
        $this->assertEquals( '10,000 KRW', nicepay_format_amount( 10000, 'KRW' ) );
    }

    public function test_format_amount_krw_large(): void {
        $this->assertEquals( '1,250,000 KRW', nicepay_format_amount( 1250000, 'KRW' ) );
    }

    public function test_format_amount_krw_zero(): void {
        $this->assertEquals( '0 KRW', nicepay_format_amount( 0, 'KRW' ) );
    }

    public function test_format_amount_usd(): void {
        $this->assertEquals( '99.99 USD', nicepay_format_amount( 99.99, 'USD' ) );
    }

    public function test_format_amount_usd_whole_number(): void {
        $this->assertEquals( '50.00 USD', nicepay_format_amount( 50, 'USD' ) );
    }

    public function test_format_amount_default_currency(): void {
        update_option( 'nicepay_currency', 'KRW' );
        $this->assertEquals( '5,000 KRW', nicepay_format_amount( 5000 ) );
    }

    public function test_format_amount_default_currency_usd(): void {
        update_option( 'nicepay_currency', 'USD' );
        $this->assertEquals( '25.50 USD', nicepay_format_amount( 25.50 ) );
    }

    // -------------------------------------------------------
    // nicepay_get_amount()
    // -------------------------------------------------------

    public function test_get_amount_krw_integer(): void {
        $this->assertEquals( '10000', nicepay_get_amount( 10000, 'KRW' ) );
    }

    public function test_get_amount_krw_strips_decimals(): void {
        $this->assertEquals( '10000', nicepay_get_amount( 10000.50, 'KRW' ) );
    }

    public function test_get_amount_usd_keeps_decimals(): void {
        $this->assertEquals( '99.99', nicepay_get_amount( 99.99, 'USD' ) );
    }

    public function test_get_amount_usd_pads_trailing_zeros(): void {
        $this->assertEquals( '50.00', nicepay_get_amount( 50, 'USD' ) );
    }

    public function test_get_amount_usd_fixed_two_decimals(): void {
        $this->assertEquals( '10.50', nicepay_get_amount( 10.5, 'USD' ) );
    }

    public function test_get_amount_default_currency(): void {
        update_option( 'nicepay_currency', 'KRW' );
        $this->assertEquals( '5000', nicepay_get_amount( 5000 ) );
    }

    public function test_get_amount_returns_string(): void {
        $result = nicepay_get_amount( 1000, 'KRW' );
        $this->assertIsString( $result );
    }

    // -------------------------------------------------------
    // nicepay_get_status_label()
    // -------------------------------------------------------

    public function test_status_label_pending(): void {
        $this->assertEquals( 'Pending', nicepay_get_status_label( 'pending' ) );
    }

    public function test_status_label_paid(): void {
        $this->assertEquals( 'Paid', nicepay_get_status_label( 'paid' ) );
    }

    public function test_status_label_failed(): void {
        $this->assertEquals( 'Failed', nicepay_get_status_label( 'failed' ) );
    }

    public function test_status_label_cancelled(): void {
        $this->assertEquals( 'Cancelled', nicepay_get_status_label( 'cancelled' ) );
    }

    public function test_status_label_refunded(): void {
        $this->assertEquals( 'Refunded', nicepay_get_status_label( 'refunded' ) );
    }

    public function test_status_label_waiting(): void {
        $this->assertEquals( 'Waiting for Deposit', nicepay_get_status_label( 'waiting' ) );
    }

    public function test_status_label_unknown_returns_raw(): void {
        $this->assertEquals( 'custom_status', nicepay_get_status_label( 'custom_status' ) );
    }

    // -------------------------------------------------------
    // nicepay_get_card_name()
    // -------------------------------------------------------

    public function test_card_name_bc(): void {
        $this->assertEquals( 'BC', nicepay_get_card_name( '01' ) );
    }

    public function test_card_name_kb_kookmin(): void {
        $this->assertEquals( 'KB Kookmin', nicepay_get_card_name( '02' ) );
    }

    public function test_card_name_samsung(): void {
        $this->assertEquals( 'Samsung', nicepay_get_card_name( '04' ) );
    }

    public function test_card_name_shinhan(): void {
        $this->assertEquals( 'Shinhan', nicepay_get_card_name( '06' ) );
    }

    public function test_card_name_hyundai(): void {
        $this->assertEquals( 'Hyundai', nicepay_get_card_name( '07' ) );
    }

    public function test_card_name_lotte(): void {
        $this->assertEquals( 'Lotte', nicepay_get_card_name( '08' ) );
    }

    public function test_card_name_kakao_bank(): void {
        $this->assertEquals( 'Kakao Bank', nicepay_get_card_name( '37' ) );
    }

    public function test_card_name_toss_bank(): void {
        $this->assertEquals( 'Toss Bank', nicepay_get_card_name( '44' ) );
    }

    public function test_card_name_toss_money(): void {
        $this->assertEquals( 'Toss Money', nicepay_get_card_name( '46' ) );
    }

    public function test_card_name_unknown_returns_code(): void {
        $this->assertEquals( '99', nicepay_get_card_name( '99' ) );
    }

    // -------------------------------------------------------
    // nicepay_get_bank_name()
    // -------------------------------------------------------

    public function test_bank_name_kdb(): void {
        $this->assertEquals( 'KDB', nicepay_get_bank_name( '002' ) );
    }

    public function test_bank_name_kb_kookmin(): void {
        $this->assertEquals( 'KB Kookmin', nicepay_get_bank_name( '004' ) );
    }

    public function test_bank_name_nh(): void {
        $this->assertEquals( 'NH', nicepay_get_bank_name( '011' ) );
    }

    public function test_bank_name_woori(): void {
        $this->assertEquals( 'Woori', nicepay_get_bank_name( '020' ) );
    }

    public function test_bank_name_im_bank_daegu(): void {
        $this->assertEquals( 'iM Bank(Daegu)', nicepay_get_bank_name( '031' ) );
    }

    public function test_bank_name_hana(): void {
        $this->assertEquals( 'Hana', nicepay_get_bank_name( '081' ) );
    }

    public function test_bank_name_shinhan(): void {
        $this->assertEquals( 'Shinhan', nicepay_get_bank_name( '088' ) );
    }

    public function test_bank_name_kakao_bank(): void {
        $this->assertEquals( 'Kakao Bank', nicepay_get_bank_name( '090' ) );
    }

    public function test_bank_name_toss_bank(): void {
        $this->assertEquals( 'Toss Bank', nicepay_get_bank_name( '092' ) );
    }

    public function test_bank_name_unknown_returns_code(): void {
        $this->assertEquals( '999', nicepay_get_bank_name( '999' ) );
    }

    // -------------------------------------------------------
    // nicepay_log()
    // -------------------------------------------------------

    public function test_log_does_not_error_when_debug_off(): void {
        // WP_DEBUG is not defined in test env, so logging should silently skip
        // This test ensures no exceptions are thrown
        nicepay_log( 'Test message' );
        nicepay_log( 'Test with data', array( 'key' => 'value' ) );
        nicepay_log( 'Test with string data', 'string_data' );

        $this->assertTrue( true ); // No exception = pass
    }
}
