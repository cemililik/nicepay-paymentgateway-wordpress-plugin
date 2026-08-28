<?php
/**
 * NicePay admin view.
 *
 * Variables are prepared by NicePay_Admin_Settings_View before inclusion.
 *
 * @package NicePay_Payment_Gateway
 */

defined( 'ABSPATH' ) || exit;
?>
        <div class="nicepay-sc-builder" data-edit-id="<?php echo esc_attr( $edit_id ); ?>">
            <div class="nicepay-sc-layout">
                <!-- Left: Builder Form -->
                <div class="nicepay-sc-form-panel">
                    <!-- Shortcode Name -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
                            <?php esc_html_e( 'Shortcode Name', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-field">
                            <label for="sc-name"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <input type="text" id="sc-name" placeholder="<?php esc_attr_e( 'e.g. Quick Payment', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input" maxlength="60">
                        </div>
                    </div>

                    <!-- Display Mode -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                            <?php esc_html_e( 'Display Mode', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-display-modes">
                            <label class="nicepay-sc-display-mode is-active" data-value="inline">
                                <input type="radio" name="sc-display-mode" value="inline" checked>
                                <div class="nicepay-sc-display-mode-visual">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="20" height="16" rx="2"/><rect x="5" y="7" width="14" height="2" rx="1"/><rect x="5" y="11" width="14" height="2" rx="1"/><rect x="7" y="15" width="10" height="3" rx="1.5"/></svg>
                                </div>
                                <div class="nicepay-sc-display-mode-text">
                                    <strong><?php esc_html_e( 'Inline', 'nicepay-payment-gateway' ); ?></strong>
                                    <span><?php esc_html_e( 'Full form shown on page', 'nicepay-payment-gateway' ); ?></span>
                                </div>
                            </label>
                            <label class="nicepay-sc-display-mode" data-value="modal">
                                <input type="radio" name="sc-display-mode" value="modal">
                                <div class="nicepay-sc-display-mode-visual">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="7" y="15" width="10" height="3" rx="1.5"/><rect x="4" y="5" width="16" height="14" rx="2" stroke-dasharray="3 2"/><path d="M12 9v2M12 13h.01"/></svg>
                                </div>
                                <div class="nicepay-sc-display-mode-text">
                                    <strong><?php esc_html_e( 'Modal', 'nicepay-payment-gateway' ); ?></strong>
                                    <span><?php esc_html_e( 'Button opens popup overlay', 'nicepay-payment-gateway' ); ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Required Fields -->
                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
                            <?php esc_html_e( 'Required', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-field">
                            <label for="sc-amount"><?php esc_html_e( 'Payment Amount', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <div class="nicepay-sc-input-group">
                                <input type="number" id="sc-amount" min="1" placeholder="10000" class="nicepay-sc-input">
                                <label class="screen-reader-text" for="sc-currency"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></label>
                                <select id="sc-currency" class="nicepay-sc-select-sm">
                                    <option value="KRW">KRW</option>
                                </select>
                            </div>
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-goods-name"><?php esc_html_e( 'Product / Service Name', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <input type="text" id="sc-goods-name" placeholder="<?php esc_attr_e( 'e.g. Premium Plan', 'nicepay-payment-gateway' ); ?>" class="nicepay-sc-input" maxlength="40">
                        </div>
                        <div class="nicepay-sc-field">
                            <label for="sc-goods-class"><?php esc_html_e( 'Mobile Payment Goods Type', 'nicepay-payment-gateway' ); ?> <span class="nicepay-sc-required">*</span></label>
                            <select id="sc-goods-class" class="nicepay-sc-input">
                                <option value="0"><?php esc_html_e( 'Digital content or service', 'nicepay-payment-gateway' ); ?></option>
                                <option value="1"><?php esc_html_e( 'Physical goods', 'nicepay-payment-gateway' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Required by the carrier when mobile payment is selected.', 'nicepay-payment-gateway' ); ?></p>
                        </div>
                    </div>

                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            <?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?>
                        </h3>
                        <div class="nicepay-sc-methods">
                            <label class="nicepay-sc-method-chip nicepay-sc-method-chip-all is-active" data-value="">
                                <input type="radio" name="sc-pay-method" value="" checked>
                                <?php esc_html_e( 'All Enabled', 'nicepay-payment-gateway' ); ?>
                            </label>
                            <?php foreach ( NicePay_API::get_available_methods() as $code => $label ) : ?>
                                <?php if ( in_array( $code, $enabled_methods, true ) ) : ?>
                                <label class="nicepay-sc-method-chip" data-value="<?php echo esc_attr( $code ); ?>">
                                    <input type="radio" name="sc-pay-method" value="<?php echo esc_attr( $code ); ?>">
                                    <?php echo nicepay_get_method_icon( $code ); ?>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="nicepay-sc-section">
                        <h3 class="nicepay-sc-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <?php esc_html_e( 'Appearance', 'nicepay-payment-gateway' ); ?>
                            <span class="nicepay-sc-optional"><?php esc_html_e( 'Optional', 'nicepay-payment-gateway' ); ?></span>
                        </h3>
                        <div class="nicepay-sc-field-row">
                            <div class="nicepay-sc-field">
                                <label for="sc-button-text"><?php esc_html_e( 'Button Text', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-button-text" placeholder="Pay Now" class="nicepay-sc-input">
                            </div>
                            <div class="nicepay-sc-field">
                                <label for="sc-language"><?php esc_html_e( 'Language', 'nicepay-payment-gateway' ); ?></label>
                                <select id="sc-language" class="nicepay-sc-input">
                                    <option value=""><?php esc_html_e( 'Default', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="KO"><?php esc_html_e( 'Korean', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="EN"><?php esc_html_e( 'English', 'nicepay-payment-gateway' ); ?></option>
                                    <option value="CN"><?php esc_html_e( 'Chinese', 'nicepay-payment-gateway' ); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="nicepay-sc-field-row">
                            <div class="nicepay-sc-field">
                                <label for="sc-button-color"><?php esc_html_e( 'Button Color', 'nicepay-payment-gateway' ); ?></label>
                                <div class="nicepay-sc-color-picker">
                                    <input type="color" id="sc-button-color" value="#2563eb" class="nicepay-sc-color-input">
                                    <div class="nicepay-sc-color-presets">
                                        <button type="button" class="nicepay-sc-color-swatch is-active" data-color="#2563eb" style="background:#2563eb" title="Blue"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#111827" style="background:#111827" title="Black"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#16a34a" style="background:#16a34a" title="Green"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#dc2626" style="background:#dc2626" title="Red"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#9333ea" style="background:#9333ea" title="Purple"></button>
                                        <button type="button" class="nicepay-sc-color-swatch" data-color="#ea580c" style="background:#ea580c" title="Orange"></button>
                                    </div>
                                </div>
                            </div>
                            <div class="nicepay-sc-field">
                                <label for="sc-button-class"><?php esc_html_e( 'CSS Class', 'nicepay-payment-gateway' ); ?></label>
                                <input type="text" id="sc-button-class" placeholder="nicepay-pay-button" class="nicepay-sc-input">
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="nicepay-sc-save-section">
                        <button type="button" id="sc-save-btn" class="button button-primary button-hero nicepay-sc-save-btn">
                            <?php echo $edit_data ? esc_html__( 'Update Shortcode', 'nicepay-payment-gateway' ) : esc_html__( 'Save Shortcode', 'nicepay-payment-gateway' ); ?>
                        </button>
                        <?php if ( $edit_data ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=nicepay-settings&tab=shortcode-generator' ) ); ?>" class="nicepay-sc-save-cancel">
                                <?php esc_html_e( 'or create new', 'nicepay-payment-gateway' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Preview & Output -->
                <div class="nicepay-sc-preview-panel">
                    <div class="nicepay-sc-preview-sticky">
                        <!-- Live Preview -->
                        <div class="nicepay-sc-preview-card">
                            <div class="nicepay-sc-preview-label"><?php esc_html_e( 'Live Preview', 'nicepay-payment-gateway' ); ?></div>
                            <div class="nicepay-sc-preview-live">
                                <!-- Product & Amount -->
                                <div class="nicepay-sc-pv-header">
                                    <div class="nicepay-sc-pv-product" id="sc-pv-product"><?php esc_html_e( 'Product Name', 'nicepay-payment-gateway' ); ?></div>
                                    <div class="nicepay-sc-pv-amount" id="sc-pv-amount">0 KRW</div>
                                </div>

                                <!-- Method Selector Preview -->
                                <div class="nicepay-sc-pv-methods" id="sc-pv-methods" style="display:none;">
                                    <div class="nicepay-sc-pv-section-label"><?php esc_html_e( 'Payment Method', 'nicepay-payment-gateway' ); ?></div>
                                    <div class="nicepay-sc-pv-method-options" id="sc-pv-method-options"></div>
                                </div>

                                <!-- Buyer Fields Preview -->
                                <div class="nicepay-sc-pv-fields" id="sc-pv-fields" style="display:none;">
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Name', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Email', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                    <div class="nicepay-sc-pv-field">
                                        <div class="nicepay-sc-pv-field-label"><?php esc_html_e( 'Phone', 'nicepay-payment-gateway' ); ?> *</div>
                                        <div class="nicepay-sc-pv-field-input"></div>
                                    </div>
                                </div>

                                <!-- Button -->
                                <button type="button" class="nicepay-pay-button" id="sc-preview-btn" style="width:100%;text-align:center;">
                                    <?php esc_html_e( 'Pay Now', 'nicepay-payment-gateway' ); ?>
                                </button>
                            </div>
                        </div>

                        <!-- Generated Shortcode -->
                        <div class="nicepay-sc-output-card">
                            <div class="nicepay-sc-output-header">
                                <div class="nicepay-sc-output-label"><?php esc_html_e( 'Generated Shortcode', 'nicepay-payment-gateway' ); ?></div>
                                <button type="button" class="nicepay-sc-copy-btn" id="sc-copy-btn" title="<?php esc_attr_e( 'Copy to clipboard', 'nicepay-payment-gateway' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                    <span><?php esc_html_e( 'Copy', 'nicepay-payment-gateway' ); ?></span>
                                </button>
                            </div>
                            <div class="nicepay-sc-output-code" id="sc-output">
                                <code id="sc-output-code">[nicepay_payment]</code>
                            </div>
                        </div>

                        <!-- Validation Messages -->
                        <div class="nicepay-sc-validation" id="sc-validation" style="display:none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                            <span id="sc-validation-msg"></span>
                        </div>
                    </div>
                </div>
            </div>
            </div>
