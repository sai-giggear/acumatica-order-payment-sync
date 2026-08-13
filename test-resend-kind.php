<?php
/**
 * Self-check for the plugin's pure decision logic. Run: php test-resend-kind.php
 *
 * Covers what fails silently and expensively if it regresses: resend routing,
 * the processor-fee wait, the API endpoint path, the host gate, the admin log
 * filters and the keep-on-blank secret handling.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP stubs. These files only define functions and register hooks.
$GLOBALS['acm_options'] = [];
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_setting( ...$a ) {}
function get_option( $name, $default = false ) {
    return $GLOBALS['acm_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['acm_options'][ $name ] = $value;
    return true;
}
function add_option( $name, $value, $deprecated = '', $autoload = null ) {
    if ( array_key_exists( $name, $GLOBALS['acm_options'] ) ) {
        return false;
    }
    $GLOBALS['acm_options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['acm_options'][ $name ] );
    return true;
}
function esc_url_raw( $url ) {
    return trim( (string) $url );
}

/** Store settings in the one option the plugin now reads. */
function acm_settings( array $settings ): void {
    $GLOBALS['acm_options'] = [ 'acumatica_settings' => $settings ];
}
function wp_unslash( $value ) {
    return is_string( $value ) ? stripslashes( $value ) : $value;
}
function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}
function absint( $value ) {
    return abs( (int) $value );
}
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// Enough of WP for the main plugin file to load. is_admin() and wp_doing_cron()
// both false keeps the update checker out of the run: it needs a live WP, and
// none of what it does is under test here.
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function get_file_data( $file, $headers ) {
    $src = (string) file_get_contents( $file, false, null, 0, 8192 );

    return array_map(
        static function ( $header ) use ( $src ): string {
            preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $src, $m );

            return isset( $m[1] ) ? trim( $m[1] ) : '';
        },
        $headers
    );
}
function plugin_dir_url( $file )  { return 'https://example.test/wp-content/plugins/acumatica/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function admin_url( $path = '' )  { return 'https://example.test/wp-admin/' . $path; }
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function home_url( $path = '' ) { return $GLOBALS['acm_home_url'] . $path; }
$GLOBALS['acm_home_url'] = 'https://shop.example.com';

require_once __DIR__ . '/acumatica-order-payment-sync.php';
require_once __DIR__ . '/includes/class-config.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/payment-sync.php';
require_once __DIR__ . '/includes/acumatica-api.php';
require_once __DIR__ . '/admin/settings-page.php';

// --- resend routing ---
// An "Order Sync Failed" row routed to the payment action posts an AR Payment
// against a sales order Acumatica never received.
assert( 'order'   === acumatica_log_resend_kind( 'Order Sync Failed' ) );
assert( 'order'   === acumatica_log_resend_kind( 'Order Sync Success' ) );
assert( 'payment' === acumatica_log_resend_kind( 'Payment Sync Failed' ) );
assert( 'payment' === acumatica_log_resend_kind( 'Payment Sync Success' ) );

$actions = acumatica_resend_actions();
foreach ( [ 'Order Sync Failed', 'Payment Sync Failed', '', 'Something Else' ] as $type ) {
    assert( isset( $actions[ acumatica_log_resend_kind( $type ) ] ), "no action for: {$type}" );
}

// --- fee wait decision ---
// Real case: order SL-38471. Fee absent at sync time, present later as "1.27".
assert( true  === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '', 0, false, 3 ) );
assert( false === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '1.27', 0, false, 3 ) );

// Give up at the ceiling and send without the fee.
assert( true  === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '', 2, false, 3 ) );
assert( false === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '', 3, false, 3 ) );

// Attempts = 0 disables waiting entirely.
assert( false === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '', 0, false, 0 ) );

// Methods with no fee tracking (bacs, zip) must never wait.
assert( false === acumatica_should_wait_for_fee( '', '', 0, false, 3 ) );

