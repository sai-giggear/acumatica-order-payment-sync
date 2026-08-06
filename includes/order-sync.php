<?php
/**
 * Sends WooCommerce orders to Acumatica ERP
 * File: includes/order-sync.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook into paid order status
add_action( 'woocommerce_order_status_processing', 'acumatica_send_order_request', 10, 1 );

/**
 * Build Sales Order payload from WooCommerce order
 */
function acumatica_build_order_payload( WC_Order $order, array $site_config, array $payment_config ): array {
    $billing_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

    $payload = [
        'CustomerID'    => [ 'value' => $site_config['customer_id'] ],
        'CustomerOrder' => [ 'value' => $order->get_order_number() ],
        'OrderNbr'      => [ 'value' => $order->get_order_number() ],
        'OrderType'     => [ 'value' => $site_config['order_type'] ],
        'Website'       => [ 'value' => $site_config['website'] ], // Use short name to fit Acumatica field
        'Source'        => [ 'value' => 'WEBSITE' ],
        'ExternalRef'   => [ 'value' => $order->get_payment_method() ],
        'Description'   => [ 'value' => $order->get_payment_method_title() ],
        'PaymentMethod' => [ 'value' => $payment_config['acumatica_method'] ],
        'Details'       => [],
        
        // Shipping contact
        'ShipToContactOverride' => [ 'value' => true ],
        'ShipToContact' => [
            'BusinessName' => [ 'value' => $billing_name ],
            'Email'        => [ 'value' => $order->get_billing_email() ],
            'Phone1'       => [ 'value' => $order->get_shipping_phone() ?: $order->get_billing_phone() ],
        ],
        
        // Shipping address
        'ShipToAddressOverride' => [ 'value' => true ],
        'ShipToAddress' => [
            'AddressLine1' => [ 'value' => $order->get_shipping_address_1() ],
            'AddressLine2' => [ 'value' => $order->get_shipping_address_2() ],
            'City'         => [ 'value' => $order->get_shipping_city() ],
            'PostalCode'   => [ 'value' => $order->get_shipping_postcode() ],
            'Country'      => [ 'value' => $order->get_shipping_country() ],
            'State'        => [ 'value' => $order->get_shipping_state() ],
        ],
        
        // Billing address
        'BillToAddressOverride' => [ 'value' => true ],
        'BillToAddress' => [
            'AddressLine1' => [ 'value' => $order->get_billing_address_1() ],
            'AddressLine2' => [ 'value' => $order->get_billing_address_2() ],
            'City'         => [ 'value' => $order->get_billing_city() ],
            'PostalCode'   => [ 'value' => $order->get_billing_postcode() ],
            'Country'      => [ 'value' => $order->get_billing_country() ],
            'State'        => [ 'value' => $order->get_billing_state() ],
        ],
        
        // Billing contact
        'BillToContactOverride' => [ 'value' => true ],
        'BillToContact' => [
            'BusinessName' => [ 'value' => $billing_name ],
            'Email'        => [ 'value' => $order->get_billing_email() ],
            'Phone1'       => [ 'value' => $order->get_billing_phone() ],
        ],
        
        // Totals
        'Totals' => [
            'OverrideFreightAmount' => [ 'value' => true ],
            'Freight'               => [ 'value' => (float) $order->get_shipping_total() ],
            'FreightTaxCategory'    => [ 'value' => 'DEFAULTD' ],
        ],
    ];

    // Add line items
    foreach ( $order->get_items() as $item ) {
        $line = acumatica_build_line_item( $item );
        if ( $line ) {
            $payload['Details'][] = $line;
        }
    }

    // Handle local pickup
    $shipping_method = $order->get_shipping_method();
    if ( Acumatica_Config::is_local_pickup( $shipping_method ) ) {
        $payload['ShipVia'] = [ 'value' => 'LOCAL PICK UP' ];
    }

    return apply_filters( 'acumatica_order_payload', $payload, $order );
}

/**
 * Build a single line item for the order payload
 */
