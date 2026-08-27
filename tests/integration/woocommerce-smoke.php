<?php
/**
 * Real WooCommerce integration assertions for the NicePay gateway.
 *
 * Run only through tests/integration/run-woocommerce-smoke.sh.
 */

if ( '1' !== getenv( 'NICEPAY_WC_INTEGRATION_TEST' ) || 'nicepay_wc_integration' !== DB_NAME ) {
	throw new RuntimeException( 'Refusing to run NicePay WooCommerce checks outside the disposable test database.' );
}

$nicepay_hpos_mode = getenv( 'NICEPAY_HPOS_MODE' );
if ( ! in_array( $nicepay_hpos_mode, array( 'legacy', 'hpos' ), true ) ) {
	throw new RuntimeException( 'NICEPAY_HPOS_MODE must be either legacy or hpos.' );
}

/** @param bool $condition Assertion result. @param string $message Failure message. */
function nicepay_wc_it_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Minimal registry double used to exercise the plugin's real Blocks callback. */
final class NicePay_WC_IT_Blocks_Registry {
	/** @var object[] */
	public $registered = array();

	/** @param object $integration Payment method integration. */
	public function register( $integration ) {
		$this->registered[] = $integration;
	}
}

nicepay_wc_it_assert( defined( 'WC_VERSION' ), 'WooCommerce did not load.' );
nicepay_wc_it_assert( class_exists( 'WC_Gateway_NicePay' ), 'NicePay gateway class did not load with WooCommerce.' );
nicepay_wc_it_assert( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ), 'WooCommerce OrderUtil is unavailable.' );
nicepay_wc_it_assert(
	false !== has_action( 'before_woocommerce_init', 'nicepay_declare_woocommerce_compatibility' ),
	'NicePay did not register its HPOS compatibility declaration.'
);
nicepay_wc_it_assert(
	class_exists( 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController' ),
	'WooCommerce feature controller is unavailable.'
);
$compatible_features = wc_get_container()
	->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class )
	->get_compatible_features_for_plugin( NICEPAY_PLUGIN_BASENAME );
nicepay_wc_it_assert(
	in_array( 'custom_order_tables', $compatible_features['compatible'], true ),
	'WooCommerce did not record NicePay as HPOS compatible.'
);
nicepay_wc_it_assert(
	! in_array( 'cart_checkout_blocks', $compatible_features['compatible'], true ),
	'Checkout Blocks compatibility was declared without the browser matrix.'
);

$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
nicepay_wc_it_assert( ( 'hpos' === $nicepay_hpos_mode ) === $hpos_enabled, 'WooCommerce did not boot in the requested order-storage mode.' );

$payment_gateways = WC()->payment_gateways()->payment_gateways();
nicepay_wc_it_assert( isset( $payment_gateways['nicepay'] ), 'WooCommerce did not register the NicePay gateway.' );
nicepay_wc_it_assert( $payment_gateways['nicepay'] instanceof WC_Gateway_NicePay, 'Registered NicePay gateway has the wrong type.' );
$gateway = $payment_gateways['nicepay'];

// A fresh install must not silently make the gateway checkout-available.
nicepay_wc_it_assert( false === get_option( 'woocommerce_nicepay_settings', false ), 'Fresh install unexpectedly persisted enabled WooCommerce gateway settings.' );
nicepay_wc_it_assert( 'no' === $gateway->enabled, 'Fresh NicePay gateway is not disabled by default.' );
nicepay_wc_it_assert( ! $gateway->is_available(), 'Fresh NicePay gateway did not fail closed.' );

// Make only this in-memory gateway instance available. No merchant setting is
// persisted. The refund lifecycle below intercepts its single cancel request
// with a locally signed response; every other outbound request is blocked.
$_SERVER['HTTPS'] = 'on';
update_option( 'woocommerce_currency', 'KRW' );
update_option( 'woocommerce_price_num_decimals', 0 );
$gateway->enabled = 'yes';
add_filter( 'nicepay_allow_test_mode_checkout', '__return_true' );
nicepay_wc_it_assert( NicePay_Installer::is_current(), 'NicePay transaction schema is not current in WooCommerce smoke.' );
$smoke_api = new NicePay_API();
nicepay_wc_it_assert( '' !== $smoke_api->get_mid(), 'NicePay smoke gateway has no MID.' );
nicepay_wc_it_assert( '' !== $smoke_api->get_merchant_key(), 'NicePay smoke gateway has no merchant key.' );
nicepay_wc_it_assert( true === apply_filters( 'nicepay_allow_test_mode_checkout', false ), 'NicePay smoke test-mode opt-in filter was not applied.' );
nicepay_wc_it_assert( ! empty( nicepay_get_enabled_methods() ), 'NicePay smoke gateway has no enabled payment methods.' );
nicepay_wc_it_assert( 'KRW' === get_woocommerce_currency(), 'WooCommerce smoke currency is not KRW.' );
nicepay_wc_it_assert( 0 === (int) wc_get_price_decimals(), 'WooCommerce smoke decimals are not zero.' );
nicepay_wc_it_assert( is_ssl(), 'WooCommerce smoke request is not HTTPS.' );
nicepay_wc_it_assert( $gateway->is_available(), 'Configured in-memory NicePay gateway should be available for the smoke order.' );

