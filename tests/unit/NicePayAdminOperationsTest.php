<?php
/**
 * Tests for filtered financial summaries, CSV exports and the system report.
 */

use PHPUnit\Framework\TestCase;

global $nicepay_admin_test_actions, $nicepay_admin_test_is_ssl, $nicepay_admin_test_cron;
global $nicepay_admin_test_can_manage, $nicepay_admin_test_nonce_valid, $nicepay_admin_test_nonce_checks;
$nicepay_admin_test_actions = array();
$nicepay_admin_test_is_ssl  = true;
$nicepay_admin_test_cron    = true;
$nicepay_admin_test_can_manage = true;
$nicepay_admin_test_nonce_valid = true;
$nicepay_admin_test_nonce_checks = array();

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback ) {
        global $nicepay_admin_test_actions;
        $nicepay_admin_test_actions[ $hook ][] = $callback;
        return true;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '' ) {
        return 'version' === $show ? '6.8.2' : '';
    }
}

if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() {
        global $nicepay_admin_test_is_ssl;
        return (bool) $nicepay_admin_test_is_ssl;
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook ) {
        global $nicepay_admin_test_cron;
        return $nicepay_admin_test_cron && 'nicepay_expire_pending_transactions' === $hook ? 1700000000 : false;
    }
}

if ( ! function_exists( 'did_action' ) ) {
    function did_action( $hook ) {
        return 0;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        return $value;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        global $nicepay_admin_test_can_manage;
        return (bool) $nicepay_admin_test_can_manage;
    }
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( $action ) {
        global $nicepay_admin_test_nonce_valid, $nicepay_admin_test_nonce_checks;
        $nicepay_admin_test_nonce_checks[] = $action;
        if ( ! $nicepay_admin_test_nonce_valid ) {
            throw new NicePayAdminOperationsDieException( 'invalid_nonce', 403 );
        }
        return 1;
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = array() ) {
        $response = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 500;
        throw new NicePayAdminOperationsDieException( (string) $message, $response );
    }
}

require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php';
require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php';

class NicePayAdminOperationsDieException extends RuntimeException {
}

class NicePayAdminOperationsWpdbFake {
    public $prefix = 'wp_';
    public $summary_rows = array();
    public $csv_batches = array();
    public $queries = array();
    public $get_row_result = null;

    public function prepare( $query, ...$values ) {
        if ( 1 === count( $values ) && is_array( $values[0] ) ) {
            $values = $values[0];
        }
        foreach ( $values as $value ) {
            $replacement = is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
            $query = preg_replace( '/%[ds]/', $replacement, $query, 1 );
        }
        return $query;
    }

    public function esc_like( $value ) {
        return addcslashes( $value, '_%\\' );
    }

    public function get_results( $query, $output = null ) {
        $this->queries[] = $query;
        if ( false !== strpos( $query, 'GROUP BY currency' ) ) {
            return $this->summary_rows;
        }
        return empty( $this->csv_batches ) ? array() : array_shift( $this->csv_batches );
    }

    public function get_var( $query ) {
        $this->queries[] = $query;
		if ( false !== strpos( $query, 'wp_nicepay_reconciliation_audit' ) ) {
			return 'wp_nicepay_reconciliation_audit';
		}
        if ( false !== strpos( $query, 'wp_nicepay_refund_attempts' ) ) {
            return 'wp_nicepay_refund_attempts';
        }
        if ( false !== strpos( $query, 'wp_nicepay_transactions' ) ) {
            return 'wp_nicepay_transactions';
        }
        return null;
    }

    public function get_row( $query ) {
        $this->queries[] = $query;
        return $this->get_row_result;
    }
}

class NicePayAdminOperationsTest extends TestCase {

    private $previous_wpdb;
    private $previous_get;

    protected function setUp(): void {
        global $wpdb, $wp_options, $nicepay_admin_test_is_ssl, $nicepay_admin_test_cron;
        global $nicepay_admin_test_can_manage, $nicepay_admin_test_nonce_valid, $nicepay_admin_test_nonce_checks;
		global $wp_timezone_string_test;

        parent::setUp();
        $this->previous_wpdb = isset( $wpdb ) ? $wpdb : null;
        $this->previous_get  = $_GET;
        $wpdb = new NicePayAdminOperationsWpdbFake();
        $wp_options = array();
        $nicepay_admin_test_is_ssl = true;
        $nicepay_admin_test_cron   = true;
        $nicepay_admin_test_can_manage  = true;
        $nicepay_admin_test_nonce_valid = true;
        $nicepay_admin_test_nonce_checks = array();
		$wp_timezone_string_test = 'UTC';
        $_GET = array();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->previous_wpdb;
        $_GET = $this->previous_get;
        parent::tearDown();
    }

