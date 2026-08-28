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
        <form method="post" action="options.php">
            <?php settings_fields( 'nicepay_general' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="nicepay-mode"><?php esc_html_e( 'Mode', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-mode" name="nicepay_mode">
                            <option value="test" <?php selected( get_option( 'nicepay_mode' ), 'test' ); ?>>
                                <?php esc_html_e( 'Test', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="live" <?php selected( get_option( 'nicepay_mode' ), 'live' ); ?>>
                                <?php esc_html_e( 'Live', 'nicepay-payment-gateway' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Use Test mode for development. Switch to Live for production.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nicepay-language"><?php esc_html_e( 'Language', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-language" name="nicepay_language">
                            <option value="KO" <?php selected( get_option( 'nicepay_language' ), 'KO' ); ?>>
                                <?php esc_html_e( 'Korean', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="EN" <?php selected( get_option( 'nicepay_language' ), 'EN' ); ?>>
                                <?php esc_html_e( 'English', 'nicepay-payment-gateway' ); ?>
                            </option>
                            <option value="CN" <?php selected( get_option( 'nicepay_language' ), 'CN' ); ?>>
                                <?php esc_html_e( 'Chinese', 'nicepay-payment-gateway' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="nicepay-currency"><?php esc_html_e( 'Currency', 'nicepay-payment-gateway' ); ?></label></th>
                    <td>
                        <select id="nicepay-currency" name="nicepay_currency">
                            <option value="KRW" <?php selected( get_option( 'nicepay_currency' ), 'KRW' ); ?>>KRW</option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'New payment requests are limited to KRW until another currency has a verified vendor fixture.', 'nicepay-payment-gateway' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Financial record retention', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e( 'Financial record retention', 'nicepay-payment-gateway' ); ?></legend>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[mode]" value="indefinite" <?php checked( $retention['mode'], 'indefinite' ); ?>>
                                <?php esc_html_e( 'Retain indefinitely (recommended until your policy is approved)', 'nicepay-payment-gateway' ); ?>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[mode]" value="custom" <?php checked( $retention['mode'], 'custom' ); ?>>
                                <?php esc_html_e( 'Permanently delete eligible NicePay financial records after', 'nicepay-payment-gateway' ); ?>
                                <input type="number"
                                       name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[days]"
                                       value="<?php echo esc_attr( $retention_days ); ?>"
                                       min="<?php echo esc_attr( NicePay_Retention::MIN_DAYS ); ?>"
                                       max="<?php echo esc_attr( NicePay_Retention::MAX_DAYS ); ?>"
                                       step="1"
                                       class="small-text">
                                <?php esc_html_e( 'days', 'nicepay-payment-gateway' ); ?>
                            </label>

                            <div class="notice notice-warning inline">
                                <p><strong><?php esc_html_e( 'This is a permanent, legally significant deletion policy.', 'nicepay-payment-gateway' ); ?></strong></p>
                                <ul>
                                    <li><?php esc_html_e( 'NicePay cannot determine which tax, accounting, payment or privacy rules apply to your organization. Obtain legal and accounting approval for the period you enter.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'The daily job deletes eligible transactions and refund-attempt history only from this plugin. It does not delete WooCommerce orders, backups, logs or records held by NICEPAY.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'Pending, approving, reconciliation-required and unknown cancel/refund records are always protected from automatic deletion.', 'nicepay-payment-gateway' ); ?></li>
                                    <li><?php esc_html_e( 'Deleting a paid or partially refunded ledger record prevents future refunds through this plugin. Export the filtered CSV and verify a recoverable backup before enabling deletion.', 'nicepay-payment-gateway' ); ?></li>
                                </ul>
                            </div>

                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( NicePay_Retention::SETTINGS_OPTION ); ?>[acknowledged]" value="yes" <?php checked( $retention['acknowledged'], 'yes' ); ?>>
                                <strong><?php esc_html_e( 'I understand that eligible NicePay ledger records will be permanently deleted after this period.', 'nicepay-payment-gateway' ); ?></strong>
                            </label>

                            <?php if ( 'custom' === $retention['mode'] ) : ?>
                                <p class="description">
                                    <?php
                                    if ( null === $retention_count ) {
                                        esc_html_e( 'The current eligible-record count could not be calculated.', 'nicepay-payment-gateway' );
                                    } else {
                                        printf(
                                            /* translators: %s: number of records currently eligible for deletion */
                                            esc_html__( 'Records currently eligible: %s.', 'nicepay-payment-gateway' ),
                                            esc_html( number_format_i18n( $retention_count ) )
                                        );
                                    }
                                    ?>
                                    <?php if ( false !== $retention_next ) : ?>
                                        <?php
                                        printf(
                                            /* translators: %s: localized date/time of next scheduled cleanup */
                                            esc_html__( 'Next scheduled cleanup: %s.', 'nicepay-payment-gateway' ),
                                            esc_html( wp_date( 'Y-m-d H:i:s T', $retention_next ) )
                                        );
                                        ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'The cleanup schedule is not currently registered; save the settings again or check WP-Cron.', 'nicepay-payment-gateway' ); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <?php if ( is_array( $last_run ) && ! empty( $last_run['completed_at'] ) ) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %1$s: last UTC run timestamp, %2$d: records deleted, %3$s: error code or none */
                                        esc_html__( 'Last cleanup (UTC): %1$s; deleted: %2$d; error: %3$s.', 'nicepay-payment-gateway' ),
                                        esc_html( $last_run['completed_at'] ),
                                        (int) ( isset( $last_run['deleted'] ) ? $last_run['deleted'] : 0 ),
                                        esc_html( ! empty( $last_run['error_code'] ) ? $last_run['error_code'] : __( 'none', 'nicepay-payment-gateway' ) )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Plugin uninstall', 'nicepay-payment-gateway' ); ?></th>
                    <td>
                        <input type="hidden" name="nicepay_delete_data_on_uninstall" value="no">
                        <label>
                            <input type="checkbox" name="nicepay_delete_data_on_uninstall" value="yes" <?php checked( get_option( 'nicepay_delete_data_on_uninstall', 'no' ), 'yes' ); ?>>
                            <strong><?php esc_html_e( 'Permanently delete NicePay tables and settings when the plugin is uninstalled.', 'nicepay-payment-gateway' ); ?></strong>
                        </label>
                        <div class="notice notice-error inline">
                            <p><?php esc_html_e( 'Leave this disabled unless you have exported the ledger and verified a recoverable backup. Uninstall deletion cannot be undone and can remove records needed for refunds, reconciliation, accounting or legal retention.', 'nicepay-payment-gateway' ); ?></p>
                        </div>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
            </form>
