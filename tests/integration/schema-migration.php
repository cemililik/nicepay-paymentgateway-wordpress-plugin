<?php
/**
 * Real WordPress/MariaDB schema integration assertions.
 *
 * Run only through tests/integration/run-schema-migration.sh.
 */

if ( '1' !== getenv( 'NICEPAY_INTEGRATION_TEST' ) || 'nicepay_integration' !== DB_NAME ) {
	throw new RuntimeException( 'Refusing to run NicePay schema integration checks outside the disposable test database.' );
}

global $wpdb;

/** @param bool $condition Assertion result. @param string $message Failure message. */
function nicepay_it_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$table        = NicePay_Installer::table_name( $wpdb );
$refund_table = NicePay_Installer::refund_table_name( $wpdb );

nicepay_it_assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Fresh transaction table is missing.' );
nicepay_it_assert( $refund_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $refund_table ) ), 'Fresh refund table is missing.' );
nicepay_it_assert( NicePay_Installer::is_current(), 'Fresh schema is not current.' );

$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
nicepay_it_assert( in_array( 'uniq_moid', $indexes, true ), 'Unique Moid index is missing.' );
nicepay_it_assert( in_array( 'uniq_active_attempt', $indexes, true ), 'Unique active-attempt index is missing.' );
nicepay_it_assert( in_array( 'idx_created_at', $indexes, true ), 'Created-at list index is missing.' );
nicepay_it_assert( in_array( 'idx_updated_at', $indexes, true ), 'Updated-at retention index is missing.' );

// Simulate the immediately previous schema and prove additive upgrade.
foreach ( array( 'cc_part_cl', 'clickpay_cl', 'card_type' ) as $column ) {
	$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
}
update_option( NicePay_Installer::VERSION_OPTION, '2026.08.20.5', false );
$upgrade = NicePay_Installer::maybe_install();
nicepay_it_assert( ! is_wp_error( $upgrade ), 'Additive legacy -> current upgrade failed.' );
foreach ( array( 'cc_part_cl', 'clickpay_cl', 'card_type' ) as $column ) {
	nicepay_it_assert( $column === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ), "Upgraded column {$column} is missing." );
}

// Recreate the sensitive legacy shape and prove the migration scrubs it.
$wpdb->query( "ALTER TABLE {$table} ADD COLUMN card_no varchar(30) NOT NULL DEFAULT ''" );
$inserted = $wpdb->insert(
	$table,
	array(
		'moid'         => 'LEGACY_20260820120000_0123456789abcdef',
		'order_id'     => 'legacy-order',
		'status'       => 'paid',
		'auth_token'   => 'spent-auth-token',
		'card_no'      => '4111111111111111',
		'payment_data' => '{"CardNo":"4111111111111111","BuyerEmail":"buyer@example.com"}',
	),
	array( '%s', '%s', '%s', '%s', '%s', '%s' )
);
nicepay_it_assert( 1 === $inserted, 'Legacy fixture could not be inserted.' );
update_option( NicePay_Installer::VERSION_OPTION, '2026.08.20.4', false );
$scrub = NicePay_Installer::maybe_install();
nicepay_it_assert( ! is_wp_error( $scrub ), 'Legacy sensitive-data migration failed.' );
$legacy = $wpdb->get_row( $wpdb->prepare( "SELECT auth_token, card_no, payment_data FROM {$table} WHERE moid = %s", 'LEGACY_20260820120000_0123456789abcdef' ) );
nicepay_it_assert( '' === $legacy->auth_token, 'Spent legacy auth token was not scrubbed.' );
nicepay_it_assert( '' === $legacy->card_no, 'Legacy PAN field was not scrubbed.' );
nicepay_it_assert( null === $legacy->payment_data, 'Legacy sensitive payload was not scrubbed.' );

// A current version option may not hide a missing physical table.
$wpdb->query( "DROP TABLE {$refund_table}" );
update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version(), false );
$repair = NicePay_Installer::maybe_install();
nicepay_it_assert( ! is_wp_error( $repair ), 'Missing-table self-repair failed.' );
nicepay_it_assert( $refund_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $refund_table ) ), 'Refund table was not recreated.' );

// The unique active-attempt key must reject two concurrently prepared payment
// forms for the same WooCommerce order, even though abandon + insert are two
// separate statements in the request path.
$active_attempt = nicepay_save_transaction(
	array(
		'moid'               => 'ACTIVE_20260820121000_0123456789abcdef',
		'order_id'           => 'active-attempt-1',
		'wc_order_id'        => 501,
		'flow'               => 'woocommerce',
		'source_ref'         => '501',
		'active_attempt_key' => 'woocommerce:501',
	)
);
nicepay_it_assert( false !== $active_attempt, 'First active WooCommerce attempt could not be inserted.' );
$previous_suppress_errors = $wpdb->suppress_errors( true );
$duplicate_active_attempt = nicepay_save_transaction(
	array(
		'moid'               => 'ACTIVE_20260820121001_fedcba9876543210',
		'order_id'           => 'active-attempt-2',
		'wc_order_id'        => 501,
		'flow'               => 'woocommerce',
		'source_ref'         => '501',
		'active_attempt_key' => 'woocommerce:501',
	)
);
$wpdb->suppress_errors( $previous_suppress_errors );
nicepay_it_assert( false === $duplicate_active_attempt, 'Duplicate active WooCommerce attempt bypassed the unique lock.' );

