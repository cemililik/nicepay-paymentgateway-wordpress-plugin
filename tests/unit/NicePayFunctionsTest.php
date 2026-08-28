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
        global $wp_options, $wp_translate_test_callback;
        $wp_options = array();
        $wp_translate_test_callback = null;

        update_option( 'nicepay_currency', 'KRW' );
    }

    protected function tearDown(): void {
        global $wp_options, $wp_translate_test_callback;
        $wp_options = array();
        $wp_translate_test_callback = null;
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

    public function test_get_amount_krw_rounds_half_up(): void {
        $this->assertSame( '10001', nicepay_get_amount( 10000.50, 'KRW' ) );
    }

    public function test_get_amount_krw_rounds_down_below_half(): void {
        $this->assertSame( '10000', nicepay_get_amount( '10000.49', 'KRW' ) );
    }

    public function test_get_amount_rejects_uncertified_currency(): void {
        $this->assertSame( '', nicepay_get_amount( '99.99', 'USD' ) );
    }

    /**
     * @dataProvider invalidPaymentAmountProvider
     */
    public function test_normalize_amount_rejects_invalid_payment_values( $amount ): void {
        $this->assertFalse( nicepay_normalize_amount( $amount, 'KRW' ) );
    }

    public static function invalidPaymentAmountProvider(): array {
        return array(
            'empty string'       => array( '' ),
            'zero'               => array( '0' ),
            'rounds down to zero'=> array( '0.49' ),
            'negative'           => array( '-1' ),
            'scientific notation'=> array( '1e3' ),
            'thousands separator'=> array( '1,000' ),
            'leading zero'       => array( '0100' ),
            'too large'          => array( '999999999999.5' ),
            'boolean'            => array( true ),
            'array'              => array( array( '1000' ) ),
            'not a number'       => array( NAN ),
            'infinity'           => array( INF ),
        );
    }

    public function test_normalize_amount_handles_large_values_without_float_conversion(): void {
        $this->assertSame( '999999999999', nicepay_normalize_amount( '999999999998.5', 'KRW' ) );
    }

    public function test_signed_response_amount_canonicalizes_fixed_width_zero_padding(): void {
        $this->assertSame( '1004', nicepay_normalize_response_amount( '000000001004', 'KRW' ) );
        $this->assertSame( '999999999999', nicepay_normalize_response_amount( '999999999999', 'KRW' ) );
    }

    /**
     * @dataProvider invalidResponseAmountProvider
     */
    public function test_signed_response_amount_rejects_non_protocol_values( $amount, string $currency = 'KRW' ): void {
        $this->assertFalse( nicepay_normalize_response_amount( $amount, $currency ) );
    }

    public static function invalidResponseAmountProvider(): array {
        return array(
            'zero'             => array( '000000000000' ),
            'too wide'         => array( '00000000001004' ),
            'decimal'          => array( '1004.00' ),
            'negative'         => array( '-1004' ),
            'whitespace'       => array( ' 1004' ),
            'non-string'       => array( 1004 ),
            'wrong currency'   => array( '1004', 'USD' ),
        );
    }

    public function test_only_krw_is_currently_certified_for_new_requests(): void {
        $this->assertTrue( nicepay_is_supported_currency( 'krw' ) );
        $this->assertFalse( nicepay_is_supported_currency( 'USD' ) );
    }

    public function test_woocommerce_goods_class_is_digital_only_when_every_item_is_virtual(): void {
        $digital_product = new class() {
            public function is_virtual() { return true; }
        };
        $physical_product = new class() {
            public function is_virtual() { return false; }
        };
        $digital_item = new class( $digital_product ) {
            private $product;
            public function __construct( $product ) { $this->product = $product; }
            public function get_product() { return $this->product; }
        };
        $physical_item = new class( $physical_product ) {
            private $product;
            public function __construct( $product ) { $this->product = $product; }
            public function get_product() { return $this->product; }
        };
        $digital_order = new class( $digital_item ) {
            private $item;
            public function __construct( $item ) { $this->item = $item; }
            public function get_items() { return array( $this->item ); }
        };
        $mixed_order = new class( $digital_item, $physical_item ) {
            private $items;
            public function __construct( $first, $second ) { $this->items = array( $first, $second ); }
            public function get_items() { return $this->items; }
        };

        $this->assertSame( '0', nicepay_get_woocommerce_goods_class( $digital_order ) );
        $this->assertSame( '1', nicepay_get_woocommerce_goods_class( $mixed_order ) );
    }

    public function test_enabled_methods_filter_uncertified_legacy_values(): void {
        update_option(
            'nicepay_enabled_methods',
            array( 'CARD', 'VBANK', 'CELLPHONE', 'GIFT_CULT', array( 'BANK' ), 'CARD' )
        );

        $this->assertSame( array( 'CARD', 'CELLPHONE' ), nicepay_get_enabled_methods() );
    }

    public function test_enabled_methods_fail_closed_for_malformed_storage(): void {
        update_option( 'nicepay_enabled_methods', 'CARD' );

        $this->assertSame( array(), nicepay_get_enabled_methods() );
    }

    public function test_enabled_methods_use_one_conservative_default_when_option_is_absent(): void {
        delete_option( 'nicepay_enabled_methods' );

        $this->assertSame( array( 'CARD' ), nicepay_default_enabled_methods() );
        $this->assertSame( nicepay_default_enabled_methods(), nicepay_get_enabled_methods() );
    }

    public function test_get_amount_default_currency(): void {
        update_option( 'nicepay_currency', 'KRW' );
        $this->assertEquals( '5000', nicepay_get_amount( 5000 ) );
    }

    public function test_get_amount_returns_string(): void {
        $result = nicepay_get_amount( 1000, 'KRW' );
        $this->assertIsString( $result );
    }

    public function test_button_contrast_color_uses_dark_text_on_light_backgrounds(): void {
        $this->assertSame( '#111827', nicepay_get_contrast_color( '#ffffff' ) );
        $this->assertSame( '#111827', nicepay_get_contrast_color( '#ffeb3b' ) );
    }

    public function test_button_contrast_color_uses_white_text_on_dark_or_invalid_backgrounds(): void {
        $this->assertSame( '#ffffff', nicepay_get_contrast_color( '#111827' ) );
        $this->assertSame( '#ffffff', nicepay_get_contrast_color( 'not-a-color' ) );
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

    /**
     * @dataProvider lifecycleStatusProvider
     */
    public function test_status_labels_cover_payment_lifecycle( string $status, string $label ): void {
        $this->assertSame( $label, nicepay_get_status_label( $status ) );
    }

    public static function lifecycleStatusProvider(): array {
        return array(
            'approval in progress' => array( 'approving', 'Approval in Progress' ),
            'partial refund'       => array( 'partially_refunded', 'Partially Refunded' ),
            'reconciliation'       => array( 'needs_reconciliation', 'Needs Reconciliation' ),
            'abandoned'            => array( 'abandoned', 'Abandoned' ),
            'expired'              => array( 'expired', 'Expired' ),
        );
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

    public function test_buyer_fields_enforce_protocol_byte_limits(): void {
        $valid = nicepay_validate_buyer_fields( '홍길동', 'buyer@example.com', '+82 10-1234-5678' );

        $this->assertIsArray( $valid );
        $this->assertSame( '홍길동', $valid['buyer_name'] );
        $this->assertInstanceOf(
            WP_Error::class,
            nicepay_validate_buyer_fields( str_repeat( '한', 11 ), 'buyer@example.com', '01012345678' )
        );
        $this->assertInstanceOf(
            WP_Error::class,
            nicepay_validate_buyer_fields( 'Buyer', str_repeat( 'a', 50 ) . '@example.com', '01012345678' )
        );
        $this->assertInstanceOf(
            WP_Error::class,
            nicepay_validate_buyer_fields( 'Buyer', 'buyer@example.com', '123456789012345678901' )
        );
    }

    public function test_optional_buyer_fields_allow_empty_values_but_validate_present_values(): void {
        $this->assertSame(
            array( 'buyer_name' => '', 'buyer_email' => '', 'buyer_tel' => '' ),
            nicepay_validate_buyer_fields( '', '', '', false )
        );
        $this->assertInstanceOf(
            WP_Error::class,
            nicepay_validate_buyer_fields( '', 'not-an-email', '', false )
        );
    }

    public function test_configuration_warnings_surface_test_mode_and_missing_live_credentials(): void {
        update_option( 'woocommerce_nicepay_settings', array( 'enabled' => 'yes' ) );
        update_option( 'nicepay_mode', 'test' );
        update_option( 'nicepay_test_mid', NICEPAY_TEST_MID );
        update_option( 'nicepay_test_merchant_key', NICEPAY_TEST_MERCHANT_KEY );

        $warnings = nicepay_get_configuration_warnings( new NicePay_API() );
        $this->assertSame( 'test_mode_enabled', $warnings[0]['code'] );

        update_option( 'nicepay_mode', 'live' );
        update_option( 'nicepay_live_mid', '' );
        update_option( 'nicepay_live_merchant_key', '' );
        $warnings = nicepay_get_configuration_warnings( new NicePay_API() );
        $this->assertSame( 'live_credentials_missing', $warnings[0]['code'] );
    }

    // -------------------------------------------------------
    // Saved shortcode defensive handling
    // -------------------------------------------------------

    public function test_default_presets_do_not_advertise_unsupported_subscriptions(): void {
        $ids = array_column( nicepay_get_default_presets(), 'id' );

        $this->assertNotContains( 'subscription', $ids );
    }

    public function test_default_presets_store_canonical_text_and_localize_at_runtime(): void {
        global $wp_options, $wp_translate_test_callback;

        $wp_translate_test_callback = static function ( $text ) {
            return 'translated:' . $text;
        };

        $runtime = nicepay_get_all_shortcodes();
        $stored  = $wp_options['nicepay_saved_shortcodes'];

        $this->assertSame( 'Quick Payment', $stored[0]['name'] );
        $this->assertSame( 'Quick Payment', $stored[0]['goods_name'] );
        $this->assertSame( 'Pay Now', $stored[0]['button_text'] );
        $this->assertSame( 1, $stored[0]['preset_version'] );
		$this->assertArrayNotHasKey( 'buyer_name', $stored[0] );
		$this->assertArrayNotHasKey( 'buyer_email', $stored[0] );
		$this->assertArrayNotHasKey( 'buyer_tel', $stored[0] );
        $this->assertSame( 'translated:Quick Payment', $runtime[0]['name'] );
        $this->assertSame( 'translated:Quick Payment', $runtime[0]['goods_name'] );
        $this->assertSame( 'translated:Pay Now', $runtime[0]['button_text'] );
    }

    public function test_localized_presets_are_canonicalized_before_storage(): void {
        global $wp_translate_test_callback;

        $wp_translate_test_callback = static function ( $text ) {
            return 'translated:' . $text;
        };

        $runtime = nicepay_get_all_shortcodes();
        $stored  = nicepay_prepare_shortcodes_for_storage( $runtime );

        $this->assertSame( 'Quick Payment', $stored[0]['name'] );
        $this->assertSame( 'Quick Payment', $stored[0]['goods_name'] );
        $this->assertSame( 'Pay Now', $stored[0]['button_text'] );
    }

    public function test_unversioned_legacy_preset_is_not_overwritten(): void {
        $legacy = array(
            'id'          => 'quick-payment',
            'name'        => 'Merchant custom title',
            'goods_name'  => 'Merchant custom goods',
            'button_text' => 'Merchant custom button',
            'is_preset'   => true,
        );

        update_option( 'nicepay_saved_shortcodes', array( $legacy ) );

        $this->assertSame( array( $legacy ), nicepay_get_all_shortcodes() );
        $this->assertSame( array( $legacy ), nicepay_prepare_shortcodes_for_storage( array( $legacy ) ) );
    }

	public function test_legacy_saved_offer_contact_is_scrubbed_from_reusable_configuration(): void {
		global $wp_options;

		$legacy = array(
			'id'          => 'merchant-offer',
			'name'        => 'Merchant offer',
			'buyer_name'  => 'Legacy Buyer',
			'buyer_email' => 'legacy@example.com',
			'buyer_tel'   => '01012345678',
		);
		update_option( 'nicepay_saved_shortcodes', array( $legacy ) );

		$result = nicepay_get_all_shortcodes();

		$this->assertArrayNotHasKey( 'buyer_name', $result[0] );
		$this->assertArrayNotHasKey( 'buyer_email', $result[0] );
		$this->assertArrayNotHasKey( 'buyer_tel', $result[0] );
		$this->assertSame( $result, $wp_options['nicepay_saved_shortcodes'] );
	}

    public function test_saved_shortcodes_reject_malformed_option_values(): void {
        update_option( 'nicepay_saved_shortcodes', 'not-an-array' );

        $this->assertSame( array(), nicepay_get_all_shortcodes() );
    }

    public function test_saved_shortcodes_filter_malformed_entries(): void {
        update_option(
            'nicepay_saved_shortcodes',
            array(
                array( 'id' => 'valid' ),
                'invalid',
                null,
                array(),
                array( 'id' => array( 'structured' ) ),
                array( 'id' => '../unsafe' ),
                array( 'id' => str_repeat( 'a', 65 ) ),
            )
        );

        $this->assertSame( array( array( 'id' => 'valid' ) ), nicepay_get_all_shortcodes() );
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

    public function test_log_redaction_is_recursive_and_strips_forged_newlines(): void {
        $redacted = nicepay_redact_log_data(
            array(
                'AuthToken' => 'secret-token',
                'nested'    => array(
                    'BuyerEmail' => 'buyer@example.com',
                    'ResultMsg'  => "line one\r\nforged line",
                    'auth_token' => 'snake-case-secret',
                    'sign_data'  => 'snake-case-signature',
                    'card_number'=> '4111111111111111',
                ),
                'refund_acct_no' => '1234567890',
                'AuthCode'       => 'authorization-code',
                'safe'      => '3001',
            )
        );

        $this->assertSame( '[REDACTED]', $redacted['AuthToken'] );
        $this->assertSame( '[REDACTED]', $redacted['nested']['BuyerEmail'] );
        $this->assertSame( '[REDACTED]', $redacted['nested']['auth_token'] );
        $this->assertSame( '[REDACTED]', $redacted['nested']['sign_data'] );
        $this->assertSame( '[REDACTED]', $redacted['nested']['card_number'] );
        $this->assertSame( '[REDACTED]', $redacted['refund_acct_no'] );
        $this->assertSame( '[REDACTED]', $redacted['AuthCode'] );
        $this->assertSame( 'line one  forged line', $redacted['nested']['ResultMsg'] );
        $this->assertSame( '3001', $redacted['safe'] );
    }

    public function test_payment_data_filter_excludes_credentials_pan_and_buyer_pii(): void {
        $filtered = nicepay_filter_payment_data(
            array(
                'ResultCode' => '3001',
                'ResultMsg'  => "approved\nforged",
                'TID'        => 'safe-tid',
                'Signature'  => 'secret-signature',
                'AuthToken'  => 'secret-token',
                'CardNo'     => '4111111111111111',
                'VbankNum'   => '123456789',
                'BuyerEmail' => 'buyer@example.com',
            )
        );

        $this->assertSame( '3001', $filtered['ResultCode'] );
        $this->assertSame( 'approved forged', $filtered['ResultMsg'] );
        $this->assertSame( 'safe-tid', $filtered['TID'] );
        $this->assertArrayNotHasKey( 'Signature', $filtered );
        $this->assertArrayNotHasKey( 'AuthToken', $filtered );
        $this->assertArrayNotHasKey( 'CardNo', $filtered );
        $this->assertArrayNotHasKey( 'VbankNum', $filtered );
        $this->assertArrayNotHasKey( 'BuyerEmail', $filtered );
    }

    public function test_refund_capability_fields_are_strictly_allowlisted(): void {
        $this->assertSame(
            array( 'cc_part_cl' => '1', 'clickpay_cl' => '7', 'card_type' => '01' ),
            nicepay_get_refund_capability_fields(
                array( 'CcPartCl' => '1', 'ClickpayCl' => '7', 'CardType' => '01', 'CardNo' => 'secret' )
            )
        );
        $this->assertSame(
            array(),
            nicepay_get_refund_capability_fields(
                array( 'CcPartCl' => 'yes', 'ClickpayCl' => array( '7' ), 'CardType' => '99' )
            )
        );
    }

    public function test_ledger_amount_math_is_exact_without_float_conversion(): void {
        $this->assertSame( '0', nicepay_normalize_ledger_amount( '0.00' ) );
        $this->assertSame( '999999999999', nicepay_normalize_ledger_amount( '999999999999.00' ) );
        $this->assertSame( 1, nicepay_compare_integer_amounts( '999999999999', '999999999998' ) );
        $this->assertSame( 0, nicepay_compare_integer_amounts( '00042', '42' ) );
        $this->assertSame( -1, nicepay_compare_integer_amounts( '41', '42' ) );
        $this->assertSame( '1000000000000', nicepay_add_integer_amounts( '999999999999', '1' ) );
        $this->assertSame( '999999999999', nicepay_subtract_integer_amounts( '1000000000000', '1' ) );
        $this->assertSame( '0', nicepay_subtract_integer_amounts( '42', '42' ) );
        $this->assertFalse( nicepay_subtract_integer_amounts( '41', '42' ) );
    }
}
