<?php
/**
 * Admin settings page for Acumatica Sync
 * File: admin/settings-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Options holding a secret, which the form never echoes back.
 *
 * @return string[]
 */
function acumatica_secret_options(): array {
    return [ 'acumatica_client_secret', 'acumatica_password' ];
}

/**
 * Keep the stored secret when the field is submitted blank.
 *
 * The form renders these fields empty so the credentials never appear in the
 * page source, which means every save posts an empty string unless the admin
 * actually typed a new value.
 *
 * ponytail: no way to blank a secret from the UI, since blank means "keep".
 * Rotating credentials is a write, not a delete, so this has not come up. If it
 * does, add a "clear" checkbox per field rather than a sentinel value.
 */
function acumatica_sanitize_secret( string $option, mixed $value ): string {
    $value = trim( (string) $value );

    return '' !== $value ? $value : (string) get_option( $option, '' );
}

/**
 * Reduce one posted payment row to exactly the known fields, all present.
 */
function acumatica_sanitize_payment_row( mixed $row ): array {
    $clean = [];

    foreach ( array_keys( Acumatica_Config::PAYMENT_FIELDS ) as $key ) {
        $clean[ $key ] = sanitize_text_field( (string) ( is_array( $row ) ? ( $row[ $key ] ?? '' ) : '' ) );
    }

    return $clean;
}

/**
 * Sanitise the repeatable payment map.
 *
 * Rows with no WooCommerce slug are dropped. That covers both the blank block
 * the Add button appends and left unfilled, and an existing block the Remove
 * button deleted from the DOM before saving.
 */
function acumatica_sanitize_payment_map( mixed $value ): array {
    $rows = [];

    foreach ( is_array( $value ) ? $value : [] as $row ) {
        $clean = acumatica_sanitize_payment_row( $row );

        if ( '' !== $clean['wc_method'] ) {
            $rows[] = $clean;
        }
    }

    return $rows;
}

/**
 * Accept a bare hostname, or a pasted URL to save an admin the edit.
 */
function acumatica_sanitize_host( mixed $value ): string {
    $host = strtolower( trim( (string) $value ) );
    $host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
    $host = explode( '/', $host )[0];

    return sanitize_text_field( $host );
}

/**
 * Register settings
 */
add_action( 'admin_init', function(): void {
    $text = [ 'sanitize_callback' => 'sanitize_text_field' ];
    $url  = [ 'sanitize_callback' => 'esc_url_raw' ];

    $settings = [
        'acumatica_token_url'           => $url,
        'acumatica_api_url'             => $url,
        'acumatica_client_id'           => $text,
        'acumatica_username'            => $text,
        'acumatica_endpoint_path'       => $text + [ 'default' => 'entity/Default2/23.200.001' ],
        'acumatica_site_host'           => [ 'sanitize_callback' => 'acumatica_sanitize_host' ],
        'acumatica_order_type'          => $text,
        'acumatica_customer_id'         => $text,
        'acumatica_website'             => $text,
        'acumatica_payment_map'         => [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => 'acumatica_sanitize_payment_map',
        ],
        'acumatica_payment_fallback'    => [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => 'acumatica_sanitize_payment_row',
        ],
        'acumatica_sync_enabled'        => [
            'default'           => '1',
            'sanitize_callback' => static fn( $value ): string => '1' === (string) $value ? '1' : '0',
        ],
        'acumatica_fee_retry_delay'     => [
            'type'              => 'integer',
            'default'           => 5,
            'sanitize_callback' => static fn( $value ): int => max( 1, (int) $value ),
        ],
        'acumatica_fee_max_attempts'    => [
            'type'              => 'integer',
            'default'           => 3,
            'sanitize_callback' => static fn( $value ): int => max( 0, (int) $value ),
        ],
    ];

    foreach ( acumatica_secret_options() as $option ) {
        $settings[ $option ] = [
            'sanitize_callback' => static fn( $value ): string => acumatica_sanitize_secret( $option, $value ),
        ];
    }

    foreach ( $settings as $setting => $args ) {
        register_setting( 'acumatica_sync_options', $setting, $args );
    }
} );

/**
 * Render a password field that starts empty and can be revealed while typing.
 */