    public function test_filters_are_canonical_and_reject_invalid_or_broadening_inputs(): void {
        $valid = NicePay_Transactions::parse_filters(
            array(
                'filter_status' => 'paid',
                'filter_method' => 'card',
                'date_from'     => '2026-08-01',
                'date_to'       => '2026-08-20',
                's'             => '<b>merchant-ref</b>',
            )
        );

        $this->assertTrue( $valid['valid'] );
        $this->assertSame( 'paid', $valid['filters']['status'] );
        $this->assertSame( 'CARD', $valid['filters']['payment_method'] );
        $this->assertSame( 'merchant-ref', $valid['filters']['search'] );

        $this->assertFalse( NicePay_Transactions::parse_filters( array( 'filter_status' => 'anything' ) )['valid'] );
        $this->assertFalse( NicePay_Transactions::parse_filters( array( 'filter_method' => 'CRYPTO' ) )['valid'] );
        $this->assertFalse( NicePay_Transactions::parse_filters( array( 'date_from' => '2026-02-30' ) )['valid'] );
        $this->assertFalse( NicePay_Transactions::parse_filters( array( 'date_from' => '2026-08-20', 'date_to' => '2026-08-01' ) )['valid'] );
        $this->assertFalse( NicePay_Transactions::parse_filters( array( 's' => array( 'unexpected' ) ) )['valid'] );
    }

    public function test_financial_summary_uses_all_filters_and_groups_by_currency_without_select_star(): void {
        global $wpdb;

        $wpdb->summary_rows = array(
            (object) array(
                'currency'          => 'KRW',
                'transaction_count' => '2',
                'total_amount'      => '1500',
                'refunded_amount'   => '200',
                'remaining_amount'  => '1300',
            ),
        );
        $state = NicePay_Transactions::parse_filters(
            array(
                'filter_status' => 'paid',
                'filter_method' => 'CARD',
                'date_from'     => '2026-08-01',
                'date_to'       => '2026-08-20',
                's'             => 'order_10%',
            )
        );

        $rows  = NicePay_Transactions::get_financial_summary( $state['filters'] );
        $query = end( $wpdb->queries );

        $this->assertCount( 1, $rows );
        $this->assertStringContainsString( 'SUM(captured_amount)', $query );
        $this->assertStringContainsString( 'SUM(refunded_amount)', $query );
        $this->assertStringContainsString( 'SUM(remaining_amount)', $query );
        $this->assertStringContainsString( 'GROUP BY currency', $query );
        $this->assertStringContainsString( "status = 'paid'", $query );
        $this->assertStringContainsString( "payment_method = 'CARD'", $query );
        $this->assertStringContainsString( "created_at >= '2026-08-01 00:00:00'", $query );
        $this->assertStringContainsString( "created_at <= '2026-08-20 23:59:59'", $query );
        $this->assertStringContainsString( 'buyer_name LIKE', $query );
        $this->assertStringNotContainsString( 'SELECT *', $query );
    }

	public function test_local_calendar_filter_bounds_are_converted_to_utc(): void {
		global $wpdb, $wp_timezone_string_test;
		$wp_timezone_string_test = 'Asia/Seoul';
		$state = NicePay_Transactions::parse_filters(
			array( 'date_from' => '2026-08-20', 'date_to' => '2026-08-20' )
		);

		NicePay_Transactions::get_financial_summary( $state['filters'] );
		$query = end( $wpdb->queries );

		$this->assertStringContainsString( "created_at >= '2026-08-19 15:00:00'", $query );
		$this->assertStringContainsString( "created_at <= '2026-08-20 14:59:59'", $query );
	}

