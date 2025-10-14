<?php
/**
 * Admin meta boxes & saving for Supplier Stocked flag
 *
 * Adds:
 * - Simple product checkbox in Inventory tab
 * - Variation-level checkbox in variation editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add checkbox to simple product inventory tab
 */
add_action( 'woocommerce_product_options_inventory_product_data', 'sss_add_supplier_stock_checkbox_simple' );
function sss_add_supplier_stock_checkbox_simple() {
    woocommerce_wp_checkbox(
        array(
            'id'          => '_supplier_stocked',
            'label'       => __( 'Supplier Stocked', 'supplier-stock-sync' ),
            'description' => __( 'Enable sync with supplier feed for this product/variation.', 'supplier-stock-sync' ),
        )
    );
}

/**
 * Save product-level meta when product is saved (works for simple & parent)
 */
add_action( 'woocommerce_admin_process_product_object', 'sss_save_supplier_stock_meta' );
function sss_save_supplier_stock_meta( $product ) {
    $value = isset( $_POST['_supplier_stocked'] ) && $_POST['_supplier_stocked'] === 'yes' ? 'yes' : 'no';
    $product->update_meta_data( '_supplier_stocked', $value );
    $product->save_meta_data();
}

/**
 * Add checkbox to variation edit rows
 */
add_action( 'woocommerce_variation_options', 'sss_variation_supplier_stock_field', 10, 3 );
function sss_variation_supplier_stock_field( $loop, $variation_data, $variation ) {
    // Field name pattern: _supplier_stocked[<variation_id>]
    $value = get_post_meta( $variation->ID, '_supplier_stocked', true );
    $checked = $value === 'yes' ? 'yes' : 'no';

    woocommerce_wp_checkbox( array(
        'id'            => "_supplier_stocked[{$variation->ID}]",
        'wrapper_class' => 'form-row form-row-full',
        'label'         => __( 'Supplier Stocked', 'supplier-stock-sync' ),
        'value'         => $checked,
    ) );
}

/**
 * Save variation meta when variations are saved
 */
add_action( 'woocommerce_save_product_variation', 'sss_save_supplier_stock_variation_meta', 10, 2 );
function sss_save_supplier_stock_variation_meta( $variation_id, $i ) {
    $val = 'no';
    if ( isset( $_POST['_supplier_stocked'][ $variation_id ] ) && $_POST['_supplier_stocked'][ $variation_id ] === 'yes' ) {
        $val = 'yes';
    }
    update_post_meta( $variation_id, '_supplier_stocked', $val );
}
