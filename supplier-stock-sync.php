<?php
/**
 * Plugin Name: Supplier Stock Sync
 * Plugin URI:  https://worzen.com/products/
 * Description: Sync product & variation stock with supplier CSV feed and auto-manage backorder / out-of-stock states. Uses Action Scheduler for background processing.
 * Version:     1.3.0
 * Author:      Al Imran Akash
 * Author URI:  https://profiles.wordpress.org/al-imran-akash/
 * Text Domain: supplier-stock-sync
 * Domain Path: /languages
 *
 * Requires:    WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSS_PLUGIN_FILE', __FILE__ );

/**
 * Feed URL: update if needed (or make it admin-settable later)
 * NOTE: keep exact URL from client:
 */
define( 'SSS_FEED_URL', 'https://app.matrixify.app/files/hx1kg2-jn/a9c39b060fb5c913dcb623116952f087/mtb-product-export.csv' );

/**
 * Transient keys & TTL
 */
define( 'SSS_FEED_TRANSIENT', 'sss_supplier_feed_data' );
define( 'SSS_FEED_TRANSIENT_TTL', 15 * MINUTE_IN_SECONDS ); // cache feed for 15 minutes

/**
 * Include files
 */
require_once SSS_PLUGIN_DIR . 'includes/admin-meta-boxes.php';
require_once SSS_PLUGIN_DIR . 'includes/class-sss-handler.php';
require_once SSS_PLUGIN_DIR . 'includes/class-sss-cron.php';

/**
 * Main Supplier Stock Sync Plugin Class
 */
class Supplier_Stock_Sync {
    
