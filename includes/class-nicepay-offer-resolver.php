<?php
/**
 * Server-authoritative NicePay offer resolution.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves saved standalone payment configurations into immutable commercial data.
 */
final class NicePay_Offer_Resolver {

	/** Verified payment methods supported by the standalone resolver. */
	const VERIFIED_METHODS = array( 'CARD', 'BANK', 'CELLPHONE' );

	/**
	 * Resolve a saved standalone configuration.
	 *
	 * Amount, currency, goods name and method always come from server-side policy.
	 * Buyer and presentation fields are deliberately excluded from the result and
	 * from the commercial fingerprint.
	 *
	 * @param mixed $config_id       Saved configuration identifier.
	 * @param mixed $requested_method Method selected by the buyer, if applicable.
	 * @return array<string,string>|WP_Error
	 */
	public static function resolve_standalone( $config_id, $requested_method = '' ) {
		if ( ! is_string( $config_id ) ||
			'' === $config_id ||
			strlen( $config_id ) > 64 ||
			! preg_match( '/\A[A-Za-z0-9_-]+\z/', $config_id ) ) {
			return new WP_Error(
				'nicepay_offer_invalid_config_id',
				__( 'Invalid payment configuration identifier.', 'nicepay-payment-gateway' )
			);
		}

		$config = nicepay_get_saved_shortcode( $config_id );
		if ( ! is_array( $config ) ) {
			return new WP_Error(
				'nicepay_offer_not_found',
				__( 'Payment configuration not found.', 'nicepay-payment-gateway' )
			);
		}

		$currency = isset( $config['currency'] ) && is_string( $config['currency'] )
			? strtoupper( trim( $config['currency'] ) )
			: '';

		if ( 'KRW' !== $currency ) {
			return new WP_Error(
				'nicepay_offer_invalid_currency',
				__( 'This payment currency is not supported.', 'nicepay-payment-gateway' )
			);
		}

		$raw_amount = isset( $config['amount'] ) ? $config['amount'] : null;
		$amount     = nicepay_normalize_amount( $raw_amount, $currency );
		if ( false === $amount ) {
			return new WP_Error(
				'nicepay_offer_invalid_amount',
				__( 'The saved payment amount is invalid.', 'nicepay-payment-gateway' )
			);
		}

		if ( ! isset( $config['goods_name'] ) || ! is_string( $config['goods_name'] ) ) {
			return new WP_Error(
				'nicepay_offer_invalid_goods_name',
				__( 'The saved goods name is invalid.', 'nicepay-payment-gateway' )
			);
		}

		$raw_goods_name = trim( $config['goods_name'] );
		if ( '' === $raw_goods_name || 1 !== preg_match( '//u', $raw_goods_name ) ) {
			return new WP_Error(
				'nicepay_offer_invalid_goods_name',
				__( 'The saved goods name is invalid.', 'nicepay-payment-gateway' )
			);
		}

		$goods_name = nicepay_utf8_byte_cut( sanitize_text_field( $raw_goods_name ), 40 );
		if ( '' === $goods_name ) {
			return new WP_Error(
				'nicepay_offer_invalid_goods_name',
				__( 'The saved goods name is invalid.', 'nicepay-payment-gateway' )
			);
		}

		$enabled_methods = self::enabled_verified_methods();
		$config_method    = self::normalize_method( isset( $config['pay_method'] ) ? $config['pay_method'] : '' );
		$request_method   = self::normalize_method( $requested_method );

		if ( false === $config_method || false === $request_method ) {
			return new WP_Error(
				'nicepay_offer_invalid_method',
				__( 'Invalid payment method.', 'nicepay-payment-gateway' )
			);
		}

		if ( '' !== $config_method ) {
			if ( ! in_array( $config_method, self::VERIFIED_METHODS, true ) ||
				! in_array( $config_method, $enabled_methods, true ) ) {
				return new WP_Error(
					'nicepay_offer_unsupported_method',
					__( 'The saved payment method is not available.', 'nicepay-payment-gateway' )
				);
			}

			if ( '' !== $request_method && $request_method !== $config_method ) {
				return new WP_Error(
					'nicepay_offer_method_mismatch',
					__( 'The selected payment method does not match this configuration.', 'nicepay-payment-gateway' )
				);
			}

			$expected_method = $config_method;
		} else {
			if ( '' === $request_method ||
				! in_array( $request_method, self::VERIFIED_METHODS, true ) ||
				! in_array( $request_method, $enabled_methods, true ) ) {
				return new WP_Error(
					'nicepay_offer_unsupported_method',
					__( 'The selected payment method is not available.', 'nicepay-payment-gateway' )
				);
			}

			$expected_method = $request_method;
		}

		$goods_class = '';
		if ( 'CELLPHONE' === $expected_method ) {
			$goods_class = isset( $config['goods_class'] ) && is_scalar( $config['goods_class'] )
				? (string) $config['goods_class']
				: '';
			if ( ! in_array( $goods_class, array( '0', '1' ), true ) ) {
				return new WP_Error(
					'nicepay_offer_invalid_goods_class',
					__( 'A content or physical-goods classification is required for mobile payments.', 'nicepay-payment-gateway' )
				);
			}
		}

		$commercial = array(
			'amount'          => $amount,
			'currency'        => $currency,
			'goods_name'      => $goods_name,
			'expected_method' => $expected_method,
			'goods_class'     => $goods_class,
		);

		return array(
			'source_ref'         => $config_id,
			'config_fingerprint' => hash( 'sha256', wp_json_encode( $commercial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'amount'             => $amount,
			'currency'           => $currency,
			'goods_name'         => $goods_name,
			'expected_method'    => $expected_method,
			'goods_class'        => $goods_class,
		);
	}

	/**
	 * Return the globally enabled subset of verified methods.
	 *
	 * @return string[]
	 */
	private static function enabled_verified_methods() {
		return nicepay_get_enabled_methods();
	}

	/**
	 * Normalize a payment method without accepting structured input.
	 *
	 * @param mixed $method Candidate method.
	 * @return string|false
	 */
	private static function normalize_method( $method ) {
		if ( null === $method ) {
			return '';
		}

		if ( ! is_string( $method ) ) {
			return false;
		}

		$method = strtoupper( trim( $method ) );
		if ( '' === $method ) {
			return '';
		}

		return preg_match( '/\A[A-Z0-9_]+\z/', $method ) ? $method : false;
	}

}
