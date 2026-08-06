<?php
/**
 * Sends WooCommerce payments to Acumatica as AR Payments
 * File: includes/payment-sync.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Processor fees (PayPal, Stripe) are written by a capture webhook that lands
// after the status transition, so reading them in the checkout request gets
// nothing. Queue the sync instead and re-check the fee before giving up.
add_action( 'woocommerce_order_status_processing', 'acumatica_schedule_payment_sync', 20, 1 );
add_action( 'acumatica_sync_payment', 'acumatica_send_payment_request', 10, 3 );

/**
 * Minutes to wait between fee re-checks, as seconds.
 *
 * How long a gateway takes to report its fee varies by processor and account,
 * so this is a tuning knob rather than a fixed constant. Floored at 1 minute.
 */
function acumatica_fee_retry_delay(): int {
    return max( 1, (int) get_option( 'acumatica_fee_retry_delay', 5 ) ) * MINUTE_IN_SECONDS;
}

/**
 * How many times to re-check for the fee before sending without it.
 * Zero disables waiting entirely.
 */
function acumatica_fee_max_attempts(): int {
    return max( 0, (int) get_option( 'acumatica_fee_max_attempts', 3 ) );
}

/**
 * Queue the payment sync rather than running it inline.
 *
 * @param int|WC_Order $order_or_id
 * @param int          $attempt  Fee re-check attempt number
 * @param bool         $force    Bypass the duplicate-send guard
 */
function acumatica_schedule_payment_sync( $order_or_id, int $attempt = 0, bool $force = false ): void {
    $order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : (int) $order_or_id;

    if ( ! $order_id || ! Acumatica_Config::sync_enabled() ) {
        return;
    }

    // Action Scheduler ships with WooCommerce. If it is somehow absent, run
    // inline rather than silently dropping the sync.
    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        acumatica_send_payment_request( $order_id, acumatica_fee_max_attempts(), $force );
        return;
    }

    // Order status can bounce (processing -> on-hold -> processing) and queue
    // this twice, which risks two AR payments for one order. Retries are
    // self-generated and strictly sequential, so only the first enqueue can
    // duplicate.
    //
    // ponytail: enqueue-time dedupe only. If Action Scheduler ever re-runs an
    // action it marked in-progress (worker timeout), the acumatica_payment_sent
    // meta guard is the last line of defence. Add an option-row mutex around
    // the send if duplicate payments ever show up in Acumatica.
    if ( 0 === $attempt && ! $force
        && function_exists( 'as_has_scheduled_action' )
        && as_has_scheduled_action( 'acumatica_sync_payment', [ $order_id, 0, false ], 'acumatica' ) ) {
        return;
    }

    $delay = $attempt > 0 ? acumatica_fee_retry_delay() : 0;

    as_schedule_single_action(
        time() + $delay,
        'acumatica_sync_payment',
        [ $order_id, $attempt, $force ],
        'acumatica'
    );
}

/**
 * Should we wait for a processor fee to land instead of sending now?
 *
 * Pure decision logic, kept separate from the order read so it is testable.
 *
 * @param string $fee_meta_key Configured fee meta key ('' if none for this method)
 * @param mixed  $fee_value    Raw meta value as read from the order
 * @param int    $attempt      Re-check attempt number
 * @param bool   $force        Manual resend, never wait
 * @param int    $max_attempts Ceiling, passed in so this stays free of options
 */
function acumatica_should_wait_for_fee( string $fee_meta_key, mixed $fee_value, int $attempt, bool $force, int $max_attempts ): bool {
    if ( $force || '' === $fee_meta_key || $attempt >= $max_attempts ) {
        return false;
    }

    return ! is_scalar( $fee_value ) || (float) $fee_value <= 0;
}

/**
 * Build AR Payment payload from WooCommerce order
 */
function acumatica_build_payment_payload( WC_Order $order, array $site_config, array $payment_config ): array {
    $payload = [
        'Type'          => [ 'value' => 'Payment' ],
        'CustomerID'    => [ 'value' => $site_config['customer_id'] ],
        'PaymentMethod' => [ 'value' => $payment_config['acumatica_method'] ],
        'CashAccount'   => [ 'value' => $payment_config['cash_account'] ],
        'PaymentAmount' => [ 'value' => (float) $order->get_total() ],
        'PaymentRef'    => [ 'value' => $order->get_order_number() ],
        'Description'   => [ 'value' => $order->get_payment_method_title() ],
        'OrdersToApply' => [
            [
                'OrderType' => [ 'value' => $site_config['order_type'] ],
                'OrderNbr'  => [ 'value' => $order->get_order_number() ],
            ],
        ],
    ];

    // Add payment processor charges/fees if applicable
    $charges = acumatica_build_payment_charges( $order, $payment_config );
    if ( $charges ) {
        $payload['Charges'] = $charges;
    }

    return apply_filters( 'acumatica_payment_payload', $payload, $order );
}

