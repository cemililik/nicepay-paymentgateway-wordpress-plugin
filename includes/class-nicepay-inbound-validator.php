<?php
/**
 * Side-effect-free validation for NicePay authentication and approval returns.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds inbound NicePay messages to the merchant-owned transaction record.
 */
final class NicePay_Inbound_Validator {

	/** Supported transaction flows. */
	const FLOWS = array( 'standalone', 'woocommerce' );

	/**
	 * Validate and bind the browser authentication return.
	 *
	 * This method deliberately performs no write or approval claim. Callers must
	 * acquire the pending-to-approving claim only after this method succeeds.
	 *
	 * @param string      $flow    Expected transaction flow.
	 * @param array       $payload Raw NicePay authentication return fields.
	 * @param NicePay_API $api     Configured NicePay API instance.
	 * @return object|WP_Error Bound transaction row, or a validation error.
	 */
	public static function validate_auth_return( $flow, array $payload, NicePay_API $api ) {
		if ( ! in_array( $flow, self::FLOWS, true ) ) {
			return self::error( 'nicepay_inbound_invalid_flow', 'Invalid payment flow.' );
		}

		$required = array(
			'Moid',
			'MID',
			'Amt',
			'PayMethod',
			'AuthResultCode',
			'AuthToken',
			'TxTid',
			'Signature',
			'NextAppURL',
			'NetCancelURL',
		);
		$missing = self::first_missing_field( $payload, $required );
		if ( null !== $missing ) {
			return self::error(
				'nicepay_inbound_missing_field',
				'Missing required authentication field.',
				array( 'field' => $missing )
			);
		}

		$moid        = $payload['Moid'];
		$transaction = nicepay_get_transaction_by_moid( $moid, $flow );
		if ( ! is_object( $transaction ) ) {
			$unscoped = nicepay_get_transaction_by_moid( $moid );
			if ( is_object( $unscoped ) && self::transaction_value( $unscoped, 'flow' ) !== $flow ) {
				return self::error( 'nicepay_inbound_flow_mismatch', 'Payment flow does not match the transaction.' );
			}

			return self::error( 'nicepay_inbound_transaction_not_found', 'Payment transaction was not found.' );
		}

		if ( self::transaction_value( $transaction, 'flow' ) !== $flow ) {
			return self::error( 'nicepay_inbound_flow_mismatch', 'Payment flow does not match the transaction.' );
		}

		if ( 'pending' !== self::transaction_value( $transaction, 'status' ) ||
			'pending' !== self::transaction_value( $transaction, 'approval_state' ) ) {
			return self::error( 'nicepay_inbound_replay', 'Payment transaction is not pending approval.' );
		}

		$expiry = self::parse_utc_datetime( self::transaction_value( $transaction, 'offer_expires_at' ) );
		if ( false === $expiry ) {
			return self::error( 'nicepay_inbound_invalid_expiry', 'Payment transaction has no valid expiry.' );
		}
		if ( $expiry->getTimestamp() <= time() ) {
			return self::error( 'nicepay_inbound_offer_expired', 'Payment offer has expired.' );
		}

		$stored_mid = self::transaction_value( $transaction, 'mid' );
		$api_mid    = (string) $api->get_mid();
		if ( '' === $stored_mid ||
			! hash_equals( $stored_mid, $payload['MID'] ) ||
			! hash_equals( $stored_mid, $api_mid ) ) {
			return self::error( 'nicepay_inbound_mid_mismatch', 'Merchant ID does not match the transaction.' );
		}

		if ( 'KRW' !== strtoupper( self::transaction_value( $transaction, 'currency' ) ) ) {
			return self::error( 'nicepay_inbound_currency_mismatch', 'Transaction currency is not supported.' );
		}

		$stored_amount = nicepay_normalize_amount( self::transaction_value( $transaction, 'amount' ), 'KRW' );
		$posted_amount = nicepay_normalize_amount( $payload['Amt'], 'KRW' );
		if ( false === $stored_amount || false === $posted_amount || ! hash_equals( $stored_amount, $posted_amount ) ) {
			return self::error( 'nicepay_inbound_amount_mismatch', 'Payment amount does not match the transaction.' );
		}

		if ( ! self::method_matches_transaction( $transaction, $payload['PayMethod'] ) ) {
			return self::error( 'nicepay_inbound_method_mismatch', 'Payment method does not match the transaction.' );
		}

		if ( '0000' !== $payload['AuthResultCode'] ) {
			return self::error( 'nicepay_inbound_auth_failed', 'NicePay authentication was not successful.' );
		}

		if ( ! $api->verify_auth_signature( $payload['AuthToken'], $payload['Amt'], $payload['Signature'] ) ) {
			return self::error( 'nicepay_inbound_signature_invalid', 'NicePay authentication signature is invalid.' );
		}

		return $transaction;
	}

