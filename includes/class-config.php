<?php
/**
 * Settings store and derived configuration for Acumatica Sync
 * File: includes/class-config.php
 *
 * Everything an admin can set lives in one option, acumatica_settings. One row,
 * one sanitiser, one autoload decision, instead of sixteen registered options
 * that had to be kept in step by hand.
 *
 * Access tokens stay in options of their own. They are written mid-sync, and a
 * settings save rewrites the whole array, so sharing a row would let a save
 * clobber a token that a concurrent request had just refreshed.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Acumatica_Config {

    /**
     * The one option. Never autoloaded: it holds the Acumatica credentials and
     * is read during a sync or on the settings screen, never on a shop page.
     */
    public const string OPTION = 'acumatica_settings';

    /**
     * Scalar settings, key => type. The type decides how a posted value is
     * sanitised, so a new field is one line here plus one row on the screen.
     */
    public const array FIELDS = [
        'token_url'        => 'url',
        'api_url'          => 'url',
        'endpoint_path'    => 'text',
        'client_id'        => 'text',
        'client_secret'    => 'secret',
        'username'         => 'text',
        'password'         => 'secret',
        'host'             => 'host',
        'order_type'       => 'text',
        'customer_id'      => 'text',
        'website'          => 'text',
        'enabled'          => 'bool',
        'fee_retry_delay'  => 'int',
        'fee_max_attempts' => 'int',
    ];

    /**
     * Settings whose default is not an empty string.
     */
    public const array DEFAULTS = [
        'endpoint_path'    => 'entity/Default2/23.200.001',
        'enabled'          => '1',
        'fee_retry_delay'  => 5,
        'fee_max_attempts' => 3,
    ];

    /**
     * Pre-1.1 option names, and the key each one becomes.
     */
    public const array LEGACY = [
        'acumatica_token_url'        => 'token_url',
        'acumatica_api_url'          => 'api_url',
        'acumatica_endpoint_path'    => 'endpoint_path',
        'acumatica_client_id'        => 'client_id',
        'acumatica_client_secret'    => 'client_secret',
        'acumatica_username'         => 'username',
        'acumatica_password'         => 'password',
        'acumatica_site_host'        => 'host',
        'acumatica_order_type'       => 'order_type',
        'acumatica_customer_id'      => 'customer_id',
        'acumatica_website'          => 'website',
        'acumatica_sync_enabled'     => 'enabled',
        'acumatica_fee_retry_delay'  => 'fee_retry_delay',
        'acumatica_fee_max_attempts' => 'fee_max_attempts',
        'acumatica_payment_map'      => 'payment_methods',
        'acumatica_payment_fallback' => 'payment_defaults',
    ];

    /**
     * Shape of one payment-method row, and the labels the settings screen
     * renders for it.
     */
    public const array PAYMENT_FIELDS = [
        'wc_method'        => 'WooCommerce method',
        'acumatica_method' => 'Payment method',
        'entry_type'       => 'Entry type',
        'fee_meta_key'     => 'Fee meta key',
        'fee_account'      => 'Fee account',
        'fee_subaccount'   => 'Fee subaccount',
        'cash_account'     => 'Cash account',
    ];

    /* -------------------------------------------------------------- store */

    /**
     * Every setting with every key present, so no caller tests for a missing one.
     */
    public static function defaults(): array {
        return self::DEFAULTS
            + array_fill_keys( array_keys( self::FIELDS ), '' )
            + [
                'payment_methods'  => [],
                'payment_defaults' => self::blank_payment_row(),
            ];
    }

    /**
     * The stored settings, filled out with defaults.
     */
    public static function all(): array {
        $stored = get_option( self::OPTION, null );

        if ( ! is_array( $stored ) ) {
            $stored = self::migrate();
        }

        return $stored + self::defaults();
    }

    /**
     * One setting.
     */
    public static function get( string $key ): mixed {
        return self::all()[ $key ] ?? '';
    }

    /**
     * Fold the pre-1.1 one-option-per-field layout into the single option.
     *
     * Called from the upgrade step, and again from the first read on a site that
     * syncs before any admin screen loads (updated over WP-CLI, then a checkout
     * comes in). Once the new option exists this is never reached again.
     *
     * The old rows are deleted, credentials included. Keeping a second copy of
     * the Acumatica password around to make a downgrade smoother is a bad trade;
     * a downgrade means retyping the connection settings.
     */
    public static function migrate(): array {
        $found = [];

        foreach ( self::LEGACY as $old => $key ) {
            $value = get_option( $old, null );

            if ( null !== $value && false !== $value ) {
                $found[ $key ] = $value;
            }
        }

        if ( ! $found ) {
            return [];
        }

        $settings = $found + self::defaults();

        update_option( self::OPTION, $settings, false );

        foreach ( array_keys( self::LEGACY ) as $old ) {
            delete_option( $old );
        }

        return $settings;
    }

    /**
     * sanitize_callback for the option.
     *
     * The screen posts every field on every save, so this rebuilds the array
     * rather than merging into what is stored. The one exception is a secret,
     * which is posted blank unless it was retyped.
     */
    public static function save( mixed $raw ): array {
        $raw    = is_array( $raw ) ? $raw : [];
        $stored = self::all();
        $clean  = [];

        foreach ( self::FIELDS as $key => $type ) {
            $value = is_scalar( $raw[ $key ] ?? null ) ? trim( (string) $raw[ $key ] ) : '';

            $clean[ $key ] = match ( $type ) {
                'url' => esc_url_raw( $value ),

                // Secret fields render empty so credentials never appear in the
                // page source, which means a blank post is "unchanged", not
                // "clear it".
                //
                // ponytail: no way to blank a secret from the UI. Rotating a
                // credential is a write, not a delete, so this has not come up.
                // Add a "clear" checkbox per field if it ever does.
                'secret' => '' !== $value ? $value : (string) $stored[ $key ],

                'host' => self::sanitize_host( $value ),
                'bool' => '1' === $value ? '1' : '0',
                'int'  => max( 0, (int) $value ),

                default => sanitize_text_field( $value ),
            };
        }

        $clean['payment_methods']  = self::sanitize_payment_rows( $raw['payment_methods'] ?? [] );
        $clean['payment_defaults'] = self::sanitize_payment_row( $raw['payment_defaults'] ?? [] );

        return $clean;
    }

    /**
     * Accept a bare hostname, or a pasted URL to save an admin the edit.
     */
    public static function sanitize_host( string $value ): string {
        $host = strtolower( trim( $value ) );
        $host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );

        return sanitize_text_field( explode( '/', $host )[0] );
    }

    /* ------------------------------------------------------------ site gate */

    /**
     * The host this install is authorised to sync as.
     *
     * Stored rather than derived. A cloned site (staging.example.com, a
     * migration copy, a dev box) carries the live credentials and the live
     * settings in its database, so comparing the stored host against the running
     * one is what tells the two apart. Derive it and a clone looks configured
     * and posts test orders into production.
     */
    public static function authorised_host(): string {
        return strtolower( trim( (string) self::get( 'host' ) ) );
    }

    /**
     * Is this install running on the host it was set up for?
     */
    public static function is_known_host( ?string $host = null ): bool {
        $host       = strtolower( (string) ( $host ?: parse_url( home_url(), PHP_URL_HOST ) ) );
        $authorised = self::authorised_host();

        return '' !== $authorised && $authorised === $host;
    }

    /**
     * Are the fields both payload builders need actually filled in?
     *
     * Without this a blank mapping posts an empty CustomerID and OrderType,
     * which Acumatica accepts far enough to create rubbish.
     */
    public static function is_configured(): bool {
        $config = self::get_site_config();

        return '' !== $config['order_type'] && '' !== $config['customer_id'];
    }

    /**
     * Master gate for all syncing. Checked before any order or payment is sent.
     */
    public static function sync_enabled(): bool {
        return '1' === (string) self::get( 'enabled' )
            && self::is_known_host()
            && self::is_configured();
    }

    /**
     * Why syncing is off, for logging and the settings page. '' when enabled.
     */
    public static function sync_disabled_reason(): string {
        if ( '1' !== (string) self::get( 'enabled' ) ) {
            return 'Sync is turned off in Acumatica Sync settings.';
        }

        $host = (string) parse_url( home_url(), PHP_URL_HOST );

        if ( '' === self::authorised_host() ) {
            return sprintf(
                'No site mapping yet. Set the authorised host, order type and customer ID '
                . 'in Acumatica Sync settings. This site is "%s".',
                $host
            );
        }

        if ( ! self::is_known_host() ) {
            return sprintf(
                'This site is "%s" but the mapping is for "%s", so it looks like a staging '
                . 'or cloned site. Update the authorised host if it should sync.',
                $host,
                self::authorised_host()
            );
        }

        if ( ! self::is_configured() ) {
            return 'Order type and customer ID are both required in Acumatica Sync settings.';
        }

        return '';
    }

    /**
     * Values sent on every order from this site.
     */
    public static function get_site_config( ?string $host = null ): array {
        $host = (string) ( $host ?: parse_url( home_url(), PHP_URL_HOST ) );

        return [
            'host'        => $host,
            // Acumatica's Website field is short, hence a separate value rather
            // than the full hostname. Falls back to the host when left blank.
            'website'     => (string) ( self::get( 'website' ) ?: $host ),
            'order_type'  => trim( (string) self::get( 'order_type' ) ),
            'customer_id' => trim( (string) self::get( 'customer_id' ) ),
        ];
    }

    /* --------------------------------------------------------- payment map */

    /**
     * One row with every key present, so callers never test for a missing key.
     */
    public static function blank_payment_row(): array {
        return array_fill_keys( array_keys( self::PAYMENT_FIELDS ), '' );
    }

    /**
     * Reduce one posted row to exactly the known fields, all present.
     */
    public static function sanitize_payment_row( mixed $row ): array {
        $clean = [];

        foreach ( array_keys( self::PAYMENT_FIELDS ) as $key ) {
            $clean[ $key ] = sanitize_text_field( (string) ( is_array( $row ) ? ( $row[ $key ] ?? '' ) : '' ) );
        }

        return $clean;
    }

    /**
     * Sanitise the repeatable rows, dropping any with no WooCommerce slug.
     *
     * That covers both a blank block the Add button appended and left unfilled,
     * and an existing block the Remove button deleted before saving. Rows come
     * back re-indexed, so removing the first block does not leave a gap.
     *
     * @return array<int, array<string, string>>
     */
    public static function sanitize_payment_rows( mixed $rows ): array {
        $clean = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $row = self::sanitize_payment_row( $row );

            if ( '' !== $row['wc_method'] ) {
                $clean[] = $row;
            }
        }

        return $clean;
    }

    /**
     * Stored rows, each filled out to the current shape.
     *
     * Rows stored before a field was added to PAYMENT_FIELDS are missing it, so
     * they are merged over a blank row rather than used as they come back.
     *
     * @return array<int, array<string, string>>
     */
    public static function get_payment_map(): array {
        $blank = self::blank_payment_row();
        $rows  = [];

        foreach ( (array) self::get( 'payment_methods' ) as $row ) {
            if ( ! is_array( $row ) || '' === trim( (string) ( $row['wc_method'] ?? '' ) ) ) {
                continue;
            }

            $rows[] = array_merge( $blank, array_intersect_key( $row, $blank ) );
        }

        return $rows;
    }

    /**
     * Row used for any WooCommerce method with no row of its own.
     */
    public static function get_payment_fallback(): array {
        $blank  = self::blank_payment_row();
        $stored = self::get( 'payment_defaults' );

        return array_merge( $blank, is_array( $stored ) ? array_intersect_key( $stored, $blank ) : [] );
    }

    /**
     * Resolved payment settings for one WooCommerce payment method.
     */
    public static function get_payment_config( string $wc_method ): array {
        $fallback = self::get_payment_fallback();
        $config   = $fallback;
        $match    = null;

        foreach ( self::get_payment_map() as $row ) {
            if ( $row['wc_method'] === $wc_method ) {
                $match = $row;
                break;
            }

            // Gateways that register one slug per alternative method
            // (stripe_afterpay_clearpay, stripe_klarna, ...) are covered by a
            // "stripe" row, so the method the customer picked at checkout does
            // not have to be mapped one by one. Longest prefix wins, and the
            // separator is required so "zip" cannot swallow "zippay".
            $next = substr( $wc_method, strlen( $row['wc_method'] ), 1 );

            if ( str_starts_with( $wc_method, $row['wc_method'] )
                && ( '_' === $next || '-' === $next )
                && ( null === $match || strlen( $row['wc_method'] ) > strlen( $match['wc_method'] ) )
            ) {
                $match = $row;
            }
        }

        if ( null !== $match ) {
            // Blank cells inherit the fallback, so shared account numbers are
            // typed once.
            $config = array_merge(
                $fallback,
                array_filter( $match, static fn( string $value ): bool => '' !== $value )
            );

            // Except the fee key, which is taken literally. Inheriting it would
            // make a method with no processor fee (bank transfer, Zip) sit and
            // wait for one that never arrives.
            $config['fee_meta_key'] = $match['fee_meta_key'];
        }

        // No mapping at all: send the slug upper-cased and let Acumatica reject
        // it loudly rather than posting a blank payment method.
        if ( '' === $config['acumatica_method'] ) {
            $config['acumatica_method'] = strtoupper( $wc_method );
        }

        unset( $config['wc_method'] );

        return apply_filters( 'acumatica_payment_config', $config, $wc_method );
    }

    /**
     * Just the Acumatica payment method name for a WooCommerce method.
     */
    public static function get_acumatica_payment_method( string $wc_method ): string {
        return self::get_payment_config( $wc_method )['acumatica_method'];
    }

    /**
     * Does this shipping method mean the customer collects in person?
     */
    public static function is_local_pickup( string $shipping_method ): bool {
        $method_lower = strtolower( trim( $shipping_method ) );
        $pickup_methods = apply_filters( 'acumatica_local_pickup_methods', [
            'local pickup',
            'click n collect',
            'click and collect',
            'pickup',
        ] );

        return in_array( $method_lower, $pickup_methods, true );
    }
}