/**
 * Build charges array for payment fees (Stripe fees, PayPal fees, etc.)
 */
function acumatica_build_payment_charges( WC_Order $order, array $payment_config ): ?array {
    // Check if fee tracking is configured for this payment method
    if ( empty( $payment_config['fee_meta_key'] ) ) {
        return null;
    }

    $raw_fee = $order->get_meta( $payment_config['fee_meta_key'] );

    // Some gateways store the fee as a breakdown array. Casting that to float
    // yields 1.0, which would post a phantom $1.00 charge to the GL.
    if ( ! is_scalar( $raw_fee ) ) {
        return null;
    }

    $fee_amount = (float) $raw_fee;

    if ( $fee_amount <= 0 ) {
        return null;
    }

    return [
        [
            'EntryTypeID' => [ 'value' => $payment_config['entry_type'] ],
            'AccID'       => [ 'value' => $payment_config['fee_account'] ],
            'SubAccID'    => [ 'value' => $payment_config['fee_subaccount'] ],
            'Amount'      => [ 'value' => $fee_amount ],
        ],
    ];
}

/**
 * Send payment to Acumatica
 *
 * @param int|WC_Order $order_or_id Order ID or order object
 * @param int          $attempt     Fee re-check attempt number
 * @param bool         $force       Bypass the duplicate-send guard (manual resend)
 */
function acumatica_send_payment_request( $order_or_id, int $attempt = 0, bool $force = false ): void {
    // Handle both order ID and order object (WC passes object on manual actions)
    if ( $order_or_id instanceof WC_Order ) {
        $order    = $order_or_id;
        $order_id = $order->get_id();
    } else {
        $order_id = (int) $order_or_id;
        $order    = wc_get_order( $order_id );
    }

    if ( ! $order ) {
        return;
    }

    if ( ! Acumatica_Config::sync_enabled() ) {
        acumatica_log( 'Payment Sync Skipped', $order_id, [], null, Acumatica_Config::sync_disabled_reason() );
        return;
    }

    // Prevent duplicate sends (unless manually triggered)
    if ( ! $force && $order->get_meta( 'acumatica_payment_sent' ) === 'true' ) {
        return;
    }

    // Get configurations
    $site_config    = Acumatica_Config::get_site_config();
    $payment_config = Acumatica_Config::get_payment_config( $order->get_payment_method() );

    // Processor fee may not have been recorded yet. Re-check shortly rather
    // than posting a payment with no Charges line.
    $fee_pending = acumatica_should_wait_for_fee(
        $payment_config['fee_meta_key'],
        $order->get_meta( $payment_config['fee_meta_key'] ),
        $attempt,
        $force,
        acumatica_fee_max_attempts()
    );

    if ( $fee_pending ) {
        acumatica_schedule_payment_sync( $order_id, $attempt + 1, $force );
        return;
    }

    // Build and send payload
    $payload = acumatica_build_payment_payload( $order, $site_config, $payment_config );
    $result  = acumatica_send_to_api( $payload );

    // Update order meta
    $success = $result['success'] ?? false;
    $order->update_meta_data( 'acumatica_payment_sent', $success ? 'true' : 'failed' );
    $order->save_meta_data();
    
    // Log the result
    acumatica_log(
        $success ? 'Payment Sync Success' : 'Payment Sync Failed',
        $order_id,
        $payload,
        $result['response'] ?? null,
        $result['error'] ?? null,
        $result['http_code'] ?? null,
        $result['duration_ms'] ?? null
    );
}

// Register manual order action
add_filter( 'woocommerce_order_actions', function( array $actions ): array {
    $actions['resend_acumatica_payment'] = 'Resend Payment to Acumatica';
    return $actions;
} );

add_action( 'woocommerce_order_action_resend_acumatica_payment', 'acumatica_resend_payment_now' );

// Handle resend from logs page
add_action( 'acumatica_resend_payment', 'acumatica_resend_payment_now' );

/**
 * Manual resend: send immediately with whatever fee is recorded, and ignore
 * the duplicate-send guard. Runs inline so the admin sees the log row appear.
 *
 * @param int|WC_Order $order_or_id
 */
function acumatica_resend_payment_now( $order_or_id ): void {
    acumatica_send_payment_request( $order_or_id, acumatica_fee_max_attempts(), true );
}