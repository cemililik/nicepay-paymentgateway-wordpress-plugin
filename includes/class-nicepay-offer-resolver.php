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
		$result = self::validate_config_id( $config_id );
		if ( ! is_wp_error( $result ) ) {
			$config = nicepay_get_saved_shortcode( $config_id );
			$result = is_array( $config )
				? self::resolve_commercial_fields( $config, $requested_method )
				: new WP_Error( 'nicepay_offer_not_found', __( 'Payment configuration not found.', 'nicepay-payment-gateway' ) );
		}

		if ( ! is_wp_error( $result ) ) {
			$commercial = $result;
			$result = array(
			'source_ref'         => $config_id,
			'config_fingerprint' => hash( 'sha256', wp_json_encode( $commercial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'amount'             => $commercial['amount'],
			'currency'           => $commercial['currency'],
			'goods_name'         => $commercial['goods_name'],
			'expected_method'    => $commercial['expected_method'],
			'goods_class'        => $commercial['goods_class'],
			);
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function validate_config_id( $config_id ) {
		$valid = is_string( $config_id ) && '' !== $config_id && strlen( $config_id ) <= 64 &&
			1 === preg_match( '/\A[A-Za-z0-9_-]+\z/', $config_id );
		return $valid
			? true
			: new WP_Error( 'nicepay_offer_invalid_config_id', __( 'Invalid payment configuration identifier.', 'nicepay-payment-gateway' ) );
	}

	/** @return array<string,string>|WP_Error */
	private static function resolve_commercial_fields( array $config, $requested_method ) {
		$result = self::resolve_product( $config );
		if ( ! is_wp_error( $result ) ) {
			$method = self::resolve_method( $config, $requested_method );
			if ( is_wp_error( $method ) ) {
				$result = $method;
			} else {
				$goods_class = self::resolve_goods_class( $config, $method );
				if ( is_wp_error( $goods_class ) ) {
					$result = $goods_class;
				} else {
					$result = array_merge( $result, array( 'expected_method' => $method, 'goods_class' => $goods_class ) );
				}
			}
		}
		return $result;
	}

	/** @return array<string,string>|WP_Error */
	private static function resolve_product( array $config ) {
		$currency = isset( $config['currency'] ) && is_string( $config['currency'] )
			? strtoupper( trim( $config['currency'] ) )
			: '';
		$raw_amount = isset( $config['amount'] ) ? $config['amount'] : null;
		$amount = 'KRW' === $currency
			? nicepay_normalize_amount( $raw_amount, $currency )
			: false;
		$raw_goods = isset( $config['goods_name'] ) && is_string( $config['goods_name'] )
			? trim( $config['goods_name'] )
			: '';
		$goods_name = '' !== $raw_goods && 1 === preg_match( '//u', $raw_goods )
			? nicepay_utf8_byte_cut( sanitize_text_field( $raw_goods ), 40 )
			: '';

		$result = array( 'amount' => $amount, 'currency' => $currency, 'goods_name' => $goods_name );
		if ( 'KRW' !== $currency ) {
			$result = new WP_Error( 'nicepay_offer_invalid_currency', __( 'This payment currency is not supported.', 'nicepay-payment-gateway' ) );
		} elseif ( false === $amount ) {
			$result = new WP_Error( 'nicepay_offer_invalid_amount', __( 'The saved payment amount is invalid.', 'nicepay-payment-gateway' ) );
		} elseif ( '' === $goods_name ) {
			$result = new WP_Error( 'nicepay_offer_invalid_goods_name', __( 'The saved goods name is invalid.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return string|WP_Error */
	private static function resolve_method( array $config, $requested_method ) {
		$enabled = self::enabled_verified_methods();
		$saved   = self::normalize_method( isset( $config['pay_method'] ) ? $config['pay_method'] : '' );
		$chosen  = self::normalize_method( $requested_method );
		$result  = '' !== $saved ? $saved : $chosen;

		if ( false === $saved || false === $chosen ) {
			$result = new WP_Error( 'nicepay_offer_invalid_method', __( 'Invalid payment method.', 'nicepay-payment-gateway' ) );
		} elseif ( '' !== $saved && '' !== $chosen && $chosen !== $saved ) {
			$result = new WP_Error( 'nicepay_offer_method_mismatch', __( 'The selected payment method does not match this configuration.', 'nicepay-payment-gateway' ) );
		} elseif ( '' === $result || ! in_array( $result, self::VERIFIED_METHODS, true ) || ! in_array( $result, $enabled, true ) ) {
			$message = '' !== $saved
				? __( 'The saved payment method is not available.', 'nicepay-payment-gateway' )
				: __( 'The selected payment method is not available.', 'nicepay-payment-gateway' );
			$result = new WP_Error( 'nicepay_offer_unsupported_method', $message );
		}
		return $result;
	}

	/** @return string|WP_Error */
	private static function resolve_goods_class( array $config, $method ) {
		$goods_class = 'CELLPHONE' === $method && isset( $config['goods_class'] ) && is_scalar( $config['goods_class'] )
			? (string) $config['goods_class']
			: '';
		return 'CELLPHONE' !== $method || in_array( $goods_class, array( '0', '1' ), true )
			? $goods_class
			: new WP_Error(
				'nicepay_offer_invalid_goods_class',
				__( 'A content or physical-goods classification is required for mobile payments.', 'nicepay-payment-gateway' )
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
		$result = false;
		if ( null === $method ) {
			$result = '';
		} elseif ( is_string( $method ) ) {
			$method = strtoupper( trim( $method ) );
			$result = '' === $method || preg_match( '/\A[A-Z0-9_]+\z/', $method ) ? $method : false;
		}
		return $result;
	}

}
