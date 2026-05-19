<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Database {

    const TABLE           = 'quotepress_requests';
    const PROJECTS_TABLE  = 'quotepress_projects';
    const PRODUCTS_TABLE  = 'quotepress_products';
    const VARIANTS_TABLE  = 'quotepress_product_variations';

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

        $products_table  = $wpdb->prefix . self::PRODUCTS_TABLE;
        $variants_table  = $wpdb->prefix . self::VARIANTS_TABLE;

        $projects_sql = "CREATE TABLE IF NOT EXISTS {$projects_table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            category    VARCHAR(100)    NOT NULL DEFAULT '',
            description TEXT            NOT NULL DEFAULT '',
            client_name VARCHAR(255)    NOT NULL DEFAULT '',
            address     TEXT            NOT NULL DEFAULT '',
            tax_office  VARCHAR(100)    NOT NULL DEFAULT '',
            tax_number  VARCHAR(50)     NOT NULL DEFAULT '',
            contacts    LONGTEXT        NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'active',
            deadline    DATE                     DEFAULT NULL,
            budget      DECIMAL(15,2)            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        $products_sql = "CREATE TABLE IF NOT EXISTS {$products_table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name          VARCHAR(255)    NOT NULL DEFAULT '',
            sku           VARCHAR(100)    NOT NULL DEFAULT '',
            description   TEXT            NOT NULL DEFAULT '',
            unit          VARCHAR(50)     NOT NULL DEFAULT '',
            base_price    DECIMAL(15,2)            DEFAULT NULL,
            wc_product_id BIGINT UNSIGNED          DEFAULT NULL,
            sort_order    INT             NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};";

        $variants_sql = "CREATE TABLE IF NOT EXISTS {$variants_table} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(255)    NOT NULL DEFAULT '',
            sku        VARCHAR(100)    NOT NULL DEFAULT '',
            price      DECIMAL(15,2)            DEFAULT NULL,
            sort_order INT             NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id)
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
        dbDelta( $products_sql );
        dbDelta( $variants_sql );

        self::run_migrations( $wpdb, $table, $projects_table );

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
        static $done = false;
        if ( $done ) return;
        $done = true;
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE;
        $pt      = $wpdb->prefix . self::PROJECTS_TABLE;
        $prodst  = $wpdb->prefix . self::PRODUCTS_TABLE;
        $vart    = $wpdb->prefix . self::VARIANTS_TABLE;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Ensure projects table exists with all columns
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$pt} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(255)    NOT NULL DEFAULT '',
            category    VARCHAR(100)    NOT NULL DEFAULT '',
            description TEXT            NOT NULL DEFAULT '',
            client_name VARCHAR(255)    NOT NULL DEFAULT '',
            address     TEXT            NOT NULL DEFAULT '',
            tax_office  VARCHAR(100)    NOT NULL DEFAULT '',
            tax_number  VARCHAR(50)     NOT NULL DEFAULT '',
            contacts    LONGTEXT        NOT NULL DEFAULT '',
            status      VARCHAR(20)     NOT NULL DEFAULT 'active',
            deadline    DATE                     DEFAULT NULL,
            budget      DECIMAL(15,2)            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset}" );

        // Ensure products & variants tables exist
        dbDelta( "CREATE TABLE IF NOT EXISTS {$prodst} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name          VARCHAR(255)    NOT NULL DEFAULT '',
            sku           VARCHAR(100)    NOT NULL DEFAULT '',
            description   TEXT            NOT NULL DEFAULT '',
            unit          VARCHAR(50)     NOT NULL DEFAULT '',
            base_price    DECIMAL(15,2)            DEFAULT NULL,
            wc_product_id BIGINT UNSIGNED          DEFAULT NULL,
            sort_order    INT             NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};" );
        dbDelta( "CREATE TABLE IF NOT EXISTS {$vart} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(255)    NOT NULL DEFAULT '',
            sku        VARCHAR(100)    NOT NULL DEFAULT '',
            price      DECIMAL(15,2)            DEFAULT NULL,
            sort_order INT             NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id)
        ) {$charset};" );

        self::run_migrations( $wpdb, $table, $pt );
    }

    private static function run_migrations( $wpdb, $table, $pt ) {
        // requests: add project_id
        if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'project_id'" ) ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_id BIGINT UNSIGNED NULL DEFAULT NULL" );
        }
        // projects: add new columns if missing
        foreach ( [
            'category'   => "ADD COLUMN category   VARCHAR(100) NOT NULL DEFAULT '' AFTER name",
            'address'    => "ADD COLUMN address    TEXT         NOT NULL DEFAULT '' AFTER client_name",
            'tax_office' => "ADD COLUMN tax_office VARCHAR(100) NOT NULL DEFAULT '' AFTER address",
            'tax_number' => "ADD COLUMN tax_number VARCHAR(50)  NOT NULL DEFAULT '' AFTER tax_office",
            'contacts'   => "ADD COLUMN contacts   LONGTEXT     NOT NULL DEFAULT '' AFTER tax_number",
        ] as $col => $ddl ) {
            if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$pt} LIKE '{$col}'" ) ) ) {
                $wpdb->query( "ALTER TABLE {$pt} {$ddl}" );
            }
        }
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

    /* ── Products helpers ───────────────────────────────────── */
    public static function products_table() {
        global $wpdb;
        return $wpdb->prefix . self::PRODUCTS_TABLE;
    }

    public static function variants_table() {
        global $wpdb;
        return $wpdb->prefix . self::VARIANTS_TABLE;
    }

    public static function get_products( $group_id = 0 ) {
        global $wpdb;
        $t = self::products_table();
        if ( $group_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE group_id=%d ORDER BY sort_order ASC, name ASC", $group_id ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY group_id ASC, sort_order ASC, name ASC" );
    }

    public static function get_product( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::products_table() . " WHERE id=%d", $id ) );
    }

    public static function insert_product( $data ) {
        global $wpdb;
        $wpdb->insert( self::products_table(), $data );
        return $wpdb->insert_id;
    }

    public static function update_product( $id, $data ) {
        global $wpdb;
        return $wpdb->update( self::products_table(), $data, [ 'id' => $id ] );
    }

    public static function delete_product( $id ) {
        global $wpdb;
        $wpdb->delete( self::variants_table(), [ 'product_id' => $id ] );
        return $wpdb->delete( self::products_table(), [ 'id' => $id ] );
    }

    public static function get_variants( $product_id ) {
        global $wpdb;
        $t = self::variants_table();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id=%d ORDER BY sort_order ASC", $product_id ) );
    }

    public static function save_variants( $product_id, array $variants ) {
        global $wpdb;
        $t = self::variants_table();
        $wpdb->delete( $t, [ 'product_id' => $product_id ] );
        foreach ( array_slice( $variants, 0, 10 ) as $i => $v ) {
            $name = sanitize_text_field( $v['name'] ?? '' );
            if ( ! $name ) continue;
            $wpdb->insert( $t, [
                'product_id' => $product_id,
                'name'       => $name,
                'sku'        => sanitize_text_field( $v['sku'] ?? '' ),
                'price'      => isset( $v['price'] ) && $v['price'] !== '' ? floatval( $v['price'] ) : null,
                'sort_order' => (int) $i,
            ] );
        }
    }
}
