<?php
/**
 * Tests for the server-authoritative standalone offer resolver.
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-nicepay-offer-resolver.php';

class NicePayOfferResolverTest extends TestCase {

	protected function setUp(): void {
		global $wp_options;
		$wp_options = array();

		update_option( 'nicepay_enabled_methods', array( 'CARD', 'BANK', 'CELLPHONE' ) );
		$this->save_config();
	}

	protected function tearDown(): void {
		global $wp_options;
		$wp_options = array();
	}

	public function test_resolves_commercial_fields_from_saved_configuration(): void {
		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'course_2026', $result['source_ref'] );
		$this->assertSame( '290000', $result['amount'] );
		$this->assertSame( 'KRW', $result['currency'] );
		$this->assertSame( 'Secure Course', $result['goods_name'] );
		$this->assertSame( 'CARD', $result['expected_method'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $result['config_fingerprint'] );
		$this->assertSame(
			hash(
				'sha256',
				wp_json_encode(
					array(
						'amount'          => '290000',
						'currency'        => 'KRW',
						'goods_name'      => 'Secure Course',
						'expected_method' => 'CARD',
						'goods_class'     => '',
					),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			),
			$result['config_fingerprint']
		);
	}

	public function test_resolver_api_has_no_client_amount_or_goods_parameters(): void {
		$method     = new ReflectionMethod( NicePay_Offer_Resolver::class, 'resolve_standalone' );
		$parameters = $method->getParameters();

		$this->assertCount( 2, $parameters );
		$this->assertSame( 'config_id', $parameters[0]->getName() );
		$this->assertSame( 'requested_method', $parameters[1]->getName() );
	}

	/**
	 * @dataProvider invalidConfigIdProvider
	 */
	public function test_rejects_invalid_configuration_identifiers( $config_id ): void {
		$result = NicePay_Offer_Resolver::resolve_standalone( $config_id, 'CARD' );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_config_id', $result );
	}

	public static function invalidConfigIdProvider(): array {
		return array(
			'empty'        => array( '' ),
			'path'         => array( '../course' ),
			'space'        => array( 'course 2026' ),
			'non-ascii'    => array( 'kurs-ü' ),
			'too long'     => array( str_repeat( 'a', 65 ) ),
			'not a string' => array( 123 ),
		);
	}

	public function test_accepts_a_64_byte_ascii_configuration_identifier(): void {
		$id = str_repeat( 'a', 64 );
		$this->save_config( array( 'id' => $id ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( $id, 'CARD' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $id, $result['source_ref'] );
	}

	public function test_rejects_unknown_configuration(): void {
		$result = NicePay_Offer_Resolver::resolve_standalone( 'missing', 'CARD' );

		$this->assertWpErrorCode( 'nicepay_offer_not_found', $result );
	}

	public function test_rejects_uncertified_currency(): void {
		$this->save_config( array( 'currency' => 'USD' ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_currency', $result );
	}

	/**
	 * @dataProvider invalidAmountProvider
	 */
	public function test_rejects_invalid_saved_amounts( $amount ): void {
		$this->save_config( array( 'amount' => $amount ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_amount', $result );
	}

	public static function invalidAmountProvider(): array {
		return array(
			'empty'      => array( '' ),
			'zero'       => array( '0' ),
			'negative'   => array( '-1' ),
			'scientific' => array( '1e3' ),
			'array'      => array( array( '1000' ) ),
		);
	}

	/**
	 * @dataProvider invalidGoodsNameProvider
	 */
	public function test_rejects_invalid_saved_goods_names( $goods_name ): void {
		$this->save_config( array( 'goods_name' => $goods_name ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_goods_name', $result );
	}

	public static function invalidGoodsNameProvider(): array {
		return array(
			'empty'        => array( '' ),
			'whitespace'   => array( '   ' ),
			'invalid utf8' => array( "\xC3\x28" ),
			'not a string' => array( array( 'Course' ) ),
		);
	}

	public function test_truncates_goods_name_to_40_utf8_bytes_without_splitting_a_character(): void {
		$this->save_config( array( 'goods_name' => str_repeat( '한', 20 ) ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertLessThanOrEqual( 40, strlen( $result['goods_name'] ) );
		$this->assertSame( 1, preg_match( '//u', $result['goods_name'] ) );
		$this->assertSame( str_repeat( '한', 13 ), $result['goods_name'] );
	}

	public function test_fixed_method_cannot_be_overridden_by_the_client(): void {
		$this->save_config( array( 'pay_method' => 'CARD' ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'BANK' );

		$this->assertWpErrorCode( 'nicepay_offer_method_mismatch', $result );
	}

	public function test_fixed_method_is_authoritative_when_client_sends_no_method(): void {
		$this->save_config( array( 'pay_method' => 'CELLPHONE' ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', '' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'CELLPHONE', $result['expected_method'] );
		$this->assertSame( '0', $result['goods_class'] );
	}

	public function test_mobile_payment_requires_an_explicit_goods_class(): void {
		$this->save_config(
			array(
				'pay_method'  => 'CELLPHONE',
				'goods_class' => '',
			)
		);

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CELLPHONE' );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_goods_class', $result );
	}

	public function test_goods_class_is_bound_into_mobile_offer_fingerprint(): void {
		$this->save_config( array( 'pay_method' => 'CELLPHONE', 'goods_class' => '0' ) );
		$content = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CELLPHONE' );
		$this->save_config( array( 'pay_method' => 'CELLPHONE', 'goods_class' => '1' ) );
		$physical = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CELLPHONE' );

		$this->assertFalse( is_wp_error( $content ) );
		$this->assertFalse( is_wp_error( $physical ) );
		$this->assertNotSame( $content['config_fingerprint'], $physical['config_fingerprint'] );
	}

	public function test_fixed_method_must_also_be_globally_enabled(): void {
		$this->save_config( array( 'pay_method' => 'BANK' ) );
		update_option( 'nicepay_enabled_methods', array( 'CARD' ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', '' );

		$this->assertWpErrorCode( 'nicepay_offer_unsupported_method', $result );
	}

	public function test_structured_requested_method_is_rejected(): void {
		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', array( 'CARD' ) );

		$this->assertWpErrorCode( 'nicepay_offer_invalid_method', $result );
	}

	public function test_open_method_configuration_requires_an_enabled_verified_selection(): void {
		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', '' );
		$this->assertWpErrorCode( 'nicepay_offer_unsupported_method', $result );

		update_option( 'nicepay_enabled_methods', array( 'CARD' ) );
		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'BANK' );
		$this->assertWpErrorCode( 'nicepay_offer_unsupported_method', $result );
	}

	/**
	 * @dataProvider failClosedMethodProvider
	 */
	public function test_unverified_methods_fail_closed_even_when_globally_enabled( $method ): void {
		update_option( 'nicepay_enabled_methods', array( 'CARD', $method ) );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', $method );

		$this->assertWpErrorCode( 'nicepay_offer_unsupported_method', $result );
	}

	public static function failClosedMethodProvider(): array {
		return array(
			'vbank'       => array( 'VBANK' ),
			'ssg bank'    => array( 'SSG_BANK' ),
			'culture cash'=> array( 'GIFT_CULT' ),
		);
	}

	public function test_only_conservative_card_method_is_enabled_when_option_is_absent(): void {
		delete_option( 'nicepay_enabled_methods' );

		$result = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'BANK' );

		$this->assertWpErrorCode( 'nicepay_offer_unsupported_method', $result );
		$this->assertSame( array( 'CARD' ), nicepay_get_enabled_methods() );
	}

	public function test_fingerprint_is_deterministic_and_excludes_buyer_and_presentation_fields(): void {
		$first = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );
		$this->assertFalse( is_wp_error( $first ) );

		$this->save_config(
			array(
				'buyer_name'   => 'Different Buyer',
				'buyer_email'  => 'different@example.com',
				'buyer_tel'    => '01099999999',
				'button_text'  => 'Different Button',
				'button_color' => '#000000',
				'display_mode' => 'modal',
			)
		);

		$second = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );
		$this->assertFalse( is_wp_error( $second ) );

		$this->assertSame( $first['config_fingerprint'], $second['config_fingerprint'] );
		$this->assertArrayNotHasKey( 'buyer_name', $second );
		$this->assertArrayNotHasKey( 'button_text', $second );
	}

	public function test_fingerprint_changes_when_a_canonical_commercial_field_changes(): void {
		$first = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );
		$this->assertFalse( is_wp_error( $first ) );

		$this->save_config( array( 'amount' => '290001' ) );
		$second = NicePay_Offer_Resolver::resolve_standalone( 'course_2026', 'CARD' );
		$this->assertFalse( is_wp_error( $second ) );

		$this->assertNotSame( $first['config_fingerprint'], $second['config_fingerprint'] );
	}

	/**
	 * Save one standalone configuration for a test.
	 *
	 * @param array<string,mixed> $overrides Config overrides.
	 * @return void
	 */
	private function save_config( array $overrides = array() ): void {
		$config = array_merge(
			array(
				'id'           => 'course_2026',
				'amount'       => '290000',
				'currency'     => 'KRW',
				'goods_name'   => 'Secure Course',
				'goods_class'  => '0',
				'pay_method'   => '',
				'buyer_name'   => 'Buyer',
				'buyer_email'  => 'buyer@example.com',
				'buyer_tel'    => '01012345678',
				'button_text'  => 'Pay now',
				'button_color' => '#2563eb',
				'display_mode' => 'inline',
			),
			$overrides
		);

		update_option( 'nicepay_saved_shortcodes', array( $config ) );
	}

	/**
	 * Assert a resolver error code.
	 *
	 * @param string $expected Expected error code.
	 * @param mixed  $actual   Resolver result.
	 * @return void
	 */
	private function assertWpErrorCode( string $expected, $actual ): void {
		$this->assertInstanceOf( WP_Error::class, $actual );
		$this->assertSame( $expected, $actual->get_error_code() );
	}
}
