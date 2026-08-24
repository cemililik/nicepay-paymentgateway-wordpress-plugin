<?php
/**
 * Characterization tests for the NicePay callback URL SSRF policy.
 */

use PHPUnit\Framework\TestCase;

class NicePayUrlPolicyTest extends TestCase {

    private NicePay_API $api;
    private ReflectionMethod $validate_url;

    protected function setUp(): void {
        global $wp_options;
        $wp_options = array();

        update_option( 'nicepay_mode', 'test' );

        $this->api          = new NicePay_API();
        $this->validate_url = new ReflectionMethod( NicePay_API::class, 'validate_nicepay_url' );
        $this->validate_url->setAccessible( true );
    }

    protected function tearDown(): void {
        global $wp_options;
        $wp_options = array();
    }

    /**
     * @dataProvider allowedNicePayUrlProvider
     */
    public function test_allows_exact_documented_https_hosts( string $url ): void {
        $this->assertTrue( $this->validate( $url ) );
    }

    public static function allowedNicePayUrlProvider(): array {
        return array(
            'approval dc1'       => array( 'https://dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'approval dc2'       => array( 'https://dc2-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'cancel api'         => array( 'https://pg-api.nicepay.co.kr/webapi/cancel_process.jsp' ),
            'explicit tls port'  => array( 'https://dc1-api.nicepay.co.kr:443/webapi/pay_process.jsp' ),
            'cancel endpoint'    => array( 'https://pg-api.nicepay.co.kr/webapi/cancel_process.jsp' ),
            'uppercase host'     => array( 'https://DC1-API.nicepay.co.kr/webapi/pay_process.jsp' ),
            'uppercase scheme'   => array( 'HTTPS://dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
        );
    }

    /**
     * @dataProvider rejectedNicePayUrlProvider
     */
    public function test_rejects_malformed_non_tls_and_host_spoofing_urls( string $url ): void {
        $this->assertFalse( $this->validate( $url ) );
    }

    public static function rejectedNicePayUrlProvider(): array {
        return array(
            'empty'                  => array( '' ),
            'relative'               => array( '/webapi/pay_process.jsp' ),
            'scheme relative'        => array( '//dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'missing host'           => array( 'https:///webapi/pay_process.jsp' ),
            'plain http'             => array( 'http://dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'trailing host dot'      => array( 'https://dc1-api.nicepay.co.kr./webapi/pay_process.jsp' ),
            'lookalike suffix'       => array( 'https://dc1-api.nicepay.co.kr.evil.example/webapi/pay_process.jsp' ),
            'lookalike prefix'       => array( 'https://dc1-api-nicepay.co.kr/webapi/pay_process.jsp' ),
            'allowed host in query'  => array( 'https://evil.example/pay?next=https://dc1-api.nicepay.co.kr/' ),
            'allowed host in path'   => array( 'https://evil.example/dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'userinfo host spoof'    => array( 'https://dc1-api.nicepay.co.kr@evil.example/webapi/pay_process.jsp' ),
            'encoded dot host spoof' => array( 'https://dc1-api%2enicepay.co.kr/webapi/pay_process.jsp' ),
            'subdomain spoof'        => array( 'https://api.dc1-api.nicepay.co.kr/webapi/pay_process.jsp' ),
            'loopback ipv4'          => array( 'https://127.0.0.1/webapi/pay_process.jsp' ),
            'loopback ipv6'          => array( 'https://[::1]/webapi/pay_process.jsp' ),
            'non tls port'           => array( 'https://dc1-api.nicepay.co.kr:8443/webapi/pay_process.jsp' ),
            'unexpected path'        => array( 'https://dc1-api.nicepay.co.kr/admin' ),
            'payment window asset'   => array( 'https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js' ),
            'query parameters'       => array( 'https://dc2-api.nicepay.co.kr/webapi/pay_process.jsp?tx=1' ),
            'fragment'               => array( 'https://dc2-api.nicepay.co.kr/webapi/pay_process.jsp#result' ),
        );
    }

    private function validate( string $url ): bool {
        return (bool) $this->validate_url->invoke( $this->api, $url );
    }

    public function test_http_policy_disables_redirects_and_pins_utf8(): void {
        $method = new ReflectionMethod( NicePay_API::class, 'request_args' );
        $method->setAccessible( true );

        $args = $method->invoke( $this->api, array( 'MID' => 'nicepay00m' ) );

        $this->assertSame( 30, $args['timeout'] );
        $this->assertSame( 0, $args['redirection'] );
        $this->assertTrue( $args['sslverify'] );
        $this->assertSame( 'application/x-www-form-urlencoded; charset=utf-8', $args['headers']['Content-Type'] );
    }

    public function test_decode_response_rejects_non_2xx_before_json_parsing(): void {
        $method = new ReflectionMethod( NicePay_API::class, 'decode_response' );
        $method->setAccessible( true );

        $result = $method->invoke(
            $this->api,
            array(
                'response' => array( 'code' => 502 ),
                'body'     => '{"ResultCode":"3001"}',
            ),
            'approval'
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_approval_http_error', $result->get_error_code() );
    }

    public function test_decode_response_requires_valid_json_object(): void {
        $method = new ReflectionMethod( NicePay_API::class, 'decode_response' );
        $method->setAccessible( true );

        $result = $method->invoke(
            $this->api,
            array(
                'response' => array( 'code' => 200 ),
                'body'     => '<html>upstream error</html>',
            ),
            'approval'
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'nicepay_approval_parse_error', $result->get_error_code() );
    }
}
