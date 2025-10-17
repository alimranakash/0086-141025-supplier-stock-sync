<?php
/**
 * Test file for backorder availability text replacement
 * 
 * This file can be used to test the new automatic backorder message replacement functionality
 * Place this in your WordPress root directory and access via browser to test
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    // Load WordPress if not already loaded
    require_once( dirname( __FILE__ ) . '/wp-config.php' );
}

// Check if WooCommerce and our plugin are active
if ( ! class_exists( 'WooCommerce' ) ) {
    die( 'WooCommerce is not active!' );
}

if ( ! class_exists( 'Supplier_Stock_Sync' ) ) {
    die( 'Supplier Stock Sync plugin is not active!' );
}

echo '<h1>Supplier Stock Sync - Backorder Text Replacement Test</h1>';

// Get plugin instance
$plugin = Supplier_Stock_Sync::get_instance();

// Test the replacement function directly
echo '<h2>Testing replace_backorder_availability_text() method:</h2>';

// Create a mock product for testing (you can replace this with a real product ID)
$test_product_id = 123; // Replace with actual product ID
$product = wc_get_product( $test_product_id );

if ( $product ) {
    echo '<h3>Testing with Product ID: ' . $test_product_id . '</h3>';
    
    // Test different availability texts
    $test_cases = array(
        'Available on backorder',
        'available on backorder',
        'Available on backorder (can be backordered)',
        'In stock',
        'Out of stock',
        'Custom availability text'
    );
    
    foreach ( $test_cases as $test_text ) {
        $result = $plugin->replace_backorder_availability_text( $test_text, $product );
        echo '<p><strong>Input:</strong> "' . esc_html( $test_text ) . '"<br>';
        echo '<strong>Output:</strong> "' . esc_html( $result ) . '"</p>';
    }
} else {
    echo '<p style="color: red;">Product with ID ' . $test_product_id . ' not found. Please update the $test_product_id variable with a valid product ID.</p>';
}

// Test current plugin settings
echo '<h2>Current Plugin Settings:</h2>';
$messages = get_option( 'sss_backorder_messages', array() );
$defaults = array(
    'product' => 'Order today for dispatch in 4 days',
    'checkout' => 'Your order will be dispatched in 4 days',
    'email' => 'Your order will be dispatched in 4 days'
);

echo '<ul>';
foreach ( array( 'product', 'checkout', 'email' ) as $type ) {
    $message = isset( $messages[$type] ) && !empty( $messages[$type] ) ? $messages[$type] : $defaults[$type];
    echo '<li><strong>' . ucfirst( $type ) . ' Message:</strong> ' . esc_html( $message ) . '</li>';
}
echo '</ul>';

// Test filter hook registration
echo '<h2>Filter Hook Registration Test:</h2>';
if ( has_filter( 'woocommerce_get_availability_text', array( $plugin, 'replace_backorder_availability_text' ) ) ) {
    echo '<p style="color: green;">✅ Filter hook is properly registered!</p>';
} else {
    echo '<p style="color: red;">❌ Filter hook is NOT registered!</p>';
}

echo '<h2>Instructions:</h2>';
echo '<ol>';
echo '<li>Visit a product page with a backordered item</li>';
echo '<li>Check if "Available on backorder" is replaced with your custom message</li>';
echo '<li>If not working, check the product\'s stock status and ensure it\'s set to "onbackorder"</li>';
echo '<li>You can customize the message in: WP Admin → Supplier Stock Sync → Messages</li>';
echo '</ol>';

echo '<p><a href="' . admin_url( 'admin.php?page=supplier-messages' ) . '">→ Go to Messages Settings</a></p>';
echo '<p><a href="' . admin_url( 'admin.php?page=supplier-stock-sync' ) . '">→ Go to Plugin Dashboard</a></p>';
?>