// Manual resend sends now regardless.
assert( false === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', '', 0, true, 3 ) );

// Array-valued fee is treated as not-yet-recorded, never cast to 1.0.
assert( true  === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', [ 'value' => 1.27 ], 0, false, 3 ) );
assert( false === acumatica_should_wait_for_fee( 'PayPal Transaction Fee', [ 'value' => 1.27 ], 3, false, 3 ) );

// Zero and negative are not a recorded fee.
assert( true  === acumatica_should_wait_for_fee( '_stripe_fee', '0', 0, false, 3 ) );
assert( true  === acumatica_should_wait_for_fee( '_stripe_fee', '-1', 0, false, 3 ) );

// --- fee timing options ---
acm_settings( [] );
assert( 300 === acumatica_fee_retry_delay() );          // 5 minutes default
assert( 3   === acumatica_fee_max_attempts() );

acm_settings( [ 'fee_retry_delay' => 10, 'fee_max_attempts' => 0 ] );
assert( 600 === acumatica_fee_retry_delay() );
assert( 0   === acumatica_fee_max_attempts() );

// Junk must not produce a zero-delay retry storm or negative attempts.
acm_settings( [ 'fee_retry_delay' => 0, 'fee_max_attempts' => -5 ] );
assert( 60 === acumatica_fee_retry_delay() );
assert( 0  === acumatica_fee_max_attempts() );

// --- endpoint path ---
acm_settings( [] );
assert( 'entity/Default2/23.200.001' === acumatica_endpoint_path() );

// Stray slashes would double up against the trailingslashit'd base URL.
acm_settings( [ 'endpoint_path' => '/entity/Custom/24.200.001/' ] );
assert( 'entity/Custom/24.200.001' === acumatica_endpoint_path() );

// Blank falls back rather than building a URL with no endpoint at all.
acm_settings( [ 'endpoint_path' => '   ' ] );
assert( 'entity/Default2/23.200.001' === acumatica_endpoint_path() );

// --- admin log filters ---
// A malformed date reaching the ts comparison returns the wrong window, so
// anything that is not Y-m-d is dropped rather than passed through.
$_GET = [
    's'         => '  SL-38471 ',
    'type'      => 'Order Sync Failed',
    'status'    => 'no',
    'order_id'  => '-42',
    'date_from' => '2026-08-01',
    'date_to'   => 'yesterday',
];
$filters = acumatica_log_filters_from_request();
assert( 'SL-38471' === $filters['search'] );
assert( 'Order Sync Failed' === $filters['type'] );
assert( 'no' === $filters['success'] );
assert( 42 === $filters['order_id'] );
assert( '2026-08-01' === $filters['date_from'] );
assert( '' === $filters['date_to'] );

// Unknown status values must not reach the SQL branch.
$_GET = [ 'status' => 'maybe' ];
assert( '' === acumatica_log_filters_from_request()['success'] );

// No query string at all means no filters, which the page reads as "unfiltered".
$_GET = [];
assert( [] === array_filter( acumatica_log_filters_from_request() ) );

// --- secret keep-on-blank ---
// The form renders these fields empty, so a blank post must keep the stored
// credential. Wiping it on every save would break syncing site-wide.
acm_settings( [ 'password' => 'stored-secret', 'order_type' => 'WS' ] );

$saved = Acumatica_Config::save( [ 'password' => '', 'order_type' => 'WS' ] );
assert( 'stored-secret' === $saved['password'] );
assert( 'stored-secret' === Acumatica_Config::save( [ 'password' => '   ' ] )['password'] );
assert( 'new-secret' === Acumatica_Config::save( [ 'password' => ' new-secret ' ] )['password'] );
assert( '' === $saved['client_secret'] );

// Everything else posted blank is a real clear, or the screen could never empty
// a field.
assert( '' === Acumatica_Config::save( [ 'order_type' => '' ] )['order_type'] );

// Types survive the round trip: a checkbox that posts nothing is off, and the
// counters stay integers rather than becoming "3".
$saved = Acumatica_Config::save( [ 'fee_retry_delay' => '10', 'fee_max_attempts' => '-2' ] );
assert( '0' === $saved['enabled'] );
assert( 10 === $saved['fee_retry_delay'] );
assert( 0 === $saved['fee_max_attempts'] );
assert( '1' === Acumatica_Config::save( [ 'enabled' => '1' ] )['enabled'] );

// --- stored JSON rendering ---
// A payload that was genuinely null and a row whose JSON is corrupt both used
// to render as "null", which is the wrong answer on the screen you open to find
// out why a sync failed.
assert( 'null' === acumatica_format_json( 'null' ) );
assert( str_starts_with( acumatica_format_json( '{"broken":' ), '[not valid JSON' ) );
assert( str_contains( acumatica_format_json( '{"broken":' ), '{"broken":' ) );

// Empty and missing columns are neither.
assert( '—' === acumatica_format_json( '' ) );
assert( '—' === acumatica_format_json( null ) );

// Valid JSON is pretty-printed, not echoed back flat.
assert( "{\n    \"a\": 1\n}" === acumatica_format_json( '{"a":1}' ) );

// Truncation applies to the rendered output, not the stored column.
$long = acumatica_format_json( json_encode( array_fill( 0, 500, 'xxxxxxxxxx' ) ), 200 );
assert( strlen( $long ) < 260 );
assert( str_ends_with( $long, '[truncated]' ) );

// --- host gate ---
// The whole point of storing the host rather than deriving it: a staging copy
// or a restored backup carries these same options, and only the mismatch tells
// it apart. Getting this wrong posts test orders into production Acumatica.
acm_settings( [
    'host'        => 'shop.example.com',
    'order_type'  => 'WS',
    'customer_id' => 'WEBSHOP',
] );
assert( true === Acumatica_Config::is_known_host() );
assert( true === Acumatica_Config::sync_enabled() );

$GLOBALS['acm_home_url'] = 'https://staging.shop.example.com';
assert( false === Acumatica_Config::is_known_host() );
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'staging' ) );
$GLOBALS['acm_home_url'] = 'https://shop.example.com';