function acumatica_build_line_item( WC_Order_Item_Product $item ): ?array {
    $product = $item->get_product();
    $qty     = (float) $item->get_quantity();
    
    if ( $qty <= 0 ) {
        return null;
    }

    $sku          = $product ? $product->get_sku() : '';
    $subtotal_ex  = (float) $item->get_subtotal();  // Before discounts, ex GST
    $total_ex     = (float) $item->get_total();     // After discounts, ex GST
    $unit_price   = round( $subtotal_ex / max( 1, $qty ), 2 );
    $discount     = max( 0, $subtotal_ex - $total_ex );

    $line = [
        'InventoryID' => [ 'value' => $sku ],
        'OrderQty'    => [ 'value' => $qty ],
        'UnitPrice'   => [ 'value' => $unit_price ],
        'ManualPrice' => [ 'value' => true ],
    ];

    // Add discount if applicable
    if ( $discount > 0.0001 ) {
        $line['ManualDiscount'] = [ 'value' => true ];
        $line['DiscountAmount'] = [ 'value' => $discount ];
    }

    return $line;
}

/**
 * Line items with no usable Acumatica InventoryID.
 *
 * An empty InventoryID makes Acumatica reject the whole sales order, not just
 * the offending line, so it is worth catching before the request goes out.
 *
 * @return string[] Item names that are missing a SKU
 */
function acumatica_missing_skus( WC_Order $order ): array {
    $missing = [];

    foreach ( $order->get_items() as $item ) {
        if ( (float) $item->get_quantity() <= 0 ) {
            continue;
        }

        $product = $item->get_product();

        // Deleted product, or one that was never given a SKU.
        if ( ! $product || '' === trim( (string) $product->get_sku() ) ) {
            $missing[] = $item->get_name();
        }
    }

    return $missing;
}

/**
 * Send order to Acumatica
 *
 * @param int|WC_Order $order_or_id Order ID or order object
 * @param bool         $force       Bypass the duplicate-send guard (manual resend)
 */
function acumatica_send_order_request( $order_or_id, bool $force = false ): void {
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
        acumatica_log( 'Order Sync Skipped', $order_id, [], null, Acumatica_Config::sync_disabled_reason() );
        return;
    }

    // Prevent duplicate sends (unless manually triggered)
    if ( ! $force && $order->get_meta( 'acumatica_order_sent' ) === 'true' ) {
        return;
    }

    // Fail loudly rather than posting a half-built order. A sales order missing
    // lines is worse to unpick than one that never arrived.
    $missing_skus = acumatica_missing_skus( $order );
    if ( $missing_skus ) {
        $order->update_meta_data( 'acumatica_order_sent', 'failed' );
        $order->save_meta_data();

        acumatica_log(
            'Order Sync Failed',
            $order_id,
            [],
            null,
            'Not sent: no SKU (Acumatica InventoryID) for: ' . implode( ', ', $missing_skus )
        );
        return;
    }

    // Get configurations
    $site_config    = Acumatica_Config::get_site_config();
    $payment_config = Acumatica_Config::get_payment_config( $order->get_payment_method() );

    // Build and send payload
    $payload = acumatica_build_order_payload( $order, $site_config, $payment_config );
    $result  = acumatica_send_salesorder_to_api( $payload );

    // Update order meta
    $success = $result['success'] ?? false;
    $order->update_meta_data( 'acumatica_order_sent', $success ? 'true' : 'failed' );
    $order->save_meta_data();
    
    // Log the result
    acumatica_log(
        $success ? 'Order Sync Success' : 'Order Sync Failed',
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
    $actions['resend_acumatica_order'] = 'Resend Sales Order to Acumatica';
    return $actions;
} );

add_action( 'woocommerce_order_action_resend_acumatica_order', 'acumatica_resend_order_now' );

// Handle resend from logs page
add_action( 'acumatica_resend_order', 'acumatica_resend_order_now' );

/**
 * Manual resend: ignore the duplicate-send guard.
 *
 * @param int|WC_Order $order_or_id
 */
function acumatica_resend_order_now( $order_or_id ): void {
    acumatica_send_order_request( $order_or_id, true );
}