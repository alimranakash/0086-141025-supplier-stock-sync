<?php
/**
 * Admin meta boxes & saving for Supplier Stocked flag + Supplier SKU + Threshold
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add fields to simple product inventory tab
 */
add_action( 'woocommerce_product_options_inventory_product_data', 'sss_add_supplier_stock_fields_simple' );
function sss_add_supplier_stock_fields_simple() {
    echo '<div class="options_group">';

    // Supplier Stocked checkbox
    woocommerce_wp_checkbox(
        array(
            'id'          => '_supplier_stocked',
            'label'       => __( 'Supplier Stocked', 'supplier-stock-sync' ),
            'description' => __( 'Enable sync with supplier feed for this product/variation.', 'supplier-stock-sync' ),
        )
    );

    // Supplier SKU text field
    woocommerce_wp_text_input(
        array(
            'id'          => '_supplier_sku',
            'label'       => __( 'Supplier SKU (optional)', 'supplier-stock-sync' ),
            'desc_tip'    => true,
            'description' => __( 'If provided, this SKU will be used instead of the product SKU when checking supplier feed.', 'supplier-stock-sync' ),
        )
    );

    // Threshold limit field
    woocommerce_wp_text_input(
        array(
            'id'          => '_supplier_threshold',
            'label'       => __( 'Supplier Threshold', 'supplier-stock-sync' ),
            'desc_tip'    => true,
            'description' => __( 'Minimum supplier quantity required to mark product as backorder. Leave blank for default (1).', 'supplier-stock-sync' ),
            'type'        => 'number',
            'custom_attributes' => array(
                'min' => '0',
                'step' => '1',
            ),
        )
    );

    echo '</div>';
}

/**
 * Save product-level meta
 */
add_action( 'woocommerce_admin_process_product_object', 'sss_save_supplier_stock_meta' );
function sss_save_supplier_stock_meta( $product ) {
    $value = isset( $_POST['_supplier_stocked'] ) && $_POST['_supplier_stocked'] === 'yes' ? 'yes' : 'no';
    $product->update_meta_data( '_supplier_stocked', $value );

    // Supplier SKU
    $supplier_sku = isset( $_POST['_supplier_sku'] ) ? sanitize_text_field( $_POST['_supplier_sku'] ) : '';
    $product->update_meta_data( '_supplier_sku', $supplier_sku );

    // Threshold
    $threshold = isset( $_POST['_supplier_threshold'] ) ? intval( $_POST['_supplier_threshold'] ) : '';
    $product->update_meta_data( '_supplier_threshold', $threshold );

    $product->save_meta_data();
}

/**
 * Add fields to variation edit rows
 */
add_action( 'woocommerce_variation_options', 'sss_variation_supplier_stock_field', 10, 3 );
function sss_variation_supplier_stock_field( $loop, $variation_data, $variation ) {

    // Supplier Stocked
    $checked = get_post_meta( $variation->ID, '_supplier_stocked', true ) === 'yes' ? 'yes' : 'no';
    woocommerce_wp_checkbox( array(
        'id'            => "_supplier_stocked[{$variation->ID}]",
        'wrapper_class' => 'form-row form-row-full',
        'label'         => __( 'Supplier Stocked', 'supplier-stock-sync' ),
        'value'         => $checked,
    ) );

    // Supplier SKU
    $supplier_sku = get_post_meta( $variation->ID, '_supplier_sku', true );
    woocommerce_wp_text_input( array(
        'id'            => "_supplier_sku[{$variation->ID}]",
        'label'         => __( 'Supplier SKU (optional)', 'supplier-stock-sync' ),
        'value'         => $supplier_sku,
        'wrapper_class' => 'form-row form-row-first',
    ) );

    // Threshold
    $threshold = get_post_meta( $variation->ID, '_supplier_threshold', true );
    woocommerce_wp_text_input( array(
        'id'            => "_supplier_threshold[{$variation->ID}]",
        'label'         => __( 'Supplier Threshold', 'supplier-stock-sync' ),
        'value'         => $threshold,
        'type'          => 'number',
        'custom_attributes' => array(
            'min'  => '0',
            'step' => '1',
        ),
        'wrapper_class' => 'form-row form-row-last',
    ) );
}

/**
 * Save variation meta
 */
add_action( 'woocommerce_save_product_variation', 'sss_save_supplier_stock_variation_meta', 10, 2 );
function sss_save_supplier_stock_variation_meta( $variation_id, $i ) {
    // Checkbox
    $val = isset( $_POST['_supplier_stocked'][ $variation_id ] ) && $_POST['_supplier_stocked'][ $variation_id ] === 'yes' ? 'yes' : 'no';
    update_post_meta( $variation_id, '_supplier_stocked', $val );

    // Supplier SKU
    if ( isset( $_POST['_supplier_sku'][ $variation_id ] ) ) {
        update_post_meta( $variation_id, '_supplier_sku', sanitize_text_field( $_POST['_supplier_sku'][ $variation_id ]) );
    }

    // Threshold
    if ( isset( $_POST['_supplier_threshold'][ $variation_id ] ) ) {
        update_post_meta( $variation_id, '_supplier_threshold', intval( $_POST['_supplier_threshold'][ $variation_id ]) );
    }
}
