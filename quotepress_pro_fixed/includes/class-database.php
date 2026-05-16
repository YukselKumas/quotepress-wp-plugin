<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Database {

    const TABLE = 'quotepress_requests';

    /* ── Install (activation hook) ─────────────────────────── */
    public static function install() {
        global $wpdb;
        $table          = $wpdb->prefix . self::TABLE;
        $services_table = $wpdb->prefix . 'quotepress_services';
        $offers_table   = $wpdb->prefix . 'quotepress_offers';
        $charset        = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_name   VARCHAR(255)    NOT NULL DEFAULT '',
            project_date   VARCHAR(20)     NOT NULL DEFAULT '',
            project_desc   TEXT            NOT NULL DEFAULT '',
            company_name   VARCHAR(255)    NOT NULL DEFAULT '',
            tax_number     VARCHAR(50)     NOT NULL DEFAULT '',
            contact_name   VARCHAR(255)    NOT NULL DEFAULT '',
            contact_title  VARCHAR(100)    NOT NULL DEFAULT '',
            contact_salutation VARCHAR(10) NOT NULL DEFAULT 'neutral',
            email          VARCHAR(255)    NOT NULL DEFAULT '',
            phone          VARCHAR(50)     NOT NULL DEFAULT '',
            city           VARCHAR(100)    NOT NULL DEFAULT '',
            country        VARCHAR(100)    NOT NULL DEFAULT '',
            extra_option   VARCHAR(100)    NOT NULL DEFAULT '',
            items          LONGTEXT        NOT NULL DEFAULT '',
            extra_note     TEXT            NOT NULL DEFAULT '',
            status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
            quote_note     TEXT            NOT NULL DEFAULT '',
            quote_data     LONGTEXT        NOT NULL DEFAULT '',
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address     VARCHAR(45)     NOT NULL DEFAULT '',
            PRIMARY KEY (id)
        ) {$charset};";

        $services_sql = "CREATE TABLE IF NOT EXISTS {$services_table} (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title        VARCHAR(255)    NOT NULL,
            template_key VARCHAR(100)    NOT NULL DEFAULT 'default',
            unit_label   VARCHAR(100)    NOT NULL DEFAULT '',
            description  TEXT            NOT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        $offers_sql = "CREATE TABLE IF NOT EXISTS {$offers_table} (
            id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            service_id    BIGINT UNSIGNED  NOT NULL,
            customer_name VARCHAR(255)     NOT NULL,
            contact_name  VARCHAR(255)     NOT NULL,
            quantity      INT              NOT NULL DEFAULT 1,
            unit_price    DECIMAL(10,2)    NOT NULL DEFAULT 0,
            total_price   DECIMAL(10,2)    NOT NULL DEFAULT 0,
            offer_html    LONGTEXT         NOT NULL,
            created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $services_sql );
        dbDelta( $offers_sql );

        update_option( 'quotepress_db_version', QP_VERSION );

        if ( ! get_option( 'quotepress_settings' ) ) {
            update_option( 'quotepress_settings', self::default_settings() );
        }

        QuotePress_Panel::register_rewrite();
        flush_rewrite_rules();
    }

    /* ── Default settings ───────────────────────────────────── */
    public static function default_settings() {
        return [
            'company_name'        => get_bloginfo( 'name' ),
            'company_address'     => '',
            'company_email'       => get_option( 'admin_email' ),
            'company_phone1'      => '',
            'company_phone2'      => '',
            'company_whatsapp'    => '',
            'company_website'     => get_site_url(),
            'recipient_email'     => get_option( 'admin_email' ),
            'default_currency'    => 'USD',
            'currencies'          => "USD\nEUR\nGBP\nTRY",
            'default_vat'         => '0',
            'validity_days'       => '30',
            'mail_footer'         => __( 'This email was sent automatically. Please do not reply.', 'quotepress' ),
            'color_theme'         => 'green',
            'custom_color'        => '#2e8b2e',
            'product_categories'  => "Product A\nProduct B\nProduct C\nOther",
            'extra_option_label'  => '',
            'extra_option_items'  => '',
            'extra_option_trigger'=> '',
            'panel_slug'          => 'quote-panel',
        ];
    }

    /* ── init (nothing needed at runtime) ──────────────────── */
    public static function init() {}

    /* ── Helpers ────────────────────────────────────────────── */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function get_all( $status = '' ) {
        global $wpdb;
        $t = self::table();
        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status=%s ORDER BY created_at DESC", $status ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY created_at DESC" );
    }

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d", $id ) );
    }

    public static function insert( $data ) {
        global $wpdb;
        $wpdb->insert( self::table(), $data );
        return $wpdb->insert_id;
    }

    public static function update( $id, $data ) {
        global $wpdb;
        return $wpdb->update( self::table(), $data, [ 'id' => $id ] );
    }

    public static function count( $status = '' ) {
        global $wpdb;
        $t = self::table();
        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status=%s", $status ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
    }
}