// Case and a pasted URL both normalise, so a mapping typed as "Shop.Example.com"
// does not silently block every order.
assert( 'shop.example.com' === Acumatica_Config::sanitize_host( ' HTTPS://Shop.Example.com/wp-admin/ ' ) );
assert( 'shop.example.com' === Acumatica_Config::save( [ 'host' => 'Shop.Example.com' ] )['host'] );

// An unmapped host is a different message from a mapped-but-wrong one.
acm_settings( [] );
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'No site mapping' ) );

// Host right but mapping half-filled still must not post.
acm_settings( [ 'host' => 'shop.example.com', 'order_type' => 'WS' ] );
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'customer ID' ) );

// Off by the switch, whatever else is set.
acm_settings( [ 'host' => 'shop.example.com', 'order_type' => 'WS', 'customer_id' => 'WEBSHOP', 'enabled' => '0' ] );
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'turned off' ) );

// --- payment mapping ---
acm_settings( [
    'payment_defaults' => [
        'acumatica_method' => '',
        'entry_type'       => 'CHARGE',
        'fee_meta_key'     => '',
        'fee_account'      => '99002',
        'fee_subaccount'   => '000000',
        'cash_account'     => '99003',
    ],
    'payment_methods' => [
        [ 'wc_method' => 'stripe', 'acumatica_method' => 'STRIPE', 'entry_type' => 'STRIPE',
          'fee_meta_key' => '_stripe_fee', 'fee_account' => '99001', 'fee_subaccount' => '',
          'cash_account' => '' ],
        [ 'wc_method' => 'bacs', 'acumatica_method' => 'EFT', 'entry_type' => '',
          'fee_meta_key' => '', 'fee_account' => '', 'fee_subaccount' => '', 'cash_account' => '' ],
    ],
] );

$stripe = Acumatica_Config::get_payment_config( 'stripe' );
assert( 'STRIPE' === $stripe['acumatica_method'] );
assert( '99001' === $stripe['fee_account'] );
// Blank cells inherit rather than posting an empty account number.
assert( '000000' === $stripe['fee_subaccount'] );
assert( '99003'  === $stripe['cash_account'] );
// wc_method is a UI column, not part of the payload contract.
assert( ! isset( $stripe['wc_method'] ) );

// A method with no processor fee must not inherit one, or every payment on it
// waits out the full retry schedule for a fee that never arrives.
$GLOBALS['acm_options']['acumatica_settings']['payment_defaults']['fee_meta_key'] = '_some_fee';
assert( '' === Acumatica_Config::get_payment_config( 'bacs' )['fee_meta_key'] );
assert( '_stripe_fee' === Acumatica_Config::get_payment_config( 'stripe' )['fee_meta_key'] );

