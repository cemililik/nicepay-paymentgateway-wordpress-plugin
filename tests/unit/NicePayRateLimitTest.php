<?php
/**
 * Tests for anonymous standalone payment initialization throttling.
 */

use PHPUnit\Framework\TestCase;

class NicePayRateLimitTest extends TestCase {

    private $previous_remote_addr;

    protected function setUp(): void {
        global $wp_transients;
        $wp_transients = array();

        $this->previous_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    }

    protected function tearDown(): void {
        global $wp_transients;
        $wp_transients = array();

        if ( null === $this->previous_remote_addr ) {
            unset( $_SERVER['REMOTE_ADDR'] );
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->previous_remote_addr;
        }
    }

    public function test_rejects_requests_after_the_fixed_window_limit(): void {
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 2, 60 ) );
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 2, 60 ) );
        $this->assertFalse( nicepay_check_public_rate_limit( 'standalone_init', 2, 60 ) );
    }

    public function test_limits_are_scoped_by_server_observed_ip_and_action(): void {
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );
        $this->assertFalse( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );

        $_SERVER['REMOTE_ADDR'] = '203.0.113.11';
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->assertTrue( nicepay_check_public_rate_limit( 'another_action', 1, 60 ) );
    }

    public function test_invalid_or_missing_server_address_fails_open_without_global_key(): void {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );

        unset( $_SERVER['REMOTE_ADDR'] );
        $this->assertTrue( nicepay_check_public_rate_limit( 'standalone_init', 1, 60 ) );
    }
}
