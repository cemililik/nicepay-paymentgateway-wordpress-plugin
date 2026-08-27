<?php
/**
 * WordPress Function Stubs
 *
 * Minimal stubs for WordPress functions used by the plugin,
 * so unit tests can run without a full WordPress environment.
 */

// In-memory options store
global $wp_options;
$wp_options = array();

// In-memory transient store with expiration timestamps.
global $wp_transients;
$wp_transients = array();

// Controllable WordPress HTTP transport for unit tests. Tests may either set a
// callback or enqueue responses; every request is captured before dispatch.
global $wp_remote_post_test_callback, $wp_remote_post_test_queue, $wp_remote_post_test_requests;
$wp_remote_post_test_callback = null;
$wp_remote_post_test_queue = array();
$wp_remote_post_test_requests = array();

// Minimal hook registry used by transport and extension-point tests.
global $wp_test_hooks, $wp_http_api_curl_test_invocations;
$wp_test_hooks = array();
$wp_http_api_curl_test_invocations = 0;

global $wp_verify_nonce_test_result, $wp_verify_nonce_test_checks;
$wp_verify_nonce_test_result = true;
$wp_verify_nonce_test_checks = array();

global $nicepay_admin_test_can_manage;
$nicepay_admin_test_can_manage = true;

global $wp_timezone_string_test;
$wp_timezone_string_test = 'UTC';

global $nicepay_admin_test_is_ssl, $nicepay_wc_currency_test, $nicepay_wc_decimals_test;
$nicepay_admin_test_is_ssl = true;
$nicepay_wc_currency_test  = 'KRW';
$nicepay_wc_decimals_test  = 0;

global $nicepay_wc_notices_test;
$nicepay_wc_notices_test = array();

if ( ! class_exists( 'NicePay_Test_Json_Response_Exception' ) ) {
	class NicePay_Test_Json_Response_Exception extends RuntimeException {
		public $success;
		public $data;
		public $status;

		public function __construct( $success, $data, $status ) {
			parent::__construct( 'WordPress JSON response', (int) $status );
			$this->success = (bool) $success;
			$this->data    = $data;
			$this->status  = (int) $status;
		}
	}
}