// Unmapped method falls back, and sends the slug rather than a blank method.
assert( 'ZIPMONEY' === Acumatica_Config::get_acumatica_payment_method( 'zipmoney' ) );
assert( '99003' === Acumatica_Config::get_payment_config( 'zipmoney' )['cash_account'] );

// Stripe registers one gateway per alternative method. Without prefix matching
// these fall through to the fallback and post STRIPE_AFTERPAY_CLEARPAY as the
// payment method, which Acumatica does not know.
assert( 'STRIPE' === Acumatica_Config::get_acumatica_payment_method( 'stripe_afterpay_clearpay' ) );
assert( 'STRIPE' === Acumatica_Config::get_acumatica_payment_method( 'stripe-klarna' ) );
assert( '_stripe_fee' === Acumatica_Config::get_payment_config( 'stripe_klarna' )['fee_meta_key'] );

// A block of its own still wins over the prefix.
$GLOBALS['acm_options']['acumatica_settings']['payment_methods'][] = [
    'wc_method' => 'stripe_afterpay_clearpay', 'acumatica_method' => 'AFTERPAY',
];
assert( 'AFTERPAY' === Acumatica_Config::get_acumatica_payment_method( 'stripe_afterpay_clearpay' ) );
assert( 'STRIPE' === Acumatica_Config::get_acumatica_payment_method( 'stripe_klarna' ) );

// The separator is required, so a slug that merely starts with another one is
// not swallowed: "zip" must not answer for "zipmoney".
$GLOBALS['acm_options']['acumatica_settings']['payment_methods'][] = [
    'wc_method' => 'zip', 'acumatica_method' => 'ZIP',
];
assert( 'ZIPMONEY' === Acumatica_Config::get_acumatica_payment_method( 'zipmoney' ) );
assert( 'ZIP' === Acumatica_Config::get_acumatica_payment_method( 'zip_pay' ) );

// --- payment map sanitising ---
// A block added and left unfilled, and one the Remove button deleted, both post
// as a row with no slug. Storing those grows the option on every save.
$saved = Acumatica_Config::sanitize_payment_rows( [
    [ 'wc_method' => ' stripe ', 'cash_account' => '99003' ],
    [ 'wc_method' => '', 'cash_account' => '99999' ],
    [ 'wc_method' => '   ' ],
    'not-an-array',
] );
assert( 1 === count( $saved ) );
assert( 'stripe' === $saved[0]['wc_method'] );
// Every key present, so no caller has to test for one.
assert( [] === array_diff_key( Acumatica_Config::blank_payment_row(), $saved[0] ) );
// Unknown keys posted by hand are dropped rather than stored.
assert( [] === array_diff_key( $saved[0], Acumatica_Config::blank_payment_row() ) );

// Rows come back re-indexed. The form posts sparse indices once a middle block
// has been removed, and a gap in the list would render as a missing block.
$saved = Acumatica_Config::sanitize_payment_rows( [
    3 => [ 'wc_method' => 'stripe' ],
    7 => [ 'wc_method' => 'bacs' ],
] );
assert( [ 0, 1 ] === array_keys( $saved ) );

// A stored row missing keys still resolves, since rows predate any field added
// to PAYMENT_FIELDS later.
$GLOBALS['acm_options']['acumatica_settings']['payment_methods'] = [ [ 'wc_method' => 'cod' ] ];
assert( 'COD' === Acumatica_Config::get_acumatica_payment_method( 'cod' ) );

// --- mapping placeholders ---
// Each placeholder promises what a blank field will actually send. Wrong text
// there is worse than no text: an admin leaves a field blank on the strength of
// it and the wrong account number goes to Acumatica.
$fallback = array_merge( Acumatica_Config::blank_payment_row(), [
    'acumatica_method' => 'CREDITCARD',
    'cash_account'     => '10200',
    'fee_meta_key'     => '_stripe_fee',
] );

