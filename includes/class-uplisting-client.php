<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Uplisting_Client {
    private $api_keys;
    private $base_url = 'https://connect.uplisting.io/';

    public function __construct($api_keys = []) {
    // Always store as a single key (first only)
    if (is_array($api_keys)) {
        $this->api_keys = [trim(reset($api_keys))];
    } else {
        $this->api_keys = [trim($api_keys)];
    }
}


    /**
     * Low-level request
     * - Se $force_key è passato, usa SOLO quella chiave
     * - Altrimenti prova in ordine tutte le chiavi e ritorna il primo 200 valido
     */
    private function request( $endpoint, $params = [], $force_key = null ) {
        $keys = $force_key ? [$force_key] : $this->api_keys;
        if ( empty( $keys ) ) return [ 'error' => 'No API keys configured' ];

        $url_base = trailingslashit( $this->base_url ) . ltrim( $endpoint, '/' );
        if ( ! empty( $params ) ) $url_base = add_query_arg( $params, $url_base );

        foreach ( $keys as $key ) {
            $encoded = base64_encode( trim( $key ) );
            $resp = wp_remote_get( $url_base, [
                'headers' => [
                    'Authorization' => 'Basic ' . $encoded,
                    'Accept'        => 'application/json'
                ],
                'timeout' => 30,
            ]);
            if ( is_wp_error( $resp ) ) continue;

            $code = wp_remote_retrieve_response_code( $resp );
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( $code === 200 && is_array($body) ) return $body;
        }
        return [ 'error' => 'All API keys failed or invalid response' ];
    }

    /** Single-key helpers (kept for compatibility) */
    public function get_properties() {
        return $this->request( 'properties' );
    }
    public function get_property( $id ) {
        if ( ! $id ) return [ 'error' => 'Missing property ID' ];
        return $this->request( 'properties/' . intval( $id ) );
    }
    public function get_availability( $args = [] ) {
        $defaults = [
            'check_in' => '', 'check_out' => '',
            'number_of_guests' => '', 'city' => ''
        ];
        $params = array_filter( wp_parse_args( $args, $defaults ), function($v){ return $v!=='' && $v!==null; });
        return $this->request( 'availability', $params );
    }

    /** -------- MULTI-KEY methods (merge across accounts) -------- */

    public function get_properties_all() {
        $all = ['data'=>[], 'included'=>[]];

        foreach ($this->api_keys as $key) {
            $resp = $this->request('properties', [], $key);
            if (!empty($resp['data'])) {
                // tag each item with source key to reuse later if needed
                foreach ($resp['data'] as $item) {
                    $item['_upl_key'] = $key;
                    $all['data'][] = $item;
                }
            }
            if (!empty($resp['included'])) {
                // simple merge + de-dup on id|type
                $seen = [];
                foreach ($all['included'] as $inc) $seen[$inc['type'].'|'.$inc['id']] = true;
                foreach ($resp['included'] as $inc) {
                    $k = $inc['type'].'|'.$inc['id'];
                    if (empty($seen[$k])) { $all['included'][] = $inc; $seen[$k] = true; }
                }
            }
        }
        return $all;
    }

    public function get_availability_all( $args = [] ) {
        $defaults = [
            'check_in' => '', 'check_out' => '',
            'number_of_guests' => '', 'city' => ''
        ];
        $params = array_filter( wp_parse_args( $args, $defaults ), function($v){ return $v!=='' && $v!==null; });

        $merged = ['data'=>[]];
        $seen   = []; // dedup by property id

        foreach ($this->api_keys as $key) {
            $resp = $this->request('availability', $params, $key);
            if (!empty($resp['data']) && is_array($resp['data'])) {
                foreach ($resp['data'] as $prop) {
                    $pid = $prop['id'] ?? null;
                    if (!$pid || isset($seen[$pid])) continue;
                    // tag with source key (used for calendar lookups)
                    $prop['_upl_key'] = $key;
                    $merged['data'][] = $prop;
                    $seen[$pid] = true;
                }
            }
        }
        return $merged;
    }

    /** Calendar per chiave specifica (serve la chiave giusta per quel listing) */
    public function get_calendar_for_key($key, $listing_id, $from = '', $to = '') {
        if (!$listing_id) return [];
        $endpoint = 'calendar/' . urlencode($listing_id);
        $params = [];
        if ($from) $params['from'] = $from;
        if ($to)   $params['to']   = $to;
        return $this->request($endpoint, $params, $key);
    }
}
