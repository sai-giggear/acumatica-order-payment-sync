<?php
/**
 * Plugin Name:       Acumatica Order & Payment Sync
 * Description:       Posts WooCommerce sales orders and AR payments to Acumatica ERP over OAuth2 when an order reaches processing.
 * Version:           1.0.0
 * Author:            T37A
 * Text Domain:       acumatica-order-payment-sync
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Version is read from the header above, so a release is one edit.
define( 'ACUMATICA_SYNC_VERSION', get_file_data( __FILE__, [ 'v' => 'Version' ] )['v'] );
define( 'ACUMATICA_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACUMATICA_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Self-hosted updates from the public GitHub repo.
 *
 * Loaded only where WordPress looks for updates, since a storefront page view
 * has no use for the library. WP-CLI is included because `wp plugin update`
 * runs outside both admin and cron.
 */
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    require_once ACUMATICA_SYNC_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

    $acumatica_sync_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/sai-giggear/acumatica-order-payment-sync/',
        __FILE__,
        'acumatica-order-payment-sync'
    );

    // Default is "master". On the wrong branch name the release lookup is
    // skipped entirely and no update is ever offered.
    $acumatica_sync_updater->setBranch( 'main' );

    $acumatica_sync_api = $acumatica_sync_updater->getVcsApi();

    // Install the zip built by the release workflow, and nothing else. Without
    // REQUIRE, a release with no attached zip silently falls back to GitHub's
    // source archive, which would push the test harness and CI files to a live
    // site.
    $acumatica_sync_api->enableReleaseAssets(
        '/^acumatica-order-payment-sync\.zip$/i',
        $acumatica_sync_api::REQUIRE_RELEASE_ASSETS
    );
}

function acumatica_sync_init(): void {
    require_once ACUMATICA_SYNC_PATH . 'includes/class-config.php';
    require_once ACUMATICA_SYNC_PATH . 'includes/acumatica-api.php';
    require_once ACUMATICA_SYNC_PATH . 'includes/logger.php';
    require_once ACUMATICA_SYNC_PATH . 'includes/order-sync.php';
    require_once ACUMATICA_SYNC_PATH . 'includes/payment-sync.php';
    
    if ( is_admin() ) {
        require_once ACUMATICA_SYNC_PATH . 'admin/settings-page.php';
    }
}
add_action( 'plugins_loaded', 'acumatica_sync_init' );

add_action( 'admin_menu', function(): void {
    add_menu_page(
        'Acumatica Order & Payment Sync',
        'Acumatica Sync',
        'manage_woocommerce',
        'acumatica-sync',
        'acumatica_logs_page',
        'dashicons-cloud-upload',
        58
    );

    // Renames the auto-created first submenu item from "Acumatica Sync" to "Logs".
    add_submenu_page(
        'acumatica-sync',
        'Logs',
        'Logs',
        'manage_woocommerce',
        'acumatica-sync',
        'acumatica_logs_page'
    );

    add_submenu_page(
        'acumatica-sync',
        'Settings',
        'Settings',
        'manage_options',
        'acumatica-sync-settings',
        'acumatica_sync_settings_page'
    );
} );

/**
 * Admin CSS/JS, on this plugin's two screens only.
 *
 * Keyed off the hook suffix WordPress passes in, which is
 * "toplevel_page_acumatica-sync" for the logs screen and
 * "acumatica-sync_page_acumatica-sync-settings" for settings. That is what
 * WordPress itself uses to identify a screen; reading it back out of $_GET
 * meant trusting the query string to still be intact at enqueue time.
 */
add_action( 'admin_enqueue_scripts', function( $hook ): void {
    if ( ! str_contains( (string) $hook, 'acumatica-sync' ) ) {
        return;
    }

    wp_enqueue_style(
        'acumatica-sync-admin',
        ACUMATICA_SYNC_URL . 'admin/admin.css',
        [ 'dashicons' ],
        ACUMATICA_SYNC_VERSION
    );

    wp_enqueue_script(
        'acumatica-sync-admin',
        ACUMATICA_SYNC_URL . 'admin/admin.js',
        [],
        ACUMATICA_SYNC_VERSION,
        true
    );
} );