assert( 'Default: 10200' === acumatica_inherit_hint( 'cash_account', $fallback ) );
// Never inherited, whatever the fallback holds, so it must not offer one.
assert( 'None. Not inherited' === acumatica_inherit_hint( 'fee_meta_key', $fallback ) );
assert( 'Empty' === acumatica_inherit_hint( 'fee_account', $fallback ) );
// Matches get_payment_config(), which upper-cases the slug when nothing is set.
assert( 'Default: CREDITCARD' === acumatica_inherit_hint( 'acumatica_method', $fallback ) );
assert( 'Slug, upper-cased' === acumatica_inherit_hint(
    'acumatica_method',
    Acumatica_Config::blank_payment_row()
) );

// --- field errors in a rejected record ---
// Real case: order ST-136003. Acumatica answered with the whole sales order and
// one generic message, and the reason it actually failed sat on the field. That
// detail is the difference between a log row you can act on and one that sends
// you back to the raw payload.
$rejected = [
    'error'         => "Inserting  'Sales Order' record raised at least one error.",
    'OrderNbr'      => [ 'value' => 'ST-136003' ],
    'PaymentMethod' => [
        'value' => 'STRIPE AFT',
        'error' => "Payment Method 'STRIPE AFT' cannot be found in the system.",
    ],
    'Details' => [
        [ 'InventoryID' => [ 'value' => 'CUSTOM-STENCIL' ], 'UnitCost' => [ 'value' => 0, 'error' => 'No cost.' ] ],
    ],
];

$fields = acumatica_field_errors( $rejected );
assert( 2 === count( $fields ) );
assert( "PaymentMethod: Payment Method 'STRIPE AFT' cannot be found in the system." === $fields[0] );
assert( 'Details.UnitCost: No cost.' === $fields[1] );

// The top-level message is reported on its own, so repeating it here would put
// it in the log row twice.
assert( [] === acumatica_field_errors( [ 'error' => 'Generic.', 'OrderNbr' => [ 'value' => 'ST-1' ] ] ) );

// A clean record has nothing to report, and an empty error string is not one.
assert( [] === acumatica_field_errors( [ 'PaymentMethod' => [ 'value' => 'STRIPE', 'error' => '' ] ] ) );

// --- settings migration ---
// A 1.0 site keeps one option per field. The first read after the update folds
// them into acumatica_settings; miss one and a working site comes back with a
// blank screen and no syncing.
$GLOBALS['acm_options'] = [
    'acumatica_site_host'   => 'shop.example.com',
    'acumatica_password'    => 'stored-secret',
    'acumatica_order_type'  => 'WS',
    'acumatica_customer_id' => 'WEBSHOP',
    'acumatica_payment_map' => [ [ 'wc_method' => 'stripe', 'acumatica_method' => 'STRIPE' ] ],
];

$migrated = Acumatica_Config::all();
assert( 'shop.example.com' === $migrated['host'] );
assert( 'stored-secret' === $migrated['password'] );
assert( 'STRIPE' === Acumatica_Config::get_acumatica_payment_method( 'stripe' ) );
assert( true === Acumatica_Config::sync_enabled() );

// Fields the old install never had come back as defaults, not empty.
assert( 'entity/Default2/23.200.001' === $migrated['endpoint_path'] );
assert( '1' === $migrated['enabled'] );

// Written once, old rows dropped. A second read that re-migrated would overwrite
// whatever had been saved since, and would leave the password in two rows.
assert( isset( $GLOBALS['acm_options']['acumatica_settings'] ) );
assert( ! isset( $GLOBALS['acm_options']['acumatica_password'] ) );
assert( ! isset( $GLOBALS['acm_options']['acumatica_site_host'] ) );

// Nothing to migrate on a fresh install: defaults, and no option written.
$GLOBALS['acm_options'] = [];
assert( 'entity/Default2/23.200.001' === Acumatica_Config::all()['endpoint_path'] );
assert( ! isset( $GLOBALS['acm_options']['acumatica_settings'] ) );

// --- version ---
// Read from the Version header rather than written twice. Empty means the regex
// stopped matching the header and every cache-busting URL silently went stale.
assert( '' !== ACUMATICA_SYNC_VERSION );
assert( 1 === preg_match( '/^\d+\.\d+\.\d+$/', ACUMATICA_SYNC_VERSION ) );

echo "ok\n";
