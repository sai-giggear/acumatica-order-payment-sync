<?php
/**
 * Acumatica Sync logging and the admin log screen
 * File: includes/logger.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Write one log row.
 *
 * @param string      $type        Log type (e.g., 'Order Sync Success', 'Payment Sync Failed')
 * @param int|null    $order_id    WooCommerce order ID
 * @param array       $request     Request payload
 * @param mixed       $response    Response data
 * @param string|null $error       Error message if failed
 * @param int|null    $http_code   HTTP response code
 * @param int|null    $duration_ms API call duration in milliseconds
 */
function acumatica_log(
    string $type,
    ?int $order_id = null,
    array $request = [],
    mixed $response = null,
    ?string $error = null,
    ?int $http_code = null,
    ?int $duration_ms = null
): void {
    global $wpdb;

    $order_number = null;
    $order_status = null;
    
    if ( $order_id && function_exists( 'wc_get_order' ) ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order_number = $order->get_order_number();
            $order_status = wc_get_order_status_name( $order->get_status() );
        }
    }

    // Infer http_code from response if not provided
    if ( is_null( $http_code ) && is_array( $response ) && isset( $response['code'] ) ) {
        $http_code = (int) $response['code'];
    }

    $table = $wpdb->prefix . 'acumatica_logs';
    
    $inserted = $wpdb->insert(
        $table,
        [
            'ts'            => current_time( 'mysql' ),
            'type'          => $type,
            'order_id'      => $order_id,
            'order_number'  => $order_number,
            'order_status'  => $order_status,
            'http_code'     => $http_code,
            'site'          => parse_url( home_url(), PHP_URL_HOST ),
            'actor'         => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
            'duration_ms'   => $duration_ms,
            'error'         => $error,
            'request_json'  => wp_json_encode( $request, JSON_UNESCAPED_SLASHES ),
            'response_json' => wp_json_encode( $response, JSON_UNESCAPED_SLASHES ),
        ],
        [ '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
    );

    // A missing or broken log table would otherwise hide every sync failure
    // behind an empty logs page that looks like "nothing went wrong".
    if ( false === $inserted ) {
        error_log( sprintf(
            'Acumatica Sync: could not write log entry "%s" for order %s. DB error: %s',
            $type,
            $order_id ?: '-',
            $wpdb->last_error ?: 'unknown'
        ) );
    }
}

/**
 * Map a log entry type to what a resend should re-send.
 *
 * Order rows must trigger a Sales Order resend, payment rows an AR Payment.
 * Sending the wrong one applies a payment against an order Acumatica never got.
 *
 * @param string $type Log entry type
 * @return string      'order' or 'payment'
 */
function acumatica_log_resend_kind( string $type ): string {
    return str_contains( strtolower( $type ), 'order sync' ) ? 'order' : 'payment';
}

/**
 * Resend kinds mapped to the action that performs them.
 * Whitelist. Never do_action() on a name taken from request data.
 *
 * @return array<string, string>
 */
function acumatica_resend_actions(): array {
    return [
        'order'   => 'acumatica_resend_order',
        'payment' => 'acumatica_resend_payment',
    ];
}

/**
 * Log entries matching the admin screen's filters.
 *
 * @param int   $page     Page number
 * @param int   $per_page Items per page
 * @param array $filters  Filter options
 * @return array          [rows, total_count]
 */
