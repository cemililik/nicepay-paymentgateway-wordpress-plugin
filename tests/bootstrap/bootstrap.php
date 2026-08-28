<?php
/**
 * PHPUnit Bootstrap
 *
 * Sets up WordPress function stubs so unit tests can run
 * without a full WordPress installation.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';

// Define WordPress constants
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'NICEPAY_VERSION', '2.0.1' );
define( 'NICEPAY_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/nicepay-payment-gateway.php' );
define( 'NICEPAY_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'NICEPAY_PLUGIN_URL', 'https://example.com/wp-content/plugins/nicepay-payment-gateway/' );
define( 'NICEPAY_PLUGIN_BASENAME', 'nicepay-payment-gateway/nicepay-payment-gateway.php' );
define( 'NICEPAY_JS_URL', 'https://pg-web.nicepay.co.kr/v3/common/js/nicepay-pgweb.js' );
define( 'NICEPAY_CANCEL_URL', 'https://pg-api.nicepay.co.kr/webapi/cancel_process.jsp' );
define( 'NICEPAY_TEST_MID', 'nicepay00m' );
define( 'NICEPAY_TEST_MERCHANT_KEY', 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==' );

// Load plugin files under test
require_once NICEPAY_PLUGIN_DIR . 'includes/nicepay-functions.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-api.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-installer.php';
require_once NICEPAY_PLUGIN_DIR . 'includes/class-nicepay-retention.php';