function acumatica_secret_field( string $name ): void {
    $stored = '' !== (string) get_option( $name, '' );
    ?>
    <div class="acm-secret">
        <?php // new-password, not off: browsers ignore "off" on password inputs and
              // would autofill the admin's own login password over the stored one. ?>
        <input type="password" name="<?php echo esc_attr( $name ); ?>" class="regular-text"
            value="" autocomplete="new-password"
            placeholder="<?php echo $stored ? '•••••••••••• (saved)' : 'Not set'; ?>">
        <button type="button" class="button acm-reveal" aria-pressed="false" aria-label="Show value">
            <span class="dashicons dashicons-visibility"></span>
        </button>
    </div>
    <p class="description">
        <?php echo $stored
            ? 'Stored. Leave blank to keep the current value.'
            : 'No value stored yet.'; ?>
    </p>
    <?php
}

/**
 * Current access token state, shared by the header pill and the Status section.
 *
 * @return array{token:string, refresh:string, expires:int, state:string, label:string}
 */
function acumatica_token_state(): array {
    $token   = (string) get_option( 'acumatica_access_token', '' );
    $expires = (int) get_option( 'acumatica_access_token_expires', 0 );

    if ( '' === $token ) {
        $state = 'idle';
        $label = 'No token';
    } elseif ( time() < $expires ) {
        $state = 'ok';
        $label = 'Token valid, expires in ' . human_time_diff( time(), $expires );
    } else {
        $state = 'fail';
        $label = 'Token expired';
    }

    return [
        'token'   => $token,
        'refresh' => (string) get_option( 'acumatica_refresh_token', '' ),
        'expires' => $expires,
        'state'   => $state,
        'label'   => $label,
    ];
}

/**
 * Payment gateway slugs installed on this site, for the method datalist.
 *
 * Only a suggestion list. The field stays free text so a row for a gateway that
 * is switched off, or not installed yet, can still be typed and still saves.
 *
 * @return array<string, string> slug => title
 */
function acumatica_gateway_choices(): array {
    if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
        return [];
    }

    $choices = [];

    // Includes gateways that are installed but switched off, since a mapping
    // outlives a gateway being toggled.
    foreach ( WC_Payment_Gateways::instance()->payment_gateways() as $gateway ) {
        $choices[ (string) $gateway->id ] = (string) $gateway->get_title();
    }

    ksort( $choices );

    return $choices;
}

/**
 * Render one payment-method block.
 *
 * $index is a string so the same function renders both the saved blocks and the
 * hidden <template> the Add button clones, which carries the literal __i__ the
 * script swaps for a fresh index.
 */
function acumatica_payment_method_block( string $index, array $row, array $fallback ): void {
    $name = 'acumatica_payment_map[' . $index . ']';
    ?>
    <div class="acm-method">
        <div class="acm-method-head">
            <label class="acm-field acm-field-slug">
                <span>WooCommerce method</span>
                <input type="text" list="acm-gateways" class="acm-slug"
                    name="<?php echo esc_attr( $name ); ?>[wc_method]"
                    value="<?php echo esc_attr( $row['wc_method'] ); ?>"
                    placeholder="e.g. stripe" autocomplete="off" spellcheck="false">
            </label>
            <button type="button" class="button acm-remove-method">Remove</button>
        </div>
        <div class="acm-method-fields">
            <?php foreach ( Acumatica_Config::PAYMENT_FIELDS as $key => $label ) : ?>
                <?php if ( 'wc_method' === $key ) { continue; } ?>
                <label class="acm-field">
                    <span><?php echo esc_html( $label ); ?></span>
                    <input type="text"
                        name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]"
                        value="<?php echo esc_attr( $row[ $key ] ); ?>"
                        placeholder="<?php echo esc_attr( acumatica_inherit_hint( $key, $fallback ) ); ?>"
                        autocomplete="off" spellcheck="false">
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * Placeholder text showing what a blank cell will actually send.
 *
 * The inheritance rules used to be a paragraph of prose above the table. Same
 * rules, shown in the field that obeys them.
 */
function acumatica_inherit_hint( string $key, array $fallback ): string {
    // The fee key is taken literally rather than inherited, so a method with no
    // processor fee does not sit waiting for one that never arrives.
    if ( 'fee_meta_key' === $key ) {
        return 'None. Not inherited';
    }

    if ( 'acumatica_method' === $key && '' === $fallback[ $key ] ) {
        return 'Slug, upper-cased';
    }

    return '' !== $fallback[ $key ]
        ? 'Default: ' . $fallback[ $key ]
        : 'Empty';
}

/**
 * Handle the Test / Refresh buttons, which post to this page rather than
 * options.php. Both share one nonce; the button name picks the action.
 */
