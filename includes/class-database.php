<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Database {

    const TABLE          = 'quotepress_requests';
    const PROJECTS_TABLE = 'quotepress_projects';

    /* ── Install (activation hook) ─────────────────────────── */
    public static function install() {
        global $wpdb;
        $table          = $wpdb->prefix . self::TABLE;
        $projects_table = $wpdb->prefix . self::PROJECTS_TABLE;
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
            project_id     BIGINT UNSIGNED     NULL DEFAULT NULL,
            PRIMARY KEY (id)
        ) {$charset};";

        $projects_sql = "CREATE TABLE IF NOT EXISTS {$projects_table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            description TEXT            NOT NULL DEFAULT '',
            client_name VARCHAR(255)    NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'active',
            deadline    DATE                     DEFAULT NULL,
            budget      DECIMAL(15,2)            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
        dbDelta( $projects_sql );
        dbDelta( $services_sql );
        dbDelta( $offers_sql );

        // Add project_id column to existing installations
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'project_id'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER ip_address" );
        }

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
            'mail_footer'         => __( 'Bu e-posta otomatik olarak gönderilmiştir. Lütfen yanıtlamayınız.', 'quotepress' ),
            'color_theme'         => 'green',
            'custom_color'        => '#2e8b2e',
            'product_categories'  => "Product A\nProduct B\nProduct C\nOther",
            'extra_option_label'  => '',
            'extra_option_items'  => '',
            'extra_option_trigger'=> '',
            'panel_slug'          => 'quote-panel',
        ];
    }

    /* ── init ───────────────────────────────────────────────── */
    public static function init() {
        // Run upgrade on every load to handle manual plugin updates (not just activation)
        static $done = false;
        if ( $done ) return;
        $done = true;
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'project_id'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_id BIGINT UNSIGNED NULL DEFAULT NULL" );
        }
        $pt = $wpdb->prefix . self::PROJECTS_TABLE;
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$pt} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            description TEXT            NOT NULL DEFAULT '',
            client_name VARCHAR(255)    NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'active',
            deadline    DATE                     DEFAULT NULL,
            budget      DECIMAL(15,2)            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) " . $wpdb->get_charset_collate() );
    }

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

    /* ── Projects helpers ───────────────────────────────────── */
    public static function projects_table() {
        global $wpdb;
        return $wpdb->prefix . self::PROJECTS_TABLE;
    }

    public static function get_projects( $status = '' ) {
        global $wpdb;
        $pt = self::projects_table();
        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pt} WHERE status=%s ORDER BY created_at DESC", $status ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$pt} ORDER BY created_at DESC" );
    }

    public static function get_project( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::projects_table() . " WHERE id=%d", $id ) );
    }

    public static function insert_project( $data ) {
        global $wpdb;
        $wpdb->insert( self::projects_table(), $data );
        return $wpdb->insert_id;
    }

    public static function update_project( $id, $data ) {
        global $wpdb;
        return $wpdb->update( self::projects_table(), $data, array( 'id' => $id ) );
    }

    public static function delete_project( $id ) {
        global $wpdb;
        // Unlink requests from this project
        $wpdb->update( self::table(), array( 'project_id' => null ), array( 'project_id' => $id ) );
        return $wpdb->delete( self::projects_table(), array( 'id' => $id ) );
    }

    public static function get_requests_by_project( $project_id ) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE project_id=%d ORDER BY created_at DESC", $project_id ) );
    }
}
