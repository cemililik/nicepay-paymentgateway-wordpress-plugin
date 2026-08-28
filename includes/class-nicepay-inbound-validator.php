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
			'ReqReserved',
		);
		$result = true;
		if ( ! in_array( $flow, self::FLOWS, true ) ) {
			$result = self::error( 'nicepay_inbound_invalid_flow', __( 'Invalid payment flow.', 'nicepay-payment-gateway' ) );
		} else {
			$missing = self::first_missing_field( $payload, $required );
			if ( null !== $missing ) {
				$result = self::error(
				'nicepay_inbound_missing_field',
				__( 'Missing required authentication field.', 'nicepay-payment-gateway' ),
				array( 'field' => $missing )
				);
			}
		}

		if ( ! is_wp_error( $result ) ) {
			$result = self::find_auth_transaction( $payload['Moid'], $flow );
		}
		if ( ! is_wp_error( $result ) ) {
			$transaction = $result;
			foreach ( self::auth_validators() as $validator ) {
				$result = self::{$validator}( $transaction, $flow, $payload, $api );
				if ( is_wp_error( $result ) ) {
					break;
				}
			}
		}
		return is_wp_error( $result ) ? $result : $transaction;
	}

	/** @return string[] */
	private static function auth_validators() {
		return array( 'validate_auth_identity', 'validate_auth_state', 'validate_auth_commercial_context', 'validate_auth_proof' );
	}

	/** @return object|WP_Error */
	private static function find_auth_transaction( $moid, $flow ) {
		$transaction = nicepay_get_transaction_by_moid( $moid, $flow );
		if ( ! is_object( $transaction ) ) {
			$unscoped = nicepay_get_transaction_by_moid( $moid );
			$transaction = is_object( $unscoped ) && self::transaction_value( $unscoped, 'flow' ) !== $flow
				? self::error( 'nicepay_inbound_flow_mismatch', __( 'Payment flow does not match the transaction.', 'nicepay-payment-gateway' ) )
				: self::error( 'nicepay_inbound_transaction_not_found', __( 'Payment transaction was not found.', 'nicepay-payment-gateway' ) );
		}
		return $transaction;
	}

	/** @return true|WP_Error */
	private static function validate_auth_identity( $transaction, $flow, array $payload, NicePay_API $api ) {
		unset( $api );
		$result = true;
		$binding_hash = self::transaction_value( $transaction, 'binding_token_hash' );
		if ( self::transaction_value( $transaction, 'flow' ) !== $flow ) {
			$result = self::error( 'nicepay_inbound_flow_mismatch', __( 'Payment flow does not match the transaction.', 'nicepay-payment-gateway' ) );
		} elseif ( 64 !== strlen( $binding_hash ) || ! hash_equals( $binding_hash, hash( 'sha256', $payload['ReqReserved'] ) ) ) {
			$result = self::error( 'nicepay_inbound_binding_mismatch', __( 'Payment return binding does not match the transaction.', 'nicepay-payment-gateway' ) );
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function validate_auth_state( $transaction, $flow, array $payload, NicePay_API $api ) {
		unset( $flow, $payload, $api );
		$result = true;
		if ( 'pending' !== self::transaction_value( $transaction, 'status' ) ||
			'pending' !== self::transaction_value( $transaction, 'approval_state' ) ) {
			$result = self::error( 'nicepay_inbound_replay', __( 'Payment transaction is not pending approval.', 'nicepay-payment-gateway' ) );
		} else {
			$expiry = self::parse_utc_datetime( self::transaction_value( $transaction, 'offer_expires_at' ) );
			if ( false === $expiry ) {
				$result = self::error( 'nicepay_inbound_invalid_expiry', __( 'Payment transaction has no valid expiry.', 'nicepay-payment-gateway' ) );
			} elseif ( $expiry->getTimestamp() <= time() ) {
				$result = self::error( 'nicepay_inbound_offer_expired', __( 'Payment offer has expired.', 'nicepay-payment-gateway' ) );
			}
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function validate_auth_commercial_context( $transaction, $flow, array $payload, NicePay_API $api ) {
		unset( $flow );
		$result = true;
		$stored_mid = self::transaction_value( $transaction, 'mid' );
		$api_mid    = (string) $api->get_mid();
		if ( '' === $stored_mid ||
			! hash_equals( $stored_mid, $payload['MID'] ) ||
			! hash_equals( $stored_mid, $api_mid ) ) {
			$result = self::error( 'nicepay_inbound_mid_mismatch', __( 'Merchant ID does not match the transaction.', 'nicepay-payment-gateway' ) );
		} elseif ( 'KRW' !== strtoupper( self::transaction_value( $transaction, 'currency' ) ) ) {
			$result = self::error( 'nicepay_inbound_currency_mismatch', __( 'Transaction currency is not supported.', 'nicepay-payment-gateway' ) );
		} else {
			$stored_amount = nicepay_normalize_amount( self::transaction_value( $transaction, 'amount' ), 'KRW' );
			$posted_amount = nicepay_normalize_response_amount( $payload['Amt'], 'KRW' );
			if ( false === $stored_amount || false === $posted_amount || ! hash_equals( $stored_amount, $posted_amount ) ) {
				$result = self::error( 'nicepay_inbound_amount_mismatch', __( 'Payment amount does not match the transaction.', 'nicepay-payment-gateway' ) );
			} elseif ( ! self::method_matches_transaction( $transaction, $payload['PayMethod'] ) ) {
				$result = self::error( 'nicepay_inbound_method_mismatch', __( 'Payment method does not match the transaction.', 'nicepay-payment-gateway' ) );
			}
		}
		return $result;
	}

	/** @return true|WP_Error */
	private static function validate_auth_proof( $transaction, $flow, array $payload, NicePay_API $api ) {
		unset( $transaction, $flow );
		$result = true;
		if ( '0000' !== $payload['AuthResultCode'] ) {
			$result = self::error( 'nicepay_inbound_auth_failed', __( 'NicePay authentication was not successful.', 'nicepay-payment-gateway' ) );
		} elseif ( ! $api->verify_auth_signature( $payload['AuthToken'], $payload['Amt'], $payload['Signature'] ) ) {
			$result = self::error( 'nicepay_inbound_signature_invalid', __( 'NicePay authentication signature is invalid.', 'nicepay-payment-gateway' ) );
		}
		return $result;
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
		$validation = is_object( $transaction )
			? self::validate_approval_fields( $result )
			: self::error( 'nicepay_approval_invalid_transaction', __( 'Approval transaction is invalid.', 'nicepay-payment-gateway' ) );
		if ( ! is_wp_error( $validation ) ) {
			$validation = self::validate_approval_identity( $transaction, $result, $api );
		}
		if ( ! is_wp_error( $validation ) ) {
			$validation = self::validate_approval_commercial_context( $transaction, $request_method, $result );
		}
		if ( ! is_wp_error( $validation ) && ! $api->verify_approval_signature( $result['TID'], $result['Amt'], $result['Signature'] ) ) {
			$validation = self::error( 'nicepay_approval_signature_invalid', __( 'NicePay approval signature is invalid.', 'nicepay-payment-gateway' ) );
		}
		return is_wp_error( $validation ) ? $validation : $transaction;
	}

	/** @return true|WP_Error */
	private static function validate_approval_fields( array $result ) {
		$missing = self::first_missing_field( $result, array( 'TID', 'MID', 'Moid', 'Amt', 'PayMethod', 'Signature' ) );
		return null === $missing
			? true
			: self::error( 'nicepay_approval_missing_field', __( 'Missing required approval field.', 'nicepay-payment-gateway' ), array( 'field' => $missing ) );
	}

	/** @return true|WP_Error */
	private static function validate_approval_identity( $transaction, array $result, NicePay_API $api ) {
		$validation  = true;
		$stored_mid  = self::transaction_value( $transaction, 'mid' );
		$stored_moid = self::transaction_value( $transaction, 'moid' );
		$stored_tid  = self::stored_tid( $transaction );
		if ( '' === $stored_mid || ! hash_equals( $stored_mid, $result['MID'] ) || ! hash_equals( $stored_mid, (string) $api->get_mid() ) ) {
			$validation = self::error( 'nicepay_approval_mid_mismatch', __( 'Approval merchant ID does not match the transaction.', 'nicepay-payment-gateway' ) );
		} elseif ( '' === $stored_moid || ! hash_equals( $stored_moid, $result['Moid'] ) ) {
			$validation = self::error( 'nicepay_approval_moid_mismatch', __( 'Approval order ID does not match the transaction.', 'nicepay-payment-gateway' ) );
		} elseif ( '' !== $stored_tid && ! hash_equals( $stored_tid, $result['TID'] ) ) {
			$validation = self::error( 'nicepay_approval_tid_mismatch', __( 'Approval transaction ID does not match the authentication return.', 'nicepay-payment-gateway' ) );
		}
		return $validation;
	}

	/** @return true|WP_Error */
	private static function validate_approval_commercial_context( $transaction, $request_method, array $result ) {
		$validation = true;
		if ( 'KRW' !== strtoupper( self::transaction_value( $transaction, 'currency' ) ) ) {
			$validation = self::error( 'nicepay_approval_currency_mismatch', __( 'Approval transaction currency is not supported.', 'nicepay-payment-gateway' ) );
		} else {
			$stored_amount = nicepay_normalize_amount( self::transaction_value( $transaction, 'amount' ), 'KRW' );
			$result_amount = nicepay_normalize_response_amount( $result['Amt'], 'KRW' );
			if ( false === $stored_amount || false === $result_amount || ! hash_equals( $stored_amount, $result_amount ) ) {
				$validation = self::error( 'nicepay_approval_amount_mismatch', __( 'Approval amount does not match the transaction.', 'nicepay-payment-gateway' ) );
			} elseif ( ! is_string( $request_method ) || '' === trim( $request_method ) ||
				! hash_equals( $request_method, $result['PayMethod'] ) ||
				! self::method_matches_transaction( $transaction, $request_method ) ) {
				$validation = self::error( 'nicepay_approval_method_mismatch', __( 'Approval payment method does not match the transaction.', 'nicepay-payment-gateway' ) );
			}
		}
		return $validation;
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
		$parsed   = DateTimeImmutable::createFromFormat( '!' . NICEPAY_DB_DATETIME_FORMAT, $value, $timezone );
		$errors   = DateTimeImmutable::getLastErrors();

		if ( false === $parsed ||
			( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ||
			$value !== $parsed->format( NICEPAY_DB_DATETIME_FORMAT ) ) {
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
		return new WP_Error( $code, $message, $data );
	}
}
