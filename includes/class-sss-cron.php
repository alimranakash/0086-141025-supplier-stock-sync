<?php
/**
 * Cron / Action Scheduler integration
 *
 * Schedule a recurring action via Action Scheduler:
 * - Runs every 30 minutes
 * - Fetches list of supplier-stocked items and updates them
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSS_Cron {

    const ACTION_HOOK = 'sss_supplier_stock_update_action';
    const INTERVAL_SECONDS = 30 * MINUTE_IN_SECONDS; // every 30 minutes

    /**
     * Schedule the recurring action via Action Scheduler.
     * We check for Action Scheduler functions before using them.
     */
    public static function schedule() {
        if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        // Clear any old scheduled actions (e.g., hourly)
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::ACTION_HOOK );
        }

        // Schedule a new one if not already scheduled
        if ( ! as_next_scheduled_action( self::ACTION_HOOK ) ) {
            as_schedule_recurring_action( time(), self::INTERVAL_SECONDS, self::ACTION_HOOK );
        }
    }

    /**
     * Unschedule all actions for our hook
     */
    public static function unschedule() {
        if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
            return;
        }

        as_unschedule_all_actions( self::ACTION_HOOK );
    }

    /**
     * Hook callback: run bulk update for all products/variations with _supplier_stocked = yes
     *
     * This function fetches the feed once (transient cached) and updates items.
     */
    public static function run() {
        // Suppress any output during sync
        ob_start();

        // Query product & variation IDs with meta _supplier_stocked = yes
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

        $items = get_posts( $args );
        if ( empty( $items ) ) {
            ob_end_clean();
            return;
        }

        // Pre-warm feed transient
        SSS_Handler::get_supplier_feed_data(true);

        // Process in chunks to avoid timeouts on very large catalogs
        $chunks = array_chunk( $items, 100 );
        foreach ( $chunks as $chunk ) {
            SSS_Handler::bulk_update_products( $chunk );
        }

        // Clean any output that might have been generated
        ob_end_clean();

        // ✅ Fire action hook after sync is fully completed
        do_action( 'sss_after_sync_completed' );
    }
}

/**
 * Hook the Action Scheduler action to our run method
 */
add_action( SSS_Cron::ACTION_HOOK, array( 'SSS_Cron', 'run' ), 10, 0 );
