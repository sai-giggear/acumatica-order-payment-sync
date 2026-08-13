<?php
/**
 * Admin settings page for Acumatica Sync
 * File: admin/settings-page.php
 *
 * Every field on this screen writes into one option, acumatica_settings, which
 * Acumatica_Config::save() sanitises in one pass. Field definitions live here
 * rather than in the config class: labels and help text are screen concerns,
 * and the dynamic ones read the current site to say what a blank will send.
 *
 * Layout is a sticky section nav beside cards, not wp-admin's form-table. The
 * screen is long enough that jumping to Payment methods matters, and a card per
 * group of fields reads better than one table of twenty rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_init', function(): void {
    register_setting( 'acumatica_sync_options', Acumatica_Config::OPTION, [
        'type'              => 'array',
        'default'           => Acumatica_Config::defaults(),
        'sanitize_callback' => [ Acumatica_Config::class, 'save' ],
    ] );
} );

/**
 * The sections, in order. Keys are the anchor ids the nav links to.
 *
 * @return array<string, string>
 */
function acumatica_settings_sections(): array {
    return [
        'connection'  => 'Connection',
        'site'        => 'This site',
        'behaviour'   => 'Sync behaviour',
        'payments'    => 'Payment methods',
        'diagnostics' => 'Diagnostics',
    ];
}

/**
 * Form field name for one setting.
 */
function acumatica_field_name( string $key ): string {
    return Acumatica_Config::OPTION . '[' . $key . ']';
}

/**
 * One labelled row inside a card.
 *
 * $args: label, type (text|url|secret|checkbox), class, placeholder, toggle
 * (checkbox label), desc (allows inline markup).
 */
function acumatica_field_row( string $key, array $args ): void {
    $id    = 'acm-' . str_replace( '_', '-', $key );
    $name  = acumatica_field_name( $key );
    $type  = $args['type'] ?? 'text';
    $value = (string) Acumatica_Config::get( $key );
    ?>
    <div class="acm-row">
        <?php if ( 'secret' === $type ) : ?>
            <span class="acm-row-label"><?php echo esc_html( $args['label'] ); ?></span>
        <?php else : ?>
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $args['label'] ); ?></label>
        <?php endif; ?>

        <div>
            <?php if ( 'secret' === $type ) : ?>
                <?php acumatica_secret_field( $key ); ?>

            <?php elseif ( 'checkbox' === $type ) : ?>
                <label class="acm-toggle">
                    <?php // Unchecked boxes post nothing, so pair with a hidden 0. ?>
                    <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
                    <input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
                        name="<?php echo esc_attr( $name ); ?>" value="1"
                        <?php checked( '1', $value ); ?>>
                    <?php echo esc_html( $args['toggle'] ?? '' ); ?>
                </label>

            <?php else : ?>
                <input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"
                    name="<?php echo esc_attr( $name ); ?>"
                    class="<?php echo esc_attr( $args['class'] ?? 'regular-text' ); ?>"
                    value="<?php echo esc_attr( $value ); ?>"
                    placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"
                    autocomplete="off" spellcheck="false">
            <?php endif; ?>

            <?php if ( ! empty( $args['desc'] ) ) : ?>
                <p class="description"><?php echo wp_kses_post( $args['desc'] ); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * A card of rows.
 *
 * @param string               $title  Card heading, '' for none
 * @param array<string, array> $fields key => args for acumatica_field_row()
 */