// A claim must atomically choose one legacy/sibling pending row and abandon all
// other pending rows for that same order before any approval transport occurs.
$claim_winner = nicepay_save_transaction(
	array(
		'moid'        => 'CLAIM_20260820121100_0123456789abcdef',
		'order_id'    => 'claim-winner',
		'wc_order_id' => 502,
		'flow'        => 'woocommerce',
		'source_ref'  => '502',
	)
);
$claim_loser = nicepay_save_transaction(
	array(
		'moid'        => 'CLAIM_20260820121101_fedcba9876543210',
		'order_id'    => 'claim-loser',
		'wc_order_id' => 502,
		'flow'        => 'woocommerce',
		'source_ref'  => '502',
	)
);
nicepay_it_assert( false !== $claim_winner && false !== $claim_loser, 'Approval-claim fixtures could not be inserted.' );
nicepay_it_assert( nicepay_claim_transaction_for_approval( $claim_winner, 'woocommerce' ), 'First WooCommerce approval claim was not acquired.' );
$claimed_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, approval_state FROM {$table} WHERE source_ref = %s ORDER BY id", '502' ) );
nicepay_it_assert( 2 === count( $claimed_rows ), 'Approval-claim fixtures could not be reloaded.' );
nicepay_it_assert( (int) $claim_winner === (int) $claimed_rows[0]->id && 'approving' === $claimed_rows[0]->status && 'approving' === $claimed_rows[0]->approval_state, 'Winning approval row did not become approving.' );
nicepay_it_assert( (int) $claim_loser === (int) $claimed_rows[1]->id && 'abandoned' === $claimed_rows[1]->status && 'abandoned' === $claimed_rows[1]->approval_state, 'Sibling approval row was not abandoned atomically.' );
nicepay_it_assert( ! nicepay_claim_transaction_for_approval( $claim_loser, 'woocommerce' ), 'Abandoned sibling acquired a second approval claim.' );

// Reserving a refund balance is a compare-and-set operation. A second request
// must fail while the first cancel outcome is still requested/unknown.
$refund_claim = nicepay_save_transaction(
	array(
		'moid'                  => 'REFUND_20260820121200_0123456789abcdef',
		'order_id'              => 'refund-claim',
		'wc_order_id'           => 503,
		'flow'                  => 'woocommerce',
		'source_ref'            => '503',
		'tid'                   => 'nicepay00m01012006221311049999',
		'amount'                => '1000',
		'captured_amount'       => '1000',
		'refunded_amount'       => '0',
		'remaining_amount'      => '1000',
		'status'                => 'paid',
		'approval_state'        => 'approved',
		'reconciliation_status' => 'not_required',
	)
);
nicepay_it_assert( false !== $refund_claim, 'Refund-claim fixture could not be inserted.' );
nicepay_it_assert( nicepay_claim_transaction_for_refund( $refund_claim, 'RF503_20260820121201_0123456789abcdef', '400' ), 'First refund reservation was not acquired.' );
nicepay_it_assert( ! nicepay_claim_transaction_for_refund( $refund_claim, 'RF503_20260820121202_fedcba9876543210', '400' ), 'A concurrent refund bypassed the existing reservation.' );

// The default unfiltered ledger query must use the dedicated ordering index.
for ( $index = 0; $index < 50; $index++ ) {
	$wpdb->insert(
		$table,
		array( 'moid' => sprintf( 'IT_%014d_%016x', $index, $index ), 'order_id' => 'integration-' . $index ),
		array( '%s', '%s' )
	);
}
$plan = $wpdb->get_row( "EXPLAIN SELECT id, created_at FROM {$table} ORDER BY created_at DESC LIMIT 20" );
nicepay_it_assert( isset( $plan->key ) && 'idx_created_at' === $plan->key, 'Default ledger query did not select idx_created_at.' );

// Retention is unscheduled by default and follows only a valid, explicitly
// acknowledged custom policy.
nicepay_it_assert( false === wp_next_scheduled( NicePay_Retention::CRON_HOOK ), 'Default indefinite retention unexpectedly scheduled deletion.' );
update_option(
	NicePay_Retention::SETTINGS_OPTION,
	array( 'mode' => 'custom', 'days' => 365, 'acknowledged' => 'yes' )
);
nicepay_it_assert( false !== wp_next_scheduled( NicePay_Retention::CRON_HOOK ), 'Acknowledged custom retention did not schedule cleanup.' );
update_option( NicePay_Retention::SETTINGS_OPTION, NicePay_Retention::default_settings() );
nicepay_it_assert( false === wp_next_scheduled( NicePay_Retention::CRON_HOOK ), 'Returning to indefinite retention did not clear cleanup.' );

