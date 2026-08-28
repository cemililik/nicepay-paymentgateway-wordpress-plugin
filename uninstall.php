<?php
/**
 * NicePay uninstall policy.
 *
 * Financial records are retained by default. Destructive cleanup runs only on
 * sites where an administrator explicitly enabled uninstall deletion.
 *
 * @package NicePay_Payment_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Delete explicitly opted-in NicePay data for the current site. */
function nicepay_uninstall_current_site() {
	if ( 'yes' !== get_option( 'nicepay_delete_data_on_uninstall', 'no' ) ) {
		return;
	}

	global $wpdb;
	foreach ( array( 'nicepay_reconciliation_audit', 'nicepay_refund_attempts', 'nicepay_transactions' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		if ( preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- validated identifier.
		}
	}

	$options = array(
		'nicepay_mode', 'nicepay_language', 'nicepay_currency',
		'nicepay_test_mid', 'nicepay_test_merchant_key',
		'nicepay_live_mid', 'nicepay_live_merchant_key',
		'nicepay_standalone_enabled', 'nicepay_enabled_methods',
		'nicepay_vbank_expiry_days', 'nicepay_saved_shortcodes',
		'nicepay_db_version', 'nicepay_transactions_schema_version',
		'nicepay_transactions_schema_verified_version',
		'nicepay_transactions_schema_lock', 'nicepay_retention_settings',
		'nicepay_retention_last_run', 'nicepay_retention_lock', 'nicepay_expiry_lock',
		'woocommerce_nicepay_settings',
		'nicepay_delete_data_on_uninstall',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	foreach ( array( '_transient_nicepay_', '_transient_timeout_nicepay_' ) as $prefix ) {
		$like = $wpdb->esc_like( $prefix ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}
}

if ( is_multisite() ) {
	$offset = 0;
	do {
		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				nicepay_uninstall_current_site();
			} finally {
				restore_current_blog();
			}
		}
		$offset += count( $site_ids );
	} while ( 100 === count( $site_ids ) );
} else {
	nicepay_uninstall_current_site();
}