$outbound_http_requests = 0;
$mock_http_response     = null;
add_filter(
	'pre_http_request',
	static function () use ( &$outbound_http_requests, &$mock_http_response ) {
		$outbound_http_requests++;
		if ( is_array( $mock_http_response ) ) {
			return $mock_http_response;
		}
		return new WP_Error( 'nicepay_wc_it_http_blocked', 'External HTTP is forbidden during the WooCommerce smoke test.' );
	},
	PHP_INT_MIN
);

// Use WooCommerce CRUD exclusively so this same contract runs against both
// the legacy posts store and High-Performance Order Storage.
$order = wc_create_order();
nicepay_wc_it_assert( $order instanceof WC_Order, 'WooCommerce could not create the smoke order.' );
$order->set_created_via( 'nicepay-integration-test' );
$order->set_currency( 'KRW' );
$order->set_billing_first_name( 'Integration' );
$order->set_billing_last_name( 'Buyer' );
$order->set_billing_email( 'integration@example.test' );
$order->set_billing_phone( '01012345678' );
$order->set_payment_method( $gateway );
$order->set_status( 'pending' );
$order->set_total( '1000' );
$order->update_meta_data( '_nicepay_wc_it_mode', $nicepay_hpos_mode );
$order_id = $order->save();
nicepay_wc_it_assert( 0 < $order_id, 'WooCommerce could not persist the smoke order.' );

$reloaded_order = wc_get_order( $order_id );
nicepay_wc_it_assert( $reloaded_order instanceof WC_Order, 'WooCommerce could not reload the smoke order.' );
nicepay_wc_it_assert( 'nicepay' === $reloaded_order->get_payment_method(), 'WooCommerce did not persist the NicePay payment method.' );
nicepay_wc_it_assert( 'KRW' === $reloaded_order->get_currency(), 'WooCommerce did not persist the smoke currency.' );
nicepay_wc_it_assert( '1000' === wc_format_decimal( $reloaded_order->get_total(), 0 ), 'WooCommerce did not persist the smoke total.' );
nicepay_wc_it_assert( $nicepay_hpos_mode === $reloaded_order->get_meta( '_nicepay_wc_it_mode' ), 'WooCommerce did not persist order metadata through CRUD.' );

global $wpdb;
if ( $hpos_enabled ) {
	$stored_order_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) );
	nicepay_wc_it_assert( (int) $order_id === (int) $stored_order_id, 'HPOS order was not stored in the WooCommerce orders table.' );
} else {
	nicepay_wc_it_assert( 'shop_order' === get_post_type( $order_id ), 'Legacy order was not stored as a shop_order post.' );
}

$expected_redirect = $reloaded_order->get_checkout_payment_url( true );
$payment_result    = $gateway->process_payment( $order_id );
nicepay_wc_it_assert( is_array( $payment_result ), 'process_payment() did not return a WooCommerce result array.' );
nicepay_wc_it_assert( 'success' === ( $payment_result['result'] ?? '' ), 'process_payment() did not return success for the payable smoke order.' );
nicepay_wc_it_assert( $expected_redirect === ( $payment_result['redirect'] ?? '' ), 'process_payment() did not return the order-pay redirect.' );

$moid = 'WCIT_' . strtoupper( $nicepay_hpos_mode ) . '_' . substr( hash( 'sha256', (string) $order_id ), 0, 24 );
$transaction_id = nicepay_save_transaction(
	array(
		'moid'               => $moid,
		'order_id'           => (string) $order_id,
		'wc_order_id'        => $order_id,
		'flow'               => 'woocommerce',
		'source_ref'         => (string) $order_id,
		'active_attempt_key' => 'woocommerce:' . (string) $order_id,
		'amount'             => '1000',
		'currency'           => 'KRW',
		'payment_method'     => 'CARD',
	)
);
nicepay_wc_it_assert( false !== $transaction_id, 'NicePay repository could not persist the WooCommerce transaction.' );

$active_transaction = nicepay_get_active_woocommerce_transaction( $order_id );
nicepay_wc_it_assert( is_object( $active_transaction ), 'NicePay repository could not reload the active order transaction.' );
nicepay_wc_it_assert( (int) $order_id === (int) $active_transaction->wc_order_id, 'NicePay transaction lost its WooCommerce order relation.' );
nicepay_wc_it_assert( $moid === $active_transaction->moid, 'NicePay active transaction lookup returned the wrong ledger row.' );
nicepay_wc_it_assert( $reloaded_order->get_id() === wc_get_order( $active_transaction->wc_order_id )->get_id(), 'Transaction relation did not resolve through WooCommerce CRUD.' );

$transaction_by_moid = nicepay_get_transaction_by_moid( $moid, 'woocommerce' );
nicepay_wc_it_assert( (int) $transaction_id === (int) $transaction_by_moid->id, 'Flow-scoped Moid lookup returned the wrong ledger row.' );
nicepay_wc_it_assert( nicepay_update_transaction( $transaction_id, array( 'result_code' => 'IT_ONLY' ), true ), 'NicePay repository could not update the smoke transaction.' );
nicepay_wc_it_assert( 'IT_ONLY' === nicepay_get_transaction_by_moid( $moid, 'woocommerce' )->result_code, 'NicePay repository update was not durable.' );