    /**
     * Plugin instance
     *
     * @var Supplier_Stock_Sync
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     *
     * @return Supplier_Stock_Sync
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }
        
        $this->setup_hooks();
        $this->setup_admin();
        $this->setup_shortcodes();
        $this->setup_cron();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Checkout page backorder notice
        add_action( 'woocommerce_review_order_before_payment', array( $this, 'display_checkout_backorder_notice' ), 20 );
        
        // Order email backorder notice
        add_action( 'woocommerce_email_order_details', array( $this, 'add_email_backorder_notice' ), 5, 4 );
        
        // Add CSS for notices
        add_action( 'wp_head', array( $this, 'add_frontend_styles' ) );
    }
    
    /**
     * Setup admin functionality
     */
    private function setup_admin() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        }
    }
    
    /**
     * Setup shortcodes
     */
    private function setup_shortcodes() {
        add_shortcode( 'supplier_backorder_note', array( $this, 'product_backorder_shortcode' ) );
        add_shortcode( 'supplier_checkout_note', array( $this, 'checkout_backorder_shortcode' ) );
    }
    
    /**
     * Setup cron functionality
     */
    private function setup_cron() {
        // Schedule the cron job if not already scheduled
        if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', SSS_Cron::ACTION_HOOK );
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule cron job
        if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', SSS_Cron::ACTION_HOOK );
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron job
        wp_clear_scheduled_hook( SSS_Cron::ACTION_HOOK );
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>Supplier Stock Sync:</strong> WooCommerce is required for this plugin to work.</p></div>';
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Add top-level menu
        add_menu_page(
            'Supplier Stock Sync',
            'Supplier Stock Sync',
            'manage_woocommerce',
            'supplier-stock-sync',
            array( $this, 'admin_dashboard_page' ),
            'dashicons-update',
            56
        );
        
        // Add submenu pages
        add_submenu_page(
            'supplier-stock-sync',
            'Stock Tester',
            'Stock Tester',
            'manage_woocommerce',
            'supplier-stock-tester',
            array( $this, 'admin_tester_page' )
        );
        
        add_submenu_page(
            'supplier-stock-sync',
            'Messages',
            'Messages',
            'manage_woocommerce',
            'supplier-messages',
            array( $this, 'admin_messages_page' )
        );
    }
    
    /**
     * Product backorder shortcode
     */
    public function product_backorder_shortcode( $atts ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return '';
        }

        global $product;

        // try to get the product object if not set
        if ( empty( $product ) ) {
            $product = wc_get_product( get_the_ID() );
        }

        if ( ! $product ) {
            return '';
        }

        $text = isset( $atts['text'] ) ? wp_kses_post( $atts['text'] ) : $this->get_message( 'product' );

        // Use is_on_backorder if available (WC_Product method)
        if ( method_exists( $product, 'is_on_backorder' ) ) {
            if ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) {
                return '<div class="supplier-note">' . esc_html( $text ) . '</div>';
            }
        } else {
            // fallback check
            if ( $product->get_stock_status() === 'onbackorder' ) {
                return '<div class="supplier-note">' . esc_html( $text ) . '</div>';
            }
        }

        return '';
    }
    
    /**
     * Checkout backorder shortcode
     */
    public function checkout_backorder_shortcode( $atts ) {
        if ( ! function_exists( 'WC' ) ) {
            return '';
        }

        $text = isset( $atts['text'] ) ? wp_kses_post( $atts['text'] ) : $this->get_message( 'checkout' );

        if ( $this->cart_has_backorder_items() ) {
            return '<div class="supplier-checkout-note" style="background:#fff3cd;padding:10px;margin:10px 0;border:1px solid #ffeaa7;border-radius:4px;">' . esc_html( $text ) . '</div>';
        }

        return '';
    }
    
    /**
     * Display checkout backorder notice
     */
    public function display_checkout_backorder_notice() {
        if ( $this->cart_has_backorder_items() ) {
            echo '<div class="supplier-checkout-notice" style="background:#fff3cd;padding:15px;margin:15px 0;border:1px solid #ffeaa7;border-radius:4px;font-weight:bold;">';
            echo '<span style="color:#856404;">ℹ️ ' . esc_html( $this->get_message( 'checkout' ) ) . '</span>';
            echo '</div>';
        }
    }
    
    /**
     * Add backorder notice to order emails
     */
    public function add_email_backorder_notice( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $this->order_has_backorder_items( $order ) ) {
            return;
        }

        $message = $this->get_message( 'email' );
        
        if ( $plain_text ) {
            echo "\n" . $message . "\n\n";
        } else {
            echo '<div style="background:#fff3cd;padding:15px;margin:15px 0;border:1px solid #ffeaa7;border-radius:4px;">';
            echo '<p style="margin:0;color:#856404;font-weight:bold;">ℹ️ ' . esc_html( $message ) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Add frontend styles
     */
    public function add_frontend_styles() {
        if ( is_product() || is_checkout() ) {
            echo '<style>
            .supplier-note, .supplier-checkout-note, .supplier-checkout-notice {
                font-size: 14px;
                line-height: 1.4;
            }
            .supplier-note {
                background: #e7f3ff;
                border: 1px solid #b3d9ff;
                color: #0066cc;
                padding: 10px;
                margin: 10px 0;
                border-radius: 4px;
            }
            .supplier-checkout-notice {
                animation: fadeIn 0.3s ease-in;
            }
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            </style>';
        }
    }

    /**
     * Helper function to check if cart contains any backordered products
     *
     * @return bool
     */
    private function cart_has_backorder_items() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( $product && ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Helper function to check if order contains any backordered products
     *
     * @param WC_Order $order
     * @return bool
     */
    private function order_has_backorder_items( $order ) {
        if ( ! $order ) {
            return false;
        }

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get customizable message text with fallback
     *
     * @param string $type Message type (product, checkout, email)
     * @return string
     */
    private function get_message( $type ) {
        $messages = get_option( 'sss_backorder_messages', array() );

        $defaults = array(
            'product' => 'Order today for dispatch in 4 days',
            'checkout' => 'Your order will be dispatched in 4 days',
            'email' => 'Your order will be dispatched in 4 days'
        );

        return isset( $messages[$type] ) && !empty( $messages[$type] ) ? $messages[$type] : $defaults[$type];
    }

    /**
     * Admin dashboard page
     */
    public function admin_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>Supplier Stock Sync</h1>
            <p>Welcome to the Supplier Stock Sync plugin dashboard.</p>

            <div class="card" style="max-width: 600px;">
                <h2>Plugin Overview</h2>
                <p>This plugin automatically syncs your WooCommerce product stock status with supplier feed data.</p>

                <h3>Features:</h3>
                <ul>
                    <li>✅ Automatic hourly stock synchronization</li>
                    <li>✅ Product page backorder messages</li>
                    <li>✅ Checkout page backorder notices</li>
                    <li>✅ Order email backorder notifications</li>
                    <li>✅ Customizable messages</li>
                </ul>

                <h3>Quick Actions:</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=supplier-stock-tester'); ?>" class="button button-primary">Test Stock Sync</a>
                    <a href="<?php echo admin_url('admin.php?page=supplier-messages'); ?>" class="button button-secondary">Customize Messages</a>
                </p>

                <h3>System Status:</h3>
                <?php
                $feed_data = SSS_Handler::get_supplier_feed_data();
                $stats = SSS_Handler::get_feed_parsing_stats();

                // Get products with supplier stocking enabled
                $args = array(
                    'post_type'      => array( 'product', 'product_variation' ),
                    'posts_per_page' => -1,
                    'meta_query'     => array(
                        array(
                            'key'   => '_supplier_stocked',
                            'value' => 'yes',
                        ),
                    ),
                    'fields'         => 'ids',
                );
                $supplier_products = get_posts( $args );
                $next_cron = wp_next_scheduled( SSS_Cron::ACTION_HOOK );
                ?>

                <table class="widefat">
                    <tr>
                        <td><strong>Feed Data Entries:</strong></td>
                        <td><?php echo count( $feed_data ); ?> SKUs loaded</td>
                    </tr>
                    <tr>
                        <td><strong>Products with Supplier Stocking:</strong></td>
                        <td><?php echo count( $supplier_products ); ?> products/variations</td>
                    </tr>
                    <tr>
                        <td><strong>Next Scheduled Sync:</strong></td>
                        <td><?php echo $next_cron ? date( 'Y-m-d H:i:s', $next_cron ) : 'Not scheduled'; ?></td>
                    </tr>
                    <?php if ( $stats ): ?>
                    <tr>
                        <td><strong>Last Feed Parse Stats:</strong></td>
                        <td>
                            <?php echo $stats['valid_entries']; ?> valid entries from <?php echo $stats['total_lines']; ?> CSV lines
                            <?php if ( $stats['duplicate_skus'] > 0 ): ?>
                                <br><em><?php echo $stats['duplicate_skus']; ?> duplicate SKUs found</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Admin tester page
     */
    public function admin_tester_page() {
        // Handle form submissions
        if ( isset($_POST['clear_cache']) ) {
            SSS_Handler::clear_feed_cache();
            echo '<div style="background:#d7ffd9;padding:10px;margin-top:15px;border:1px solid #70d47b;">✅ Feed cache cleared successfully!</div>';
        }

        if ( isset($_POST['run_supplier_check']) ) {
            // Run the actual sync
            SSS_Cron::run();
            echo '<div style="background:#d7ffd9;padding:10px;margin-top:15px;border:1px solid #70d47b;">✅ Feed Sync Completed Successfully!</div>';
        }

        if ( isset($_POST['view_feed']) ) {
            $start_time = microtime(true);
            $feed = SSS_Handler::get_supplier_feed_data();
            $end_time = microtime(true);
            $parsing_time = round(($end_time - $start_time) * 1000, 2);

            echo '<h3>Feed Preview (Parsed in ' . $parsing_time . 'ms):</h3>';
            echo '<p><strong>Total entries:</strong> ' . count($feed) . '</p>';

            // Show parsing stats if available
            $stats = SSS_Handler::get_feed_parsing_stats();
            if ($stats) {
                echo '<div style="background:#f0f0f0;padding:10px;margin:10px 0;border-radius:5px;">';
                echo '<strong>Parsing Stats:</strong><br>';
                echo 'Total CSV lines: ' . $stats['total_lines'] . '<br>';
                echo 'Valid entries: ' . $stats['valid_entries'] . '<br>';
                echo 'Skipped (no SKU): ' . $stats['skipped_no_sku'] . '<br>';
                echo 'Duplicate SKUs: ' . $stats['duplicate_skus'] . '<br>';
                echo '</div>';
            }

            echo '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:500px;overflow:auto;">';
            foreach ($feed as $sku => $qty) {
                echo htmlspecialchars($sku) . ' => ' . $qty . "\n";
            }
            echo '</pre>';
        }

        echo '
        <div class="wrap">
            <h1>Stock Sync Tester</h1>
            <p>Use this page to manually test the supplier stock sync functionality.</p>

        <form method="post" style="margin-top:20px;">
            <input type="submit" name="run_supplier_check" class="button button-primary" value="🔄 Run Stock Sync Now" />
            <input type="submit" name="view_feed" class="button button-secondary" value="👁️ View Feed Data" />
            <input type="submit" name="clear_cache" class="button button-secondary" value="🗑️ Clear Feed Cache" />
        </form>
        </div>';
    }

    /**
     * Admin messages page
     */
    public function admin_messages_page() {
        // Handle form submission
        if ( isset( $_POST['save_messages'] ) && wp_verify_nonce( $_POST['sss_messages_nonce'], 'sss_save_messages' ) ) {
            $messages = array(
                'product' => sanitize_text_field( $_POST['product_message'] ),
                'checkout' => sanitize_text_field( $_POST['checkout_message'] ),
                'email' => sanitize_text_field( $_POST['email_message'] )
            );

            update_option( 'sss_backorder_messages', $messages );
            echo '<div class="notice notice-success"><p>Messages saved successfully!</p></div>';
        }

        $current_messages = get_option( 'sss_backorder_messages', array() );
        $defaults = array(
            'product' => 'Order today for dispatch in 4 days',
            'checkout' => 'Your order will be dispatched in 4 days',
            'email' => 'Your order will be dispatched in 4 days'
        );

        ?>
        <div class="wrap">
            <h1>Backorder Messages</h1>
            <p>Customize the backorder messages displayed in different locations.</p>

            <form method="post" action="">
                <?php wp_nonce_field( 'sss_save_messages', 'sss_messages_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="product_message">Product Page Message</label>
                        </th>
                        <td>
                            <input type="text" id="product_message" name="product_message"
                                   value="<?php echo esc_attr( isset( $current_messages['product'] ) ? $current_messages['product'] : $defaults['product'] ); ?>"
                                   class="regular-text" />
                            <p class="description">Message shown on product detail pages when item is on backorder.</p>
                            <p class="description"><strong>Shortcode:</strong> <code>[supplier_backorder_note]</code></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="checkout_message">Checkout Page Message</label>
                        </th>
                        <td>
                            <input type="text" id="checkout_message" name="checkout_message"
                                   value="<?php echo esc_attr( isset( $current_messages['checkout'] ) ? $current_messages['checkout'] : $defaults['checkout'] ); ?>"
                                   class="regular-text" />
                            <p class="description">Message shown on checkout page when cart contains backordered items.</p>
                            <p class="description"><strong>Shortcode:</strong> <code>[supplier_checkout_note]</code> (also displays automatically)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="email_message">Order Email Message</label>
                        </th>
                        <td>
                            <input type="text" id="email_message" name="email_message"
                                   value="<?php echo esc_attr( isset( $current_messages['email'] ) ? $current_messages['email'] : $defaults['email'] ); ?>"
                                   class="regular-text" />
                            <p class="description">Message included in order confirmation emails when order contains backordered items.</p>
                            <p class="description">Automatically added to all order emails (customer and admin).</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="save_messages" class="button-primary" value="Save Messages" />
                </p>
            </form>

            <div style="background:#f0f0f0;padding:15px;margin-top:30px;border-radius:5px;">
                <h3>Usage Instructions</h3>
                <ul>
                    <li><strong>Product Pages:</strong> Use shortcode <code>[supplier_backorder_note]</code> in product descriptions or use <code>[supplier_backorder_note text="Custom message"]</code> to override.</li>
                    <li><strong>Checkout Page:</strong> Message displays automatically when cart has backordered items. You can also use <code>[supplier_checkout_note]</code> shortcode.</li>
                    <li><strong>Order Emails:</strong> Message is automatically added to all order confirmation emails when the order contains backordered products.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

/**
 * Activation: schedule the action (if Action Scheduler available)
 */
function sss_activate_plugin() {
    if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
        wp_schedule_event( time(), 'hourly', SSS_Cron::ACTION_HOOK );
    }
}

/**
 * Deactivation: clear scheduled events
 */
function sss_deactivate_plugin() {
    wp_clear_scheduled_hook( SSS_Cron::ACTION_HOOK );
}

// Initialize the plugin
Supplier_Stock_Sync::get_instance();