function acumatica_get_logs( int $page = 1, int $per_page = 25, array $filters = [] ): array {
    global $wpdb;
    
    $table  = $wpdb->prefix . 'acumatica_logs';
    $offset = max( 0, ( $page - 1 ) * $per_page );
    
    $where  = [ '1=1' ];
    $params = [];

    if ( ! empty( $filters['type'] ) ) {
        $where[]  = 'type = %s';
        $params[] = $filters['type'];
    }

    if ( ! empty( $filters['order_id'] ) ) {
        $where[]  = 'order_id = %d';
        $params[] = (int) $filters['order_id'];
    }

    if ( isset( $filters['http_code'] ) && $filters['http_code'] !== '' ) {
        $where[]  = 'http_code = %d';
        $params[] = (int) $filters['http_code'];
    }

    if ( ! empty( $filters['success'] ) ) {
        if ( $filters['success'] === 'yes' ) {
            $where[] = 'http_code >= 200 AND http_code < 300';
        } else {
            $where[] = '(http_code < 200 OR http_code >= 300 OR http_code IS NULL)';
        }
    }

    if ( ! empty( $filters['search'] ) ) {
        $like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
        $where[]  = '(order_number LIKE %s OR error LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }

    // ts is site-local (current_time), so the bounds are read as site-local too.
    if ( ! empty( $filters['date_from'] ) ) {
        $where[]  = 'ts >= %s';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if ( ! empty( $filters['date_to'] ) ) {
        $where[]  = 'ts <= %s';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    $where_sql = implode( ' AND ', $where );

    // Separate COUNT rather than SQL_CALC_FOUND_ROWS, which is deprecated as of
    // MySQL 8.0.17. prepare() errors on a query with no placeholders, so the
    // unfiltered case has to skip it.
    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
    $total     = (int) ( $params
        ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
        : $wpdb->get_var( $count_sql ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
        ...array_merge( $params, [ (int) $per_page, (int) $offset ] )
    ), ARRAY_A );

    return [ $rows, $total ];
}

function acumatica_clear_logs(): void {
    global $wpdb;
    $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acumatica_logs" );
}

function acumatica_get_log_stats(): array {
    global $wpdb;
    $table = $wpdb->prefix . 'acumatica_logs';
    
    return [
        'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
        'success' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE http_code >= 200 AND http_code < 300" ),
        // Skipped rows have no http_code but are not failures. Without this a
        // staging site reads as 100% failed.
        'failed'  => (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE ( http_code < 200 OR http_code >= 300 OR http_code IS NULL )
               AND type NOT LIKE '%Skipped%'"
        ),
        // ts is written with current_time('mysql'), i.e. site-local. Comparing
        // it against a UTC midnight counted up to a full extra day.
        'today'   => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ts >= %s",
            wp_date( 'Y-m-d' ) . ' 00:00:00'
        ) ),
    ];
}

/**
 * Distinct entry types present in the log, for the filter dropdown.
 *
 * @return string[]
 */
function acumatica_log_types(): array {
    global $wpdb;

    return $wpdb->get_col( "SELECT DISTINCT type FROM {$wpdb->prefix}acumatica_logs ORDER BY type" ) ?: [];
}

/**
 * Read and validate the log list filters out of the query string.
 *
 * @return array{search:string, type:string, success:string, order_id:int, date_from:string, date_to:string}
 */
function acumatica_log_filters_from_request(): array {
    // Anything but Y-m-d is dropped rather than passed to the date comparison.
    $date = static function( string $key ): string {
        $value = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) );

        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
    };

    return [
        'search'    => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
        'type'      => sanitize_text_field( wp_unslash( $_GET['type'] ?? '' ) ),
        'success'   => in_array( $_GET['status'] ?? '', [ 'yes', 'no' ], true ) ? $_GET['status'] : '',
        'order_id'  => absint( $_GET['order_id'] ?? 0 ),
        'date_from' => $date( 'date_from' ),
        'date_to'   => $date( 'date_to' ),
    ];
}

/**
 * Handle the Clear / Resend buttons on the logs page.
 */
function acumatica_handle_logs_actions(): void {
    if ( ! isset( $_POST['acm_action'] ) || ! check_admin_referer( 'acm_logs_action', 'acm_nonce' ) ) {
        return;
    }

    switch ( $_POST['acm_action'] ) {
        case 'clear':
            acumatica_clear_logs();
            add_settings_error( 'acumatica_logs', 'cleared', 'All log entries deleted.', 'success' );
            break;

        case 'resend':
            $resend_id = (int) ( $_POST['resend_order_id'] ?? 0 );
            $kind      = sanitize_key( $_POST['resend_kind'] ?? '' );
            $actions   = acumatica_resend_actions();

            if ( $resend_id && isset( $actions[ $kind ] ) ) {
                do_action( $actions[ $kind ], $resend_id );
                add_settings_error(
                    'acumatica_logs',
                    'resend',
                    sprintf(
                        '%s resend triggered for order #%d. Reload for the result.',
                        'order' === $kind ? 'Sales order' : 'Payment',
                        $resend_id
                    ),
                    'success'
                );
            }
            break;
    }
}