register_activation_hook( __FILE__, function(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'acumatica_logs';
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts            DATETIME NOT NULL,
        type          VARCHAR(50) NOT NULL,
        order_id      BIGINT UNSIGNED NULL,
        order_number  VARCHAR(100) NULL,
        order_status  VARCHAR(50) NULL,
        http_code     SMALLINT UNSIGNED NULL,
        site          VARCHAR(191) NULL,
        actor         VARCHAR(60) NULL,
        duration_ms   INT UNSIGNED NULL,
        error         TEXT NULL,
        request_json  LONGTEXT NULL,
        response_json LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY ts_idx (ts),
        KEY order_id_idx (order_id),
        KEY type_idx (type),
        KEY http_code_idx (http_code)
    ) {$charset};";

    dbDelta( $sql );

    if ( ! wp_next_scheduled( 'acumatica_logs_purge_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'acumatica_logs_purge_event' );
    }

    acumatica_sync_harden_option_storage();

    // Recorded so the admin_init upgrade step below can tell it has already run.
    update_option( 'acumatica_sync_version', ACUMATICA_SYNC_VERSION, false );
} );

register_deactivation_hook( __FILE__, function(): void {
    wp_clear_scheduled_hook( 'acumatica_logs_purge_event' );
} );

/**
 * Daily purge of log rows older than 30 days.
 */
add_action( 'acumatica_logs_purge_event', function(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'acumatica_logs';

    // ts is stored site-local via current_time('mysql'), so the cutoff has to
    // be site-local too. wp_date() formats a UTC timestamp in the site timezone.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE ts < %s",
        wp_date( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * 30 ) )
    ) );
} );

/**
 * Options that should never be autoloaded.
 *
 * Autoloaded options are pulled into memory on every request, front-end page
 * views included. These are read during a sync or on the settings screen and
 * nowhere else, so autoloading them puts the Acumatica password, client secret
 * and access tokens in the site-wide alloptions cache for no benefit.
 *
 * @return string[]
 */
function acumatica_sync_private_options(): array {
    return [
        'acumatica_access_token',
        'acumatica_access_token_expires',
        'acumatica_refresh_token',
        'acumatica_client_id',
        'acumatica_client_secret',
        'acumatica_username',
        'acumatica_password',
        'acumatica_token_url',
        'acumatica_api_url',
        'acumatica_endpoint_path',
    ];
}

/**
 * Move the private options off autoload.
 *
 * New writes pass autoload = false directly; this fixes rows already stored
 * with autoload = yes by an earlier version.
 */
function acumatica_sync_harden_option_storage(): void {
    foreach ( acumatica_sync_private_options() as $name ) {
        wp_set_option_autoload( $name, false );
    }
}

/**
 * One-time upgrade steps, run when the stored version is behind the code.
 */
add_action( 'admin_init', function(): void {
    if ( get_option( 'acumatica_sync_version' ) === ACUMATICA_SYNC_VERSION ) {
        return;
    }

    acumatica_sync_harden_option_storage();

    // The repo is public, so updates no longer authenticate. Drop the stored
    // token rather than leave a live credential sitting in the options table.
    delete_option( 'acumatica_gh_token' );

    // Purge event is only scheduled on activation, so a plugin updated in place
    // without a deactivate/reactivate cycle would otherwise never purge.
    if ( ! wp_next_scheduled( 'acumatica_logs_purge_event' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'acumatica_logs_purge_event' );
    }

    update_option( 'acumatica_sync_version', ACUMATICA_SYNC_VERSION, false );
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( array $links ): array {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=acumatica-sync-settings' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

/**
 * Declare WooCommerce HPOS compatibility
 */
add_action( 'before_woocommerce_init', function(): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
