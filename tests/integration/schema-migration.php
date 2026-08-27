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
$audit_table  = NicePay_Installer::reconciliation_audit_table_name( $wpdb );

nicepay_it_assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Fresh transaction table is missing.' );
nicepay_it_assert( $refund_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $refund_table ) ), 'Fresh refund table is missing.' );
nicepay_it_assert( $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ), 'Fresh reconciliation audit table is missing.' );
nicepay_it_assert( NicePay_Installer::is_current(), 'Fresh schema is not current.' );

$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
nicepay_it_assert( in_array( 'uniq_moid', $indexes, true ), 'Unique Moid index is missing.' );
nicepay_it_assert( in_array( 'uniq_active_attempt', $indexes, true ), 'Unique active-attempt index is missing.' );
nicepay_it_assert( in_array( 'idx_created_at', $indexes, true ), 'Created-at list index is missing.' );
nicepay_it_assert( in_array( 'idx_updated_at', $indexes, true ), 'Updated-at retention index is missing.' );

// Rebuild the exact published 1.x table shape. This is intentionally not a
// current table with a few columns removed: nullability and legacy indexes are
// the migration hazards this fixture must exercise.
$wpdb->query( "DROP TABLE {$table}" );
delete_option( NicePay_Installer::VERSION_OPTION );
delete_option( NicePay_Installer::VERIFIED_VERSION_OPTION );
$charset_collate = $wpdb->get_charset_collate();
$legacy_sql = "CREATE TABLE {$table} (
	id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	tid varchar(50) NOT NULL DEFAULT '',
	order_id varchar(100) NOT NULL DEFAULT '',
	wc_order_id bigint(20) UNSIGNED DEFAULT NULL,
	moid varchar(64) NOT NULL DEFAULT '',
	amount decimal(12,2) NOT NULL DEFAULT 0,
	payment_method varchar(20) NOT NULL DEFAULT '',
	pay_method_name varchar(50) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'pending',
	result_code varchar(10) NOT NULL DEFAULT '',
	result_msg text NOT NULL,
	auth_token varchar(50) NOT NULL DEFAULT '',
	buyer_name varchar(100) NOT NULL DEFAULT '',
	buyer_email varchar(100) NOT NULL DEFAULT '',
	buyer_tel varchar(30) NOT NULL DEFAULT '',
	goods_name varchar(100) NOT NULL DEFAULT '',
	card_code varchar(5) NOT NULL DEFAULT '',
	card_name varchar(50) NOT NULL DEFAULT '',
	card_no varchar(30) NOT NULL DEFAULT '',
	card_quota varchar(5) NOT NULL DEFAULT '',
	bank_code varchar(5) NOT NULL DEFAULT '',
	bank_name varchar(50) NOT NULL DEFAULT '',
	vbank_num varchar(30) NOT NULL DEFAULT '',
	vbank_exp_date varchar(20) NOT NULL DEFAULT '',
	payment_data longtext,
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	KEY idx_tid (tid),
	KEY idx_order_id (order_id),
	KEY idx_wc_order_id (wc_order_id),
	KEY idx_moid (moid),
	KEY idx_status (status)
) {$charset_collate}";
nicepay_it_assert( false !== $wpdb->query( $legacy_sql ), 'Published 1.x schema fixture could not be created.' );

