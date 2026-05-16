<?php
// Only run when WordPress is uninstalling the plugin
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop the requests table
$table = $wpdb->prefix . 'quotepress_requests';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Remove plugin options
delete_option( 'quotepress_settings' );
delete_option( 'quotepress_db_version' );

// Flush rewrite rules
flush_rewrite_rules();
