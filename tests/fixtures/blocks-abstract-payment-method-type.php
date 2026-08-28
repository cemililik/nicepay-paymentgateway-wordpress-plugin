<?php
/**
 * Minimal WooCommerce Blocks contract used by the unit-test bootstrap.
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

abstract class AbstractPaymentMethodType {
    protected $name = '';

    abstract public function initialize();

    abstract public function is_active();

    abstract public function get_payment_method_script_handles();

    abstract public function get_payment_method_data();
}
