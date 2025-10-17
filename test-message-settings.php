<?php
/**
 * Diagnostic test for Supplier Stock Sync message settings
 * 
 * This file helps diagnose issues with saving and retrieving backorder messages
 * Place this in your WordPress root directory and access via browser
 */

// Load WordPress
require_once( dirname( __FILE__ ) . '/wp-config.php' );

// Check if user is admin
if ( ! current_user_can( 'manage_options' ) ) {
    die( 'You must be an administrator to run this test.' );
}

echo '<h1>Supplier Stock Sync - Message Settings Diagnostic</h1>';
echo '<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .test-section { background: #f5f5f5; padding: 15px; margin: 15px 0; border-left: 4px solid #0073aa; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: #0073aa; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    table td, table th { border: 1px solid #ddd; padding: 8px; text-align: left; }
    table th { background-color: #0073aa; color: white; }
    .code { background: #f0f0f0; padding: 2px 6px; font-family: monospace; border: 1px solid #ddd; }
</style>';

// Test 1: Check if option exists in database
echo '<div class="test-section">';
echo '<h2>Test 1: Database Option Check</h2>';
$saved_messages = get_option( 'sss_backorder_messages', false );

if ( $saved_messages === false ) {
    echo '<p class="error">❌ Option "sss_backorder_messages" does NOT exist in database</p>';
    echo '<p class="info">This means no custom messages have been saved yet, or they were deleted.</p>';
} else {
    echo '<p class="success">✅ Option "sss_backorder_messages" exists in database</p>';
    echo '<p><strong>Saved values:</strong></p>';
    echo '<table>';
    echo '<tr><th>Message Type</th><th>Saved Value</th></tr>';
    foreach ( array( 'product', 'checkout', 'email' ) as $type ) {
        $value = isset( $saved_messages[$type] ) ? $saved_messages[$type] : '<em>Not set</em>';
        echo '<tr><td>' . ucfirst( $type ) . '</td><td>' . esc_html( $value ) . '</td></tr>';
    }
    echo '</table>';
}
echo '</div>';

// Test 2: Check plugin instance and get_message() method
echo '<div class="test-section">';
echo '<h2>Test 2: Plugin get_message() Method Test</h2>';

if ( class_exists( 'Supplier_Stock_Sync' ) ) {
    echo '<p class="success">✅ Supplier_Stock_Sync class exists</p>';
    
    $plugin = Supplier_Stock_Sync::get_instance();
    
    // Use reflection to access private method
    $reflection = new ReflectionClass( $plugin );
    $method = $reflection->getMethod( 'get_message' );
    $method->setAccessible( true );
    
    echo '<p><strong>Messages returned by get_message() method:</strong></p>';
    echo '<table>';
    echo '<tr><th>Message Type</th><th>Returned Value</th></tr>';
    foreach ( array( 'product', 'checkout', 'email' ) as $type ) {
        $message = $method->invoke( $plugin, $type );
        echo '<tr><td>' . ucfirst( $type ) . '</td><td>' . esc_html( $message ) . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<p class="error">❌ Supplier_Stock_Sync class NOT found - plugin may not be active</p>';
}
echo '</div>';

// Test 3: Test saving a message
echo '<div class="test-section">';
echo '<h2>Test 3: Save Test Message</h2>';

if ( isset( $_GET['test_save'] ) && $_GET['test_save'] === 'yes' ) {
    $test_messages = array(
        'product' => 'TEST MESSAGE - Product Page - ' . date( 'Y-m-d H:i:s' ),
        'checkout' => 'TEST MESSAGE - Checkout Page - ' . date( 'Y-m-d H:i:s' ),
        'email' => 'TEST MESSAGE - Email - ' . date( 'Y-m-d H:i:s' )
    );
    
    $result = update_option( 'sss_backorder_messages', $test_messages );
    
    if ( $result ) {
        echo '<p class="success">✅ Test messages saved successfully!</p>';
    } else {
        echo '<p class="info">ℹ️ update_option() returned false (this could mean the value was already the same)</p>';
    }
    
    // Verify the save
    $verify = get_option( 'sss_backorder_messages' );
    echo '<p><strong>Verification - Messages now in database:</strong></p>';
    echo '<table>';
    echo '<tr><th>Message Type</th><th>Value</th></tr>';
    foreach ( $verify as $type => $message ) {
        echo '<tr><td>' . ucfirst( $type ) . '</td><td>' . esc_html( $message ) . '</td></tr>';
    }
    echo '</table>';
    
    echo '<p><a href="' . remove_query_arg( 'test_save' ) . '">← Back to diagnostic page</a></p>';
} else {
    echo '<p>Click the button below to save test messages and verify the save/retrieve process works:</p>';
    echo '<p><a href="' . add_query_arg( 'test_save', 'yes' ) . '" class="button button-primary">Run Save Test</a></p>';
}
echo '</div>';

// Test 4: Check for caching issues
echo '<div class="test-section">';
echo '<h2>Test 4: Caching Check</h2>';

if ( function_exists( 'wp_cache_get' ) ) {
    $cached = wp_cache_get( 'sss_backorder_messages', 'options' );
    if ( $cached !== false ) {
        echo '<p class="info">ℹ️ Option is cached in object cache</p>';
        echo '<pre>' . print_r( $cached, true ) . '</pre>';
    } else {
        echo '<p class="success">✅ No object cache detected for this option</p>';
    }
} else {
    echo '<p class="info">ℹ️ Object caching not available</p>';
}

// Check if any caching plugins are active
$active_plugins = get_option( 'active_plugins' );
$caching_plugins = array( 'wp-super-cache', 'w3-total-cache', 'wp-rocket', 'litespeed-cache', 'wp-fastest-cache' );
$found_caching = array();

foreach ( $active_plugins as $plugin ) {
    foreach ( $caching_plugins as $cache_plugin ) {
        if ( strpos( $plugin, $cache_plugin ) !== false ) {
            $found_caching[] = $plugin;
        }
    }
}

if ( ! empty( $found_caching ) ) {
    echo '<p class="info">ℹ️ Caching plugins detected:</p>';
    echo '<ul>';
    foreach ( $found_caching as $plugin ) {
        echo '<li>' . esc_html( $plugin ) . '</li>';
    }
    echo '</ul>';
    echo '<p><strong>Recommendation:</strong> Clear your cache after saving messages.</p>';
} else {
    echo '<p class="success">✅ No common caching plugins detected</p>';
}
echo '</div>';

// Test 5: Direct database query
echo '<div class="test-section">';
echo '<h2>Test 5: Direct Database Query</h2>';

global $wpdb;
$option_name = 'sss_backorder_messages';
$query = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name );
$result = $wpdb->get_var( $query );

if ( $result ) {
    echo '<p class="success">✅ Option found in database via direct query</p>';
    echo '<p><strong>Raw database value:</strong></p>';
    echo '<pre>' . esc_html( $result ) . '</pre>';
    echo '<p><strong>Unserialized value:</strong></p>';
    echo '<pre>' . print_r( maybe_unserialize( $result ), true ) . '</pre>';
} else {
    echo '<p class="error">❌ Option NOT found in database via direct query</p>';
}
echo '</div>';

// Test 6: Form submission test
echo '<div class="test-section">';
echo '<h2>Test 6: Manual Form Submission Test</h2>';

if ( isset( $_POST['test_form_submit'] ) && wp_verify_nonce( $_POST['test_nonce'], 'test_save' ) ) {
    $test_product_msg = sanitize_text_field( $_POST['test_product_message'] );
    $test_checkout_msg = sanitize_text_field( $_POST['test_checkout_message'] );
    $test_email_msg = sanitize_text_field( $_POST['test_email_message'] );
    
    $messages = array(
        'product' => $test_product_msg,
        'checkout' => $test_checkout_msg,
        'email' => $test_email_msg
    );
    
    $save_result = update_option( 'sss_backorder_messages', $messages );
    
    echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0;">';
    echo '<p class="success">✅ Form submitted and processed!</p>';
    echo '<p><strong>Submitted values:</strong></p>';
    echo '<ul>';
    echo '<li>Product: ' . esc_html( $test_product_msg ) . '</li>';
    echo '<li>Checkout: ' . esc_html( $test_checkout_msg ) . '</li>';
    echo '<li>Email: ' . esc_html( $test_email_msg ) . '</li>';
    echo '</ul>';
    echo '<p><strong>update_option() result:</strong> ' . ( $save_result ? 'TRUE (saved)' : 'FALSE (not saved or same value)' ) . '</p>';
    echo '</div>';
    
    // Verify
    $verify = get_option( 'sss_backorder_messages' );
    echo '<p><strong>Verification - Current database values:</strong></p>';
    echo '<pre>' . print_r( $verify, true ) . '</pre>';
}

$current = get_option( 'sss_backorder_messages', array() );
$defaults = array(
    'product' => 'Order today for dispatch in 4 days',
    'checkout' => 'Your order will be dispatched in 4 days',
    'email' => 'Your order will be dispatched in 4 days'
);

echo '<p>Use this form to test saving messages (simulates the plugin\'s Messages page):</p>';
echo '<form method="post" style="background: white; padding: 15px; border: 1px solid #ddd;">';
wp_nonce_field( 'test_save', 'test_nonce' );
echo '<table class="form-table">';
echo '<tr>';
echo '<th><label for="test_product_message">Product Message:</label></th>';
echo '<td><input type="text" id="test_product_message" name="test_product_message" value="' . esc_attr( isset( $current['product'] ) ? $current['product'] : $defaults['product'] ) . '" style="width: 100%; max-width: 500px;" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th><label for="test_checkout_message">Checkout Message:</label></th>';
echo '<td><input type="text" id="test_checkout_message" name="test_checkout_message" value="' . esc_attr( isset( $current['checkout'] ) ? $current['checkout'] : $defaults['checkout'] ) . '" style="width: 100%; max-width: 500px;" /></td>';
echo '</tr>';
echo '<tr>';
echo '<th><label for="test_email_message">Email Message:</label></th>';
echo '<td><input type="text" id="test_email_message" name="test_email_message" value="' . esc_attr( isset( $current['email'] ) ? $current['email'] : $defaults['email'] ) . '" style="width: 100%; max-width: 500px;" /></td>';
echo '</tr>';
echo '</table>';
echo '<p><input type="submit" name="test_form_submit" value="Save Test Messages" class="button button-primary" /></p>';
echo '</form>';
echo '</div>';

// Navigation
echo '<div style="margin-top: 30px; padding: 15px; background: #fff; border: 1px solid #ddd;">';
echo '<h3>Quick Links</h3>';
echo '<ul>';
echo '<li><a href="' . admin_url( 'admin.php?page=supplier-messages' ) . '">→ Go to Plugin Messages Settings</a></li>';
echo '<li><a href="' . admin_url( 'admin.php?page=supplier-stock-sync' ) . '">→ Go to Plugin Dashboard</a></li>';
echo '<li><a href="' . admin_url( 'options-general.php' ) . '">→ WordPress Settings</a></li>';
echo '</ul>';
echo '</div>';
?>