for ( $legacy_index = 1; $legacy_index <= 5; $legacy_index++ ) {
	$is_paid = $legacy_index <= 3;
	$inserted = $wpdb->insert(
		$table,
		array(
			'tid'          => $is_paid ? sprintf( 'legacy-tid-%d', $legacy_index ) : '',
			'order_id'     => 'legacy-order-' . $legacy_index,
			'wc_order_id'  => 700 + $legacy_index,
			'moid'         => 'LEGACY_' . $legacy_index,
			'amount'       => 1000 * $legacy_index,
			'status'       => $is_paid ? 'paid' : 'pending',
			'auth_token'   => $is_paid ? 'spent-auth-token' : '',
			'card_no'      => $is_paid ? '4111111111111111' : '',
			'payment_data' => $is_paid ? '{"CardNo":"4111111111111111","AuthCode":"legacy-audit"}' : null,
		),
		array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	nicepay_it_assert( 1 === $inserted, 'A published 1.x fixture row could not be inserted.' );
}

$upgrade = NicePay_Installer::maybe_install();
nicepay_it_assert( ! is_wp_error( $upgrade ), 'Published 1.x -> current schema upgrade failed.' );
$tid_column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'tid'" );
nicepay_it_assert( isset( $tid_column->Null ) && 'YES' === $tid_column->Null, 'Legacy TID column is still NOT NULL.' );
nicepay_it_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE tid = ''" ), 'Legacy empty TID sentinels were not normalized.' );
$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
foreach ( array( 'uniq_moid', 'uniq_tid', 'uniq_active_attempt', 'idx_source_ref', 'idx_status_offer_expiry', 'idx_status_approval_started' ) as $required_index ) {
	nicepay_it_assert( in_array( $required_index, $indexes, true ), "Required upgraded index {$required_index} is missing." );
}
$legacy = $wpdb->get_row( "SELECT * FROM {$table} WHERE moid = 'LEGACY_1'" );
nicepay_it_assert( 'woocommerce' === $legacy->flow, 'Legacy WooCommerce flow was not backfilled.' );
nicepay_it_assert( 'KRW' === $legacy->currency, 'Legacy currency was not backfilled.' );
nicepay_it_assert( 'test' === $legacy->mode && NICEPAY_TEST_MID === $legacy->mid, 'Legacy merchant context was not backfilled.' );
nicepay_it_assert( '1000.00' === $legacy->captured_amount && '1000.00' === $legacy->remaining_amount, 'Legacy captured balance was not backfilled.' );
nicepay_it_assert( '' === $legacy->auth_token && '' === $legacy->card_no, 'Spent legacy credentials were not scrubbed.' );
nicepay_it_assert( null !== $legacy->payment_data, 'Legacy audit payload was erased without operator confirmation.' );
nicepay_it_assert( is_object( nicepay_get_transaction_by_tid( 'legacy-tid-1', 701 ) ), 'Backfilled legacy payment cannot be resolved for refunds.' );
nicepay_it_assert( false !== nicepay_save_transaction( array( 'moid' => 'POST_UPGRADE_1', 'order_id' => 'post-upgrade-1' ) ), 'First post-upgrade transaction could not be inserted.' );
nicepay_it_assert( false !== nicepay_save_transaction( array( 'moid' => 'POST_UPGRADE_2', 'order_id' => 'post-upgrade-2' ) ), 'Second post-upgrade transaction could not be inserted.' );

// Repository timestamps must be UTC even when the database session is not.
$wpdb->query( "SET time_zone = '+09:00'" );
$utc_before = gmdate( 'Y-m-d H:i:s' );
$utc_row_id = nicepay_save_transaction( array( 'moid' => 'UTC_CONTRACT_1', 'order_id' => 'utc-contract' ) );
$utc_after  = gmdate( 'Y-m-d H:i:s' );
$utc_row    = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, updated_at FROM {$table} WHERE id = %d", $utc_row_id ) );
nicepay_it_assert( $utc_row && $utc_row->created_at >= $utc_before && $utc_row->created_at <= $utc_after, 'Transaction created_at inherited the database session timezone.' );
nicepay_it_assert( $utc_row->created_at === $utc_row->updated_at, 'Initial UTC transaction timestamps diverged.' );
$wpdb->query( "SET time_zone = '+00:00'" );

// A current version option may not hide a missing physical table.
$wpdb->query( "DROP TABLE {$refund_table}" );
update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version(), false );
delete_option( NicePay_Installer::VERIFIED_VERSION_OPTION );
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
		array(
			'moid'       => sprintf( 'IT_%014d_%016x', $index, $index ),
			'order_id'   => 'integration-' . $index,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%s', '%s', '%s', '%s' )
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
$wpdb->query( "UPDATE {$table} SET created_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE id IN ({$retention_ids})" );
$purged = NicePay_Retention::purge_batch( 365, 50 );
nicepay_it_assert( 1 === $purged, 'Retention did not delete exactly the one eligible old transaction.' );
nicepay_it_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_eligible ) ), 'Eligible old transaction was not deleted.' );
nicepay_it_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$refund_table} WHERE id = %d", $eligible_refund ) ), 'Confirmed refund history was not deleted with its eligible parent.' );
nicepay_it_assert( (int) $retention_reconciliation === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_reconciliation ) ), 'Reconciliation-required transaction was deleted.' );
nicepay_it_assert( (int) $retention_unknown_refund === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $retention_unknown_refund ) ), 'Transaction with an unknown refund was deleted.' );
nicepay_it_assert( (int) $unknown_refund === (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$refund_table} WHERE id = %d", $unknown_refund ) ), 'Unknown refund attempt was deleted.' );

// Uninstall retains financial data by default and purges only after explicit opt-in.
delete_option( 'nicepay_delete_data_on_uninstall' );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
require dirname( __DIR__, 2 ) . '/uninstall.php';
nicepay_it_assert( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), 'Default uninstall policy deleted the transaction ledger.' );
update_option( 'nicepay_delete_data_on_uninstall', 'yes', false );
nicepay_uninstall_current_site();
foreach ( array( $table, $refund_table, $audit_table ) as $deleted_table ) {
	nicepay_it_assert( $deleted_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $deleted_table ) ), 'Opt-in uninstall did not remove a NicePay table.' );
}
nicepay_it_assert( false === get_option( NicePay_Installer::VERSION_OPTION, false ), 'Opt-in uninstall retained the schema version option.' );

echo "NicePay schema integration checks passed.\n";