function acumatica_handle_settings_actions(): void {
    if ( ! isset( $_POST['acumatica_action_nonce'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to do that.' );
    }

    check_admin_referer( 'acumatica_settings_action', 'acumatica_action_nonce' );

    if ( isset( $_POST['acumatica_refresh'] ) ) {
        $result = acumatica_refresh_token();

        if ( is_wp_error( $result ) ) {
            add_settings_error( 'acumatica_sync_options', 'refresh', 'Token refresh failed: ' . $result->get_error_message() );
        } else {
            add_settings_error( 'acumatica_sync_options', 'refresh', 'Access token refreshed.', 'success' );
        }

        return;
    }

    if ( isset( $_POST['acumatica_test'] ) ) {
        $token = acumatica_get_token( true );

        if ( is_wp_error( $token ) ) {
            add_settings_error( 'acumatica_sync_options', 'test', 'Connection failed: ' . $token->get_error_message() );
        } else {
            add_settings_error( 'acumatica_sync_options', 'test', 'Connection successful. Token obtained.', 'success' );
        }
    }
}

/**
 * Render the settings page
 */
function acumatica_sync_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to view this page.' );
    }

    acumatica_handle_settings_actions();

    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'acumatica_sync_options', 'saved', 'Settings saved.', 'success' );
    }

    $current      = Acumatica_Config::get_site_config();
    $payment_rows = Acumatica_Config::get_payment_map();
    $fallback_row = Acumatica_Config::get_payment_fallback();
    $gateways     = acumatica_gateway_choices();
    $token        = acumatica_token_state();
    $blocked      = ! Acumatica_Config::is_known_host();
    $sync_on      = '1' === get_option( 'acumatica_sync_enabled', '1' );
    $endpoint_preview = rtrim( (string) get_option( 'acumatica_api_url' ), '/' ) . '/' . acumatica_endpoint_path() . '/SalesOrder';
    ?>
    <div class="wrap acm-wrap">
        <div class="acm-head">
            <div>
                <h1>Acumatica Sync</h1>
                <p>Connection, sync behaviour and site mapping for the WooCommerce → Acumatica bridge.</p>
            </div>
            <div class="acm-head-actions">
                <span class="acm-pill acm-pill-<?php echo esc_attr( $sync_on && ! $blocked ? 'ok' : 'idle' ); ?>">
                    <?php echo esc_html( $sync_on && ! $blocked ? 'Sync on' : 'Sync off' ); ?>
                </span>
                <span class="acm-pill acm-pill-<?php echo esc_attr( $token['state'] ); ?>">
                    <?php echo esc_html( $token['label'] ); ?>
                </span>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=acumatica-sync' ) ); ?>">View logs</a>
            </div>
        </div>

        <?php settings_errors( 'acumatica_sync_options' ); ?>

        <?php if ( $blocked ) : ?>
            <div class="notice notice-error inline">
                <p>
                    <strong>Syncing is blocked.</strong>
                    <?php echo esc_html( Acumatica_Config::sync_disabled_reason() ); ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php" class="acm-form">
            <?php settings_fields( 'acumatica_sync_options' ); ?>

            <!-- ------------------------------------------------ Connection -->
            <section class="acm-section">
                <h2>Connection</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="acm-token-url">Token URL</label></th>
                        <td>
                            <input type="url" id="acm-token-url" name="acumatica_token_url" class="large-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_token_url' ) ); ?>"
                                placeholder="https://yourinstance.acumatica.com/identity/connect/token">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-api-url">API base URL</label></th>
                        <td>
                            <input type="url" id="acm-api-url" name="acumatica_api_url" class="large-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_api_url' ) ); ?>"
                                placeholder="https://yourinstance.acumatica.com/">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-endpoint">Endpoint path</label></th>
                        <td>
                            <input type="text" id="acm-endpoint" name="acumatica_endpoint_path" class="large-text"
                                value="<?php echo esc_attr( acumatica_endpoint_path() ); ?>"
                                placeholder="entity/Default2/23.200.001">
                            <p class="description">
                                Sits between the base URL and the resource name:
                                <code>entity/{endpoint}/{version}</code>. Use your own endpoint name
                                instead of <code>Default2</code> if you publish an extended one, and
                                update the version after an Acumatica upgrade.
                            </p>
                            <p class="description">
                                Resolves to <code><?php echo esc_html( $endpoint_preview ); ?></code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-client-id">Client ID</label></th>
                        <td>
                            <input type="text" id="acm-client-id" name="acumatica_client_id" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_client_id' ) ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Client secret</th>
                        <td><?php acumatica_secret_field( 'acumatica_client_secret' ); ?></td>
                    </tr>
                    <tr>
                        <th><label for="acm-username">Username</label></th>
                        <td>
                            <input type="text" id="acm-username" name="acumatica_username" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_username' ) ); ?>"
                                autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th>Password</th>
                        <td><?php acumatica_secret_field( 'acumatica_password' ); ?></td>
                    </tr>
                    <tr>
                        <th>Check it works</th>
                        <td>
                            <button type="submit" form="acm-actions" name="acumatica_test" class="button">
                                Test connection
                            </button>
                            <button type="submit" form="acm-actions" name="acumatica_refresh" class="button">
                                Refresh token
                            </button>
                            <p class="description">Save first. Both buttons use the stored credentials.</p>
                        </td>
                    </tr>
                </table>
            </section>

            <!-- ------------------------------------------------- This site -->
            <section class="acm-section">
                <h2>This site</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="acm-site-host">Authorised host</label></th>
                        <td>
                            <input type="text" id="acm-site-host" name="acumatica_site_host"
                                class="regular-text"
                                value="<?php echo esc_attr( Acumatica_Config::authorised_host() ); ?>"
                                placeholder="<?php echo esc_attr( $current['host'] ); ?>">
                            <p class="description">
                                This site is <code><?php echo esc_html( $current['host'] ); ?></code>.
                                Syncing runs only while the two match. That is what stops a staging
                                copy or a restored database, which carry these same settings, from
                                posting test orders into Acumatica as real ones.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-order-type">Order type</label></th>
                        <td>
                            <input type="text" id="acm-order-type" name="acumatica_order_type"
                                class="small-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_order_type', '' ) ); ?>">
                            <p class="description">Acumatica order type used for every order from this site.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-customer-id">Customer ID</label></th>
                        <td>
                            <input type="text" id="acm-customer-id" name="acumatica_customer_id"
                                class="regular-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_customer_id', '' ) ); ?>">
                            <p class="description">The Acumatica customer that web orders are billed to.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-website">Website field</label></th>
                        <td>
                            <input type="text" id="acm-website" name="acumatica_website"
                                class="regular-text"
                                value="<?php echo esc_attr( get_option( 'acumatica_website', '' ) ); ?>"
                                placeholder="<?php echo esc_attr( $current['host'] ); ?>">
                            <p class="description">
                                Sent as the order's Website value. Acumatica's field is short, so use
                                an abbreviation where the hostname does not fit. Blank sends the host.
                            </p>
                        </td>
                    </tr>
                </table>
            </section>

            <!-- ------------------------------------------------------ Sync -->
            <section class="acm-section">
                <h2>Sync behaviour</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Enabled</th>
                        <td>
                            <label>
                                <?php // Unchecked boxes post nothing, so pair with a hidden 0. ?>
                                <input type="hidden" name="acumatica_sync_enabled" value="0">
                                <input type="checkbox" name="acumatica_sync_enabled" value="1"
                                    <?php checked( $sync_on ); ?>>
                                Send orders and payments to Acumatica
                            </label>
                            <p class="description">
                                Turn off to stop syncing without deactivating the plugin.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="acm-fee-delay">Processor fees</label></th>
                        <td>
                            <p class="acm-inline">
                                Wait
                                <input type="number" id="acm-fee-delay" name="acumatica_fee_retry_delay"
                                    class="small-text" min="1" step="1"
                                    value="<?php echo esc_attr( (string) get_option( 'acumatica_fee_retry_delay', 5 ) ); ?>">
                                minutes between checks, up to
                                <input type="number" id="acm-fee-attempts" name="acumatica_fee_max_attempts"
                                    class="small-text" min="0" step="1"
                                    value="<?php echo esc_attr( (string) get_option( 'acumatica_fee_max_attempts', 3 ) ); ?>">
                                attempts.
                            </p>
                            <p class="description">
                                Processor fees (PayPal, Stripe) arrive on a webhook after checkout. The
                                payment waits this long for the fee before posting without it. Set
                                attempts to <code>0</code> to post immediately and never wait.
                                Worst case delay is
                                <strong><?php echo esc_html( sprintf(
                                    '%d minutes',
                                    max( 1, (int) get_option( 'acumatica_fee_retry_delay', 5 ) )
                                        * max( 0, (int) get_option( 'acumatica_fee_max_attempts', 3 ) )
                                ) ); ?></strong>.
                            </p>
                        </td>
                    </tr>
                </table>
            </section>

            <!-- -------------------------------------------- Payment methods -->
            <section class="acm-section">
                <h2>Payment methods</h2>
                <p class="description acm-section-intro">
                    One block per WooCommerce payment method. Leave a field blank to use the default
                    below it, which is what each placeholder shows.
                </p>

                <?php // Suggestions for the method field. Free text either way, so a
                      // gateway that is switched off or not installed still maps. ?>
                <datalist id="acm-gateways">
                    <?php foreach ( $gateways as $slug => $title ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $title ); ?></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="acm-methods">
                    <?php foreach ( $payment_rows as $i => $row ) : ?>
                        <?php acumatica_payment_method_block( (string) (int) $i, $row, $fallback_row ); ?>
                    <?php endforeach; ?>
                </div>

                <p class="acm-methods-empty" <?php echo $payment_rows ? 'hidden' : ''; ?>>
                    No methods mapped yet. Every method uses the defaults below.
                </p>

                <p>
                    <button type="button" class="button acm-add-method"
                        data-next-index="<?php echo esc_attr( (string) count( $payment_rows ) ); ?>">
                        <span class="dashicons dashicons-plus-alt2"></span> Add method
                    </button>
                </p>

                <?php // Cloned by the Add button. __i__ becomes the next free index. ?>
                <template id="acm-method-template">
                    <?php acumatica_payment_method_block( '__i__', Acumatica_Config::blank_payment_row(), $fallback_row ); ?>
                </template>

                <h3>Defaults</h3>
                <p class="description acm-section-intro">
                    Used for any method with no block above, and for any blank field in one. Leaving
                    the payment method blank sends the WooCommerce slug upper-cased, which Acumatica
                    will reject if it does not recognise it. The fee meta key is never inherited: a
                    method with no processor fee would otherwise sit waiting for one that never arrives.
                </p>
                <div class="acm-method acm-method-fallback">
                    <div class="acm-method-fields">
                        <?php foreach ( Acumatica_Config::PAYMENT_FIELDS as $key => $label ) : ?>
                            <?php if ( 'wc_method' === $key ) { continue; } ?>
                            <label class="acm-field">
                                <span><?php echo esc_html( $label ); ?></span>
                                <input type="text"
                                    name="acumatica_payment_fallback[<?php echo esc_attr( $key ); ?>]"
                                    value="<?php echo esc_attr( $fallback_row[ $key ] ); ?>"
                                    autocomplete="off" spellcheck="false">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------------- Status -->
            <section class="acm-section">
                <h2>Status</h2>
                <table class="acm-table">
                    <tbody>
                        <tr>
                            <th style="width:180px;">Token expires</th>
                            <td>
                                <?php echo $token['expires']
                                    ? esc_html( wp_date( 'M j, Y g:i a', $token['expires'] ) )
                                    : '—'; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Access token</th>
                            <td>
                                <code class="acm-mono"><?php echo $token['token']
                                    ? esc_html( substr( $token['token'], 0, 48 ) . '…' )
                                    : '—'; ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th>Refresh token</th>
                            <td>
                                <code class="acm-mono"><?php echo $token['refresh']
                                    ? esc_html( substr( $token['refresh'], 0, 48 ) . '…' )
                                    : '—'; ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th>Detected host</th>
                            <td><code><?php echo esc_html( $current['host'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Website field</th>
                            <td><code><?php echo esc_html( $current['website'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Order type</th>
                            <td><code><?php echo esc_html( $current['order_type'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Customer ID</th>
                            <td><code><?php echo esc_html( $current['customer_id'] ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Log purge</th>
                            <td>
                                <?php
                                $next = wp_next_scheduled( 'acumatica_logs_purge_event' );
                                echo $next
                                    ? esc_html( 'Next run ' . wp_date( 'M j, g:i a', $next ) . ' · keeps 30 days' )
                                    : 'Not scheduled';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Version</th>
                            <td>
                                <code><?php echo esc_html( ACUMATICA_SYNC_VERSION ); ?></code>
                                <a href="https://github.com/sai-giggear/acumatica-order-payment-sync/releases"
                                    target="_blank" rel="noopener">Releases</a>
                                <span class="description">
                                    Checked twice a day. "Check for updates" on the
                                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">plugins screen</a>
                                    forces it now.
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="acm-savebar">
                <?php submit_button( 'Save settings', 'primary', 'submit', false ); ?>
            </div>
        </form>

        <?php // Form owner for the Test / Refresh buttons rendered inside the sections above. ?>
        <form id="acm-actions" method="post">
            <?php wp_nonce_field( 'acumatica_settings_action', 'acumatica_action_nonce' ); ?>
        </form>
    </div>
    <?php
}
