<?php
/**
 * WooCommerce NicePay Payment Gateway
 *
 * Integrates NicePay payment gateway with WooCommerce checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-nicepay-refund-processor.php';
require_once __DIR__ . '/class-nicepay-woocommerce-return-handler.php';

class WC_Gateway_NicePay extends WC_Payment_Gateway {

    /** @var NicePay_API */
    private $api;

    public function __construct( $api = null ) {
        $this->id                 = 'nicepay';
        $this->method_title       = __( 'NicePay', 'nicepay-payment-gateway' );
        $this->method_description = __( 'Configure NicePay card, bank transfer, and mobile payment flows. Validate the merchant account in the sandbox before enabling payments.', 'nicepay-payment-gateway' );
        $this->has_fields         = false;
        $this->supports           = array( 'products', 'refunds' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'NicePay Payment', 'nicepay-payment-gateway' ) );
        $this->description = $this->get_option( 'description', __( 'Pay securely via NicePay.', 'nicepay-payment-gateway' ) );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

		$this->api = $api instanceof NicePay_API ? $api : new NicePay_API();

        // Hooks
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_api_nicepay_return', array( $this, 'handle_return' ) );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'nicepay-payment-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable NicePay Payment', 'nicepay-payment-gateway' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'nicepay-payment-gateway' ),
                'type'        => 'text',
                'description' => __( 'Payment method title shown at checkout.', 'nicepay-payment-gateway' ),
                'default'     => __( 'NicePay Payment', 'nicepay-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'nicepay-payment-gateway' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description shown at checkout.', 'nicepay-payment-gateway' ),
                'default'     => __( 'Pay securely via NicePay (Credit Card, Bank Transfer, or Mobile).', 'nicepay-payment-gateway' ),
                'desc_tip'    => true,
            ),
            'settings_notice' => array(
                'title'       => __( 'API Settings', 'nicepay-payment-gateway' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: settings page URL */
                    __( 'Configure MID, Merchant Key, and other API settings on the <a href="%s">NicePay Settings</a> page.', 'nicepay-payment-gateway' ),
                    admin_url( 'admin.php?page=nicepay-settings' )
                ),
            ),
        );
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
		$test_mode_allowed = 'test' !== $this->api->get_mode() ||
			(bool) apply_filters( 'nicepay_allow_test_mode_checkout', false );

		return 'yes' === $this->enabled &&
			NicePay_Installer::is_current() &&
			(bool) $this->api->get_mid() &&
			(bool) $this->api->get_merchant_key() &&
			$test_mode_allowed &&
			! empty( nicepay_get_enabled_methods() ) &&
			nicepay_is_supported_currency( get_woocommerce_currency() ) &&
			0 === (int) wc_get_price_decimals() &&
			is_ssl();
    }

    /**
     * Process payment - redirect to receipt page for NicePay form
     */
    public function process_payment( $order_id ) {
		$order  = wc_get_order( $order_id );
		$error  = $this->payment_validation_error( $order, $order_id );
		$result = array( 'result' => 'failure' );

		if ( is_wp_error( $error ) ) {
			wc_add_notice( $error->get_error_message(), 'error' );
			nicepay_log( $error->get_error_code(), $error->get_error_data(), 'warning' );
		} else {
			$active = nicepay_get_active_woocommerce_transaction( $order->get_id() );
			if ( $active && $this->recover_paid_order_from_ledger( $order, $active ) ) {
				$result = array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			} elseif ( ! $order->needs_payment() ) {
				wc_add_notice( __( 'This order no longer requires payment.', 'nicepay-payment-gateway' ), 'error' );
				nicepay_log( 'NicePay process_payment rejected a non-payable order', array( 'order_id' => $order->get_id() ), 'warning' );
			} else {
				$result = array( 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) );
			}
		}

		return $result;
    }

	/** Return the first checkout validation error, if any. */
	private function payment_validation_error( $order, $order_id ) {
		$error = null;
		if ( ! $order ) {
			$error = new WP_Error(
				'NicePay process_payment could not load order',
				__( 'The order could not be loaded. Please return to checkout and try again.', 'nicepay-payment-gateway' ),
				array( 'order_id' => absint( $order_id ) )
			);
		} elseif ( 'nicepay' !== $order->get_payment_method() ) {
			$error = new WP_Error(
				'NicePay process_payment rejected a payment-method mismatch',
				__( 'NicePay is not selected for this order.', 'nicepay-payment-gateway' ),
				array( 'order_id' => $order->get_id() )
			);
		} elseif ( ! nicepay_is_supported_currency( $order->get_currency() ) ) {
			$error = new WP_Error(
				'NicePay process_payment rejected unsupported order currency',
				__( 'NicePay supports KRW orders only.', 'nicepay-payment-gateway' ),
				array( 'order_id' => $order->get_id(), 'currency' => $order->get_currency() )
			);
		} elseif ( '' === nicepay_get_amount( $order->get_total(), $order->get_currency() ) ) {
			$error = new WP_Error(
				'NicePay process_payment rejected a non-integer KRW total',
				__( 'The order total must be a positive whole KRW amount.', 'nicepay-payment-gateway' ),
				array( 'order_id' => $order->get_id() )
			);
		} elseif ( ! $this->is_available() ) {
			$error = new WP_Error(
				'NicePay process_payment rejected an unavailable gateway',
				__( 'NicePay is not currently available. Please choose another payment method or contact the merchant.', 'nicepay-payment-gateway' ),
				array( 'order_id' => $order->get_id() )
			);
		}
		return $error;
	}

	/**
	 * Validate buyer fields during checkout, before the receipt page is reached.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		$first_name = isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '';
		$last_name  = isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '';
		$email      = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		$phone      = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$buyer      = nicepay_validate_buyer_fields( trim( $first_name . ' ' . $last_name ), $email, $phone );

		if ( is_wp_error( $buyer ) ) {
			wc_add_notice( $buyer->get_error_message(), 'error' );
			return false;
		}

		return true;
	}

    /**
     * Display payment form on receipt page
     */
    public function receipt_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'nicepay-payment-gateway' ) . '</p>';
		} else {
			$request_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
			$active      = nicepay_get_active_woocommerce_transaction( $order->get_id() );
			if ( '' === $request_key || ! hash_equals( (string) $order->get_order_key(), (string) $request_key ) ) {
				echo '<p>' . esc_html__( 'This payment link is invalid or expired.', 'nicepay-payment-gateway' ) . '</p>';
			} elseif ( 'nicepay' !== $order->get_payment_method() || ! $order->needs_payment() ) {
				echo '<p>' . esc_html__( 'This order is not eligible for a new NicePay payment.', 'nicepay-payment-gateway' ) . '</p>';
			} elseif ( $active && $this->recover_paid_order_from_ledger( $order, $active ) ) {
				echo '<p>' . esc_html__( 'This NicePay payment was already confirmed and the order has been recovered.', 'nicepay-payment-gateway' ) . '</p>';
				echo '<a href="' . esc_url( $this->get_return_url( $order ) ) . '">' . esc_html__( 'View Order', 'nicepay-payment-gateway' ) . '</a>';
			} else {
				$this->generate_payment_form( $order );
			}
        }
    }

    /**
     * Complete a WooCommerce order from a verified paid ledger row after a
     * previous request stopped between capture persistence and payment_complete.
     *
     * @param WC_Order $order       WooCommerce order.
     * @param object   $transaction NicePay transaction row.
     * @return bool
     */
    private function recover_paid_order_from_ledger( $order, $transaction ) {
		$recovered = false;
		$valid_row = is_object( $transaction ) &&
			'paid' === (string) $transaction->status &&
			'approved' === (string) $transaction->approval_state &&
			! empty( $transaction->tid ) &&
			(int) $transaction->wc_order_id === (int) $order->get_id();
		if ( $valid_row ) {
			$stored_amount = nicepay_normalize_amount( $transaction->captured_amount, (string) $transaction->currency );
			$order_amount  = nicepay_normalize_amount( $order->get_total(), $order->get_currency() );
			$valid_row     = false !== $stored_amount && false !== $order_amount &&
				hash_equals( $stored_amount, $order_amount ) &&
				hash_equals( (string) $transaction->currency, (string) $order->get_currency() );
		}
		if ( $valid_row ) {
			try {
				$order->update_meta_data( '_nicepay_tid', (string) $transaction->tid );
				$order->update_meta_data( '_nicepay_pay_method', (string) $transaction->payment_method );
				$order->payment_complete( (string) $transaction->tid );
				$order->add_order_note( __( 'WooCommerce order completion was recovered from the verified NicePay payment ledger.', 'nicepay-payment-gateway' ) );
				$order->save();
				$recovered = true;
			} catch ( Throwable $throwable ) {
				nicepay_update_transaction( $transaction->id, array(
					'status'                => 'needs_reconciliation',
					'reconciliation_status' => 'required',
					'reconciliation_note'   => 'woocommerce_payment_recovery_failed',
				) );
				nicepay_log( 'WooCommerce payment recovery failed', $transaction->id, 'error' );
			}
		}
		if ( $recovered ) {
			nicepay_update_transaction( $transaction->id, array( 'active_attempt_key' => null ) );
		}
		return $recovered;
    }

    /**
     * Generate NicePay payment form
     */
    private function generate_payment_form( $order ) {
		$context = $this->payment_form_context( $order );
		if ( ! is_wp_error( $context ) ) {
			$context = $this->create_payment_attempt( $order, $context );
		}
		if ( ! is_wp_error( $context ) ) {
			$form_data = $this->payment_form_data( $order, $context );
			if ( ! is_wp_error( $form_data ) ) {
				$enabled_methods = $context['enabled_methods'];
				$is_test_mode    = $this->api->is_test_mode();
				include NICEPAY_PLUGIN_DIR . 'templates/payment-form.php';
			} else {
				$this->render_payment_form_error( $form_data );
			}
		} else {
			$this->render_payment_form_error( $context );
		}
    }

	/** Validate and normalize the immutable payment-form inputs. */
	private function payment_form_context( $order ) {
		$items           = $order->get_items();
		$edi_date        = $this->api->generate_edi_date();
		$amount          = nicepay_get_amount( $order->get_total(), $order->get_currency() );
		$binding_token   = nicepay_generate_payment_binding_token();
		$enabled_methods = nicepay_get_enabled_methods();
		$goods_name      = '';
		$error           = null;

		if ( ! empty( $items ) ) {
			$first_item = reset( $items );
			$goods_name = $first_item->get_name();
			$extra      = count( $items ) - 1;
			if ( $extra > 0 ) {
				$goods_name .= sprintf(
					/* translators: %d: number of additional items */
					_n( ' and %d more item', ' and %d more items', $extra, 'nicepay-payment-gateway' ),
					$extra
				);
			}
		}
		$buyer = nicepay_validate_buyer_fields(
			(string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name(),
			(string) $order->get_billing_email(),
			(string) $order->get_billing_phone()
		);

		if ( '' === $amount || ! $this->api->get_mid() || ! $this->api->get_merchant_key() ) {
			$error = new WP_Error( 'nicepay_form_configuration', __( 'NicePay is not configured for this order.', 'nicepay-payment-gateway' ) );
		} elseif ( false === $binding_token ) {
			$error = new WP_Error( 'nicepay_form_binding', __( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ), array( 'checkout_link' => true ) );
		} elseif ( empty( $enabled_methods ) ) {
			$error = new WP_Error( 'nicepay_form_methods', __( 'No certified NicePay payment method is enabled.', 'nicepay-payment-gateway' ) );
		} elseif ( is_wp_error( $buyer ) ) {
			$error = new WP_Error( 'nicepay_form_buyer', $buyer->get_error_message(), array( 'checkout_link' => true ) );
		}

		$result = $error;
		if ( ! $error ) {
			$result = array(
				'edi_date'        => $edi_date,
				'moid'            => $this->api->generate_moid( 'WC' . $order->get_id() ),
				'amount'          => $amount,
				'currency'        => $order->get_currency(),
				'binding_token'   => $binding_token,
				'enabled_methods' => $enabled_methods,
				'goods_name'      => nicepay_utf8_byte_cut( sanitize_text_field( $goods_name ), 40 ),
				'buyer'           => $buyer,
			);
		}
		return $result;
	}

	/** Claim a unique attempt and persist the order presentation metadata. */
	private function create_payment_attempt( $order, $context ) {
		$result = $context;
		if ( ! nicepay_abandon_pending_transactions( 'woocommerce', (string) $order->get_id() ) ) {
			nicepay_log( 'Could not retire an older WooCommerce payment attempt', $order->get_id(), 'error' );
			$result = new WP_Error( 'nicepay_form_attempt', __( 'NicePay could not prepare a unique payment attempt.', 'nicepay-payment-gateway' ) );
		} else {
			$tx_id = nicepay_save_transaction( array(
				'order_id'           => $context['moid'],
				'wc_order_id'        => $order->get_id(),
				'moid'               => $context['moid'],
				'flow'               => 'woocommerce',
				'source_ref'         => (string) $order->get_id(),
				'active_attempt_key' => 'woocommerce:' . (string) $order->get_id(),
				'config_fingerprint' => hash( 'sha256', wp_json_encode( array( $order->get_id(), $context['amount'], $context['currency'], $context['enabled_methods'] ) ) ),
				'allowed_methods'    => implode( ',', $context['enabled_methods'] ),
				'binding_token_hash' => hash( 'sha256', $context['binding_token'] ),
				'wc_order_key_hash'  => hash( 'sha256', (string) $order->get_order_key() ),
				'edi_date'           => $context['edi_date'],
				'offer_expires_at'   => gmdate( NICEPAY_DB_DATETIME_FORMAT, time() + 30 * MINUTE_IN_SECONDS ),
				'mid'                => $this->api->get_mid(),
				'mode'               => $this->api->get_mode(),
				'currency'           => $context['currency'],
				'amount'             => $context['amount'],
				'status'             => 'pending',
				'approval_state'     => 'pending',
				'buyer_name'         => $context['buyer']['buyer_name'],
				'buyer_email'        => $context['buyer']['buyer_email'],
				'buyer_tel'          => $context['buyer']['buyer_tel'],
				'goods_name'         => $context['goods_name'],
			) );
			if ( false === $tx_id ) {
				nicepay_log( 'Failed to save initial transaction for order', $order->get_id(), 'error' );
				$order->add_order_note( __( 'NicePay could not initialize a payment attempt. The order remains payable.', 'nicepay-payment-gateway' ) );
				wc_add_notice( __( 'Payment initialization failed. Please try again.', 'nicepay-payment-gateway' ), 'error' );
				$result = new WP_Error( 'nicepay_form_save', __( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ), array( 'checkout_link' => true ) );
			} else {
				$context['tx_id'] = $tx_id;
				try {
					$order->update_meta_data( '_nicepay_moid', $context['moid'] );
					$order->update_meta_data( '_nicepay_edi_date', $context['edi_date'] );
					$order->save();
					$result = $context;
				} catch ( Throwable $throwable ) {
					nicepay_update_transaction( $tx_id, array(
						'status'             => 'failed',
						'approval_state'     => 'failed',
						'active_attempt_key' => null,
						'result_code'        => 'ORDER_METADATA_ERROR',
					), true );
					nicepay_log( 'WooCommerce order metadata could not be persisted before payment', $order->get_id(), 'error' );
					$result = new WP_Error( 'nicepay_form_metadata', __( 'Payment initialization failed. Please return to checkout.', 'nicepay-payment-gateway' ), array( 'checkout_link' => true ) );
				}
			}
		}
		return $result;
	}

	/** Build form fields and enforce payment-method-specific requirements. */
	private function payment_form_data( $order, $context ) {
		$form_data = array(
			'GoodsName'    => $context['goods_name'],
			'Amt'          => $context['amount'],
			'MID'          => $this->api->get_mid(),
			'EdiDate'      => $context['edi_date'],
			'Moid'         => $context['moid'],
			'SignData'     => $this->api->create_auth_sign_data( $context['edi_date'], $context['amount'] ),
			'ReqReserved'  => $context['binding_token'],
			'ReturnURL'    => WC()->api_request_url( 'nicepay_return' ),
			'BuyerName'    => $context['buyer']['buyer_name'],
			'BuyerTel'     => $context['buyer']['buyer_tel'],
			'BuyerEmail'   => $context['buyer']['buyer_email'],
			'NpLang'       => NicePay_API::get_nicepay_lang(),
			'CurrencyCode' => $context['currency'],
			'CharSet'      => 'utf-8',
		);
		if ( in_array( 'VBANK', $context['enabled_methods'], true ) ) {
			$form_data['VbankExpDate'] = $this->api->get_vbank_exp_date();
		}
		if ( in_array( 'CELLPHONE', $context['enabled_methods'], true ) ) {
			$goods_class = nicepay_get_woocommerce_goods_class( $order );
			if ( false === $goods_class ) {
				nicepay_update_transaction( $context['tx_id'], array(
					'status'             => 'failed',
					'approval_state'     => 'failed',
					'active_attempt_key' => null,
					'result_code'        => 'INVALID_GOODS_CLASS',
				), true );
				$form_data = new WP_Error( 'nicepay_form_goods_class', __( 'The mobile-payment goods classification is invalid.', 'nicepay-payment-gateway' ) );
			} else {
				$form_data['GoodsCl'] = $goods_class;
			}
		}
		return $form_data;
	}

	/** Render a shopper-safe initialization error. */
	private function render_payment_form_error( $error ) {
		echo '<p>' . esc_html( $error->get_error_message() ) . '</p>';
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['checkout_link'] ) ) {
			echo '<a href="' . esc_url( wc_get_checkout_url() ) . '">' . esc_html__( 'Return to Checkout', 'nicepay-payment-gateway' ) . '</a>';
		}
	}

    /**
     * Handle return from NicePay authentication
     */
    public function handle_return() {
		$handler = new NicePay_WooCommerce_Return_Handler( $this->api, $this );
		$handler->process();
    }

    /**
     * Process refund via WooCommerce
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$processor = new NicePay_Refund_Processor( $this->api );
		return $processor->process( $order_id, $amount, $reason );
    }
}
