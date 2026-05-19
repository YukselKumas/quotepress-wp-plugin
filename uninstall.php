<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── Auto-backup before wiping data ───────────────────────────────
$tables_to_export = [
    'requests' => $wpdb->prefix . 'quotepress_requests',
    'projects' => $wpdb->prefix . 'quotepress_projects',
    'products' => $wpdb->prefix . 'quotepress_products',
    'variants' => $wpdb->prefix . 'quotepress_product_variations',
    'services' => $wpdb->prefix . 'quotepress_services',
    'offers'   => $wpdb->prefix . 'quotepress_offers',
];

$data = [
    'version'     => get_option( 'quotepress_db_version', '1.0.0' ),
    'exported_at' => gmdate( 'Y-m-d H:i:s' ),
    'uninstalled' => true,
    'site_url'    => get_site_url(),
    'tables'      => [],
    'options'     => [
        'quotepress_settings'           => get_option( 'quotepress_settings' ),
        'quotepress_templates'          => get_option( 'quotepress_templates' ),
        'quotepress_project_categories' => get_option( 'quotepress_project_categories' ),
    ],
];

foreach ( $tables_to_export as $key => $table ) {
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ); // phpcs:ignore
    if ( $exists ) {
        $data['tables'][ $key ] = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: []; // phpcs:ignore
    }
}

$json = function_exists( 'wp_json_encode' )
    ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
    : json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'quotepress-backups';
if ( ! file_exists( $dir ) ) {
    wp_mkdir_p( $dir );
    file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
    file_put_contents( $dir . '/index.php', '<?php // Silence' );
}
file_put_contents( $dir . '/quotepress-uninstall-' . gmdate( 'Y-m-d_H-i-s' ) . '.json', $json );

// ── Drop tables ───────────────────────────────────────────────────
foreach ( array_values( $tables_to_export ) as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
}

// ── Remove options ────────────────────────────────────────────────
delete_option( 'quotepress_settings' );
delete_option( 'quotepress_db_version' );
delete_option( 'quotepress_templates' );
delete_option( 'quotepress_project_categories' );

// ── Clear scheduled cron ──────────────────────────────────────────
wp_clear_scheduled_hook( 'quotepress_daily_backup' );

flush_rewrite_rules();
