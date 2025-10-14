<?php
/**
 * Supplier Stock Handler
 *
 * - Downloads & parses CSV (with header detection)
 * - Caches parsed feed in transient
 * - Provides update routine for a single product (simple or variation)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSS_Handler {

    /**
     * Return associative array of SKU => qty
     *
     * Attempts to detect header columns (sku, stock, qty, quantity, available, etc.)
     * Caches result in transient for SSS_FEED_TRANSIENT_TTL seconds.
     *
     * @return array
     */
    public static function get_supplier_feed_data() {
        $cached = get_transient( SSS_FEED_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $url = defined( 'SSS_FEED_URL' ) ? SSS_FEED_URL : '';
        if ( empty( $url ) ) {
            return array();
        }

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return array();
        }

        // Remove UTF-8 BOM if present
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        // Parse CSV using more robust method
        $data = [];
        $stats = [
            'total_lines' => 0,
            'processed_lines' => 0,
            'skipped_empty' => 0,
            'skipped_no_sku' => 0,
            'duplicate_skus' => 0,
            'valid_entries' => 0
        ];

        // Use fgetcsv for better CSV parsing
        $temp_file = tmpfile();
        fwrite($temp_file, $body);
        rewind($temp_file);

        $header = null;
        $line_number = 0;

        while (($row = fgetcsv($temp_file)) !== FALSE) {
            $line_number++;
            $stats['total_lines']++;

            // Skip completely empty rows
            if (empty(array_filter($row, function($cell) { return trim($cell) !== ''; }))) {
                $stats['skipped_empty']++;
                continue;
            }

            if ($line_number === 1) {
                // Normalize header keys
                $header = array_map(function($h){
                    return strtolower(trim(preg_replace('/\s+/', ' ', $h)));
                }, $row);
                continue;
            }

            $stats['processed_lines']++;

            // Map row to associative array by header
            $assoc = [];
            foreach ($header as $i => $col) {
                $assoc[$col] = isset($row[$i]) ? trim($row[$i]) : '';
            }

            // Use exact header names from your CSV
            // headers normalized to lowercase with spaces collapsed:
            // 'variant sku' and 'variant inventory qty'
            $sku_key = 'variant sku';
            $qty_key = 'variant inventory qty';

            // Check if SKU field exists and is not empty
            if ( !isset($assoc[$sku_key]) || $assoc[$sku_key] === '' ) {
                $stats['skipped_no_sku']++;
                continue;
            }

            $sku = trim($assoc[$sku_key]);

            // Check for duplicate SKUs
            if (isset($data[$sku])) {
                $stats['duplicate_skus']++;
                // Log duplicate for debugging (you can remove this in production)
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("SSS: Duplicate SKU found: {$sku} (line {$line_number})");
                }
            }

            // Parse quantity (fallback to 0)
            $qty = 0;
            if ( isset($assoc[$qty_key]) && $assoc[$qty_key] !== '' ) {
                $qty = intval(preg_replace('/[^0-9\-]/', '', $assoc[$qty_key]));
            }

            $data[$sku] = $qty;
            $stats['valid_entries']++;
        }

        fclose($temp_file);

        // Store stats for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('SSS Feed Parsing Stats: ' . print_r($stats, true));
        }

        // Store stats in a separate transient for debugging
        set_transient( SSS_FEED_TRANSIENT . '_stats', $stats, SSS_FEED_TRANSIENT_TTL );

        set_transient( SSS_FEED_TRANSIENT, $data, SSS_FEED_TRANSIENT_TTL );
        return $data;
    }

    /**
     * Get parsing statistics for debugging
     *
     * @return array|false
     */
    public static function get_feed_parsing_stats() {
        return get_transient( SSS_FEED_TRANSIENT . '_stats' );
    }

    /**
     * Clear feed cache and stats (useful for testing)
     *
     * @return void
     */
    public static function clear_feed_cache() {
        delete_transient( SSS_FEED_TRANSIENT );
        delete_transient( SSS_FEED_TRANSIENT . '_stats' );
    }

    /**
     * Update a single product/variation based on supplier feed
     *
     * @param int|WC_Product $product_or_id
     * @return void
     */
    public static function update_product_from_feed( $product_or_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = is_object( $product_or_id ) ? $product_or_id : wc_get_product( $product_or_id );
        if ( ! $product ) {
            return;
        }

        // Only process items that have supplier stocking enabled
        $meta = $product->get_meta( '_supplier_stocked', true );
        if ( $meta !== 'yes' ) {
            return;
        }

        $sku = $product->get_sku();
        if ( empty( $sku ) ) {
            return;
        }

        $feed = self::get_supplier_feed_data();
        echo '<pre>'; print_r($feed); echo '</pre>';
        if ( empty( $feed ) ) {
            return;
        }

        // Normalize sku matching: exact match. If you need case-insensitive, use strtolower keys on both sides.
        if ( ! isset( $feed[ $sku ] ) ) {
            // try case-insensitive match
            $lower_feed = array_change_key_case( $feed, CASE_LOWER );
            $lower_sku  = strtolower( $sku );
            if ( isset( $lower_feed[ $lower_sku ] ) ) {
                $supplier_qty = intval( $lower_feed[ $lower_sku ] );
            } else {
                return;
            }
        } else {
            $supplier_qty = intval( $feed[ $sku ] );
        }

        $current_stock = (int) $product->get_stock_quantity();
        $current_status = $product->get_stock_status();

        // If store has stock and was on backorder, set to in stock and stop checking by requirement
        if ( $current_stock > 0 && $current_status === 'onbackorder' ) {
            $product->set_stock_status( 'instock' );
            $product->save();
            return;
        }

        // Only consult supplier when our stock is 0 (per requirements)
        if ( $current_stock <= 0 ) {
            if ( $supplier_qty > 0 ) {
                // mark backorder
                $product->set_stock_status( 'onbackorder' );
                // ensure backorders allowed so customers can purchase
                if ( method_exists( $product, 'set_backorders' ) ) {
                    // 'notify' allows backorders and notifies customer — change to 'yes' if you want silent
                    $product->set_backorders( 'notify' );
                }
                $product->save();
            } else {
                // supplier shows 0 => out of stock
                $product->set_stock_status( 'outofstock' );
                $product->save();
            }
        }
    }

    /**
     * Bulk update helper for arrays of IDs
     *
     * @param int[] $ids
     * @return void
     */
    public static function bulk_update_products( $ids = array() ) {
        if ( empty( $ids ) ) {
            return;
        }

        foreach ( $ids as $id ) {
            self::update_product_from_feed( $id );
        }
    }

    public static function get_supplier_feed_data_static() {
        $instance = new self();
        return $instance->get_supplier_feed_data();
    }

}