function acumatica_logs_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'You do not have permission to view this page.' );
    }

    acumatica_handle_logs_actions();

    // Paginated: each entry renders its full request and response JSON inline,
    // so 200 rows could push megabytes of HTML in a single page.
    $per_page = (int) ( $_GET['per_page'] ?? 25 );
    $per_page = in_array( $per_page, [ 25, 50, 100 ], true ) ? $per_page : 25;
    $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

    $filters = acumatica_log_filters_from_request();
    $active  = (bool) array_filter( $filters );

    list( $logs, $total ) = acumatica_get_logs( $paged, $per_page, $filters );

    $stats = acumatica_get_log_stats();
    $base  = admin_url( 'admin.php?page=acumatica-sync' );
    $today = wp_date( 'Y-m-d' );

    $tiles = [
        [ 'total', 'Total', $stats['total'], $base, ! $active ],
        [ 'ok', 'Success', $stats['success'], add_query_arg( 'status', 'yes', $base ), 'yes' === $filters['success'] ],
        [ 'fail', 'Failed', $stats['failed'], add_query_arg( 'status', 'no', $base ), 'no' === $filters['success'] ],
        [ 'today', 'Today', $stats['today'], add_query_arg( 'date_from', $today, $base ), $today === $filters['date_from'] ],
    ];

    $first = $total ? ( ( $paged - 1 ) * $per_page ) + 1 : 0;
    $last  = min( $total, $paged * $per_page );
    ?>
    <div class="wrap acm-wrap">
        <div class="acm-head">
            <div>
                <h1>Acumatica Sync</h1>
                <p>Order and payment requests sent to Acumatica, with the raw payloads. Kept 30 days.</p>
            </div>
            <div class="acm-head-actions">
                <?php if ( ! Acumatica_Config::sync_enabled() ) : ?>
                    <span class="acm-pill acm-pill-warn" title="<?php echo esc_attr( Acumatica_Config::sync_disabled_reason() ); ?>">
                        Sync off
                    </span>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=acumatica-sync-settings' ) ); ?>">
                    Settings
                </a>
            </div>
        </div>

        <?php settings_errors( 'acumatica_logs' ); ?>

        <div class="acm-stats">
            <?php foreach ( $tiles as list( $key, $label, $value, $link, $is_active ) ) : ?>
                <a class="acm-stat acm-stat-<?php echo esc_attr( $key . ( $is_active ? ' is-active' : '' ) ); ?>"
                    href="<?php echo esc_url( $link ); ?>">
                    <b><?php echo esc_html( number_format_i18n( $value ) ); ?></b>
                    <span><?php echo esc_html( $label ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="acm-toolbar">
            <form method="get">
                <input type="hidden" name="page" value="acumatica-sync">
                <?php if ( $filters['order_id'] ) : ?>
                    <input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $filters['order_id'] ); ?>">
                <?php endif; ?>

                <input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
                    placeholder="Order number or error text">

                <select name="type" aria-label="Entry type">
                    <option value="">All types</option>
                    <?php foreach ( acumatica_log_types() as $type ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>>
                            <?php echo esc_html( $type ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" aria-label="Result">
                    <option value="">Any result</option>
                    <option value="yes" <?php selected( $filters['success'], 'yes' ); ?>>Success</option>
                    <option value="no" <?php selected( $filters['success'], 'no' ); ?>>Failed</option>
                </select>

                <span class="acm-field-label">From</span>
                <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"
                    max="<?php echo esc_attr( $today ); ?>" aria-label="From date">
                <span class="acm-field-label">to</span>
                <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"
                    max="<?php echo esc_attr( $today ); ?>" aria-label="To date">

                <select name="per_page" aria-label="Entries per page">
                    <?php foreach ( [ 25, 50, 100 ] as $size ) : ?>
                        <option value="<?php echo esc_attr( (string) $size ); ?>" <?php selected( $per_page, $size ); ?>>
                            <?php echo esc_html( $size . ' / page' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="button button-primary">Filter</button>

                <?php if ( $active ) : ?>
                    <a class="button-link" href="<?php echo esc_url( $base ); ?>">Reset</a>
                <?php endif; ?>
            </form>

            <div class="acm-toolbar-end">
                <form method="post">
                    <?php wp_nonce_field( 'acm_logs_action', 'acm_nonce' ); ?>
                    <input type="hidden" name="acm_action" value="clear">
                    <button type="submit" class="button acm-danger"
                        onclick="return confirm('Delete every log entry? This cannot be undone.');">
                        Clear logs
                    </button>
                </form>
            </div>
        </div>

        <?php if ( $total ) : ?>
            <p class="acm-result-line">
                <?php echo esc_html( sprintf(
                    'Showing %s–%s of %s %s',
                    number_format_i18n( $first ),
                    number_format_i18n( $last ),
                    number_format_i18n( $total ),
                    $active ? 'matching entries' : 'entries'
                ) ); ?>
            </p>
        <?php endif; ?>

        <?php if ( empty( $logs ) ) : ?>
            <div class="acm-empty">
                <span class="dashicons dashicons-<?php echo $active ? 'search' : 'cloud'; ?>"></span>
                <h2><?php echo $active ? 'No entries match these filters' : 'Nothing logged yet'; ?></h2>
                <p>
                    <?php if ( $active ) : ?>
                        <a href="<?php echo esc_url( $base ); ?>">Clear the filters</a> to see everything.
                    <?php else : ?>
                        Entries appear here as soon as an order or payment is sent to Acumatica.
                    <?php endif; ?>
                </p>
            </div>
        <?php else : ?>
            <div class="acm-logs">
                <?php foreach ( $logs as $log ) : ?>
                    <?php acumatica_render_log_entry( $log ); ?>
                <?php endforeach; ?>
            </div>
            <?php acumatica_render_log_pagination( $paged, $per_page, $total ); ?>
        <?php endif; ?>
    </div>
    <?php
}

function acumatica_render_log_pagination( int $paged, int $per_page, int $total ): void {
    $total_pages = (int) ceil( $total / max( 1, $per_page ) );

    if ( $total_pages < 2 ) {
        return;
    }

    $links = paginate_links( [
        'base'      => add_query_arg( 'paged', '%#%' ),
        'format'    => '',
        'current'   => $paged,
        'total'     => $total_pages,
        'prev_text' => '&lsaquo;',
        'next_text' => '&rsaquo;',
    ] );
    ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php echo esc_html( sprintf( 'Page %d of %d', $paged, $total_pages ) ); ?>
            </span>
            <?php echo wp_kses_post( (string) $links ); ?>
        </div>
    </div>
    <?php
}

/**
 * Admin edit URL for an order, on both HPOS and legacy post storage.
 */
function acumatica_order_edit_url( int $order_id ): string {
    // The helper landed part-way through the WooCommerce 7.x line, and this
    // plugin supports 7.0, so the method has to be checked as well as the class.
    $util = \Automattic\WooCommerce\Utilities\OrderUtil::class;

    if ( class_exists( $util ) && method_exists( $util, 'get_order_admin_edit_url' ) ) {
        return $util::get_order_admin_edit_url( $order_id );
    }

    return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
}

function acumatica_render_log_entry( array $log ): void {
    $http_code = isset( $log['http_code'] ) ? (int) $log['http_code'] : null;
    $success   = $http_code && $http_code >= 200 && $http_code < 300;

    // Skipped rows never reached the API, so they are neither a success nor a
    // failure and there is nothing to resend until the cause is fixed.
    $skipped = str_contains( strtolower( (string) $log['type'] ), 'skipped' );
    $state   = $skipped ? 'skip' : ( $success ? 'ok' : 'fail' );

    $id            = (int) $log['id'];
    $order_id      = (int) $log['order_id'];
    $order_display = $log['order_number'] ?: ( $order_id ?: '' );

    // ts is stored site-local, so it has to go through GMT before it can be
    // compared against time().
    $ts_utc = strtotime( get_gmt_from_date( (string) $log['ts'] ) . ' UTC' );
    ?>
    <details class="acm-log acm-log-<?php echo esc_attr( $state ); ?>">
        <summary>
            <span class="acm-badge acm-badge-<?php echo esc_attr( $state ); ?>">
                <?php echo esc_html( $skipped ? 'skip' : ( $success ? 'ok' : 'fail' ) ); ?>
            </span>
            <span class="acm-log-type"><?php echo esc_html( $log['type'] ); ?></span>
            <span class="acm-log-col">
                <?php echo $order_display ? esc_html( '#' . $order_display ) : '—'; ?>
                <?php if ( $log['order_status'] ) : ?>
                    · <?php echo esc_html( $log['order_status'] ); ?>
                <?php endif; ?>
            </span>
            <span class="acm-log-col"><?php echo esc_html( $http_code ? 'HTTP ' . $http_code : '—' ); ?></span>
            <span class="acm-log-col"><?php echo esc_html( $log['duration_ms'] ? $log['duration_ms'] . ' ms' : '—' ); ?></span>
            <span class="acm-log-col acm-log-time" title="<?php echo esc_attr( (string) $log['ts'] ); ?>">
                <?php echo esc_html( $ts_utc ? human_time_diff( $ts_utc ) . ' ago' : (string) $log['ts'] ); ?>
            </span>
        </summary>

        <div class="acm-log-body">
            <?php if ( ! empty( $log['error'] ) ) : ?>
                <div class="acm-log-error">
                    <span class="dashicons dashicons-warning"></span>
                    <span><?php echo esc_html( $log['error'] ); ?></span>
                </div>
            <?php endif; ?>

            <div class="acm-panes">
                <?php foreach ( [ 'request' => $log['request_json'], 'response' => $log['response_json'] ] as $label => $payload ) : ?>
                    <?php $pane_id = 'acm-' . $label . '-' . $id; ?>
                    <div class="acm-pane">
                        <div class="acm-pane-head">
                            <span><?php echo esc_html( $label ); ?></span>
                            <button type="button" class="button acm-copy"
                                data-copy-target="<?php echo esc_attr( $pane_id ); ?>">Copy</button>
                        </div>
                        <pre id="<?php echo esc_attr( $pane_id ); ?>"><?php
                            echo esc_html( acumatica_format_json( $payload ) );
                        ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="acm-log-meta">
                <span><?php echo esc_html( mysql2date( 'M j, Y g:i:s a', (string) $log['ts'] ) ); ?></span>
                <span>Site: <?php echo esc_html( $log['site'] ?: '—' ); ?></span>
                <span>By: <?php echo esc_html( $log['actor'] ?: '—' ); ?></span>

                <?php if ( $order_id ) : ?>
                    <span>
                        <a href="<?php echo esc_url( acumatica_order_edit_url( $order_id ) ); ?>">Edit order</a>
                        ·
                        <a href="<?php echo esc_url( add_query_arg(
                            'order_id',
                            $order_id,
                            admin_url( 'admin.php?page=acumatica-sync' )
                        ) ); ?>">All entries for this order</a>
                    </span>
                <?php endif; ?>

                <?php if ( ! $success && ! $skipped && $order_id ) : ?>
                    <?php $resend_kind = acumatica_log_resend_kind( (string) $log['type'] ); ?>
                    <form method="post">
                        <?php wp_nonce_field( 'acm_logs_action', 'acm_nonce' ); ?>
                        <input type="hidden" name="acm_action" value="resend">
                        <input type="hidden" name="resend_kind" value="<?php echo esc_attr( $resend_kind ); ?>">
                        <input type="hidden" name="resend_order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
                        <button type="submit" class="button button-small">
                            <?php echo 'order' === $resend_kind ? 'Resend sales order' : 'Resend payment'; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </details>
    <?php
}

/**
 * Pretty-print a stored JSON column for display, with truncation.
 *
 * Takes the raw column rather than a decoded value, so json_validate() (PHP
 * 8.3) can tell a corrupt row apart from a payload that genuinely was null.
 * Both render as "null" once decoded, and this is the screen you open to find
 * out why a sync failed.
 */
function acumatica_format_json( ?string $stored, int $limit = 50000 ): string {
    $stored = trim( (string) $stored );

    if ( '' === $stored ) {
        return '—';
    }

    $output = json_validate( $stored )
        ? (string) wp_json_encode( json_decode( $stored ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
        : "[not valid JSON, raw column value follows]\n\n" . $stored;

    return strlen( $output ) > $limit
        ? substr( $output, 0, $limit ) . "\n… [truncated]"
        : $output;
}