function acumatica_card( string $title, array $fields ): void {
    ?>
    <div class="acm-card">
        <?php if ( '' !== $title ) : ?>
            <h3><?php echo esc_html( $title ); ?></h3>
        <?php endif; ?>
        <?php foreach ( $fields as $key => $args ) : ?>
            <?php acumatica_field_row( $key, $args ); ?>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * Render a password field that starts empty and can be revealed while typing.
 *
 * Blank means "keep what is stored", which is why nothing is echoed back into
 * the value attribute. Acumatica_Config::save() holds the other half.
 */
function acumatica_secret_field( string $key ): void {
    $stored = '' !== (string) Acumatica_Config::get( $key );
    ?>
    <div class="acm-secret">
        <?php // new-password, not off: browsers ignore "off" on password inputs and
              // would autofill the admin's own login password over the stored one. ?>
        <input type="password" name="<?php echo esc_attr( acumatica_field_name( $key ) ); ?>"
            class="regular-text" value="" autocomplete="new-password"
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
 * Current access token state, shared by the header pill and the status tiles.
 *
 * @return array{token:string, refresh:string, expires:int, state:string, label:string, short:string}
 */
function acumatica_token_state(): array {
    $token   = (string) get_option( 'acumatica_access_token', '' );
    $expires = (int) get_option( 'acumatica_access_token_expires', 0 );

    if ( '' === $token ) {
        $state = 'idle';
        $short = 'None';
        $label = 'No token';
    } elseif ( time() < $expires ) {
        $state = 'ok';
        $short = 'Valid';
        $label = 'Token valid, expires in ' . human_time_diff( time(), $expires );
    } else {
        $state = 'fail';
        $short = 'Expired';
        $label = 'Token expired';
    }

    return [
        'token'   => $token,
        'refresh' => (string) get_option( 'acumatica_refresh_token', '' ),
        'expires' => $expires,
        'state'   => $state,
        'short'   => $short,
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
 * maxlength attribute for a mapping field, where Acumatica has a hard width.
 *
 * An over-long payment method is not rejected as too long. Acumatica cuts it
 * down to the field width and rejects what it is left with:
 * STRIPE_AFTERPAY_CLEARPAY arrived as "STRIPE AFT", which does not exist.
 * Cheaper to stop it being typed.
 */
function acumatica_payment_maxlength( string $key ): void {
    $widths = [ 'acumatica_method' => 10 ];

    if ( isset( $widths[ $key ] ) ) {
        echo 'maxlength="' . (int) $widths[ $key ] . '"';
    }
}

/**
 * Placeholder text showing what a blank field will actually send.
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
 * Render one payment-method block.
 *
 * $index is a string so the same function renders both the saved blocks and the
 * hidden <template> the Add button clones, which carries the literal __i__ the
 * script swaps for a fresh index.
 */
function acumatica_payment_method_block( string $index, array $row, array $fallback ): void {
    $name = Acumatica_Config::OPTION . '[payment_methods][' . $index . ']';
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
                        <?php acumatica_payment_maxlength( $key ); ?>
                        autocomplete="off" spellcheck="false">
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
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
 * The four things that decide whether an order can reach Acumatica, as tiles.
 *
 * @return array<int, array{label:string, value:string, state:string}>
 */
function acumatica_status_tiles( array $token, int $mapped ): array {
    $on         = '1' === (string) Acumatica_Config::get( 'enabled' );
    $host_ok    = Acumatica_Config::is_known_host();
    $configured = Acumatica_Config::is_configured();

    return [
        [
            'label' => 'Syncing',
            'value' => $on && $host_ok && $configured ? 'On' : 'Off',
            'state' => $on && $host_ok && $configured ? 'ok' : 'fail',
        ],
        [
            'label' => 'Access token',
            'value' => $token['short'],
            'state' => $token['state'],
        ],
        [
            'label' => 'This host',
            'value' => $host_ok ? 'Authorised' : ( '' === Acumatica_Config::authorised_host() ? 'Unset' : 'Mismatch' ),
            'state' => $host_ok ? 'ok' : 'fail',
        ],
        [
            'label' => 'Methods mapped',
            'value' => (string) $mapped,
            'state' => 'today',
        ],
    ];
}

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
    $sync_on      = '1' === (string) Acumatica_Config::get( 'enabled' );

    $delay        = max( 1, (int) Acumatica_Config::get( 'fee_retry_delay' ) );
    $attempts     = max( 0, (int) Acumatica_Config::get( 'fee_max_attempts' ) );
    $endpoint     = rtrim( (string) Acumatica_Config::get( 'api_url' ), '/' ) . '/' . acumatica_endpoint_path() . '/SalesOrder';
    ?>
    <div class="wrap acm-wrap acm-settings">
        <div class="acm-head">
            <div>
                <h1>Acumatica Sync</h1>
                <p>Connection, sync behaviour and site mapping.</p>
            </div>
            <div class="acm-head-actions">
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

        <?php // Four tiles rather than a status table at the bottom of a long
              // form: these are what someone opening this screen came to check. ?>
        <div class="acm-stats">
            <?php foreach ( acumatica_status_tiles( $token, count( $payment_rows ) ) as $tile ) : ?>
                <div class="acm-stat acm-stat-<?php echo esc_attr( $tile['state'] ); ?>">
                    <b><?php echo esc_html( $tile['value'] ); ?></b>
                    <span><?php echo esc_html( $tile['label'] ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="options.php" class="acm-layout">
            <?php settings_fields( 'acumatica_sync_options' ); ?>

            <?php // Sticky while the form scrolls past it. The script marks the
                  // section currently in view. ?>
            <nav class="acm-nav" aria-label="Settings sections">
                <?php foreach ( acumatica_settings_sections() as $id => $label ) : ?>
                    <a href="#<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="acm-sections">
                <section class="acm-section" id="connection">
                    <h2>Connection</h2>
                    <p class="description acm-section-intro">
                        Where the Acumatica instance lives and what this site signs in as.
                    </p>

                    <?php acumatica_card( 'Endpoint', [
                        'token_url' => [
                            'label'       => 'Token URL',
                            'type'        => 'url',
                            'class'       => 'large-text',
                            'placeholder' => 'https://yourinstance.acumatica.com/identity/connect/token',
                        ],
                        'api_url' => [
                            'label'       => 'API base URL',
                            'type'        => 'url',
                            'class'       => 'large-text',
                            'placeholder' => 'https://yourinstance.acumatica.com/',
                        ],
                        'endpoint_path' => [
                            'label'       => 'Endpoint path',
                            'class'       => 'large-text',
                            'placeholder' => 'entity/Default2/23.200.001',
                            'desc'        => 'Sits between the base URL and the resource name: <code>entity/{endpoint}/{version}</code>. '
                                . 'Use your own endpoint name instead of <code>Default2</code> if you publish an extended one, and update '
                                . 'the version after an Acumatica upgrade. Resolves to <code>' . esc_html( $endpoint ) . '</code>',
                        ],
                    ] ); ?>

                    <?php acumatica_card( 'Credentials', [
                        'client_id'     => [ 'label' => 'Client ID' ],
                        'client_secret' => [ 'label' => 'Client secret', 'type' => 'secret' ],
                        'username'      => [ 'label' => 'Username' ],
                        'password'      => [ 'label' => 'Password', 'type' => 'secret' ],
                    ] ); ?>

                    <div class="acm-card">
                        <div class="acm-row">
                            <span class="acm-row-label">Check it works</span>
                            <div>
                                <p class="acm-buttons">
                                    <button type="submit" form="acm-actions" name="acumatica_test" class="button">
                                        Test connection
                                    </button>
                                    <button type="submit" form="acm-actions" name="acumatica_refresh" class="button">
                                        Refresh token
                                    </button>
                                </p>
                                <p class="description">Save first. Both buttons use the stored credentials.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="acm-section" id="site">
                    <h2>This site</h2>
                    <p class="description acm-section-intro">
                        Which install is allowed to sync, and what goes on every order it sends.
                    </p>

                    <?php acumatica_card( '', [
                        'host' => [
                            'label'       => 'Authorised host',
                            'placeholder' => $current['host'],
                            'desc'        => 'This site is <code>' . esc_html( $current['host'] ) . '</code>. Syncing runs only while the '
                                . 'two match. A staging copy or a restored database carries these same settings, and the mismatch is what '
                                . 'keeps it from posting test orders into Acumatica as real ones.',
                        ],
                        'order_type' => [
                            'label' => 'Order type',
                            'class' => 'small-text',
                            'desc'  => 'Acumatica order type used for every order from this site.',
                        ],
                        'customer_id' => [
                            'label' => 'Customer ID',
                            'desc'  => 'The Acumatica customer that web orders are billed to.',
                        ],
                        'website' => [
                            'label'       => 'Website field',
                            'placeholder' => $current['host'],
                            'desc'        => "Sent as the order's Website value. Acumatica's field is short, so use an abbreviation where "
                                . 'the hostname does not fit. Blank sends the host.',
                        ],
                    ] ); ?>
                </section>

                <section class="acm-section" id="behaviour">
                    <h2>Sync behaviour</h2>

                    <?php acumatica_card( '', [
                        'enabled' => [
                            'label'  => 'Enabled',
                            'type'   => 'checkbox',
                            'toggle' => 'Send orders and payments to Acumatica',
                            'desc'   => 'Turn off to stop syncing without deactivating the plugin.',
                        ],
                    ] ); ?>

                    <div class="acm-card">
                        <div class="acm-row">
                            <label for="acm-fee-retry-delay">Processor fees</label>
                            <div>
                                <p class="acm-inline">
                                    Wait
                                    <input type="number" id="acm-fee-retry-delay" class="small-text" min="1" step="1"
                                        name="<?php echo esc_attr( acumatica_field_name( 'fee_retry_delay' ) ); ?>"
                                        value="<?php echo esc_attr( (string) $delay ); ?>">
                                    minutes between checks, up to
                                    <input type="number" id="acm-fee-max-attempts" class="small-text" min="0" step="1"
                                        name="<?php echo esc_attr( acumatica_field_name( 'fee_max_attempts' ) ); ?>"
                                        value="<?php echo esc_attr( (string) $attempts ); ?>">
                                    attempts.
                                </p>
                                <p class="description">
                                    Processor fees (PayPal, Stripe) arrive on a webhook after checkout. The payment waits
                                    this long for the fee before posting without it. Set attempts to <code>0</code> to post
                                    immediately and never wait. Worst case delay is
                                    <strong><?php echo esc_html( $delay * $attempts ); ?> minutes</strong>.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="acm-section" id="payments">
                    <h2>Payment methods</h2>
                    <p class="description acm-section-intro">
                        One block per WooCommerce payment method. A blank field takes the default from the Defaults card
                        below, and each placeholder shows what that will send. A block also covers the gateway's own
                        sub-methods, so <code>stripe</code> catches <code>stripe_afterpay_clearpay</code> unless that has a
                        block of its own. Payment method is capped at 10 characters, which is the width Acumatica stores.
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

                    <div class="acm-card">
                        <h3>Defaults</h3>
                        <p class="description">
                            Used for any method with no block above, and for any blank field in one. A blank payment
                            method sends the WooCommerce slug upper-cased, which Acumatica rejects unless it recognises
                            it. The fee meta key is never inherited, so a method with no processor fee does not sit
                            waiting for one that never arrives.
                        </p>
                        <div class="acm-method-fields">
                            <?php foreach ( Acumatica_Config::PAYMENT_FIELDS as $key => $label ) : ?>
                                <?php if ( 'wc_method' === $key ) { continue; } ?>
                                <label class="acm-field">
                                    <span><?php echo esc_html( $label ); ?></span>
                                    <input type="text"
                                        name="<?php echo esc_attr( Acumatica_Config::OPTION ); ?>[payment_defaults][<?php echo esc_attr( $key ); ?>]"
                                        value="<?php echo esc_attr( $fallback_row[ $key ] ); ?>"
                                        <?php acumatica_payment_maxlength( $key ); ?>
                                        autocomplete="off" spellcheck="false">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="acm-section" id="diagnostics">
                    <h2>Diagnostics</h2>
                    <div class="acm-card">
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
                                    <th>Sending as</th>
                                    <td>
                                        <code><?php echo esc_html( $current['website'] ); ?></code>
                                        order type <code><?php echo esc_html( $current['order_type'] ?: '—' ); ?></code>
                                        for customer <code><?php echo esc_html( $current['customer_id'] ?: '—' ); ?></code>
                                    </td>
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
                    </div>
                </section>

                <div class="acm-savebar">
                    <?php submit_button( 'Save settings', 'primary', 'submit', false ); ?>
                    <?php if ( ! $sync_on ) : ?>
                        <span class="acm-pill acm-pill-idle">Sync off</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <?php // Form owner for the Test / Refresh buttons rendered inside the sections above. ?>
        <form id="acm-actions" method="post">
            <?php wp_nonce_field( 'acumatica_settings_action', 'acumatica_action_nonce' ); ?>
        </form>
    </div>
    <?php
}