    public function test_financial_summary_revalidates_direct_filters_and_binds_search_text(): void {
        global $wpdb;

        NicePay_Transactions::get_financial_summary(
            array(
                'status' => "paid' OR 1=1 --",
                'search' => "merchant' OR 1=1 --",
            )
        );
        $query = end( $wpdb->queries );

        $this->assertStringNotContainsString( "status = 'paid' OR 1=1", $query );
        $this->assertStringContainsString( "status = ''", $query );
        $this->assertStringContainsString( "merchant\\' OR 1=1 --", $query );
        $this->assertStringNotContainsString( 'WHERE 1=1', $query );
    }

    public function test_csv_is_allowlisted_formula_safe_filtered_paginated_and_bounded(): void {
        global $wpdb;

        $this->assertSame( 250, NicePay_Transactions::CSV_BATCH_SIZE );
        $this->assertSame( 10000, NicePay_Transactions::CSV_MAX_ROWS );

        $base = array(
            'id'                 => 17,
            'tid'                => '=HYPERLINK("https://example.invalid")',
            'wc_order_id'        => 42,
            'moid'               => '+merchant-order',
            'flow'               => 'woocommerce',
            'currency'           => 'KRW',
            'amount'             => '1000',
            'captured_amount'    => '1000',
            'refunded_amount'    => '100',
            'remaining_amount'   => '900',
            'payment_method'     => 'CARD',
            'status'             => 'paid',
            'result_code'        => '4111111111111111',
            'mode'               => 'live',
            'created_at'         => '2026-08-20 10:00:00',
            'approved_at'        => '2026-08-20 10:00:01',
            'buyer_email'        => 'buyer@example.com',
            'buyer_tel'          => '010-1234-5678',
            'auth_token'         => 'secret-auth-token',
            'payment_data'       => '{"CardNo":"4111111111111111"}',
        );
        $second = $base;
        $second['id'] = 18;
        $second['tid'] = 'safe-tid';
        $second['moid'] = 'safe-moid';
        $third = $base;
        $third['id'] = 19;
        $third['tid'] = '@SUM(1+1)';
        $third['mode'] = '=invalid';
        $wpdb->csv_batches = array( array( $base, $second ), array( $third ) );

        $stream = fopen( 'php://temp', 'w+' );
        $count  = NicePay_Transactions::write_csv_export(
            $stream,
            NicePay_Transactions::parse_filters( array( 'filter_status' => 'paid' ) )['filters'],
            3,
            2
        );
        rewind( $stream );
        $rows = array();
        while ( false !== ( $row = fgetcsv( $stream ) ) ) {
            $rows[] = $row;
        }
        fclose( $stream );

        $this->assertSame( 3, $count );
        $this->assertCount( 4, $rows );
		$this->assertCount( 20, $rows[0] );
        $this->assertNotContains( 'Buyer Email', $rows[0] );
        $this->assertNotContains( 'Payment Data', $rows[0] );
        $this->assertSame( '\'=HYPERLINK("https://example.invalid")', $rows[1][1] );
        $this->assertSame( '\'+merchant-order', $rows[1][3] );
        $this->assertSame( '[redacted]', $rows[1][12] );
        $this->assertSame( '\'@SUM(1+1)', $rows[3][1] );
        $this->assertSame( '[redacted]', $rows[3][13] );
        $this->assertStringNotContainsString( 'buyer@example.com', wp_json_encode( $rows ) );
        $this->assertStringNotContainsString( 'secret-auth-token', wp_json_encode( $rows ) );
        $this->assertCount( 2, $wpdb->queries );
        $this->assertStringContainsString( 'LIMIT 2', $wpdb->queries[0] );
        $this->assertStringNotContainsString( 'OFFSET', $wpdb->queries[0] );
        $this->assertStringContainsString( "created_at < '2026-08-20 10:00:00'", $wpdb->queries[1] );
        $this->assertStringContainsString( "created_at = '2026-08-20 10:00:00' AND id < 18", $wpdb->queries[1] );
        $this->assertStringContainsString( 'LIMIT 1', $wpdb->queries[1] );
        $this->assertStringNotContainsString( 'SELECT *', implode( "\n", $wpdb->queries ) );
        $this->assertStringNotContainsString( 'buyer_email', implode( "\n", $wpdb->queries ) );
        $this->assertStringNotContainsString( 'auth_token', implode( "\n", $wpdb->queries ) );
        $this->assertStringNotContainsString( 'payment_data', implode( "\n", $wpdb->queries ) );
    }

