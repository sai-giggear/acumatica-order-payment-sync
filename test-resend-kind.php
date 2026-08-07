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
$GLOBALS['acm_options'] = [];
assert( 300 === acumatica_fee_retry_delay() );          // 5 minutes default
assert( 3   === acumatica_fee_max_attempts() );

$GLOBALS['acm_options'] = [ 'acumatica_fee_retry_delay' => 10, 'acumatica_fee_max_attempts' => 0 ];
assert( 600 === acumatica_fee_retry_delay() );
assert( 0   === acumatica_fee_max_attempts() );

// Junk must not produce a zero-delay retry storm or negative attempts.
$GLOBALS['acm_options'] = [ 'acumatica_fee_retry_delay' => 0, 'acumatica_fee_max_attempts' => -5 ];
assert( 60 === acumatica_fee_retry_delay() );
assert( 0  === acumatica_fee_max_attempts() );

// --- endpoint path ---
$GLOBALS['acm_options'] = [];
assert( 'entity/Default2/23.200.001' === acumatica_endpoint_path() );

// Stray slashes would double up against the trailingslashit'd base URL.
$GLOBALS['acm_options'] = [ 'acumatica_endpoint_path' => '/entity/Custom/24.200.001/' ];
assert( 'entity/Custom/24.200.001' === acumatica_endpoint_path() );

// Blank falls back rather than building a URL with no endpoint at all.
$GLOBALS['acm_options'] = [ 'acumatica_endpoint_path' => '   ' ];
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
$GLOBALS['acm_options'] = [ 'acumatica_password' => 'stored-secret' ];
assert( 'stored-secret' === acumatica_sanitize_secret( 'acumatica_password', '' ) );
assert( 'stored-secret' === acumatica_sanitize_secret( 'acumatica_password', '   ' ) );
assert( 'new-secret' === acumatica_sanitize_secret( 'acumatica_password', ' new-secret ' ) );
assert( '' === acumatica_sanitize_secret( 'acumatica_client_secret', '' ) );

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
$GLOBALS['acm_options'] = [
    'acumatica_site_host'  => 'shop.example.com',
    'acumatica_order_type' => 'WS',
    'acumatica_customer_id' => 'WEBSHOP',
];
assert( true === Acumatica_Config::is_known_host() );
assert( true === Acumatica_Config::sync_enabled() );

$GLOBALS['acm_home_url'] = 'https://staging.shop.example.com';
assert( false === Acumatica_Config::is_known_host() );
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'staging' ) );
$GLOBALS['acm_home_url'] = 'https://shop.example.com';

// Case and a pasted URL both normalise, so a mapping typed as "Shop.Example.com"
// does not silently block every order.
$GLOBALS['acm_options']['acumatica_site_host'] = 'Shop.Example.com';
assert( 'shop.example.com' === acumatica_sanitize_host( ' HTTPS://Shop.Example.com/wp-admin/ ' ) );

// An unmapped host is a different message from a mapped-but-wrong one.
$GLOBALS['acm_options'] = [];
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'No site mapping' ) );

// Host right but mapping half-filled still must not post.
$GLOBALS['acm_options'] = [ 'acumatica_site_host' => 'shop.example.com', 'acumatica_order_type' => 'WS' ];
assert( false === Acumatica_Config::sync_enabled() );
assert( str_contains( Acumatica_Config::sync_disabled_reason(), 'customer ID' ) );

// --- payment mapping ---
$GLOBALS['acm_options'] = [
    'acumatica_payment_fallback' => [
        'acumatica_method' => '',
        'entry_type'       => 'CHARGE',
        'fee_meta_key'     => '',
        'fee_account'      => '99002',
        'fee_subaccount'   => '000000',
        'cash_account'     => '99003',
    ],
    'acumatica_payment_map' => [
        [ 'wc_method' => 'stripe', 'acumatica_method' => 'STRIPE', 'entry_type' => 'STRIPE',
          'fee_meta_key' => '_stripe_fee', 'fee_account' => '99001', 'fee_subaccount' => '',
          'cash_account' => '' ],
        [ 'wc_method' => 'bacs', 'acumatica_method' => 'EFT', 'entry_type' => '',
          'fee_meta_key' => '', 'fee_account' => '', 'fee_subaccount' => '', 'cash_account' => '' ],
    ],
];

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
$GLOBALS['acm_options']['acumatica_payment_fallback']['fee_meta_key'] = '_some_fee';
assert( '' === Acumatica_Config::get_payment_config( 'bacs' )['fee_meta_key'] );
assert( '_stripe_fee' === Acumatica_Config::get_payment_config( 'stripe' )['fee_meta_key'] );

// Unmapped method falls back, and sends the slug rather than a blank method.
assert( 'ZIPMONEY' === Acumatica_Config::get_acumatica_payment_method( 'zipmoney' ) );
assert( '99003' === Acumatica_Config::get_payment_config( 'zipmoney' )['cash_account'] );

// --- payment map sanitising ---
// A block added and left unfilled, and one the Remove button deleted, both post
// as a row with no slug. Storing those grows the option on every save.
$saved = acumatica_sanitize_payment_map( [
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

// A stored row missing keys still resolves, since rows predate any field added
// to PAYMENT_FIELDS later.
$GLOBALS['acm_options']['acumatica_payment_map'] = [ [ 'wc_method' => 'cod' ] ];
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

// --- version ---
// Read from the Version header rather than written twice. Empty means the regex
// stopped matching the header and every cache-busting URL silently went stale.
assert( '' !== ACUMATICA_SYNC_VERSION );
assert( 1 === preg_match( '/^\d+\.\d+\.\d+$/', ACUMATICA_SYNC_VERSION ) );

echo "ok\n";