	/**
	 * Validate that an approval response belongs to the claimed transaction.
	 *
	 * ResultCode is intentionally not interpreted here. This method establishes
	 * identity and integrity only; the caller owns success/failure transitions.
	 *
	 * @param object      $transaction   Merchant-owned transaction row.
	 * @param string      $request_method Method authorized on the auth return.
	 * @param array       $result        Raw decoded approval response.
	 * @param NicePay_API $api           Configured NicePay API instance.
	 * @return object|WP_Error Bound transaction row, or a validation error.
	 */
	public static function validate_approval_response( $transaction, $request_method, array $result, NicePay_API $api ) {
		if ( ! is_object( $transaction ) ) {
			return self::error( 'nicepay_approval_invalid_transaction', 'Approval transaction is invalid.' );
		}

		$required = array( 'TID', 'MID', 'Moid', 'Amt', 'PayMethod', 'Signature' );
		$missing  = self::first_missing_field( $result, $required );
		if ( null !== $missing ) {
			return self::error(
				'nicepay_approval_missing_field',
				'Missing required approval field.',
				array( 'field' => $missing )
			);
		}

		$stored_mid = self::transaction_value( $transaction, 'mid' );
		if ( '' === $stored_mid ||
			! hash_equals( $stored_mid, $result['MID'] ) ||
			! hash_equals( $stored_mid, (string) $api->get_mid() ) ) {
			return self::error( 'nicepay_approval_mid_mismatch', 'Approval merchant ID does not match the transaction.' );
		}

		$stored_moid = self::transaction_value( $transaction, 'moid' );
		if ( '' === $stored_moid || ! hash_equals( $stored_moid, $result['Moid'] ) ) {
			return self::error( 'nicepay_approval_moid_mismatch', 'Approval order ID does not match the transaction.' );
		}

		if ( 'KRW' !== strtoupper( self::transaction_value( $transaction, 'currency' ) ) ) {
			return self::error( 'nicepay_approval_currency_mismatch', 'Approval transaction currency is not supported.' );
		}

		$stored_amount = nicepay_normalize_amount( self::transaction_value( $transaction, 'amount' ), 'KRW' );
		$result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );
		if ( false === $stored_amount || false === $result_amount || ! hash_equals( $stored_amount, $result_amount ) ) {
			return self::error( 'nicepay_approval_amount_mismatch', 'Approval amount does not match the transaction.' );
		}

		if ( ! is_string( $request_method ) || '' === trim( $request_method ) ||
			! hash_equals( $request_method, $result['PayMethod'] ) ||
			! self::method_matches_transaction( $transaction, $request_method ) ) {
			return self::error( 'nicepay_approval_method_mismatch', 'Approval payment method does not match the transaction.' );
		}

		$stored_tid = self::stored_tid( $transaction );
		if ( '' !== $stored_tid && ! hash_equals( $stored_tid, $result['TID'] ) ) {
			return self::error( 'nicepay_approval_tid_mismatch', 'Approval transaction ID does not match the authentication return.' );
		}

		if ( ! $api->verify_approval_signature( $result['TID'], $result['Amt'], $result['Signature'] ) ) {
			return self::error( 'nicepay_approval_signature_invalid', 'NicePay approval signature is invalid.' );
		}

		return $transaction;
	}

	/**
	 * Return the first missing or non-string required field.
	 *
	 * @param array    $payload Payload to inspect.
	 * @param string[] $fields  Required field names.
	 * @return string|null
	 */
	private static function first_missing_field( array $payload, array $fields ) {
		foreach ( $fields as $field ) {
			if ( ! isset( $payload[ $field ] ) ||
				! is_string( $payload[ $field ] ) ||
				'' === trim( $payload[ $field ] ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Read a scalar transaction property as a string.
	 *
	 * @param object $transaction Transaction row.
	 * @param string $property    Property name.
	 * @return string
	 */
	private static function transaction_value( $transaction, $property ) {
		if ( ! is_object( $transaction ) || ! isset( $transaction->{$property} ) || ! is_scalar( $transaction->{$property} ) ) {
			return '';
		}

		return (string) $transaction->{$property};
	}

	/**
	 * Determine whether a method is bound by expected_method/allowed_methods.
	 *
	 * @param object $transaction Transaction row.
	 * @param string $method      NicePay method code.
	 * @return bool
	 */
	private static function method_matches_transaction( $transaction, $method ) {
		if ( ! is_string( $method ) || '' === trim( $method ) ) {
			return false;
		}

		$expected = self::transaction_value( $transaction, 'expected_method' );
		if ( '' !== $expected ) {
			return hash_equals( $expected, $method );
		}

		$allowed = array();
		foreach ( explode( ',', self::transaction_value( $transaction, 'allowed_methods' ) ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $candidate ) {
				$allowed[] = $candidate;
			}
		}

		return in_array( $method, $allowed, true );
	}

	/**
	 * Read the auth-returned transaction ID from supported row shapes.
	 *
	 * @param object $transaction Transaction row.
	 * @return string
	 */
	private static function stored_tid( $transaction ) {
		foreach ( array( 'tid', 'tx_tid', 'TxTid' ) as $property ) {
			$value = self::transaction_value( $transaction, $property );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Parse a MySQL UTC datetime without inheriting the process timezone.
	 *
	 * @param string $value MySQL datetime.
	 * @return DateTimeImmutable|false
	 */
	private static function parse_utc_datetime( $value ) {
		if ( '' === $value ) {
			return false;
		}

		$timezone = new DateTimeZone( 'UTC' );
		$parsed   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();

		if ( false === $parsed ||
			( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ||
			$value !== $parsed->format( 'Y-m-d H:i:s' ) ) {
			return false;
		}

		return $parsed;
	}

	/**
	 * Build a namespaced validation error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Operator-safe message.
	 * @param mixed  $data    Optional error context.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $data = '' ) {
		return new WP_Error( $code, __( $message, 'nicepay-payment-gateway' ), $data );
	}
}
