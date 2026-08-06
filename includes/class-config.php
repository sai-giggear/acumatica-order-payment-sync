<?php
/**
 * Central configuration for Acumatica Sync
 * File: includes/class-config.php
 *
 * Everything here used to be hardcoded: the host mapping, the customer IDs and
 * the GL accounts. That put one company's chart of accounts in the plugin
 * source, so it all moved to options and is entered on the Mapping tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Acumatica_Config {

    /**
     * Shape of one payment-method row, and the labels the Mapping tab renders.
     *
     * The settings screen, the sanitiser and the fallback row all read the
     * shape from here, so adding a field is a one-line change.
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

    /**
     * The host this install is authorised to sync as.
     *
     * Stored rather than derived. A cloned site (staging.example.com, a
     * migration copy, a dev box) carries the live credentials AND the live
     * options in its database, so comparing the stored host against the running
     * one is the only thing that tells the two apart. Derive it and a clone
     * looks perfectly configured and posts test orders into production.
     */
    public static function authorised_host(): string {
        return strtolower( trim( (string) get_option( 'acumatica_site_host', '' ) ) );
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
        return '1' === get_option( 'acumatica_sync_enabled', '1' )
            && self::is_known_host()
            && self::is_configured();
    }

    /**
     * Why syncing is off, for logging and the settings page. '' when enabled.
     */
    public static function sync_disabled_reason(): string {
        if ( '1' !== get_option( 'acumatica_sync_enabled', '1' ) ) {
            return 'Sync is turned off in Acumatica Sync settings.';
        }

        $host = (string) parse_url( home_url(), PHP_URL_HOST );

        if ( '' === self::authorised_host() ) {
            return sprintf(
                'No site mapping yet. Set the authorised host, order type and customer ID '
                . 'on the Mapping tab. This site is "%s".',
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
            return 'Order type and customer ID are both required on the Mapping tab.';
        }

        return '';
    }

    /**
     * Get site configuration for the current site.
     */
    public static function get_site_config( ?string $host = null ): array {
        $host = (string) ( $host ?: parse_url( home_url(), PHP_URL_HOST ) );

        return [
            'host'        => $host,
            // Acumatica's Website field is short, hence a separate value rather
            // than the full hostname. Falls back to the host when left blank.
            'website'     => (string) ( get_option( 'acumatica_website', '' ) ?: $host ),
            'order_type'  => trim( (string) get_option( 'acumatica_order_type', '' ) ),
            'customer_id' => trim( (string) get_option( 'acumatica_customer_id', '' ) ),
        ];
    }

    /**
     * One row with every key present, so callers never test for a missing key.
     */
    public static function blank_payment_row(): array {
        return array_fill_keys( array_keys( self::PAYMENT_FIELDS ), '' );
    }

    /**
     * Per-method payment rows, keyed by nothing: order is what the admin typed.
     *
     * @return array<int, array<string, string>>
     */
    public static function get_payment_map(): array {
        $stored = get_option( 'acumatica_payment_map', [] );
        $blank  = self::blank_payment_row();
        $rows   = [];

        foreach ( is_array( $stored ) ? $stored : [] as $row ) {
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
        $stored = get_option( 'acumatica_payment_fallback', [] );

        return array_merge(
            self::blank_payment_row(),
            is_array( $stored ) ? array_intersect_key( $stored, self::blank_payment_row() ) : []
        );
    }

    /**
     * Get payment configuration for a WooCommerce payment method.
     */
    public static function get_payment_config( string $wc_method ): array {
        $fallback = self::get_payment_fallback();
        $config   = $fallback;

        foreach ( self::get_payment_map() as $row ) {
            if ( $row['wc_method'] !== $wc_method ) {
                continue;
            }

            // Blank cells inherit the fallback, so shared account numbers are
            // typed once.
            $config = array_merge(
                $fallback,
                array_filter( $row, static fn( string $value ): bool => '' !== $value )
            );

            // Except the fee key, which is taken literally. Inheriting it would
            // make a method with no processor fee (bank transfer, Zip) sit and
            // wait for one that never arrives.
            $config['fee_meta_key'] = $row['fee_meta_key'];
            break;
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
     * Get just the Acumatica payment method name for a WC payment method
     */
    public static function get_acumatica_payment_method( string $wc_method ): string {
        return self::get_payment_config( $wc_method )['acumatica_method'];
    }

    /**
     * Check if a shipping method indicates local pickup
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
