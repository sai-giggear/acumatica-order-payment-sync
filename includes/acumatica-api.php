<?php
/**
 * Acumatica REST calls and OAuth2 token handling
 * File: includes/acumatica-api.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Endpoint path between the base URL and the resource name.
 *
 * Format is entity/{endpoint}/{version}. A site publishing an extended endpoint
 * has its own name here instead of Default2, and the version moves with each
 * Acumatica upgrade, so both are configurable.
 */
function acumatica_endpoint_path(): string {
    $default = 'entity/Default2/23.200.001';
    $path    = trim( (string) get_option( 'acumatica_endpoint_path', $default ), " \t\n\r\0\x0B/" );

    return $path !== '' ? $path : $default;
}

/**
 * Send a request to the Acumatica API
 *
 * @param string $endpoint  API endpoint (e.g., 'Payment', 'SalesOrder')
 * @param array  $data      Payload data
 * @param string $method    HTTP method (default: PUT)
 * @return array            ['success' => bool, 'response' => mixed, 'http_code' => int, 'duration_ms' => int, 'error' => string|null]
 */
function acumatica_api_request( string $endpoint, array $data, string $method = 'PUT' ): array {
    $start_time = microtime( true );
    
    $base_url = get_option( 'acumatica_api_url' );
    if ( empty( $base_url ) ) {
        return [
            'success'     => false,
            'response'    => null,
            'http_code'   => null,
            'duration_ms' => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
            'error'       => 'Acumatica API URL not configured',
        ];
    }
    
    $api_url = trailingslashit( $base_url ) . acumatica_endpoint_path() . '/' . $endpoint;
    
    $token = acumatica_get_token();
    if ( is_wp_error( $token ) ) {
        return [
            'success'     => false,
            'response'    => null,
            'http_code'   => null,
            'duration_ms' => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
            'error'       => 'Token error: ' . $token->get_error_message(),
        ];
    }

    $response = wp_remote_request( $api_url, [
        'method'  => $method,
        'timeout' => 30,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
        'body' => wp_json_encode( $data ),
    ] );

    $duration_ms = (int) ( ( microtime( true ) - $start_time ) * 1000 );

    if ( is_wp_error( $response ) ) {
        return [
            'success'     => false,
            'response'    => null,
            'http_code'   => null,
            'duration_ms' => $duration_ms,
            'error'       => 'Request failed: ' . $response->get_error_message(),
        ];
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body      = wp_remote_retrieve_body( $response );
    
    // Try to decode JSON, keep raw body if it fails
    $body_data = null;
    if ( ! empty( $body ) ) {
        $decoded = json_decode( $body, true );
        $body_data = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $body;
    }

    $success = $http_code >= 200 && $http_code < 300;

    $error = null;
    if ( ! $success ) {
        if ( is_array( $body_data ) ) {
            // Check nested innerException for detailed error
            $inner = $body_data['innerException'] ?? [];
            $error = $inner['exceptionMessage'] 
                ?? $body_data['exceptionMessage'] 
                ?? $body_data['message'] 
                ?? $body_data['Message']
                ?? $body_data['error']
                ?? 'API error (HTTP ' . $http_code . ')';
        } else {
            $error = $body ?: 'API error (HTTP ' . $http_code . ')';
        }
    }

    return [
        'success'     => $success,
        'response'    => $body_data,
        'http_code'   => $http_code,
        'duration_ms' => $duration_ms,
        'error'       => $error,
    ];
}

/**
 * Send AR Payment to Acumatica
 *
 * @param array $data Payment payload
 * @return array Result array with success, response, http_code, duration_ms, error
 */
function acumatica_send_to_api( array $data ): array {
    return acumatica_api_request( 'Payment', $data, 'PUT' );
}

/**
 * Send Sales Order to Acumatica
 *
 * @param array $data Order payload
 * @return array Result array with success, response, http_code, duration_ms, error
 */
function acumatica_send_salesorder_to_api( array $data ): array {
    return acumatica_api_request( 'SalesOrder', $data, 'PUT' );
}

/**
 * Token via the refresh grant. Returns the token data, or false on failure
 * after clearing the stored refresh token if the server rejected it.
 *
 * @param string $token_url
 * @param string $client_id
 * @param string $client_secret
 * @param string $refresh_token
 * @return array|false
 */
function acumatica_try_refresh_token( string $token_url, string $client_id, string $client_secret, string $refresh_token ): array|false {
    $response = wp_remote_post( $token_url, [
        'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        'body'    => http_build_query( [
            'grant_type'    => 'refresh_token',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
        ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $data      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code === 200 && ! empty( $data['access_token'] ) ) {
        return $data;
    }

    // Refresh token was rejected (invalid_grant or similar). Clear it so the
    // next request falls through to the password grant instead of repeatedly
    // failing with a stale token (e.g. after an instance change).
    //
    // Only clear the token we actually used: a concurrent sync may have already
    // rotated it, and deleting the newer one would force a pointless password
    // grant on every worker that follows.
    if ( get_option( 'acumatica_refresh_token' ) === $refresh_token ) {
        delete_option( 'acumatica_refresh_token' );
    }

    return false;
}

/**
 * Token via the password grant (ROPC), used when there is no usable refresh
 * token.
 *
 * @param string $token_url
 * @param string $client_id
 * @param string $client_secret
 * @return array|WP_Error
 */
function acumatica_try_password_grant( string $token_url, string $client_id, string $client_secret ): array|WP_Error {
    $username = get_option( 'acumatica_username' );
    $password = get_option( 'acumatica_password' );

    if ( ! $username || ! $password ) {
        return new WP_Error( 'config_error', 'Acumatica username/password not configured' );
    }

    $response = wp_remote_post( $token_url, [
        'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        'body'    => http_build_query( [
            'grant_type'    => 'password',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'username'      => $username,
            'password'      => $password,
            'scope'         => 'api offline_access',
        ] ),
        'timeout' => 30,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $data      = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code !== 200 || empty( $data['access_token'] ) ) {
        $error_msg = $data['error_description'] ?? $data['error'] ?? 'Could not retrieve access token';
        return new WP_Error( 'token_error', $error_msg );
    }

    return $data;
}

/**
 * Store token data returned from either grant flow.
 *
 * @param array $data Token response data
 */
function acumatica_store_token_data( array $data ): void {
    // autoload = false: these are only read during a sync, never on a front-end
    // page view, and autoloaded options are loaded into memory on every request.
    update_option( 'acumatica_access_token', $data['access_token'], false );

    if ( ! empty( $data['refresh_token'] ) ) {
        update_option( 'acumatica_refresh_token', $data['refresh_token'], false );
    }

    // Set expiry with 30-second buffer
    $ttl = ! empty( $data['expires_in'] ) ? (int) $data['expires_in'] : 300;
    update_option( 'acumatica_access_token_expires', time() + $ttl - 30, false );
}

/**
 * Retrieve or refresh the Acumatica OAuth2 access token.
 *
 * @param bool $force_refresh Fetch a new token even if the cached one is live.
 * @return string|WP_Error
 */
function acumatica_get_token( bool $force_refresh = false ): string|WP_Error {
    $access_token = get_option( 'acumatica_access_token', '' );
    $expires_at   = (int) get_option( 'acumatica_access_token_expires', 0 );

    // 1. Return cached token if still valid
    if ( ! $force_refresh && $access_token && time() < $expires_at ) {
        return $access_token;
    }

    $token_url     = get_option( 'acumatica_token_url' );
    $client_id     = get_option( 'acumatica_client_id' );
    $client_secret = get_option( 'acumatica_client_secret' );

    if ( ! $token_url || ! $client_id || ! $client_secret ) {
        return new WP_Error( 'config_error', 'Acumatica API credentials not configured' );
    }

    $data = false;

    // 2. Try refresh token if available
    $refresh_token = get_option( 'acumatica_refresh_token', '' );
    if ( $refresh_token ) {
        $data = acumatica_try_refresh_token( $token_url, $client_id, $client_secret, $refresh_token );
        // If $data is false, the refresh token was rejected and has been cleared.
        // Fall through to password grant below.
    }

    // 3. Fall back to password grant if refresh token was missing or rejected
    if ( false === $data ) {
        $data = acumatica_try_password_grant( $token_url, $client_id, $client_secret );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
    }

    acumatica_store_token_data( $data );

    return $data['access_token'];
}

/**
 * Force refresh the access token.
 *
 * @return string|WP_Error
 */
function acumatica_refresh_token(): string|WP_Error {
    return acumatica_get_token( true );
}