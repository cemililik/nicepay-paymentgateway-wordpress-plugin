<?php
/**
 * Regression contracts for multiple standalone payment forms on one page.
 */

use PHPUnit\Framework\TestCase;

class NicePayStandaloneTemplateTest extends TestCase {

    public function test_template_carries_instance_configuration_without_inline_global_handlers(): void {
        $template = file_get_contents( NICEPAY_PLUGIN_DIR . 'templates/standalone-payment-form.php' );

        $this->assertStringContainsString( 'data-nicepay-config-id=', $template );
        $this->assertStringContainsString( 'data-nicepay-start=', $template );
        $this->assertStringContainsString( 'class="nicepay-standalone-form"', $template );
        $this->assertStringNotContainsString( '<script>', $template );
        $this->assertStringNotContainsString( 'onclick=', $template );
        $this->assertStringNotContainsString( 'name="payForm" class="nicepay-standalone-form"', $template );
        $this->assertStringNotContainsString( 'function nicepayStartStandalone', $template );
        $this->assertStringContainsString( "array_unshift( \$button_classes, 'nicepay-pay-button' )", $template );
        $this->assertStringContainsString( '--nicepay-button-text:', $template );
    }

    public function test_frontend_reads_config_from_the_clicked_form_instance(): void {
        $script = file_get_contents( NICEPAY_PLUGIN_DIR . 'assets/js/nicepay.js' );

        $this->assertStringContainsString(
            "form.getAttribute('data-nicepay-config-id')",
            $script
        );
        $this->assertStringContainsString(
            "startStandalone(startButton.getAttribute('data-nicepay-start'))",
            $script
        );
        $this->assertStringContainsString( 'activePaymentForm && activePaymentForm !== form', $script );
        $this->assertStringContainsString( "form.setAttribute('name', 'payForm')", $script );
        $this->assertStringNotContainsString( 'nicepayStartStandalone', $script );
    }
}