// Exercise WooCommerce's real refund ordering: WC_Order_Refund is persisted
// before WC_Gateway_NicePay::process_refund() is called. This catches ledger
// checks that pass in direct unit calls but reject the actual core lifecycle.
$tid = 'nicepay00m' . str_pad( (string) $order_id, 20, '0', STR_PAD_LEFT );
nicepay_wc_it_assert(
	nicepay_update_transaction(
		$transaction_id,
		array(
			'tid'                   => $tid,
			'mid'                   => NICEPAY_TEST_MID,
			'mode'                  => 'test',
			'status'                => 'paid',
			'approval_state'        => 'approved',
			'captured_amount'       => '1000',
			'refunded_amount'       => '0',
			'remaining_amount'      => '1000',
			'cc_part_cl'            => '1',
			'reconciliation_status' => 'not_required',
			'active_attempt_key'    => null,
		),
		true
	),
	'NicePay repository could not prepare the paid refund fixture.'
);
$reloaded_order->update_meta_data( '_nicepay_tid', $tid );
$reloaded_order->payment_complete( $tid );
$reloaded_order->save();

$cancel_amount     = '500';
$cancel_signature  = hash( 'sha256', $tid . NICEPAY_TEST_MID . $cancel_amount . NICEPAY_TEST_MERCHANT_KEY );
$mock_http_response = array(
	'headers'  => array(),
	'body'     => wp_json_encode(
		array(
			'TID'        => $tid,
			'CancelAmt'  => $cancel_amount,
			'Signature'  => $cancel_signature,
			'ResultCode' => '2001',
			'ResultMsg'  => 'integration refund confirmed',
			'OTID'       => 'WCIT-CANCEL-CHAIN',
		)
	),
	'response' => array( 'code' => 200, 'message' => 'OK' ),
	'cookies'  => array(),
	'filename' => null,
);
$refund = wc_create_refund(
	array(
		'order_id'      => $order_id,
		'amount'        => $cancel_amount,
		'reason'        => 'NicePay integration refund',
		'refund_payment'=> true,
		'restock_items' => false,
	)
);
$mock_http_response = null;
nicepay_wc_it_assert( $refund instanceof WC_Order_Refund, 'WooCommerce could not complete the NicePay refund lifecycle.' );
$refunded_transaction = nicepay_get_transaction_by_tid( $tid, $order_id );
nicepay_wc_it_assert( '500' === wc_format_decimal( $refunded_transaction->refunded_amount, 0 ), 'NicePay ledger did not persist the WooCommerce refund amount.' );
nicepay_wc_it_assert( '500' === wc_format_decimal( $refunded_transaction->remaining_amount, 0 ), 'NicePay ledger did not persist the post-refund balance.' );
nicepay_wc_it_assert( '500' === wc_format_decimal( wc_get_order( $order_id )->get_total_refunded(), 0 ), 'WooCommerce refund total did not match the NicePay ledger.' );

// Exercise the plugin's Blocks registration callback with WooCommerce's real
// abstract integration type loaded, then verify availability delegates to the
// same fail-closed gateway instance.
nicepay_wc_it_assert(
	class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ),
	'WooCommerce Blocks payment integration contract is unavailable.'
);
$plugin = nicepay_init();
nicepay_wc_it_assert(
	false !== has_action( 'woocommerce_blocks_payment_method_type_registration', array( $plugin, 'register_woocommerce_blocks_payment_method' ) ),
	'NicePay did not attach its WooCommerce Blocks registration callback.'
);

$blocks_registry = new NicePay_WC_IT_Blocks_Registry();
$plugin->register_woocommerce_blocks_payment_method( $blocks_registry );
nicepay_wc_it_assert( 1 === count( $blocks_registry->registered ), 'NicePay Blocks callback did not register exactly one integration.' );
$blocks_integration = $blocks_registry->registered[0];
nicepay_wc_it_assert( $blocks_integration instanceof NicePay_Blocks_Integration, 'NicePay Blocks callback registered the wrong integration type.' );
$blocks_integration->initialize();
nicepay_wc_it_assert( $blocks_integration->is_active(), 'NicePay Blocks integration did not expose the available gateway.' );
$gateway->enabled = 'no';
nicepay_wc_it_assert( ! $blocks_integration->is_active(), 'NicePay Blocks integration did not follow the gateway fail-closed state.' );

nicepay_wc_it_assert( 1 === $outbound_http_requests, 'WooCommerce smoke test did not issue exactly one intercepted refund request.' );

// The entire database is disposable, but deleting through CRUD also proves the
// selected store can perform a complete lifecycle before the next mode boots.
$reloaded_order->delete( true );

echo sprintf(
	"NicePay WooCommerce smoke checks passed (WooCommerce %s, storage: %s).\n",
	WC_VERSION,
	$nicepay_hpos_mode
);
