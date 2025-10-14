<?php
/**
 * Debug helper for Supplier Stock Sync feed parsing
 * 
 * Place this file in your WordPress root directory and access it via:
 * yoursite.com/debug-feed-parsing.php
 * 
 * This will help you identify why rows are being lost during CSV parsing.
 */

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

// Check if user is admin
if (!current_user_can('manage_options')) {
    wp_die('Access denied. Admin privileges required.');
}

// Include the plugin files
if (!class_exists('SSS_Handler')) {
    require_once(WP_PLUGIN_DIR . '/supplier-stock-sync/includes/class-sss-handler.php');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>SSS Feed Parsing Debug</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .stats { background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { color: red; }
        .success { color: green; }
        .warning { color: orange; }
        pre { background: #fff; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow: auto; }
        .button { background: #0073aa; color: white; padding: 8px 15px; text-decoration: none; border-radius: 3px; margin: 5px; display: inline-block; }
        .button:hover { background: #005a87; }
    </style>
</head>
<body>
    <h1>Supplier Stock Sync - Feed Parsing Debug</h1>
    
    <?php
    // Handle actions
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'clear_cache':
                SSS_Handler::clear_feed_cache();
                echo '<div class="success">✓ Feed cache cleared successfully!</div>';
                break;
            case 'test_feed':
                echo '<div class="success">✓ Testing feed parsing...</div>';
                break;
        }
    }
    ?>
    
    <div style="margin: 20px 0;">
        <a href="?action=clear_cache" class="button">Clear Feed Cache</a>
        <a href="?action=test_feed" class="button">Test Feed Parsing</a>
        <a href="?" class="button">Refresh</a>
    </div>

    <?php
    // Test the feed parsing
    echo '<h2>Feed Parsing Test</h2>';
    
    $start_time = microtime(true);
    $feed_data = SSS_Handler::get_supplier_feed_data();
    $end_time = microtime(true);
    $parsing_time = round(($end_time - $start_time) * 1000, 2);
    
    echo '<div class="stats">';
    echo '<h3>Parsing Results</h3>';
    echo '<strong>Parsing Time:</strong> ' . $parsing_time . ' ms<br>';
    echo '<strong>Total Entries in Feed Array:</strong> ' . count($feed_data) . '<br>';
    
    if (empty($feed_data)) {
        echo '<div class="error">❌ No data retrieved! Check your feed URL and network connectivity.</div>';
    } else {
        echo '<div class="success">✓ Feed data retrieved successfully!</div>';
    }
    echo '</div>';
    
    // Show parsing statistics
    $stats = SSS_Handler::get_feed_parsing_stats();
    if ($stats) {
        echo '<div class="stats">';
        echo '<h3>Detailed Parsing Statistics</h3>';
        echo '<strong>Total Lines in CSV:</strong> ' . $stats['total_lines'] . '<br>';
        echo '<strong>Processed Data Lines:</strong> ' . $stats['processed_lines'] . '<br>';
        echo '<strong>Skipped Empty Lines:</strong> ' . $stats['skipped_empty'] . '<br>';
        echo '<strong>Skipped (No SKU):</strong> ' . $stats['skipped_no_sku'] . '<br>';
        echo '<strong>Duplicate SKUs Found:</strong> ' . $stats['duplicate_skus'] . '<br>';
        echo '<strong>Valid Entries Added:</strong> ' . $stats['valid_entries'] . '<br>';
        
        // Calculate data loss
        $expected_data_rows = $stats['total_lines'] - 1; // minus header
        $actual_entries = count($feed_data);
        $data_loss = $expected_data_rows - $actual_entries;
        $data_loss_percent = $expected_data_rows > 0 ? round(($data_loss / $expected_data_rows) * 100, 2) : 0;
        
        echo '<hr>';
        echo '<strong>Expected Data Rows:</strong> ' . $expected_data_rows . ' (total lines minus header)<br>';
        echo '<strong>Actual Entries in Array:</strong> ' . $actual_entries . '<br>';
        echo '<strong>Data Loss:</strong> ' . $data_loss . ' rows (' . $data_loss_percent . '%)<br>';
        
        if ($data_loss > 0) {
            echo '<div class="warning">⚠️ Data loss detected! Possible causes:</div>';
            echo '<ul>';
            if ($stats['skipped_no_sku'] > 0) {
                echo '<li>' . $stats['skipped_no_sku'] . ' rows skipped due to missing/empty SKU</li>';
            }
            if ($stats['duplicate_skus'] > 0) {
                echo '<li>' . $stats['duplicate_skus'] . ' duplicate SKUs (later entries overwrite earlier ones)</li>';
            }
            if ($stats['skipped_empty'] > 0) {
                echo '<li>' . $stats['skipped_empty'] . ' completely empty rows skipped</li>';
            }
            echo '</ul>';
        } else {
            echo '<div class="success">✓ No significant data loss detected!</div>';
        }
        echo '</div>';
    }
    
    // Show sample data
    if (!empty($feed_data)) {
        echo '<h3>Sample Feed Data (First 10 entries)</h3>';
        echo '<pre>';
        $sample = array_slice($feed_data, 0, 10, true);
        foreach ($sample as $sku => $qty) {
            echo htmlspecialchars($sku) . ' => ' . $qty . "\n";
        }
        echo '</pre>';
        
        // Show some statistics about the data
        echo '<div class="stats">';
        echo '<h3>Data Analysis</h3>';
        $quantities = array_values($feed_data);
        echo '<strong>Min Quantity:</strong> ' . min($quantities) . '<br>';
        echo '<strong>Max Quantity:</strong> ' . max($quantities) . '<br>';
        echo '<strong>Average Quantity:</strong> ' . round(array_sum($quantities) / count($quantities), 2) . '<br>';
        echo '<strong>Zero Stock Items:</strong> ' . count(array_filter($quantities, function($q) { return $q == 0; })) . '<br>';
        echo '<strong>In Stock Items:</strong> ' . count(array_filter($quantities, function($q) { return $q > 0; })) . '<br>';
        echo '</div>';
    }
    
    // Show feed URL and basic info
    echo '<h3>Configuration</h3>';
    echo '<div class="stats">';
    echo '<strong>Feed URL:</strong> ' . (defined('SSS_FEED_URL') ? SSS_FEED_URL : 'Not defined') . '<br>';
    echo '<strong>Cache TTL:</strong> ' . (defined('SSS_FEED_TRANSIENT_TTL') ? SSS_FEED_TRANSIENT_TTL . ' seconds' : 'Not defined') . '<br>';
    echo '<strong>WordPress Debug:</strong> ' . (defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled') . '<br>';
    echo '</div>';
    ?>
    
    <h3>Next Steps</h3>
    <div class="stats">
        <p><strong>If you're still seeing data loss:</strong></p>
        <ol>
            <li>Check the WordPress debug log for detailed error messages</li>
            <li>Verify that your CSV has the expected headers: "Variant SKU" and "Variant Inventory Qty"</li>
            <li>Look for duplicate SKUs in your source data</li>
            <li>Check for rows with empty SKU fields</li>
            <li>Ensure your CSV is properly formatted (no embedded line breaks in fields)</li>
        </ol>
        
        <p><strong>To enable detailed logging:</strong></p>
        <p>Add these lines to your wp-config.php:</p>
        <pre>define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>
        <p>Then check /wp-content/debug.log for detailed parsing information.</p>
    </div>
    
    <p><em>Remember to delete this debug file when you're done testing!</em></p>
</body>
</html>
