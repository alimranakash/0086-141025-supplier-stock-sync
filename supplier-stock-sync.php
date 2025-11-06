<?php
/**
 * Plugin Name: Supplier Stock Sync
 * Plugin URI:  https://worzen.com/products/
 * Description: Sync product & variation stock with supplier CSV feed and auto-manage backorder / out-of-stock states. Uses Action Scheduler for background processing.
 * Version:     1.4.5
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
        // add_action( 'init', array( $this, 'wc_init' ) );
        // add_action( 'woocommerce_init', array( $this, 'wc_init' ) );
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

    public function wc_init() {

        // $args = array(
        //     'post_type'      => array( 'product', 'product_variation' ),
        //     'posts_per_page' => -1,
        //     'meta_query'     => array(
        //         array(
        //             'key'   => '_supplier_stocked',
        //             'value' => 'yes',
        //         ),
        //     ),
        //     'fields'         => 'ids',
        // );

        // $items = get_posts( $args );
        // if ( ! empty( $items ) ) {
        //     SSS_Handler::update_product_from_feed( $items );
        // }
        
        // $feed_data  = SSS_Handler::get_supplier_feed_data();
        // print_r($feed_data);
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Replace WooCommerce default backorder availability text
        add_filter( 'woocommerce_get_availability_text', array( $this, 'replace_backorder_availability_text' ), 10, 2 );

        // Checkout page backorder notice
        add_action( 'woocommerce_review_order_before_payment', array( $this, 'display_checkout_backorder_notice' ), 20 );

        // Order email backorder notice
        add_action( 'woocommerce_email_order_details', array( $this, 'add_email_backorder_notice' ), 5, 4 );

        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

        // Add CSS for notices
        add_action( 'wp_head', array( $this, 'add_frontend_styles' ) );
        add_action( 'sss_after_sync_completed', array( $this, 'sss_after_sync_completed' ) );
    }
    
    /**
     * Setup admin functionality
     */
    private function setup_admin() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
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
     * Add custom 30-minute cron schedule
     */
    public function add_cron_interval( $schedules ) {
        if ( ! isset( $schedules['every_30_minutes'] ) ) {
            $schedules['every_30_minutes'] = array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 30 Minutes', 'supplier-stock-sync' ),
            );
        }
        return $schedules;
    }

    
    /**
     * Setup cron functionality
     */
    private function setup_cron() {
        // Only schedule if not already scheduled (don't clear existing schedule)
        if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
            wp_schedule_event( time(), 'every_30_minutes', SSS_Cron::ACTION_HOOK );
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Schedule cron if not already scheduled
        if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
            wp_schedule_event( time(), 'every_30_minutes', SSS_Cron::ACTION_HOOK );
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
     * Enqueue admin styles
     */
    public function enqueue_admin_styles( $hook ) {
        // Only load on our plugin pages
        $plugin_pages = array(
            'toplevel_page_supplier-stock-sync',
            'supplier-stock-sync_page_supplier-stock-tester',
            'supplier-stock-sync_page_supplier-messages',
            'supplier-stock-sync_page_supplier-settings'
        );

        if ( in_array( $hook, $plugin_pages ) ) {
            // Enqueue Tailwind CSS Play CDN (for development/prototyping)
            wp_enqueue_script(
                'sss-tailwind-css',
                'https://cdn.tailwindcss.com',
                array(),
                '3.4.1',
                false // Load in header
            );

            // Add Tailwind config and custom CSS
            add_action( 'admin_head', function() {
                ?>
                <script>
                    tailwind.config = {
                        corePlugins: {
                            preflight: false,
                        },
                        theme: {
                            extend: {
                                colors: {
                                    primary: {
                                        50: '#eff6ff',
                                        100: '#dbeafe',
                                        200: '#bfdbfe',
                                        300: '#93c5fd',
                                        400: '#60a5fa',
                                        500: '#3b82f6',
                                        600: '#2563eb',
                                        700: '#1d4ed8',
                                        800: '#1e40af',
                                        900: '#1e3a8a',
                                    },
                                    success: {
                                        50: '#f0fdf4',
                                        100: '#dcfce7',
                                        500: '#22c55e',
                                        600: '#16a34a',
                                        700: '#15803d',
                                    },
                                    warning: {
                                        50: '#fffbeb',
                                        100: '#fef3c7',
                                        500: '#f59e0b',
                                        600: '#d97706',
                                    },
                                    danger: {
                                        50: '#fef2f2',
                                        100: '#fee2e2',
                                        500: '#ef4444',
                                        600: '#dc2626',
                                    }
                                },
                                animation: {
                                    'fade-in': 'fadeIn 0.5s ease-in-out',
                                    'slide-up': 'slideUp 0.4s ease-out',
                                    'slide-down': 'slideDown 0.4s ease-out',
                                    'scale-in': 'scaleIn 0.3s ease-out',
                                    'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                                },
                                keyframes: {
                                    fadeIn: {
                                        '0%': { opacity: '0' },
                                        '100%': { opacity: '1' },
                                    },
                                    slideUp: {
                                        '0%': { transform: 'translateY(20px)', opacity: '0' },
                                        '100%': { transform: 'translateY(0)', opacity: '1' },
                                    },
                                    slideDown: {
                                        '0%': { transform: 'translateY(-20px)', opacity: '0' },
                                        '100%': { transform: 'translateY(0)', opacity: '1' },
                                    },
                                    scaleIn: {
                                        '0%': { transform: 'scale(0.9)', opacity: '0' },
                                        '100%': { transform: 'scale(1)', opacity: '1' },
                                    }
                                },
                                boxShadow: {
                                    'soft': '0 2px 15px 0 rgba(0, 0, 0, 0.05)',
                                    'medium': '0 4px 20px 0 rgba(0, 0, 0, 0.08)',
                                    'strong': '0 10px 40px 0 rgba(0, 0, 0, 0.12)',
                                }
                            }
                        }
                    }
                </script>
                <style type="text/tailwindcss">
                    @layer utilities {
                        .wrap.sss-admin-page {
                            margin: -10px -20px 0 -20px !important;
                            padding: 0 !important;
                            min-height: calc(100vh - 32px);
                        }
                        .glass-effect {
                            background: rgba(255, 255, 255, 0.9);
                            backdrop-filter: blur(10px);
                        }
                        .gradient-border {
                            border-image: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                            border-image-slice: 1;
                        }
                    }
                </style>
                <style>
                    #wpbody-content {
                        padding-bottom: 0 !important;
                    }
                    /* Ensure Tailwind classes work properly */
                    .wrap * {
                        box-sizing: border-box;
                    }
                    /* Smooth transitions for all interactive elements */
                    .sss-admin-page button,
                    .sss-admin-page a,
                    .sss-admin-page input,
                    .sss-admin-page select {
                        transition: all 0.2s ease-in-out;
                    }
                </style>
                <?php
            });
        }
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

        add_submenu_page(
            'supplier-stock-sync',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'supplier-settings',
            array( $this, 'admin_settings_page' )
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
     * Replace WooCommerce default backorder availability text with custom supplier message
     *
     * @param string $availability_text The default availability text
     * @param WC_Product $product The product object
     * @return string Modified availability text
     */
    public function replace_backorder_availability_text( $availability_text, $product ) {

        if ( ! $product ) {
            return $availability_text;
        }

        // ✅ NEW FEATURE #1 — Skip message for excluded categories
        $excluded_cats = get_option( 'sss_excluded_categories', array() );
        if ( ! empty( $excluded_cats ) ) {
            $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
            if ( array_intersect( $excluded_cats, $product_cats ) ) {
                // return $availability_text; // skip replacement if product in excluded cat
                return ''; // skip replacement if product in excluded cat
            }
        }

        // Check if product is on backorder
        if ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) {
            // Only replace if this is the default "Available on backorder" text
            if ( strpos( $availability_text, 'Available on back-order' ) !== false ||
                 strpos( $availability_text, 'Available on back-order' ) !== false ) {
                return $this->get_message( 'product' );
            }
        }

        // if ( $product->is_on_backorder() || $product->get_stock_status() === 'onbackorder' ) {
        //     // Only replace if this is the default "Available on backorder" text
        //     if ( strpos( $availability_text, 'Available on backorder' ) !== false ||
        //          strpos( $availability_text, 'Available on backorder' ) !== false ) {
        //         return $this->get_message( 'product' );
        //     }
        // }

        return $availability_text;
    }

    /**
     * Display checkout backorder notice
     */
    public function display_checkout_backorder_notice() {

        // Check if cart has backorder items
        if ( ! $this->cart_has_backorder_items() ) {
            return;
        }

        // ✅ Check for excluded categories - skip notice if cart contains excluded category products
        $excluded_cats = get_option( 'sss_excluded_categories', array() );
        if ( ! empty( $excluded_cats ) && function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $product = $cart_item['data'];
                if ( $product ) {
                    $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
                    // If product belongs to an excluded category, skip the checkout notice entirely
                    if ( array_intersect( $excluded_cats, $product_cats ) ) {
                        return;
                    }
                }
            }
        }

        // Display the checkout backorder notice
        echo '<div class="supplier-checkout-notice" style="background:#fff3cd;padding:15px;margin-bottom: 10px;border:1px solid #ffeaa7;border-radius:4px;font-weight:bold;">';
        echo '<span style="color:#856404;">' . esc_html( $this->get_message( 'checkout' ) ) . '</span>';
        echo '</div>';

        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const parent = document.querySelector('.e-checkout__order_review-2');
                if (parent && !parent.classList.contains('sct')) {
                    parent.classList.add('sct');
                }
            });
        </script>
        <?php

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
            echo '<p style="margin:0;color:#856404;font-weight:bold;">' . esc_html( $message ) . '</p>';
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
            .e-checkout__order_review-2 .woo-checkout-payment-method-title {
                bottom: -7rem;
            }
            .e-checkout__order_review-2 .elementor-widget-woocommerce-checkout-page .woocommerce .woocommerce-checkout #payment#payment {
                padding-top: 4rem;
                margin-top: 15px;
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
        $feed_data  = SSS_Handler::get_supplier_feed_data();
        $stats      = SSS_Handler::get_feed_parsing_stats();

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
        $supplier_products  = get_posts( $args );
        $next_cron          = wp_next_scheduled( SSS_Cron::ACTION_HOOK );
        $last_sync          = get_option( 'sss_last_sync_time' );

        // Debug: Check if cron is properly scheduled
        // If $next_cron is false or 0, the cron is not scheduled
        // If $next_cron equals current time, there might be an issue
        $current_time = time();
        $time_until_next = $next_cron ? ( $next_cron - $current_time ) : 0;
        ?>
        <div class="wrap sss-admin-page bg-gradient-to-br from-gray-50 via-blue-50 to-purple-50">
            <!-- Top Bar with Gradient -->
            <div class="bg-gradient-to-r from-primary-600 via-purple-600 to-pink-600 px-8 py-6 shadow-strong">
                <div class="max-w-7xl mx-auto">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4 animate-fade-in">
                            <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-3 shadow-lg">
                                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                            </div>
                            <div>
                                <h1 class="text-3xl font-bold text-white mb-1">Supplier Stock Sync</h1>
                                <p class="text-blue-100 text-sm">Real-time inventory synchronization dashboard</p>
                            </div>
                        </div>
                        <?php if ( $last_sync ): ?>
                        <div class="hidden md:flex items-center space-x-3 bg-white/10 backdrop-blur-sm rounded-xl px-5 py-3 border border-white/20 animate-slide-down">
                            <div class="flex items-center space-x-2">
                                <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                                <span class="text-white text-sm font-medium">Last Sync</span>
                            </div>
                            <div class="text-right">
                                <div class="text-white font-semibold text-sm"><?php echo date( 'H:i:s', $last_sync ); ?></div>
                                <div class="text-blue-100 text-xs"><?php echo date( 'M d, Y', $last_sync ); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="max-w-7xl mx-auto px-8 py-8">

                <!-- Stats Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Feed Data Card -->
                    <div class="group relative bg-white rounded-2xl shadow-soft hover:shadow-strong p-6 border border-gray-100 overflow-hidden transition-all duration-300 hover:-translate-y-1 animate-slide-up">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-primary-500/10 to-primary-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-4">
                                <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-3 shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>
                                <span class="text-xs font-semibold text-primary-600 bg-primary-50 px-3 py-1 rounded-full">FEED</span>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider mb-1">Feed Data</p>
                                <p class="text-4xl font-bold text-gray-900 mb-1"><?php echo number_format( count( $feed_data ) ); ?></p>
                                <p class="text-sm text-gray-600">SKUs loaded</p>
                            </div>
                        </div>
                    </div>

                    <!-- Products Card -->
                    <div class="group relative bg-white rounded-2xl shadow-soft hover:shadow-strong p-6 border border-gray-100 overflow-hidden transition-all duration-300 hover:-translate-y-1 animate-slide-up" style="animation-delay: 0.1s;">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-success-500/10 to-success-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-4">
                                <div class="bg-gradient-to-br from-success-500 to-success-600 rounded-xl p-3 shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <span class="text-xs font-semibold text-success-600 bg-success-50 px-3 py-1 rounded-full">SYNCED</span>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider mb-1">Products</p>
                                <p class="text-4xl font-bold text-gray-900 mb-1"><?php echo number_format( count( $supplier_products ) ); ?></p>
                                <p class="text-sm text-gray-600">Active items</p>
                            </div>
                        </div>
                    </div>

                    <!-- Valid Entries Card -->
                    <?php if ( $stats ): ?>
                    <div class="group relative bg-white rounded-2xl shadow-soft hover:shadow-strong p-6 border border-gray-100 overflow-hidden transition-all duration-300 hover:-translate-y-1 animate-slide-up" style="animation-delay: 0.2s;">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-purple-500/10 to-purple-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-4">
                                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-3 shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-3 py-1 rounded-full">VALID</span>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider mb-1">Valid Entries</p>
                                <p class="text-4xl font-bold text-gray-900 mb-1"><?php echo number_format( $stats['valid_entries'] ); ?></p>
                                <p class="text-sm text-gray-600">of <?php echo number_format( $stats['total_lines'] ); ?> lines</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Next Sync Card -->
                    <div class="group relative bg-white rounded-2xl shadow-soft hover:shadow-strong p-6 border border-gray-100 overflow-hidden transition-all duration-300 hover:-translate-y-1 animate-slide-up" style="animation-delay: 0.3s;">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-warning-500/10 to-warning-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>
                        <div class="relative">
                            <div class="flex items-center justify-between mb-4">
                                <div class="bg-gradient-to-br from-warning-500 to-warning-600 rounded-xl p-3 shadow-lg">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <?php if ( $next_cron && $time_until_next > 0 ): ?>
                                    <span class="text-xs font-semibold text-warning-600 bg-warning-50 px-3 py-1 rounded-full">
                                        IN <?php echo ceil( $time_until_next / 60 ); ?> MIN
                                    </span>
                                <?php elseif ( $next_cron && $time_until_next <= 0 ): ?>
                                    <span class="text-xs font-semibold text-success-600 bg-success-50 px-3 py-1 rounded-full">RUNNING</span>
                                <?php else: ?>
                                    <span class="text-xs font-semibold text-gray-600 bg-gray-50 px-3 py-1 rounded-full">NOT SET</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider mb-1">Next Sync</p>
                                <p class="text-2xl font-bold text-gray-900 mb-1">
                                    <?php
                                    if ( $next_cron ) {
                                        echo date( 'H:i:s', $next_cron );
                                    } else {
                                        echo 'Not scheduled';
                                    }
                                    ?>
                                </p>
                                <p class="text-sm text-gray-600">
                                    <?php
                                    if ( $next_cron ) {
                                        echo date( 'M d, Y', $next_cron );
                                        if ( $time_until_next > 0 ) {
                                            $hours = floor( $time_until_next / 3600 );
                                            $minutes = floor( ( $time_until_next % 3600 ) / 60 );
                                            if ( $hours > 0 ) {
                                                echo ' (' . $hours . 'h ' . $minutes . 'm)';
                                            } else {
                                                echo ' (' . $minutes . ' minutes)';
                                            }
                                        }
                                    } else {
                                        echo 'Configure cron';
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Plugin Overview -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Overview Card -->
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-8 animate-fade-in">
                            <div class="flex items-center mb-6">
                                <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-3 shadow-lg mr-4">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900">Plugin Overview</h2>
                                    <p class="text-gray-500 text-sm">Automated inventory synchronization</p>
                                </div>
                            </div>

                            <p class="text-gray-600 mb-6 leading-relaxed">This plugin automatically syncs your WooCommerce product stock status with supplier feed data, ensuring real-time inventory accuracy across your store.</p>

                            <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                                <span class="w-1 h-6 bg-gradient-to-b from-primary-500 to-primary-600 rounded-full mr-3"></span>
                                Key Features
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <div class="group flex items-start p-4 bg-gradient-to-r from-success-50 to-transparent rounded-xl hover:from-success-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-success-500 to-success-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Automatic Synchronization</p>
                                        <p class="text-sm text-gray-600">Hourly stock updates</p>
                                    </div>
                                </div>
                                <div class="group flex items-start p-4 bg-gradient-to-r from-primary-50 to-transparent rounded-xl hover:from-primary-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Smart Text Replacement</p>
                                        <p class="text-sm text-gray-600">Automatic backorder messages</p>
                                    </div>
                                </div>
                                <div class="group flex items-start p-4 bg-gradient-to-r from-purple-50 to-transparent rounded-xl hover:from-purple-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Product Page Messages</p>
                                        <p class="text-sm text-gray-600">Custom backorder notices</p>
                                    </div>
                                </div>
                                <div class="group flex items-start p-4 bg-gradient-to-r from-warning-50 to-transparent rounded-xl hover:from-warning-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-warning-500 to-warning-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Checkout Integration</p>
                                        <p class="text-sm text-gray-600">Cart backorder alerts</p>
                                    </div>
                                </div>
                                <div class="group flex items-start p-4 bg-gradient-to-r from-pink-50 to-transparent rounded-xl hover:from-pink-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-pink-500 to-pink-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Email Notifications</p>
                                        <p class="text-sm text-gray-600">Order backorder alerts</p>
                                    </div>
                                </div>
                                <div class="group flex items-start p-4 bg-gradient-to-r from-indigo-50 to-transparent rounded-xl hover:from-indigo-100 transition-all">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                                        <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <p class="font-semibold text-gray-900">Fully Customizable</p>
                                        <p class="text-sm text-gray-600">Personalized messages</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Alerts -->
                            <?php if ( $stats && $stats['duplicate_skus'] > 0 ): ?>
                            <div class="bg-gradient-to-r from-warning-50 to-warning-100 border-l-4 border-warning-500 p-5 rounded-xl shadow-soft animate-pulse-slow mt-6">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <svg class="w-6 h-6 text-warning-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-bold text-warning-800">Duplicate SKUs Detected</h3>
                                        <p class="mt-1 text-sm text-warning-700">Found <strong><?php echo $stats['duplicate_skus']; ?></strong> duplicate SKUs in feed data</p>
                                        <a href="<?php echo admin_url('admin.php?page=supplier-stock-tester'); ?>" class="mt-2 inline-flex items-center text-xs font-semibold text-warning-800 hover:text-warning-900">
                                            View Details
                                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions Sidebar -->
                    <div class="space-y-6">
                        <!-- Quick Actions Card -->
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-6 animate-fade-in">
                            <div class="flex items-center mb-6">
                                <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-3 shadow-lg mr-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <h2 class="text-xl font-bold text-gray-900">Quick Actions</h2>
                            </div>
                            <div class="space-y-3">
                                <a href="<?php echo admin_url('admin.php?page=supplier-stock-tester'); ?>"
                                   class="group flex items-center justify-between w-full bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-semibold py-3.5 px-5 rounded-xl transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                                    <span>Test Stock Feed</span>
                                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                    </svg>
                                </a>
                                <a href="<?php echo admin_url('admin.php?page=supplier-messages'); ?>"
                                   class="group flex items-center justify-between w-full bg-gradient-to-r from-success-600 to-success-700 hover:from-success-700 hover:to-success-800 text-white font-semibold py-3.5 px-5 rounded-xl transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                                    <span>Configure Messages</span>
                                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                    </svg>
                                </a>
                                <a href="<?php echo admin_url('admin.php?page=supplier-settings'); ?>"
                                   class="group flex items-center justify-between w-full bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-semibold py-3.5 px-5 rounded-xl transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                                    <span>Plugin Settings</span>
                                    <svg class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                    </svg>
                                </a>
                                <button onclick="location.reload();"
                                        class="group flex items-center justify-between w-full bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-700 hover:to-gray-800 text-white font-semibold py-3.5 px-5 rounded-xl transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5">
                                    <span>Refresh Dashboard</span>
                                    <svg class="w-5 h-5 group-hover:rotate-180 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Admin tester page
     */
    public function admin_tester_page() {
        $show_feed = false;
        $feed = array();
        $parsing_time = 0;
        $stats = null;

        // Handle form submissions
        $success_message = '';
        if ( isset($_POST['clear_cache']) ) {
            SSS_Handler::clear_feed_cache();
            $success_message = 'Feed cache cleared successfully!';
        }

        if ( isset($_POST['run_supplier_check']) ) {
            SSS_Cron::run();
            $success_message = 'Feed Sync Completed Successfully!';
        }

        if ( isset($_POST['view_feed']) ) {
            $show_feed = true;
            $start_time = microtime(true);
            $feed = SSS_Handler::get_supplier_feed_data();
            $end_time = microtime(true);
            $parsing_time = round(($end_time - $start_time) * 1000, 2);
            $stats = SSS_Handler::get_feed_parsing_stats();
        }

        ?>
        <div class="wrap sss-admin-page bg-gradient-to-br from-gray-50 via-blue-50 to-indigo-50">
            <!-- Top Bar -->
            <div class="bg-gradient-to-r from-primary-600 via-indigo-600 to-purple-600 px-8 py-6 shadow-strong">
                <div class="max-w-7xl mx-auto">
                    <div class="flex items-center space-x-4 animate-fade-in">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-3 shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-white mb-1">Stock Sync Tester</h1>
                            <p class="text-blue-100 text-sm">Test and validate your supplier feed integration</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="max-w-7xl mx-auto px-8 py-8">

                <!-- Success Message -->
                <?php if ( $success_message ): ?>
                <div class="bg-gradient-to-r from-success-50 to-success-100 border-l-4 border-success-500 p-5 mb-6 rounded-xl shadow-soft animate-slide-down">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-success-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <p class="ml-3 font-semibold text-success-800"><?php echo $success_message; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Test Actions Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <form method="post" class="animate-fade-in" style="animation-delay: 0s;">
                        <div class="group relative bg-white rounded-2xl shadow-soft border-2 border-primary-200 hover:border-primary-400 p-8 h-full transition-all hover:shadow-strong transform hover:-translate-y-2 overflow-hidden">
                            <!-- Decorative Background -->
                            <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-primary-500/10 to-primary-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative">
                                <!-- Icon -->
                                <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-2xl p-5 shadow-lg mb-6 inline-block group-hover:scale-110 transition-transform">
                                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                </div>

                                <!-- Content -->
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Run Stock Sync</h3>
                                <p class="text-gray-600 mb-6 leading-relaxed">Manually trigger the stock synchronization process to update product inventory from supplier feed</p>

                                <!-- Stats -->
                                <div class="bg-primary-50 rounded-xl p-4 mb-6 border border-primary-100">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-primary-700 uppercase tracking-wider">Frequency</span>
                                        <span class="text-sm font-bold text-primary-900">Every 30 min</span>
                                    </div>
                                </div>

                                <!-- Button -->
                                <button type="submit" name="run_supplier_check"
                                        class="w-full bg-gradient-to-r from-primary-600 to-primary-700 hover:from-primary-700 hover:to-primary-800 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-md hover:shadow-lg flex items-center justify-center group">
                                    <svg class="w-5 h-5 mr-2 group-hover:rotate-180 transition-transform duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                    </svg>
                                    Execute Sync
                                </button>
                            </div>
                        </div>
                    </form>

                    <form method="post" class="animate-fade-in" style="animation-delay: 0.1s;">
                        <div class="group relative bg-white rounded-2xl shadow-soft border-2 border-success-200 hover:border-success-400 p-8 h-full transition-all hover:shadow-strong transform hover:-translate-y-2 overflow-hidden">
                            <!-- Decorative Background -->
                            <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-success-500/10 to-success-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative">
                                <!-- Icon -->
                                <div class="bg-gradient-to-br from-success-500 to-success-600 rounded-2xl p-5 shadow-lg mb-6 inline-block group-hover:scale-110 transition-transform">
                                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>

                                <!-- Content -->
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">View Feed Data</h3>
                                <p class="text-gray-600 mb-6 leading-relaxed">Inspect the current supplier feed data with detailed parsing statistics and SKU information</p>

                                <!-- Stats -->
                                <div class="bg-success-50 rounded-xl p-4 mb-6 border border-success-100">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-success-700 uppercase tracking-wider">Data Source</span>
                                        <span class="text-sm font-bold text-success-900">CSV Feed</span>
                                    </div>
                                </div>

                                <!-- Button -->
                                <button type="submit" name="view_feed"
                                        class="w-full bg-gradient-to-r from-success-600 to-success-700 hover:from-success-700 hover:to-success-800 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-md hover:shadow-lg flex items-center justify-center group">
                                    <svg class="w-5 h-5 mr-2 group-hover:scale-125 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                    </svg>
                                    Inspect Feed
                                </button>
                            </div>
                        </div>
                    </form>

                    <form method="post" class="animate-fade-in" style="animation-delay: 0.2s;">
                        <div class="group relative bg-white rounded-2xl shadow-soft border-2 border-danger-200 hover:border-danger-400 p-8 h-full transition-all hover:shadow-strong transform hover:-translate-y-2 overflow-hidden">
                            <!-- Decorative Background -->
                            <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-danger-500/10 to-danger-600/5 rounded-full -mr-16 -mt-16 group-hover:scale-150 transition-transform duration-500"></div>

                            <div class="relative">
                                <!-- Icon -->
                                <div class="bg-gradient-to-br from-danger-500 to-danger-600 rounded-2xl p-5 shadow-lg mb-6 inline-block group-hover:scale-110 transition-transform">
                                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </div>

                                <!-- Content -->
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Clear Feed Cache</h3>
                                <p class="text-gray-600 mb-6 leading-relaxed">Remove cached feed data to force a fresh fetch from the supplier on the next synchronization</p>

                                <!-- Stats -->
                                <div class="bg-danger-50 rounded-xl p-4 mb-6 border border-danger-100">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-danger-700 uppercase tracking-wider">Action Type</span>
                                        <span class="text-sm font-bold text-danger-900">Destructive</span>
                                    </div>
                                </div>

                                <!-- Button -->
                                <button type="submit" name="clear_cache"
                                        class="w-full bg-gradient-to-r from-danger-600 to-danger-700 hover:from-danger-700 hover:to-danger-800 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-md hover:shadow-lg flex items-center justify-center group">
                                    <svg class="w-5 h-5 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                    Clear Cache
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ( $show_feed ): ?>
                <!-- Feed Preview -->
                <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-8 mb-8 animate-slide-up">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <div class="bg-gradient-to-br from-success-500 to-success-600 rounded-xl p-3 shadow-lg mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900">Feed Preview</h2>
                                <p class="text-gray-500 text-sm">Real-time feed data analysis</p>
                            </div>
                        </div>
                        <div class="bg-gradient-to-r from-primary-50 to-primary-100 border border-primary-200 px-4 py-2 rounded-xl">
                            <div class="flex items-center space-x-2">
                                <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                <span class="text-primary-800 text-sm font-semibold">Parsed in <?php echo $parsing_time; ?>ms</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-r from-primary-50 to-purple-50 rounded-xl p-5 mb-6 border border-primary-100">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-primary-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <p class="text-gray-700"><strong class="text-gray-900">Total entries:</strong> <span class="text-primary-600 font-bold text-lg ml-2"><?php echo number_format(count($feed)); ?></span></p>
                        </div>
                    </div>

                    <?php if ($stats): ?>
                    <div class="mb-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center text-lg">
                            <span class="w-1 h-6 bg-gradient-to-b from-primary-500 to-primary-600 rounded-full mr-3"></span>
                            Parsing Statistics
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="group bg-gradient-to-br from-gray-50 to-gray-100 p-5 rounded-xl border border-gray-200 hover:border-gray-300 hover:shadow-md transition-all">
                                <p class="text-gray-500 text-xs font-semibold uppercase tracking-wider mb-2">Total CSV Lines</p>
                                <p class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_lines']); ?></p>
                            </div>
                            <div class="group bg-gradient-to-br from-success-50 to-success-100 p-5 rounded-xl border border-success-200 hover:border-success-300 hover:shadow-md transition-all">
                                <p class="text-success-600 text-xs font-semibold uppercase tracking-wider mb-2">Valid Entries</p>
                                <p class="text-3xl font-bold text-success-700"><?php echo number_format($stats['valid_entries']); ?></p>
                            </div>
                            <div class="group bg-gradient-to-br from-warning-50 to-warning-100 p-5 rounded-xl border border-warning-200 hover:border-warning-300 hover:shadow-md transition-all">
                                <p class="text-warning-600 text-xs font-semibold uppercase tracking-wider mb-2">Skipped (No SKU)</p>
                                <p class="text-3xl font-bold text-warning-700"><?php echo number_format($stats['skipped_no_sku']); ?></p>
                            </div>
                            <div class="group bg-gradient-to-br from-danger-50 to-danger-100 p-5 rounded-xl border border-danger-200 hover:border-danger-300 hover:shadow-md transition-all">
                                <p class="text-danger-600 text-xs font-semibold uppercase tracking-wider mb-2">Duplicate SKUs</p>
                                <p class="text-3xl font-bold text-danger-700"><?php echo number_format($stats['duplicate_skus']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="relative">
                        <div class="absolute top-4 right-4 bg-gray-800 text-gray-300 text-xs px-3 py-1 rounded-lg font-mono">
                            SKU => QTY
                        </div>
                        <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-6 overflow-auto shadow-inner border border-gray-700" style="max-height: 500px;">
                            <pre class="text-green-400 text-sm font-mono leading-relaxed"><?php
                                foreach ($feed as $sku => $qty) {
                                    echo '<span class="text-gray-500">' . htmlspecialchars($sku) . '</span> <span class="text-blue-400">=></span> <span class="text-green-400 font-bold">' . $qty . "</span>\n";
                                }
                            ?></pre>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Information Card -->
                <div class="bg-gradient-to-r from-primary-50 to-indigo-50 border-l-4 border-primary-500 p-6 rounded-xl shadow-soft">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <div class="bg-primary-100 rounded-full p-3">
                                <svg class="w-6 h-6 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-bold text-primary-900 mb-3">Testing Information</h3>
                            <div class="space-y-2">
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-primary-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    <p class="text-primary-800"><strong class="font-semibold">Run Stock Sync Now:</strong> Manually triggers the stock synchronization process</p>
                                </div>
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-primary-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    <p class="text-primary-800"><strong class="font-semibold">View Feed Data:</strong> Displays the current supplier feed data and parsing statistics</p>
                                </div>
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-primary-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    <p class="text-primary-800"><strong class="font-semibold">Clear Feed Cache:</strong> Removes cached feed data to force a fresh fetch on next sync</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Admin messages page
     */
    public function admin_messages_page() {
        // Handle form submission
        if ( isset( $_POST['save_messages'] ) && check_admin_referer( 'sss_save_messages', 'sss_messages_nonce' ) ) {
            // Sanitize and validate input
            $product_msg = isset( $_POST['product_message'] ) ? sanitize_text_field( $_POST['product_message'] ) : '';
            $checkout_msg = isset( $_POST['checkout_message'] ) ? sanitize_text_field( $_POST['checkout_message'] ) : '';
            $email_msg = isset( $_POST['email_message'] ) ? sanitize_text_field( $_POST['email_message'] ) : '';

            $messages = array(
                'product' => $product_msg,
                'checkout' => $checkout_msg,
                'email' => $email_msg
            );

            $result = update_option( 'sss_backorder_messages', $messages );

            // Redirect to prevent form resubmission
            $redirect_url = add_query_arg(
                array(
                    'page' => 'supplier-messages',
                    'settings-updated' => 'true'
                ),
                admin_url( 'admin.php' )
            );
            wp_redirect( $redirect_url );
            exit;
        }

        $current_messages = get_option( 'sss_backorder_messages', array() );
        $defaults = array(
            'product' => 'Order today for dispatch in 4 days',
            'checkout' => 'Your order will be dispatched in 4 days',
            'email' => 'Your order will be dispatched in 4 days'
        );

        ?>
        <div class="wrap sss-admin-page bg-gradient-to-br from-gray-50 via-purple-50 to-pink-50">
            <!-- Top Bar -->
            <div class="bg-gradient-to-r from-purple-600 via-pink-600 to-rose-600 px-8 py-6 shadow-strong">
                <div class="max-w-7xl mx-auto">
                    <div class="flex items-center space-x-4 animate-fade-in">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-3 shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-white mb-1">Backorder Messages</h1>
                            <p class="text-purple-100 text-sm">Customize customer-facing backorder notifications</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="max-w-7xl mx-auto px-8 py-8">

                <!-- Success Message -->
                <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ): ?>
                <div class="bg-gradient-to-r from-success-50 to-success-100 border-l-4 border-success-500 p-5 mb-6 rounded-xl shadow-soft animate-slide-down">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-success-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <p class="ml-3 font-semibold text-success-800">Messages saved successfully!</p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Messages Form -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-8 animate-fade-in">
                            <div class="flex items-center mb-8">
                                <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-3 shadow-lg mr-4">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900">Message Settings</h2>
                                    <p class="text-gray-500 text-sm">Configure messages for different touchpoints</p>
                                </div>
                            </div>

                            <form method="post" action="">
                                <?php wp_nonce_field( 'sss_save_messages', 'sss_messages_nonce' ); ?>

                                <!-- Product Page Message -->
                                <div class="mb-8 group">
                                    <label for="product_message" class="flex items-center text-sm font-bold text-gray-900 mb-3">
                                        <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-lg p-2 mr-3">
                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                            </svg>
                                        </div>
                                        Product Page Message
                                    </label>
                                    <input type="text" id="product_message" name="product_message"
                                           value="<?php echo esc_attr( isset( $current_messages['product'] ) ? $current_messages['product'] : $defaults['product'] ); ?>"
                                           class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-gray-900 font-medium"
                                           placeholder="Enter product page message..." />
                                    <div class="mt-3 bg-primary-50 rounded-lg p-4 border border-primary-100">
                                        <p class="text-sm text-primary-800 mb-2">📍 Message automatically shown on product detail pages when item is on backorder</p>
                                        <div class="flex items-center space-x-2">
                                            <span class="text-xs font-semibold text-primary-700">Shortcode:</span>
                                            <code class="bg-white px-3 py-1 rounded-lg text-xs font-mono text-primary-900 border border-primary-200">[supplier_backorder_note]</code>
                                        </div>
                                    </div>
                                </div>

                                <!-- Checkout Page Message -->
                                <div class="mb-8 group">
                                    <label for="checkout_message" class="flex items-center text-sm font-bold text-gray-900 mb-3">
                                        <div class="bg-gradient-to-br from-success-500 to-success-600 rounded-lg p-2 mr-3">
                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                            </svg>
                                        </div>
                                        Checkout Page Message
                                    </label>
                                    <input type="text" id="checkout_message" name="checkout_message"
                                           value="<?php echo esc_attr( isset( $current_messages['checkout'] ) ? $current_messages['checkout'] : $defaults['checkout'] ); ?>"
                                           class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-success-500 focus:border-success-500 transition-all text-gray-900 font-medium"
                                           placeholder="Enter checkout page message..." />
                                    <div class="mt-3 bg-success-50 rounded-lg p-4 border border-success-100">
                                        <p class="text-sm text-success-800 mb-2">🛒 Message shown on checkout page when cart contains backordered items</p>
                                        <div class="flex items-center space-x-2">
                                            <span class="text-xs font-semibold text-success-700">Shortcode:</span>
                                            <code class="bg-white px-3 py-1 rounded-lg text-xs font-mono text-success-900 border border-success-200">[supplier_checkout_note]</code>
                                        </div>
                                    </div>
                                </div>

                                <!-- Order Email Message -->
                                <div class="mb-8 group">
                                    <label for="email_message" class="flex items-center text-sm font-bold text-gray-900 mb-3">
                                        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg p-2 mr-3">
                                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        Order Email Message
                                    </label>
                                    <input type="text" id="email_message" name="email_message"
                                           value="<?php echo esc_attr( isset( $current_messages['email'] ) ? $current_messages['email'] : $defaults['email'] ); ?>"
                                           class="w-full px-5 py-4 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all text-gray-900 font-medium"
                                           placeholder="Enter email message..." />
                                    <div class="mt-3 bg-purple-50 rounded-lg p-4 border border-purple-100">
                                        <p class="text-sm text-purple-800 mb-2">📧 Message included in order confirmation emails when order contains backordered items</p>
                                        <p class="text-xs text-purple-700">Automatically added to all order emails (customer and admin)</p>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <div class="flex items-center justify-between pt-6 border-t-2 border-gray-100">
                                    <button type="submit" name="save_messages"
                                            class="group bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold py-4 px-10 rounded-xl transition-all shadow-md hover:shadow-strong transform hover:-translate-y-0.5 flex items-center">
                                        <svg class="w-5 h-5 mr-2 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        Save All Messages
                                    </button>
                                    <span class="text-sm text-gray-500">Changes apply immediately</span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Usage Instructions -->
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-6 animate-fade-in">
                            <div class="flex items-center mb-5">
                                <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-2.5 shadow-lg mr-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900">Usage Guide</h3>
                            </div>
                            <div class="space-y-4">
                                <div class="group bg-gradient-to-r from-primary-50 to-transparent p-4 rounded-xl border-l-4 border-primary-500 hover:from-primary-100 transition-all">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <div class="bg-primary-100 rounded-lg p-2">
                                                <svg class="w-4 h-4 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <p class="font-bold text-gray-900 text-sm mb-1">Product Pages</p>
                                            <p class="text-xs text-gray-600 leading-relaxed">Replaces WooCommerce's default "Available on backorder" text</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="group bg-gradient-to-r from-success-50 to-transparent p-4 rounded-xl border-l-4 border-success-500 hover:from-success-100 transition-all">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <div class="bg-success-100 rounded-lg p-2">
                                                <svg class="w-4 h-4 text-success-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <p class="font-bold text-gray-900 text-sm mb-1">Checkout Page</p>
                                            <p class="text-xs text-gray-600 leading-relaxed">Displays automatically when cart has backordered items</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="group bg-gradient-to-r from-purple-50 to-transparent p-4 rounded-xl border-l-4 border-purple-500 hover:from-purple-100 transition-all">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <div class="bg-purple-100 rounded-lg p-2">
                                                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                </svg>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <p class="font-bold text-gray-900 text-sm mb-1">Order Emails</p>
                                            <p class="text-xs text-gray-600 leading-relaxed">Added to all order confirmation emails automatically</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Debug Info -->
                        <?php if ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' ): ?>
                        <div class="bg-gradient-to-br from-warning-50 to-warning-100 border-2 border-warning-300 rounded-2xl shadow-soft p-6 animate-slide-up">
                            <div class="flex items-center mb-5">
                                <div class="bg-warning-500 rounded-xl p-2.5 shadow-lg mr-3">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-warning-900">Debug Info</h3>
                            </div>
                            <div class="mb-4">
                                <p class="font-bold text-warning-900 text-sm mb-2">Database Values:</p>
                                <pre class="bg-white p-4 rounded-xl border-2 border-warning-200 text-xs overflow-auto font-mono text-gray-800 shadow-inner"><?php
                                    $debug_messages = get_option( 'sss_backorder_messages', 'NOT SET' );
                                    print_r( $debug_messages );
                                ?></pre>
                            </div>
                            <div class="mb-4">
                                <p class="font-bold text-warning-900 text-sm mb-3">Active Messages:</p>
                                <div class="space-y-2">
                                    <div class="bg-white rounded-lg p-3 border border-warning-200">
                                        <p class="text-xs font-semibold text-warning-700 mb-1">Product:</p>
                                        <p class="text-sm text-gray-800"><?php echo esc_html( $this->get_message( 'product' ) ); ?></p>
                                    </div>
                                    <div class="bg-white rounded-lg p-3 border border-warning-200">
                                        <p class="text-xs font-semibold text-warning-700 mb-1">Checkout:</p>
                                        <p class="text-sm text-gray-800"><?php echo esc_html( $this->get_message( 'checkout' ) ); ?></p>
                                    </div>
                                    <div class="bg-white rounded-lg p-3 border border-warning-200">
                                        <p class="text-xs font-semibold text-warning-700 mb-1">Email:</p>
                                        <p class="text-sm text-gray-800"><?php echo esc_html( $this->get_message( 'email' ) ); ?></p>
                                    </div>
                                </div>
                            </div>
                            <a href="<?php echo remove_query_arg( 'debug' ); ?>"
                               class="inline-flex items-center text-warning-800 hover:text-warning-900 font-semibold text-sm bg-white px-4 py-2 rounded-lg border border-warning-300 hover:border-warning-400 transition-all">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Hide Debug Info
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="bg-gradient-to-br from-gray-100 to-gray-200 rounded-2xl p-5 text-center border border-gray-300 hover:border-gray-400 transition-all">
                            <a href="<?php echo add_query_arg( 'debug', '1' ); ?>"
                               class="inline-flex items-center text-gray-700 hover:text-gray-900 font-semibold text-sm">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                </svg>
                                Show Debug Information
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Admin Settings Page — add Threshold Limit, Excluded Categories, and Display Last Sync Time
     */
    public function admin_settings_page() {
        $settings_saved = false;

        // ✅ Handle Unified Settings Save
        if ( isset( $_POST['save_settings'] ) && check_admin_referer( 'sss_save_settings', 'sss_settings_nonce' ) ) {
            // Save Threshold
            $threshold = isset( $_POST['sss_threshold_limit'] ) ? absint( $_POST['sss_threshold_limit'] ) : 1;
            update_option( 'sss_threshold_limit', $threshold );

            // Save Excluded Categories
            $excluded = isset( $_POST['sss_excluded_categories'] ) ? array_map( 'absint', $_POST['sss_excluded_categories'] ) : array();
            update_option( 'sss_excluded_categories', $excluded );

            $settings_saved = true;
        }

        // ✅ Get data for display
        $current_threshold = get_option( 'sss_threshold_limit', 1 );
        $excluded_cats = get_option( 'sss_excluded_categories', array() );

        // ✅ Get categories for dropdown
        $categories = get_terms( array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ) );

        // ✅ Get last sync time (stored by cron handler)
        $last_sync = get_option( 'sss_last_sync_time' );
        ?>
        <div class="wrap sss-admin-page bg-gradient-to-br from-gray-50 via-indigo-50 to-blue-50">
            <!-- Top Bar -->
            <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-cyan-600 px-8 py-6 shadow-strong">
                <div class="max-w-7xl mx-auto">
                    <div class="flex items-center space-x-4 animate-fade-in">
                        <div class="bg-white/20 backdrop-blur-sm rounded-2xl p-3 shadow-lg">
                            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-white mb-1">Supplier Stock Settings</h1>
                            <p class="text-blue-100 text-sm">Configure synchronization rules and preferences</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="max-w-7xl mx-auto px-8 py-8">

                <!-- Success Message -->
                <?php if ( $settings_saved ): ?>
                <div class="bg-gradient-to-r from-success-50 to-success-100 border-l-4 border-success-500 p-5 mb-6 rounded-xl shadow-soft animate-slide-down">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-success-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <p class="ml-3 font-semibold text-success-800">Settings saved successfully!</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Unified Settings Form -->
                <form method="post" action="">
                    <?php wp_nonce_field( 'sss_save_settings', 'sss_settings_nonce' ); ?>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Main Settings -->
                        <div class="lg:col-span-2 space-y-8">
                            <!-- Stock Threshold Section -->
                            <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-8 animate-fade-in">
                                <div class="flex items-center mb-6">
                                    <div class="bg-gradient-to-br from-primary-500 to-primary-600 rounded-xl p-3 shadow-lg mr-4">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-900">Stock Threshold</h2>
                                        <p class="text-gray-500 text-sm">Define low stock trigger point</p>
                                    </div>
                                </div>

                                <div class="bg-gradient-to-r from-primary-50 to-blue-50 rounded-xl p-5 mb-6 border border-primary-100">
                                    <p class="text-sm text-primary-800">Set the minimum stock quantity before marking as low or out-of-stock</p>
                                </div>

                                <div class="mb-6">
                                    <label for="sss_threshold_limit" class="block text-sm font-bold text-gray-900 mb-4">
                                        Threshold Limit
                                    </label>
                                    <div class="flex items-center space-x-4">
                                        <div class="relative">
                                            <input type="number" id="sss_threshold_limit" name="sss_threshold_limit"
                                                   value="<?php echo esc_attr( $current_threshold ); ?>"
                                                   min="0"
                                                   class="w-40 px-6 py-5 border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition-all text-3xl font-bold text-gray-900 text-center" />
                                        </div>
                                        <div class="bg-gray-100 px-5 py-5 rounded-xl">
                                            <span class="text-gray-700 font-semibold text-lg">units</span>
                                        </div>
                                    </div>
                                    <div class="mt-4 bg-warning-50 rounded-lg p-4 border border-warning-100">
                                        <div class="flex items-start">
                                            <svg class="w-5 h-5 text-warning-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                            </svg>
                                            <p class="text-sm text-warning-800">Products with stock at or below this value will be marked as low stock</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Excluded Categories Section -->
                            <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-8 animate-fade-in">
                                <div class="flex items-center mb-6">
                                    <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl p-3 shadow-lg mr-4">
                                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-900">Excluded Categories</h2>
                                        <p class="text-gray-500 text-sm">Control message visibility by category</p>
                                    </div>
                                </div>

                                <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-5 mb-6 border border-purple-100">
                                    <p class="text-sm text-purple-800">Select categories where backorder/stock messages should NOT appear</p>
                                </div>

                                <div class="mb-6">
                                    <label class="block text-sm font-bold text-gray-900 mb-4">
                                        Select Categories to Exclude
                                    </label>
                                    <div class="border-2 border-gray-200 rounded-xl p-5 bg-gradient-to-br from-gray-50 to-white max-h-96 overflow-y-auto">
                                        <?php if ( !empty( $categories ) ): ?>
                                            <div class="space-y-3">
                                                <?php foreach ( $categories as $cat ): ?>
                                                    <label class="group flex items-center p-4 bg-white rounded-xl border-2 border-gray-200 hover:border-purple-400 hover:bg-gradient-to-r hover:from-purple-50 hover:to-transparent transition-all cursor-pointer shadow-sm hover:shadow-md">
                                                        <input type="checkbox"
                                                               name="sss_excluded_categories[]"
                                                               value="<?php echo $cat->term_id; ?>"
                                                               <?php checked( in_array( $cat->term_id, $excluded_cats ) ); ?>
                                                               class="w-6 h-6 text-purple-600 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 cursor-pointer transition-all">
                                                        <span class="ml-4 text-gray-900 font-semibold group-hover:text-purple-900 transition-colors"><?php echo esc_html( $cat->name ); ?></span>
                                                        <span class="ml-auto bg-gradient-to-r from-purple-100 to-purple-200 text-purple-800 text-xs font-bold px-3 py-1.5 rounded-lg"><?php echo $cat->count; ?> products</span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center py-12">
                                                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                                </svg>
                                                <p class="text-gray-500 font-medium">No product categories found</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-4 bg-purple-50 rounded-lg p-4 border border-purple-100">
                                        <div class="flex items-start">
                                            <svg class="w-5 h-5 text-purple-600 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                            </svg>
                                            <p class="text-sm text-purple-800">Products in selected categories will not display backorder messages</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Unified Save Button -->
                            <div class="bg-gradient-to-r from-indigo-600 via-blue-600 to-cyan-600 rounded-2xl shadow-strong p-8 animate-fade-in">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="bg-white/20 backdrop-blur-sm rounded-xl p-3 mr-4">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="text-xl font-bold text-white mb-1">Ready to Save?</h3>
                                            <p class="text-blue-100 text-sm">All changes will be applied immediately</p>
                                        </div>
                                    </div>
                                    <button type="submit" name="save_settings"
                                            class="group bg-white hover:bg-gray-50 text-indigo-700 font-bold py-5 px-10 rounded-xl transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1 flex items-center">
                                        <svg class="w-6 h-6 mr-3 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        <span class="text-lg">Save All Settings</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Last Sync Time -->
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-100 p-6 animate-fade-in">
                            <div class="flex items-center mb-5">
                                <div class="bg-gradient-to-br from-success-500 to-success-600 rounded-xl p-2.5 shadow-lg mr-3">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900">Last Sync</h3>
                            </div>
                            <?php if ( $last_sync ): ?>
                                <div class="bg-gradient-to-br from-success-50 to-success-100 border-l-4 border-success-500 p-5 rounded-xl">
                                    <div class="flex items-center mb-3">
                                        <div class="w-2 h-2 bg-success-500 rounded-full animate-pulse mr-2"></div>
                                        <p class="text-xs font-semibold text-success-700 uppercase tracking-wider">Last Successful Sync</p>
                                    </div>
                                    <p class="text-3xl font-bold text-success-800 mb-2"><?php echo esc_html( date( 'H:i:s', $last_sync ) ); ?></p>
                                    <p class="text-sm text-success-700 font-medium mb-3"><?php echo esc_html( date( 'F d, Y', $last_sync ) ); ?></p>
                                    <div class="pt-3 border-t border-success-200">
                                        <p class="text-xs text-success-600 flex items-center">
                                            <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                            Updates automatically
                                        </p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-gradient-to-br from-gray-100 to-gray-200 border-l-4 border-gray-400 p-5 rounded-xl">
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 text-gray-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <p class="text-gray-700 font-medium">No sync completed yet</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Info Card -->
                        <div class="bg-gradient-to-br from-primary-50 to-indigo-50 border-2 border-primary-200 rounded-2xl p-6 shadow-soft">
                            <div class="flex items-center mb-5">
                                <div class="bg-primary-500 rounded-xl p-2.5 shadow-lg mr-3">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-primary-900">Settings Info</h3>
                            </div>
                            <div class="space-y-3">
                                <div class="bg-white rounded-lg p-3 border border-primary-100">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <p class="ml-2 text-sm text-primary-900 font-medium">Threshold applies to all synced products</p>
                                    </div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-primary-100">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <p class="ml-2 text-sm text-primary-900 font-medium">Excluded categories won't show stock messages</p>
                                    </div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-primary-100">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 mt-0.5">
                                            <svg class="w-4 h-4 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                        </div>
                                        <p class="ml-2 text-sm text-primary-900 font-medium">Sync runs automatically every 30 minutes</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </form>
            </div>
        </div>
        <?php
    }

    public function sss_after_sync_completed() {
        update_option( 'sss_last_sync_time', time() );
    }

}

/**
 * Activation: schedule the action (if Action Scheduler available)
 */
function sss_activate_plugin() {
    if ( ! wp_next_scheduled( SSS_Cron::ACTION_HOOK ) ) {
        wp_schedule_event( time(), 'every_30_minutes', SSS_Cron::ACTION_HOOK );
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
