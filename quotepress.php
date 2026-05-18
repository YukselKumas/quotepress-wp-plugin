<?php
/**
 * Plugin Name:       QuotePress – Quote Request Form & Manager
 * Plugin URI:        https://wordpress.org/plugins/quotepress
 * Description:       A complete quote request system with a customizable form, admin panel, PDF generation, and email notifications. Supports multiple color themes and is fully translatable.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            QuotePress
 * Author URI:        https://wordpress.org/plugins/quotepress
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       quotepress
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'QP_VERSION',  '1.0.0' );
define( 'QP_PATH',     plugin_dir_path( __FILE__ ) );
define( 'QP_URL',      plugin_dir_url( __FILE__ ) );
define( 'QP_BASENAME', plugin_basename( __FILE__ ) );

/* ── Autoload classes ───────────────────────────────────────── */
foreach ( [
    'class-database',
    'class-catalog',
    'class-settings',
    'class-form',
    'class-panel',
    'class-mailer',
    'class-pdf',
    'class-report',
    'class-projects',
] as $file ) {
    require_once QP_PATH . 'includes/' . $file . '.php';
}

/* ── Bootstrap ──────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'quotepress', false, dirname( QP_BASENAME ) . '/languages' );

    QuotePress_Database::init();
    QuotePress_Catalog::init();
    QuotePress_Settings::init();
    QuotePress_Form::init();
    QuotePress_Panel::init();
    QuotePress_Mailer::init();
    QuotePress_Report::init();
    QuotePress_Projects::init();
} );

/* ── Activation / Deactivation ──────────────────────────────── */
register_activation_hook( __FILE__, [ 'QuotePress_Database', 'install' ] );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* ── Settings link on plugins page ─────────────────────────── */
add_filter( 'plugin_action_links_' . QP_BASENAME, function ( $links ) {
    $url  = admin_url( 'admin.php?page=quotepress-settings' );
    $link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'quotepress' ) . '</a>';
    array_unshift( $links, $link );
    return $links;
} );
