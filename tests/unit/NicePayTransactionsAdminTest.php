<?php
/**
 * Tests for the privacy-safe administrative transaction detail view model.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook, $callback ) {
        return true;
    }
}

require_once NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php';

class NicePayTransactionsAdminTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        global $wp_translate_test_callback;
        $wp_translate_test_callback = null;
    }

    public function test_detail_fields_show_persisted_provider_mode_and_safe_card_summary(): void {
        $transaction = (object) array(
            'result_code'     => '3001',
            'result_msg'      => 'Approved',
            'mode'            => 'live',
            'payment_method'  => 'CARD',
            'pay_method_name' => 'Credit card',
            'card_name'       => 'Shinhan',
            'card_code'       => '06',
            'card_quota'      => '03',
            'auth_token'      => 'must-never-be-rendered',
            'payment_data'    => '{"CardNo":"4111111111111111"}',
            'buyer_email'     => 'buyer@example.com',
            'buyer_tel'       => '010-1234-5678',
            'vbank_num'       => '1234567890123456',
        );

        $fields = $this->index_by_label( NicePay_Transactions::get_safe_detail_fields( $transaction ) );
        $output = wp_json_encode( $fields );

        $this->assertSame( '3001', $fields['Provider result code'] );
        $this->assertSame( 'Approved', $fields['Provider result message'] );
        $this->assertSame( 'Live', $fields['Environment'] );
        $this->assertSame( 'Credit card · Shinhan [06] · installment 03', $fields['Payment instrument'] );
        $this->assertStringNotContainsString( 'must-never-be-rendered', $output );
        $this->assertStringNotContainsString( '4111111111111111', $output );
        $this->assertStringNotContainsString( 'buyer@example.com', $output );
        $this->assertStringNotContainsString( '010-1234-5678', $output );
        $this->assertStringNotContainsString( '1234567890123456', $output );
    }

    public function test_provider_message_redacts_common_pan_contact_and_credential_shapes(): void {
        $transaction = (object) array(
            'result_code'     => '4111111111111111',
            'result_msg'      => 'BuyerEmail=buyer@example.com CardNo=4111-1111-1111-1111 AuthToken=abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN phone 010-1234-5678',
            'mode'            => 'test',
            'payment_method'  => 'BANK',
            'pay_method_name' => 'Bank transfer',
            'bank_name'       => 'Woori buyer@example.com',
            'bank_code'       => '020',
        );

        $fields  = $this->index_by_label( NicePay_Transactions::get_safe_detail_fields( $transaction ) );
        $output  = wp_json_encode( $fields );

        $this->assertSame( '[redacted]', $fields['Provider result code'] );
        $this->assertSame( 'Test', $fields['Environment'] );
        $this->assertStringContainsString( '[redacted]', $fields['Provider result message'] );
        $this->assertStringContainsString( '[redacted]', $fields['Payment instrument'] );
        $this->assertStringNotContainsString( 'buyer@example.com', $output );
        $this->assertStringNotContainsString( '4111-1111-1111-1111', $output );
        $this->assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN', $output );
        $this->assertStringNotContainsString( '010-1234-5678', $output );
    }

    public function test_unknown_or_missing_mode_is_not_inferred_from_current_settings(): void {
        update_option( 'nicepay_mode', 'live' );

        $fields = $this->index_by_label(
            NicePay_Transactions::get_safe_detail_fields(
                (object) array(
                    'result_code'    => '3001',
                    'mode'           => '',
                    'payment_method' => 'CELLPHONE',
                )
            )
        );

        $this->assertArrayNotHasKey( 'Environment', $fields );
        $this->assertSame( 'CELLPHONE', $fields['Payment instrument'] );
    }

    public function test_detail_view_escapes_every_label_and_value_at_output_boundary(): void {
        $source = file_get_contents( NICEPAY_PLUGIN_DIR . 'admin/class-nicepay-transactions.php' );

        $this->assertStringContainsString( "esc_html( \$detail['label'] )", $source );
        $this->assertStringContainsString( "esc_html( \$detail['value'] )", $source );
        $this->assertStringNotContainsString( '$transaction->auth_token', $source );
        $this->assertStringNotContainsString( '$transaction->payment_data', $source );
        $this->assertStringNotContainsString( '$transaction->buyer_email', $source );
        $this->assertStringNotContainsString( '$transaction->buyer_tel', $source );
        $this->assertStringNotContainsString( '$transaction->vbank_num', $source );
    }

    /**
     * @param array<int,array{label:string,value:string}> $fields Detail fields.
     * @return array<string,string>
     */
    private function index_by_label( array $fields ) {
        $indexed = array();
        foreach ( $fields as $field ) {
            $indexed[ $field['label'] ] = $field['value'];
        }
        return $indexed;
    }
}