// Finite retention removes only old, settled local-ledger rows. It must retain
// reconciliation cases and any transaction with an unresolved refund attempt.
$retention_eligible = nicepay_save_transaction(
	array(
		'moid'                  => 'RETENTION_ELIGIBLE_20260824_0123456789',
		'order_id'              => 'retention-eligible',
		'wc_order_id'           => 601,
		'flow'                  => 'woocommerce',
		'source_ref'            => '601',
		'tid'                   => 'nicepay00m01012006241311046001',
		'amount'                => '1000',
		'captured_amount'       => '1000',
		'remaining_amount'      => '1000',
		'status'                => 'paid',
		'approval_state'        => 'approved',
		'reconciliation_status' => 'not_required',
	)
);
$eligible_refund = nicepay_save_refund_attempt(
	array(
		'transaction_id'   => $retention_eligible,
		'wc_order_id'      => 601,
		'tid'              => 'nicepay00m01012006241311046001',
		'cancel_moid'      => 'RETENTION_RF_20260824_0123456789',
		'requested_amount' => '100',
		'currency'         => 'KRW',
		'reason'           => 'confirmed historical refund',
	)
);
nicepay_it_assert( false !== $retention_eligible && false !== $eligible_refund, 'Eligible retention fixtures could not be inserted.' );
nicepay_it_assert(
	nicepay_complete_refund_attempt(
		$eligible_refund,
		'RETENTION_RF_20260824_0123456789',
		array( 'status' => 'confirmed', 'result_code' => '2001' )
	),
	'Eligible confirmed refund fixture could not be completed.'
);

$retention_reconciliation = nicepay_save_transaction(
	array(
		'moid'                  => 'RETENTION_RECON_20260824_0123456789abc',
		'order_id'              => 'retention-reconciliation',
		'wc_order_id'           => 602,
		'flow'                  => 'woocommerce',
		'source_ref'            => '602',
		'tid'                   => 'nicepay00m01012006241311046002',
		'status'                => 'needs_reconciliation',
		'approval_state'        => 'needs_reconciliation',
		'reconciliation_status' => 'required',
		'net_cancel_status'     => 'unknown',
	)
);
nicepay_it_assert( false !== $retention_reconciliation, 'Protected reconciliation fixture could not be inserted.' );

$retention_unknown_refund = nicepay_save_transaction(
	array(
		'moid'                  => 'RETENTION_UNKNOWN_20260824_0123456789a',
		'order_id'              => 'retention-unknown-refund',
		'wc_order_id'           => 603,
		'flow'                  => 'woocommerce',
		'source_ref'            => '603',
		'tid'                   => 'nicepay00m01012006241311046003',
		'amount'                => '1000',
		'captured_amount'       => '1000',
		'remaining_amount'      => '1000',
		'status'                => 'paid',
		'approval_state'        => 'approved',
		'reconciliation_status' => 'not_required',
	)
);
$unknown_refund = nicepay_save_refund_attempt(
	array(
		'transaction_id'   => $retention_unknown_refund,
		'wc_order_id'      => 603,
		'tid'              => 'nicepay00m01012006241311046003',
		'cancel_moid'      => 'RETENTION_UNKNOWN_RF_20260824_0123456',
		'requested_amount' => '100',
		'currency'         => 'KRW',
		'reason'           => 'unknown historical refund',
	)
);
nicepay_it_assert( false !== $retention_unknown_refund && false !== $unknown_refund, 'Protected unknown-refund fixtures could not be inserted.' );
nicepay_it_assert(
	nicepay_complete_refund_attempt(
		$unknown_refund,
		'RETENTION_UNKNOWN_RF_20260824_0123456',
		array( 'status' => 'unknown', 'result_code' => '9999' )
	),
	'Protected unknown-refund fixture could not be completed.'
);

$retention_ids = implode( ',', array_map( 'absint', array( $retention_eligible, $retention_reconciliation, $retention_unknown_refund ) ) );
$wpdb->query( "UPDATE {$table} SET updated_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE id IN ({$retention_ids})" );
$purged = NicePay_Retention::purge_batch( 365, 50 );
nicepay_it_assert( 1 === $purged, 'Retention did not delete exactly the one eligible old transaction.' );
nicepay_it_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_eligible ) ), 'Eligible old transaction was not deleted.' );
nicepay_it_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$refund_table} WHERE id = %d", $eligible_refund ) ), 'Confirmed refund history was not deleted with its eligible parent.' );
nicepay_it_assert( (int) $retention_reconciliation === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_reconciliation ) ), 'Reconciliation-required transaction was deleted.' );
nicepay_it_assert( (int) $retention_unknown_refund === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_unknown_refund ) ), 'Transaction with an unknown refund was deleted.' );
nicepay_it_assert( (int) $unknown_refund === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$refund_table} WHERE id = %d", $unknown_refund ) ), 'Unknown refund attempt was deleted.' );

echo "NicePay schema integration checks passed.\n";