if ( ! class_exists( 'NicePay_Test_Redirect_Exception' ) ) {
	class NicePay_Test_Redirect_Exception extends RuntimeException {
		public $location;

		public function __construct( $location ) {
			parent::__construct( 'WordPress redirect' );
			$this->location = (string) $location;
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        global $wp_test_hooks;
        $wp_test_hooks[ $hook_name ][ $priority ][] = array(
            'callback'      => $callback,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        return add_filter( $hook_name, $callback, $priority, $accepted_args );
    }
}

if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $hook_name, $callback, $priority = 10 ) {
        global $wp_test_hooks;
        if ( empty( $wp_test_hooks[ $hook_name ][ $priority ] ) ) {
            return false;
        }

        foreach ( $wp_test_hooks[ $hook_name ][ $priority ] as $index => $entry ) {
            if ( $entry['callback'] === $callback ) {
                unset( $wp_test_hooks[ $hook_name ][ $priority ][ $index ] );
                if ( empty( $wp_test_hooks[ $hook_name ][ $priority ] ) ) {
                    unset( $wp_test_hooks[ $hook_name ][ $priority ] );
                }
                if ( empty( $wp_test_hooks[ $hook_name ] ) ) {
                    unset( $wp_test_hooks[ $hook_name ] );
                }
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $hook_name, $callback, $priority = 10 ) {
        return remove_filter( $hook_name, $callback, $priority );
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value ) {
        global $wp_test_hooks;
        $args = func_get_args();
        array_shift( $args );
        if ( empty( $wp_test_hooks[ $hook_name ] ) ) {
            return $value;
        }

        ksort( $wp_test_hooks[ $hook_name ] );
        foreach ( $wp_test_hooks[ $hook_name ] as $entries ) {
            foreach ( $entries as $entry ) {
                $accepted = max( 1, (int) $entry['accepted_args'] );
                $args[0] = call_user_func_array( $entry['callback'], array_slice( $args, 0, $accepted ) );
            }
        }
        return $args[0];
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name ) {
        global $wp_test_hooks;
        $args = func_get_args();
        array_shift( $args );
        if ( empty( $wp_test_hooks[ $hook_name ] ) ) {
            return;
        }

        ksort( $wp_test_hooks[ $hook_name ] );
        foreach ( $wp_test_hooks[ $hook_name ] as $entries ) {
            foreach ( $entries as $entry ) {
                call_user_func_array(
                    $entry['callback'],
                    array_slice( $args, 0, max( 0, (int) $entry['accepted_args'] ) )
                );
            }
        }
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $wp_options;
        return isset( $wp_options[ $option ] ) ? $wp_options[ $option ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        global $wp_options;
        $wp_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
        global $wp_options;
        if ( isset( $wp_options[ $option ] ) ) {
            return false;
        }

        $wp_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        global $wp_options;
        unset( $wp_options[ $option ] );
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $wp_transients;
        if ( ! isset( $wp_transients[ $transient ] ) ) {
            return false;
        }
        if ( $wp_transients[ $transient ]['expires'] < time() ) {
            unset( $wp_transients[ $transient ] );
            return false;
        }
        return $wp_transients[ $transient ]['value'];
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $wp_transients;
        $wp_transients[ $transient ] = array(
            'value'   => $value,
            'expires' => time() + max( 1, (int) $expiration ),
        );
        return true;
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'unit-test-salt-' . $scheme;
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        }
        return array_merge( $defaults, $args );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) {
        return filter_var( trim( $email ), FILTER_SANITIZE_EMAIL );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( $min = 0, $max = 0 ) {
        return random_int( $min, $max );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		global $wp_verify_nonce_test_result, $wp_verify_nonce_test_checks;
		$wp_verify_nonce_test_checks[] = array( 'nonce' => $nonce, 'action' => $action );
		return $wp_verify_nonce_test_result ? 1 : false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		global $nicepay_admin_test_can_manage;
		return (bool) $nicepay_admin_test_can_manage;
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		throw new NicePay_Test_Json_Response_Exception( true, $data, null === $status_code ? 200 : $status_code );
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		throw new NicePay_Test_Json_Response_Exception( false, $data, null === $status_code ? 200 : $status_code );
	}
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.com/' . ltrim( (string) $path, '/' );
    }
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		global $nicepay_admin_test_is_ssl;
		return (bool) $nicepay_admin_test_is_ssl;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() {
		global $nicepay_wc_currency_test;
		return (string) $nicepay_wc_currency_test;
	}
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		global $nicepay_wc_decimals_test;
		return (int) $nicepay_wc_decimals_test;
	}
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	function wc_get_checkout_url() {
		return 'https://example.com/checkout/';
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success' ) {
		global $nicepay_wc_notices_test;
		$nicepay_wc_notices_test[] = array( 'message' => $message, 'type' => $notice_type );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {
		throw new NicePay_Test_Redirect_Exception( $location );
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
		global $wp_timezone_string_test;
		$date = new DateTimeImmutable( (string) $date_string, new DateTimeZone( $wp_timezone_string_test ) );
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $key, $value = null, $url = '' ) {
        $args = is_array( $key ) ? $key : array( $key => $value );
        $url  = is_array( $key ) ? (string) $value : (string) $url;
        $separator = false === strpos( $url, '?' ) ? '?' : '&';
        return $url . $separator . http_build_query( $args );
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        global $wp_remote_post_test_callback, $wp_remote_post_test_queue, $wp_remote_post_test_requests;
        global $wp_http_api_curl_test_invocations;

        $wp_remote_post_test_requests[] = array(
            'url'  => $url,
            'args' => $args,
        );

        if ( function_exists( 'curl_init' ) ) {
            // The stub never performs network I/O; the URL is passed separately
            // so the transport hook can be exercised without configuring cURL.
            $handle = curl_init();
            if ( false !== $handle ) {
                do_action( 'http_api_curl', $handle, $args, $url );
                $wp_http_api_curl_test_invocations++;
                curl_close( $handle );
            }
        }

        if ( is_callable( $wp_remote_post_test_callback ) ) {
            return call_user_func( $wp_remote_post_test_callback, $url, $args );
        }

        if ( ! empty( $wp_remote_post_test_queue ) ) {
            return array_shift( $wp_remote_post_test_queue );
        }

        // Preserve the original fail-closed default when no test seam is set.
        return new WP_Error( 'stub', 'wp_remote_post is stubbed' );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        if ( is_array( $response ) && isset( $response['body'] ) ) {
            return $response['body'];
        }
        return '';
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return ( $thing instanceof WP_Error );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        $key = strtolower( (string) $key );
        return preg_replace( '/[^a-z0-9_\-]/', '', $key );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        global $wp_translate_test_callback;
        if ( is_callable( $wp_translate_test_callback ) ) {
            return call_user_func( $wp_translate_test_callback, $text, $domain );
        }
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = 'default' ) {
        echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

// WP_Error class stub
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message() {
            return $this->message;
        }

        public function get_error_data() {
            return $this->data;
        }
    }
}