    public function test_export_action_is_registered_and_guards_before_output_headers(): void {
        $source = file_get_contents( NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php' );
        $this->assertStringContainsString( "add_action( 'admin_post_nicepay_export_transactions'", $source );
        $method = substr( $source, strpos( $source, 'public function handle_csv_export()' ) );
        $capability_position = strpos( $method, 'nicepay_current_user_can_manage_payments()' );
        $nonce_position      = strpos( $method, "check_admin_referer( 'nicepay_export_transactions' )" );
        $filter_position     = strpos( $method, 'self::parse_filters( $_GET )' );
        $header_position     = strpos( $method, "header( 'Content-Type: text/csv; charset=UTF-8' )" );

        $this->assertIsInt( $capability_position );
        $this->assertIsInt( $nonce_position );
        $this->assertIsInt( $filter_position );
        $this->assertIsInt( $header_position );
        $this->assertLessThan( $nonce_position, $capability_position );
        $this->assertLessThan( $filter_position, $nonce_position );
        $this->assertLessThan( $header_position, $filter_position );
    }

    public function test_export_action_enforces_capability_nonce_and_filter_validation(): void {
        global $nicepay_admin_test_can_manage, $nicepay_admin_test_nonce_valid, $nicepay_admin_test_nonce_checks;

        $handler = new NicePay_Transactions();

        $nicepay_admin_test_can_manage = false;
        try {
            $handler->handle_csv_export();
            $this->fail( 'Unauthorized export must terminate.' );
        } catch ( NicePayAdminOperationsDieException $error ) {
            $this->assertSame( 403, $error->getCode() );
        }
        $this->assertSame( array(), $nicepay_admin_test_nonce_checks );

        $nicepay_admin_test_can_manage = true;
        $nicepay_admin_test_nonce_valid = false;
        try {
            $handler->handle_csv_export();
            $this->fail( 'Invalid export nonce must terminate.' );
        } catch ( NicePayAdminOperationsDieException $error ) {
            $this->assertSame( 403, $error->getCode() );
        }
        $this->assertSame( array( 'nicepay_export_transactions' ), $nicepay_admin_test_nonce_checks );

		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( NicePay_Installer::VERIFIED_VERSION_OPTION, NicePay_Installer::schema_version() );
        $nicepay_admin_test_nonce_valid = true;
        $_GET = array( 'filter_status' => 'not-a-real-status' );
        try {
            $handler->handle_csv_export();
            $this->fail( 'Invalid filters must terminate before CSV output.' );
        } catch ( NicePayAdminOperationsDieException $error ) {
            $this->assertSame( 400, $error->getCode() );
        }
    }

    public function test_system_report_contains_operational_allowlist_and_no_secrets_or_host_data(): void {
        global $wp_options;

		update_option( NicePay_Installer::VERSION_OPTION, NicePay_Installer::schema_version() );
		update_option( NicePay_Installer::VERIFIED_VERSION_OPTION, NicePay_Installer::schema_version() );
        update_option( 'nicepay_mode', 'live' );
        update_option( 'nicepay_currency', 'KRW' );
        update_option( 'nicepay_enabled_methods', array( 'CARD', 'BANK' ) );
        update_option( 'woocommerce_nicepay_settings', array( 'enabled' => 'yes', 'merchant_key' => 'wc-secret' ) );
        update_option( 'nicepay_standalone_enabled', 'no' );
        update_option( 'permalink_structure', '/%postname%/' );
        update_option( 'nicepay_live_mid', 'sensitive-mid' );
        update_option( 'nicepay_live_merchant_key', 'sensitive-key' );
        $wp_options['unrelated_path'] = '/srv/private/wordpress';

        $report = NicePay_Admin::get_system_report_data();
        $text   = NicePay_Admin::format_system_report( $report );

        $this->assertSame( 'yes', $report['schema_ready'] );
        $this->assertSame( 'live', $report['mode'] );
        $this->assertSame( 'yes', $report['gateway_enabled'] );
        $this->assertSame( 'BANK,CARD', $report['enabled_payment_methods'] );
        $this->assertSame( 'KRW', $report['currency'] );
        $this->assertSame( 'yes', $report['https'] );
        $this->assertSame( 'scheduled', $report['recovery_cron'] );
		$this->assertSame( 'indefinite', $report['financial_retention'] );
		$this->assertSame( 'not_required', $report['financial_retention_cron'] );
        $this->assertSame( 'pretty', $report['permalinks'] );
        $this->assertArrayHasKey( 'woocommerce_blocks', $report );
        $this->assertArrayHasKey( 'woocommerce_hpos', $report );
        $this->assertStringNotContainsString( 'sensitive-mid', $text );
        $this->assertStringNotContainsString( 'sensitive-key', $text );
        $this->assertStringNotContainsString( 'wc-secret', $text );
        $this->assertStringNotContainsString( '/srv/private', $text );
        $this->assertStringNotContainsString( 'http://', $text );
        $this->assertStringNotContainsString( 'https://', $text );
    }

    public function test_admin_ui_exposes_escaped_report_filtered_export_and_contrast_variables(): void {
        $transactions_source = file_get_contents( NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php' );
        $settings_source     = file_get_contents( NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-admin.php' );

        $this->assertStringContainsString( "wp_nonce_url(", $transactions_source );
        $this->assertStringContainsString( "esc_url( \$export_url )", $transactions_source );
        $this->assertStringContainsString( 'GROUP BY currency', $transactions_source );
        $this->assertStringContainsString( 'tab=system-report', $settings_source );
        $this->assertStringContainsString( 'esc_textarea( $report_text )', $settings_source );
        $this->assertStringContainsString( 'esc_attr( $report_text )', $settings_source );
        $this->assertStringContainsString( 'nicepay_get_contrast_color( $preview_background )', $settings_source );
        $this->assertStringContainsString( '--nicepay-button-background:', $settings_source );
        $this->assertStringContainsString( '--nicepay-button-text:', $settings_source );
        $this->assertStringContainsString( "add_action( 'woocommerce_admin_order_data_after_order_details'", $settings_source );
        $this->assertStringContainsString( 'nicepay_current_user_can_manage_payments()', $settings_source );
        $this->assertStringContainsString( 'NicePay_Transactions::get_safe_detail_fields( $transaction )', $settings_source );
    }

    public function test_order_admin_summary_renders_safe_ledger_fields_without_tid_or_pii(): void {
        global $wpdb;

        $wpdb->get_row_result = (object) array(
            'id'                 => 17,
            'tid'                => 'sensitive-tid-not-for-panel',
            'status'             => 'paid',
            'currency'           => 'KRW',
            'captured_amount'    => '1000',
            'refunded_amount'    => '100',
            'remaining_amount'   => '900',
            'result_code'        => '3001',
            'result_msg'         => 'Approved BuyerEmail=buyer@example.com',
            'mode'               => 'live',
            'payment_method'     => 'CARD',
            'pay_method_name'    => 'Credit card',
            'card_name'          => 'Shinhan',
            'card_code'          => '06',
            'card_quota'         => '00',
            'auth_token'         => 'secret-auth-token',
            'buyer_email'        => 'buyer@example.com',
            'payment_data'       => '{"CardNo":"4111111111111111"}',
        );
        $order = new class() {
            public function get_id() {
                return 42;
            }
            public function get_meta( $key ) {
                return '_nicepay_tid' === $key ? 'sensitive-tid-not-for-panel' : '';
            }
        };

        ob_start();
        ( new NicePay_Admin() )->render_order_payment_summary( $order );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Paid', $output );
        $this->assertStringContainsString( '1,000 KRW', $output );
        $this->assertStringContainsString( 'Provider result code', $output );
        $this->assertStringContainsString( '[redacted]', $output );
        $this->assertStringContainsString( 'transaction_id=17', $output );
        $this->assertStringNotContainsString( 'sensitive-tid-not-for-panel', $output );
        $this->assertStringNotContainsString( 'buyer@example.com', $output );
        $this->assertStringNotContainsString( 'secret-auth-token', $output );
        $this->assertStringNotContainsString( '4111111111111111', $output );
        $this->assertStringContainsString( "tid = 'sensitive-tid-not-for-panel'", end( $wpdb->queries ) );
        $this->assertStringContainsString( 'wc_order_id = 42', end( $wpdb->queries ) );
    }
}
