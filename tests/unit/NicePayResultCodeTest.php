<?php
/**
 * Tests for result code logic
 *
 * Ensures success/failure code mapping is correct for all
 * payment methods and cancel operations per NicePay spec.
 */

use PHPUnit\Framework\TestCase;

class NicePayResultCodeTest extends TestCase {

    private NicePay_API $api;

    protected function setUp(): void {
        global $wp_options;
        $wp_options = array();

        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );

        $this->api = new NicePay_API();
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
    }

    // -------------------------------------------------------
    // Per-method success codes (from NicePay doc Section 6.4)
    // -------------------------------------------------------

    /**
     * @dataProvider successCodeProvider
     */
    public function test_success_code_per_method( string $method, string $code, bool $expected ): void {
        $this->assertEquals( $expected, $this->api->is_success_code( $code, $method ) );
    }

    public static function successCodeProvider(): array {
        return [
            // CARD success = 3001
            [ 'CARD', '3001', true ],
            [ 'CARD', '4000', false ],
            [ 'CARD', 'A000', false ],
            [ 'CARD', '0000', false ],
            [ 'CARD', '',     false ],

            // BANK success = 4000
            [ 'BANK', '4000', true ],
            [ 'BANK', '3001', false ],
            [ 'BANK', '4100', false ],

            // VBANK success = 4100
            [ 'VBANK', '4100', true ],
            [ 'VBANK', '4000', false ],
            [ 'VBANK', '3001', false ],

            // CELLPHONE success = A000
            [ 'CELLPHONE', 'A000', true ],
            [ 'CELLPHONE', '3001', false ],
            [ 'CELLPHONE', 'a000', false ], // case sensitive

            // SSG_BANK success = 0000
            [ 'SSG_BANK', '0000', true ],
            [ 'SSG_BANK', '3001', false ],

            // GIFT_CULT success = 0000
            [ 'GIFT_CULT', '0000', true ],
            [ 'GIFT_CULT', '3001', false ],
        ];
    }

    /**
     * @dataProvider genericSuccessCodeProvider
     */
    public function test_success_code_without_method( string $code, bool $expected ): void {
        $this->assertEquals( $expected, $this->api->is_success_code( $code ) );
    }

    public static function genericSuccessCodeProvider(): array {
        return [
            [ '3001', false ],
            [ '4000', false ],
            [ '4100', false ],
            [ 'A000', false ],
            [ '0000', false ],
            [ '2001', false ],  // cancel code, not approval
            [ '9999', false ],
            [ '',     false ],
            [ 'XXXX', false ],
        ];
    }

    // -------------------------------------------------------
    // Cancel success codes
    // -------------------------------------------------------

    /**
     * @dataProvider cancelCodeProvider
     */
    public function test_cancel_codes( string $code, bool $expected ): void {
        $this->assertEquals( $expected, $this->api->is_cancel_success( $code ) );
    }

    public static function cancelCodeProvider(): array {
        return [
            [ '2001', true ],
            [ '2211', true ],
            [ '2000', false ],
            [ '3001', false ],  // approval code, not cancel
            [ '4000', false ],
            [ '',     false ],
            [ '2001 ', false ], // trailing space
        ];
    }

    // -------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------

    public function test_success_code_unknown_method_fails_closed(): void {
        $this->assertFalse( $this->api->is_success_code( '3001', 'UNKNOWN_METHOD' ) );
        $this->assertFalse( $this->api->is_success_code( '0000', 'unknown' ) );
    }

    public function test_success_code_null_equivalent(): void {
        $this->assertFalse( $this->api->is_success_code( '0', 'CARD' ) );
    }
}
