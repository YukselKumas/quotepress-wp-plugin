<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Settings {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_menu',            [ __CLASS__, 'register_catalog_menu' ] );
        add_action( 'admin_menu',            [ __CLASS__, 'register_template_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'save' ] );
        add_action( 'admin_post_qp_save_templates', [ __CLASS__, 'save_templates' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    /* ── Menu ───────────────────────────────────────────────── */
    public static function register_catalog_menu() {
        add_submenu_page(
            'quotepress-settings',
            'Ürün & Hizmet Kataloğu',
            '📦 Katalog',
            'manage_options',
            'quotepress-catalog',
            [ 'QuotePress_Catalog', 'render_page' ]
        );
    }

    public static function register_template_menu() {
        add_submenu_page(
            'quotepress-settings',
            'Teklif Şablonları',
            '📝 Şablonlar',
            'manage_options',
            'quotepress-templates',
            [ __CLASS__, 'render_templates_page' ]
        );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'QuotePress', 'quotepress' ),
            __( 'QuotePress', 'quotepress' ),
            'manage_options',
            'quotepress-settings',
            [ __CLASS__, 'render' ],
            'dashicons-clipboard',
            58
        );
        add_submenu_page(
            'quotepress-settings',
            __( 'Settings', 'quotepress' ),
            __( 'Settings', 'quotepress' ),
            'manage_options',
            'quotepress-settings',
            [ __CLASS__, 'render' ]
        );
        add_submenu_page(
            'quotepress-settings',
            __( 'Quote Panel', 'quotepress' ),
            __( 'Quote Panel', 'quotepress' ),
            'manage_options',
            'quotepress-panel-link',
            [ __CLASS__, 'redirect_panel' ]
        );
    }

    public static function redirect_panel() {
        $slug = self::get( 'panel_slug', 'quote-panel' );
        wp_redirect( home_url( '/' . $slug . '/' ) );
        exit;
    }

    /* ── Enqueue (color picker) ─────────────────────────────── */
    public static function enqueue( $hook ) {
        // Medya kütüphanesi (katalog sayfası için)
        if ( strpos( $hook, 'quotepress' ) !== false ) {
            wp_enqueue_media();
        }
        if ( strpos( $hook, 'quotepress' ) === false ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){ $(".qp-color-picker").wpColorPicker(); });' );
    }

    /* ── Save ───────────────────────────────────────────────── */
    public static function save() {
        if ( ! isset( $_POST['qp_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['qp_settings_nonce'], 'qp_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $fields = [
            'company_name', 'company_address', 'company_email',
            'company_phone1', 'company_phone2', 'company_whatsapp', 'company_website',
            'recipient_email', 'default_currency', 'currencies', 'default_vat',
            'validity_days', 'mail_footer', 'color_theme', 'custom_color',
            'product_categories', 'extra_option_label', 'extra_option_items',
            'extra_option_trigger', 'panel_slug', 'detailed_format_triggers',
            'quote_number_start', 'quote_number_prefix',
        ];

        $current = get_option( 'quotepress_settings', [] );
        foreach ( $fields as $f ) {
            $current[ $f ] = sanitize_textarea_field( wp_unslash( $_POST[ $f ] ?? '' ) );
        }

        // Logo: WordPress medya kütüphanesi attachment ID
        if ( isset( $_POST['company_logo_id'] ) ) {
            $current['company_logo_id'] = absint( $_POST['company_logo_id'] );
        }

        update_option( 'quotepress_settings', $current );

        // Re-flush rewrite for panel slug changes
        QuotePress_Panel::register_rewrite();
        flush_rewrite_rules();

        add_settings_error( 'qp_msg', 'qp_saved', __( 'Settings saved successfully.', 'quotepress' ), 'success' );
    }

    /* ── Get single setting ─────────────────────────────────── */
    public static function get( $key, $default = '' ) {
        $s = get_option( 'quotepress_settings', [] );
        return $s[ $key ] ?? $default;
    }

    /* ── Theme colors ───────────────────────────────────────── */
    public static function themes() {
        return [
            'green'  => [ 'label' => __( 'Green',  'quotepress' ), 'primary' => '#2e8b2e', 'dark' => '#1a5e1a', 'light' => '#eef7ee', 'border' => '#d0e4d0' ],
            'blue'   => [ 'label' => __( 'Blue',   'quotepress' ), 'primary' => '#1a6eb5', 'dark' => '#0f4a80', 'light' => '#e8f2fb', 'border' => '#c0d8f0' ],
            'red'    => [ 'label' => __( 'Red',    'quotepress' ), 'primary' => '#c0392b', 'dark' => '#8e1a10', 'light' => '#fdecea', 'border' => '#f5b7b1' ],
            'purple' => [ 'label' => __( 'Purple', 'quotepress' ), 'primary' => '#7b2d8b', 'dark' => '#511c5e', 'light' => '#f5eef8', 'border' => '#dbb8e4' ],
            'orange' => [ 'label' => __( 'Orange', 'quotepress' ), 'primary' => '#d35400', 'dark' => '#943a00', 'light' => '#fef0e7', 'border' => '#f5cba7' ],
            'custom' => [ 'label' => __( 'Custom', 'quotepress' ), 'primary' => '', 'dark' => '', 'light' => '', 'border' => '' ],
        ];
    }

    public static function active_theme_colors() {
        $theme  = self::get( 'color_theme', 'green' );
        $themes = self::themes();
        $colors = $themes[ $theme ] ?? $themes['green'];

        if ( $theme === 'custom' ) {
            $hex           = self::get( 'custom_color', '#2e8b2e' );
            $colors['primary'] = $hex;
            $colors['dark']    = self::darken( $hex, 30 );
            $colors['light']   = self::lighten( $hex, 90 );
            $colors['border']  = self::lighten( $hex, 70 );
        }
        return $colors;
    }

    /* ── Color helpers ──────────────────────────────────────── */
    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [ hexdec( substr($hex,0,2) ), hexdec( substr($hex,2,2) ), hexdec( substr($hex,4,2) ) ];
    }
    private static function darken( $hex, $pct ) {
        [$r,$g,$b] = self::hex_to_rgb( $hex );
        $f = ( 100 - $pct ) / 100;
        return sprintf( '#%02x%02x%02x', max(0,round($r*$f)), max(0,round($g*$f)), max(0,round($b*$f)) );
    }
    private static function lighten( $hex, $pct ) {
        [$r,$g,$b] = self::hex_to_rgb( $hex );
        $f = $pct / 100;
        return sprintf( '#%02x%02x%02x', min(255,round($r+(255-$r)*$f)), min(255,round($g+(255-$g)*$f)), min(255,round($b+(255-$b)*$f)) );
    }

    /* ── Render ─────────────────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        settings_errors( 'qp_msg' );

        $s      = get_option( 'quotepress_settings', QuotePress_Database::default_settings() );
        $themes = self::themes();
        $slug   = $s['panel_slug'] ?? 'quote-panel';
        $c      = self::active_theme_colors();
        $primary = $c['primary'];
        $light   = $c['light'];
        $border  = $c['border'];
        $dark    = $c['dark'];

        $parse = fn( $key, $default = '' ) => array_values( array_filter(
            array_map( 'trim', explode( "\n", $s[ $key ] ?? $default ) )
        ) );

        $currencies   = $parse( 'currencies', "USD\nEUR\nGBP\nTRY" );
        $def_cur      = trim( $s['default_currency'] ?? 'USD' );
        $def_vat      = trim( $s['default_vat'] ?? '0' );
        $categories   = $parse( 'product_categories' );
        $extra_items  = $parse( 'extra_option_items' );
        $triggers     = $parse( 'extra_option_trigger' );
        $det_triggers = $parse( 'detailed_format_triggers' );
        $logo_id      = intval( $s['company_logo_id'] ?? 0 );
        $logo_url     = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

        $vat_options = [
            '0'  => __( 'No VAT', 'quotepress' ),
            '5'  => '5%', '8' => '8%', '10' => '10%',
            '18' => '18%', '20' => '20%', '21' => '21%', '25' => '25%',
        ];
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:<?php echo esc_attr($primary); ?>;color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Settings', 'quotepress' ); ?>
        </h1>

        <form method="post" action="" id="qp-settings-form">
        <?php wp_nonce_field( 'qp_save_settings', 'qp_settings_nonce' ); ?>

        <style>
        .qp-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:4px;}
        .qp-full{grid-column:1/-1;}
        .qp-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px 24px;}
        .qp-card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:<?php echo esc_attr($primary); ?>;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid <?php echo esc_attr($light); ?>;}
        .qp-row{display:grid;grid-template-columns:160px 1fr;gap:4px 14px;align-items:start;margin-bottom:14px;}
        .qp-row>label{font-size:13px;font-weight:600;color:#555;padding-top:9px;line-height:1.4;}
        .qp-field{display:flex;flex-direction:column;gap:4px;}
        .qp-input{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit;}
        .qp-input:focus{outline:none;border-color:<?php echo esc_attr($primary); ?>;box-shadow:0 0 0 2px <?php echo esc_attr($light); ?>;}
        textarea.qp-input{resize:vertical;}
        .qp-hint{font-size:11px;color:#999;line-height:1.5;margin:0;}
        /* Chip UI */
        .qp-chip-wrap{border:1px solid #ccc;border-radius:6px;padding:8px 10px;background:#fff;cursor:text;transition:border-color .15s,box-shadow .15s;}
        .qp-chip-wrap:focus-within{border-color:<?php echo esc_attr($primary); ?>;box-shadow:0 0 0 2px <?php echo esc_attr($light); ?>;}
        .qp-chips{display:flex;flex-wrap:wrap;gap:5px;min-height:24px;}
        .qp-chip{display:inline-flex;align-items:center;gap:3px;background:<?php echo esc_attr($light); ?>;border:1px solid <?php echo esc_attr($border); ?>;border-radius:20px;padding:3px 6px 3px 10px;font-size:12px;font-weight:600;color:<?php echo esc_attr($dark); ?>;line-height:1.4;}
        .qp-chip-rm{background:none;border:none;cursor:pointer;color:<?php echo esc_attr($primary); ?>;font-size:15px;line-height:1;padding:0 2px;opacity:.7;}
        .qp-chip-rm:hover{opacity:1;}
        .qp-chip-add-row{display:flex;gap:6px;margin-top:6px;}
        .qp-chip-add-row input{flex:1;border:1px solid #e0e0e0;border-radius:6px;padding:5px 9px;font-size:13px;background:#fafafa;font-family:inherit;}
        .qp-chip-add-row input:focus{outline:none;border-color:<?php echo esc_attr($primary); ?>;background:#fff;}
        .qp-chip-add-btn{background:<?php echo esc_attr($primary); ?>;color:#fff;border:none;border-radius:6px;padding:5px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;transition:opacity .15s;}
        .qp-chip-add-btn:hover{opacity:.85;}
        /* Checkbox group */
        .qp-check-group{display:flex;flex-wrap:wrap;gap:6px 16px;padding:9px 11px;border:1px solid #ccc;border-radius:6px;min-height:40px;background:#fff;}
        .qp-check-group label{display:inline-flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;user-select:none;}
        /* Theme buttons */
        .qp-theme-grid{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
        .qp-theme-btn{border:2px solid #ddd;border-radius:8px;padding:8px 12px;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;background:#fff;transition:all .15s;}
        .qp-theme-btn:hover,.qp-theme-btn.active{border-color:<?php echo esc_attr($primary); ?>;box-shadow:0 0 0 3px <?php echo esc_attr($light); ?>;}
        .qp-swatch{width:16px;height:16px;border-radius:50%;display:inline-block;flex-shrink:0;}
        .qp-info-bar{margin-top:18px;background:<?php echo esc_attr($light); ?>;border:1px solid <?php echo esc_attr($border); ?>;border-radius:8px;padding:14px 18px;font-size:13px;color:<?php echo esc_attr($dark); ?>;}
        .qp-info-bar a{color:<?php echo esc_attr($primary); ?>;font-weight:600;}
        @media(max-width:900px){.qp-grid{grid-template-columns:1fr;}.qp-row{grid-template-columns:1fr;}.qp-row>label{padding-top:0;}}
        </style>

        <div class="qp-grid">

        <!-- ── Company ───────────────────────────────────────────── -->
        <div class="qp-card">
            <div class="qp-card-title">🏢 <?php esc_html_e( 'Company Information', 'quotepress' ); ?></div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Company Name', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="company_name" value="<?php echo esc_attr( $s['company_name'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Address', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <textarea class="qp-input" name="company_address" rows="2"><?php echo esc_textarea( $s['company_address'] ?? '' ); ?></textarea>
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Email', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="email" name="company_email" value="<?php echo esc_attr( $s['company_email'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Phone 1', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="company_phone1" value="<?php echo esc_attr( $s['company_phone1'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Phone 2', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="company_phone2" value="<?php echo esc_attr( $s['company_phone2'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label>WhatsApp</label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="company_whatsapp" value="<?php echo esc_attr( $s['company_whatsapp'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Website', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="company_website" value="<?php echo esc_attr( $s['company_website'] ?? '' ); ?>">
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Logo', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div id="qp-logo-preview" style="margin-bottom:6px;">
                        <?php if ( $logo_url ) : ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;max-width:200px;border:1px solid #ddd;border-radius:4px;padding:4px;">
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="company_logo_id" id="qp-logo-id" value="<?php echo esc_attr( $logo_id ); ?>">
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="button" id="qp-logo-btn"><?php esc_html_e( 'Choose Logo', 'quotepress' ); ?></button>
                        <button type="button" class="button" id="qp-logo-remove" style="color:#a00;<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'quotepress' ); ?></button>
                    </div>
                    <p class="qp-hint"><?php esc_html_e( 'PNG or SVG with transparent background. Appears top-left on PDF quotes.', 'quotepress' ); ?></p>
                </div>
            </div>
        </div>

        <!-- ── Email ─────────────────────────────────────────────── -->
        <div class="qp-card">
            <div class="qp-card-title">📧 <?php esc_html_e( 'Email Settings', 'quotepress' ); ?></div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Recipient Email', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="recipient_email" value="<?php echo esc_attr( $s['recipient_email'] ?? '' ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'New quote request notifications go here. Separate multiple with commas.', 'quotepress' ); ?></p>
                </div>
            </div>
            <div class="qp-row">
                <label><?php esc_html_e( 'Mail Footer', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <textarea class="qp-input" name="mail_footer" rows="3"><?php echo esc_textarea( $s['mail_footer'] ?? '' ); ?></textarea>
                </div>
            </div>
        </div>

        <!-- ── Pricing ───────────────────────────────────────────── -->
        <div class="qp-card">
            <div class="qp-card-title">💰 <?php esc_html_e( 'Pricing', 'quotepress' ); ?></div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Currencies', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-chip-wrap">
                        <div class="qp-chips" id="qp-chips-currencies"></div>
                        <div class="qp-chip-add-row">
                            <input type="text" id="qp-add-currencies" class="qp-chip-add-input" data-field="currencies"
                                   placeholder="<?php esc_attr_e( 'e.g. USD', 'quotepress' ); ?>" maxlength="10">
                            <button type="button" class="qp-chip-add-btn" onclick="qpAddChip('currencies')">
                                + <?php esc_html_e( 'Add', 'quotepress' ); ?>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="currencies" id="qp-hidden-currencies" value="<?php echo esc_attr( implode( "\n", $currencies ) ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'Currency codes available when preparing a quote (USD, EUR, TRY…)', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Default Currency', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <select class="qp-input" name="default_currency" id="qp-sel-default_currency" style="max-width:180px;">
                        <?php foreach ( $currencies as $cur ) : ?>
                        <option value="<?php echo esc_attr($cur); ?>" <?php selected( $def_cur, $cur ); ?>><?php echo esc_html($cur); ?></option>
                        <?php endforeach; ?>
                        <?php if ( $def_cur && ! in_array( $def_cur, $currencies, true ) ) : ?>
                        <option value="<?php echo esc_attr($def_cur); ?>" selected><?php echo esc_html($def_cur); ?></option>
                        <?php endif; ?>
                    </select>
                    <p class="qp-hint"><?php esc_html_e( 'Pre-selected when the quote panel opens. Updates automatically when you add/remove currencies above.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Default VAT', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <select class="qp-input" name="default_vat" style="max-width:180px;">
                        <?php foreach ( $vat_options as $val => $label ) : ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected( $def_vat, (string)$val ); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="qp-hint"><?php esc_html_e( 'Pre-selected VAT rate on the quote panel.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Validity', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input class="qp-input" type="number" name="validity_days" value="<?php echo esc_attr( $s['validity_days'] ?? '30' ); ?>" min="1" style="max-width:110px;">
                        <span style="font-size:13px;color:#888;"><?php esc_html_e( 'days', 'quotepress' ); ?></span>
                    </div>
                    <p class="qp-hint"><?php esc_html_e( 'Expiry date shown on the PDF quote.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Quote Number', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input class="qp-input" type="text" name="quote_number_prefix"
                               value="<?php echo esc_attr( $s['quote_number_prefix'] ?? '' ); ?>"
                               style="max-width:90px;" placeholder="<?php esc_attr_e( 'Prefix', 'quotepress' ); ?>">
                        <input class="qp-input" type="number" name="quote_number_start"
                               value="<?php echo esc_attr( $s['quote_number_start'] ?? '1' ); ?>"
                               style="max-width:100px;" min="1" placeholder="<?php esc_attr_e( 'Start', 'quotepress' ); ?>">
                    </div>
                    <p class="qp-hint"><?php esc_html_e( 'Prefix + start number. Example: QP- + 100 → QP-0100', 'quotepress' ); ?></p>
                </div>
            </div>
        </div>

        <!-- ── Products & Options ────────────────────────────────── -->
        <div class="qp-card">
            <div class="qp-card-title">📦 <?php esc_html_e( 'Products & Options', 'quotepress' ); ?></div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Product Categories', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-chip-wrap">
                        <div class="qp-chips" id="qp-chips-product_categories"></div>
                        <div class="qp-chip-add-row">
                            <input type="text" id="qp-add-product_categories" class="qp-chip-add-input" data-field="product_categories"
                                   placeholder="<?php esc_attr_e( 'e.g. Air Conditioner', 'quotepress' ); ?>">
                            <button type="button" class="qp-chip-add-btn" onclick="qpAddChip('product_categories')">
                                + <?php esc_html_e( 'Add', 'quotepress' ); ?>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="product_categories" id="qp-hidden-product_categories" value="<?php echo esc_attr( implode( "\n", $categories ) ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'Appear in the quote request form dropdown.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Detailed PDF Triggers', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-chip-wrap">
                        <div class="qp-chips" id="qp-chips-detailed_format_triggers"></div>
                        <div class="qp-chip-add-row">
                            <input type="text" id="qp-add-detailed_format_triggers" class="qp-chip-add-input" data-field="detailed_format_triggers"
                                   placeholder="<?php esc_attr_e( 'e.g. heat cost', 'quotepress' ); ?>">
                            <button type="button" class="qp-chip-add-btn" onclick="qpAddChip('detailed_format_triggers')">
                                + <?php esc_html_e( 'Add', 'quotepress' ); ?>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="detailed_format_triggers" id="qp-hidden-detailed_format_triggers" value="<?php echo esc_attr( implode( "\n", $det_triggers ) ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'If a product name contains any keyword, the 4-page detailed PDF is used. Leave empty for defaults (ısı gider, heat cost).', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Extra Option Label', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="extra_option_label"
                           value="<?php echo esc_attr( $s['extra_option_label'] ?? '' ); ?>"
                           placeholder="<?php esc_attr_e( 'e.g. Communication Type', 'quotepress' ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'Label for the extra question on the form. Leave blank to disable entirely.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Extra Option Choices', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-chip-wrap">
                        <div class="qp-chips" id="qp-chips-extra_option_items"></div>
                        <div class="qp-chip-add-row">
                            <input type="text" id="qp-add-extra_option_items" class="qp-chip-add-input" data-field="extra_option_items"
                                   placeholder="<?php esc_attr_e( 'e.g. Wired', 'quotepress' ); ?>">
                            <button type="button" class="qp-chip-add-btn" onclick="qpAddChip('extra_option_items')">
                                + <?php esc_html_e( 'Add', 'quotepress' ); ?>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" name="extra_option_items" id="qp-hidden-extra_option_items" value="<?php echo esc_attr( implode( "\n", $extra_items ) ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'Radio button choices for the extra option.', 'quotepress' ); ?></p>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Show Extra Option When', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-check-group" id="qp-trigger-wrap">
                        <?php if ( empty( $categories ) ) : ?>
                        <span style="color:#aaa;font-size:12px;font-style:italic;"><?php esc_html_e( 'Add product categories first.', 'quotepress' ); ?></span>
                        <?php else : foreach ( $categories as $cat ) : ?>
                        <label>
                            <input type="checkbox" name="__trigger_check[]" value="<?php echo esc_attr($cat); ?>"
                                   onchange="qpSyncTriggers()"
                                   <?php checked( in_array( $cat, $triggers, true ) ); ?>>
                            <?php echo esc_html($cat); ?>
                        </label>
                        <?php endforeach; endif; ?>
                    </div>
                    <input type="hidden" name="extra_option_trigger" id="qp-hidden-extra_option_trigger" value="<?php echo esc_attr( implode( "\n", $triggers ) ); ?>">
                    <p class="qp-hint"><?php esc_html_e( 'The extra option question appears when any checked product is added.', 'quotepress' ); ?></p>
                </div>
            </div>
        </div>

        <!-- ── Design ────────────────────────────────────────────── -->
        <div class="qp-card qp-full">
            <div class="qp-card-title">🎨 <?php esc_html_e( 'Design & Appearance', 'quotepress' ); ?></div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Color Theme', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <div class="qp-theme-grid">
                        <?php foreach ( $themes as $key => $t ) :
                            $color  = $key === 'custom' ? ( $s['custom_color'] ?? '#2e8b2e' ) : $t['primary'];
                            $active = ( $s['color_theme'] ?? 'green' ) === $key ? 'active' : '';
                        ?>
                        <label class="qp-theme-btn <?php echo $active; ?>">
                            <input type="radio" name="color_theme" value="<?php echo esc_attr($key); ?>"
                                   <?php checked( $s['color_theme'] ?? 'green', $key ); ?> style="display:none">
                            <span class="qp-swatch" style="background:<?php echo esc_attr($color); ?>;"></span>
                            <?php echo esc_html( $t['label'] ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div id="qp-custom-color-wrap" style="margin-top:10px;<?php echo ( ($s['color_theme']??'green') !== 'custom' ) ? 'display:none;' : ''; ?>">
                        <input type="text" name="custom_color" class="qp-color-picker" value="<?php echo esc_attr( $s['custom_color'] ?? '#2e8b2e' ); ?>">
                    </div>
                </div>
            </div>

            <div class="qp-row">
                <label><?php esc_html_e( 'Panel URL Slug', 'quotepress' ); ?></label>
                <div class="qp-field">
                    <input class="qp-input" type="text" name="panel_slug" value="<?php echo esc_attr( $slug ); ?>" style="max-width:300px;">
                    <p class="qp-hint"><?php printf( esc_html__( 'Quote panel will be at: %s', 'quotepress' ), '<strong>' . esc_url( home_url('/'.$slug.'/') ) . '</strong>' ); ?></p>
                </div>
            </div>
        </div>

        </div><!-- /grid -->

        <div style="margin-top:20px;">
            <?php submit_button( __( 'Save Settings', 'quotepress' ), 'primary large' ); ?>
        </div>

        </form>

        <div class="qp-info-bar">
            <strong><?php esc_html_e( 'Form Shortcode:', 'quotepress' ); ?></strong>
            <code>[quotepress_form]</code> &nbsp;—&nbsp;
            <?php esc_html_e( 'Add to any page to display the quote request form.', 'quotepress' ); ?>
            &nbsp;|&nbsp;
            <strong><?php esc_html_e( 'Quote Panel:', 'quotepress' ); ?></strong>
            <a href="<?php echo esc_url( home_url('/'.$slug.'/') ); ?>" target="_blank">
                <?php echo esc_url( home_url('/'.$slug.'/') ); ?> →
            </a>
        </div>
        </div>

        <script>
        /* ── Chip state ─────────────────────────────────────────── */
        var QP_CHIPS = {
            currencies:               <?php echo wp_json_encode( $currencies ); ?>,
            product_categories:       <?php echo wp_json_encode( $categories ); ?>,
            extra_option_items:       <?php echo wp_json_encode( $extra_items ); ?>,
            detailed_format_triggers: <?php echo wp_json_encode( $det_triggers ); ?>
        };

        function qpEsc(s){
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function qpRenderChips(field){
            var el = document.getElementById('qp-chips-' + field);
            if (!el) return;
            el.innerHTML = QP_CHIPS[field].map(function(val,i){
                return '<span class="qp-chip">' + qpEsc(val)
                    + '<button type="button" class="qp-chip-rm" title="<?php echo esc_js( __( 'Remove', 'quotepress' ) ); ?>" onclick="qpRemoveChip(\'' + field + '\',' + i + ')">×</button>'
                    + '</span>';
            }).join('');
            qpSyncHidden(field);
            if (field === 'currencies')         qpSyncCurrencySelect();
            if (field === 'product_categories') qpRebuildTriggers();
        }

        function qpAddChip(field){
            var input = document.getElementById('qp-add-' + field);
            var val   = input.value.trim();
            if (!val) return;
            if (QP_CHIPS[field].indexOf(val) === -1){
                QP_CHIPS[field].push(val);
                qpRenderChips(field);
            }
            input.value = '';
            input.focus();
        }

        function qpRemoveChip(field, idx){
            QP_CHIPS[field].splice(idx, 1);
            qpRenderChips(field);
        }

        function qpSyncHidden(field){
            var el = document.getElementById('qp-hidden-' + field);
            if (el) el.value = QP_CHIPS[field].join('\n');
        }

        function qpSyncCurrencySelect(){
            var sel = document.getElementById('qp-sel-default_currency');
            if (!sel) return;
            var prev = sel.value;
            sel.innerHTML = '';
            QP_CHIPS['currencies'].forEach(function(c){
                var o = document.createElement('option');
                o.value = c; o.textContent = c;
                if (c === prev) o.selected = true;
                sel.appendChild(o);
            });
        }

        function qpRebuildTriggers(){
            var wrap   = document.getElementById('qp-trigger-wrap');
            var hidden = document.getElementById('qp-hidden-extra_option_trigger');
            if (!wrap || !hidden) return;
            var checked = hidden.value.split('\n').filter(Boolean);
            wrap.querySelectorAll('input[type=checkbox]:checked').forEach(function(cb){ if (checked.indexOf(cb.value)===-1) checked.push(cb.value); });
            if (QP_CHIPS['product_categories'].length === 0){
                wrap.innerHTML = '<span style="color:#aaa;font-size:12px;font-style:italic;"><?php echo esc_js( __( 'Add product categories first.', 'quotepress' ) ); ?></span>';
                hidden.value = '';
                return;
            }
            wrap.innerHTML = QP_CHIPS['product_categories'].map(function(cat){
                var chk = checked.indexOf(cat) !== -1 ? ' checked' : '';
                return '<label><input type="checkbox" name="__trigger_check[]" value="' + qpEsc(cat) + '" onchange="qpSyncTriggers()"' + chk + '> ' + qpEsc(cat) + '</label>';
            }).join('');
            qpSyncTriggers();
        }

        function qpSyncTriggers(){
            var wrap   = document.getElementById('qp-trigger-wrap');
            var hidden = document.getElementById('qp-hidden-extra_option_trigger');
            if (!wrap || !hidden) return;
            var vals = [];
            wrap.querySelectorAll('input[type=checkbox]:checked').forEach(function(cb){ vals.push(cb.value); });
            hidden.value = vals.join('\n');
        }

        /* ── Color theme ────────────────────────────────────────── */
        document.querySelectorAll('input[name="color_theme"]').forEach(function(r){
            r.addEventListener('change', function(){
                document.getElementById('qp-custom-color-wrap').style.display = this.value === 'custom' ? 'block' : 'none';
                document.querySelectorAll('.qp-theme-btn').forEach(function(b){ b.classList.remove('active'); });
                this.closest('.qp-theme-btn').classList.add('active');
            });
        });

        /* ── Logo media picker ──────────────────────────────────── */
        jQuery(function($){
            var frame;
            $('#qp-logo-btn').on('click', function(){
                if (frame){ frame.open(); return; }
                frame = wp.media({ title:'<?php echo esc_js( __( "Choose Logo", "quotepress" ) ); ?>', button:{ text:'<?php echo esc_js( __( "Use this image", "quotepress" ) ); ?>' }, multiple:false });
                frame.on('select', function(){
                    var att  = frame.state().get('selection').first().toJSON();
                    var prev = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                    $('#qp-logo-id').val(att.id);
                    $('#qp-logo-preview').html('<img src="'+prev+'" style="max-height:60px;max-width:200px;border:1px solid #ddd;border-radius:4px;padding:4px;">');
                    $('#qp-logo-remove').show();
                });
                frame.open();
            });
            $('#qp-logo-remove').on('click', function(){ $('#qp-logo-id').val(''); $('#qp-logo-preview').html(''); $(this).hide(); });
        });

        /* ── Init on load ───────────────────────────────────────── */
        document.addEventListener('DOMContentLoaded', function(){
            ['currencies','product_categories','extra_option_items','detailed_format_triggers'].forEach(qpRenderChips);
            document.querySelectorAll('.qp-chip-add-input').forEach(function(inp){
                inp.addEventListener('keydown', function(e){
                    if (e.key === 'Enter'){ e.preventDefault(); qpAddChip(this.dataset.field); }
                });
            });
        });
        </script>
        <?php
    }
    /* ── Şablon kaydet ──────────────────────────────────────── */
    public static function save_templates() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz' );
        check_admin_referer( 'qp_save_templates' );

        $tpl = get_option( 'quotepress_templates', [] );

        // Isı gider şablonu alanları
        // Dinamik sorular
        $questions = array_values( array_filter( array_map( 'sanitize_text_field', (array)( $_POST['ig_questions'] ?? [] ) ) ) );
        for ( $qi = 1; $qi <= 10; $qi++ ) {
            $tpl[ 'ig_q' . $qi ] = $questions[ $qi - 1 ] ?? '';
        }

        // Rozet iconları
        for ( $bi = 1; $bi <= 4; $bi++ ) {
            $tpl[ 'ig_badge' . $bi . '_icon' ] = sanitize_text_field( $_POST[ 'ig_badge' . $bi . '_icon' ] ?? '&#10003;' );
        }

        $fields_ig = [
            'ig_salutation_text', 'ig_intro_text', 'ig_answer_text',
            'ig_comparison_title', 'ig_commitments_title', 'ig_commitments_sub',
            'ig_card1_num','ig_card1_unit','ig_card1_title','ig_card1_sub','ig_card1_body',
            'ig_card2_num','ig_card2_unit','ig_card2_title','ig_card2_sub','ig_card2_body',
            'ig_card3_num','ig_card3_unit','ig_card3_title','ig_card3_sub','ig_card3_body',
            'ig_card4_num','ig_card4_unit','ig_card4_title','ig_card4_sub','ig_card4_body',
            'ig_card5_num','ig_card5_unit','ig_card5_title','ig_card5_sub','ig_card5_body',
            'ig_card6_num','ig_card6_unit','ig_card6_title','ig_card6_sub','ig_card6_body',
            'ig_stat1_num','ig_stat1_label','ig_stat2_num','ig_stat2_label','ig_stat3_num','ig_stat3_label',
            'ig_annual_title','ig_annual_body',
            'ig_cta_title','ig_cta_body',
            'ig_badge1','ig_badge2','ig_badge3','ig_badge4',
            'ig_price_note','ig_guarantee_note',
        ];
        foreach ( $fields_ig as $f ) {
            $tpl[$f] = sanitize_textarea_field( wp_unslash( $_POST[$f] ?? '' ) );
        }

        // Genel sorular
        foreach ( ['ig_q1','ig_q2','ig_q3'] as $qf ) {
            $tpl[$qf] = sanitize_text_field( wp_unslash( $_POST[$qf] ?? '' ) );
        }

        update_option( 'quotepress_templates', $tpl );
        wp_redirect( admin_url( 'admin.php?page=quotepress-templates&saved=1' ) );
        exit;
    }

    public static function get_tpl( $key, $default = '' ) {
        $tpl = get_option( 'quotepress_templates', [] );
        return $tpl[$key] ?? $default;
    }

    /* ── Şablon sayfası ─────────────────────────────────────── */
    public static function render_templates_page() {
        $saved = isset($_GET['saved']);
        $t = fn($k, $d='') => esc_textarea( self::get_tpl($k, $d) );
        $v = fn($k, $d='') => esc_attr( self::get_tpl($k, $d) );
        ?>
        <style>
        .qpt-wrap{max-width:900px;}
        .qpt-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;}
        .qpt-tab{padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;border:none;background:none;color:#888;border-bottom:2px solid transparent;margin-bottom:-2px;}
        .qpt-tab.active{color:#1a6eb5;border-bottom-color:#1a6eb5;}
        .qpt-panel{display:none;} .qpt-panel.active{display:block;}
        .qpt-section{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 20px;margin-bottom:16px;}
        .qpt-section h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1a6eb5;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e8f0fb;}
        .qpt-row{display:grid;gap:10px;margin-bottom:10px;}
        .qpt-row.cols-2{grid-template-columns:1fr 1fr;}
        .qpt-row.cols-3{grid-template-columns:80px 1fr 1fr;}
        .qpt-row.cols-5{grid-template-columns:70px 100px 1fr 1fr 2fr;}
        .qpt-row label{font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:3px;}
        .qpt-row input,.qpt-row textarea{width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;font-family:inherit;}
        .qpt-row textarea{resize:vertical;min-height:60px;}
        .qpt-card-group{border:1px solid #e8f0fb;border-radius:8px;padding:12px;margin-bottom:10px;background:#f8fbff;}
        .qpt-card-num{font-size:22px;font-weight:900;color:#1a6eb5;text-align:center;padding:4px 0;}
        .qpt-hint{font-size:11px;color:#aaa;margin:2px 0 0;font-style:italic;}
        .qpt-save-bar{position:sticky;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding:14px 0;margin-top:24px;display:flex;gap:10px;align-items:center;}
        .qpt-save-btn{background:#1a6eb5;color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;}
        .qpt-save-btn:hover{background:#155e99;}
        .qpt-saved{color:#16a34a;font-weight:600;font-size:13px;}
        </style>

        <div class="qpt-wrap wrap">
          <h1 style="margin-bottom:4px;">📝 Teklif Şablonları</h1>
          <p style="color:#666;font-size:13px;margin-bottom:20px;">PDF tekliflerinde görünen tüm metinleri buradan özelleştirin. Boş bırakılan alanlar varsayılan metni kullanır.</p>

          <?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>✓ Şablon kaydedildi.</p></div><?php endif; ?>

          <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="qp_save_templates">
            <?php wp_nonce_field('qp_save_templates'); ?>

            <!-- SEKME BAŞLIKLARI -->
            <div class="qpt-tabs">
              <button type="button" class="qpt-tab active" onclick="qptTab('ig',this)">🔥 Isı Gider Paylaşım</button>
              <button type="button" class="qpt-tab" onclick="qptTab('std',this)">📄 Standart Kompakt</button>
            </div>

            <!-- ISI GİDER ŞABLONU -->
            <div class="qpt-panel active" id="qpt-ig">

              <div class="qpt-section">
                <h3>Giriş Mektubu</h3>
                <div class="qpt-row">
                  <label>Giriş paragrafı</label>
                  <textarea name="ig_intro_text" rows="3" placeholder="[MÜŞTERİ]&#039;nin ısı gider paylaşım süreçleri için [ŞİRKET]&#039;ye gösterdiğiniz ilgiye teşekkür ederiz..."><?php echo $t('ig_intro_text'); ?></textarea>
                  <p class="qpt-hint">[MÜŞTERİ] = firma adı, [ŞİRKET] = şirket adınız/web adresiniz</p>
                </div>
                <div class="qpt-row">
                  <label>Cevap kutusu metni</label>
                  <textarea name="ig_answer_text" rows="2" placeholder="Bu soruların tamamına net bir yanıt veriyoruz..."><?php echo $t('ig_answer_text'); ?></textarea>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Müşteri Soruları <span style="font-size:10px;font-weight:400;color:#aaa;">(artırılabilir/azaltılabilir)</span></h3>
                <div id="qpt-questions-list">
                  <?php
                  $def_qs = [
                      'Bağımsız bölümlere düşen ısı gideri payı nasıl hesaplanacak, sakinler itiraz ederse ne olacak?',
                      'Fatura geldiğinde hesapları kim yapacak, raporları kim dağıtacak?',
                      'Arıza yapan sayaçlar nasıl takip edilecek, yasal yükümlülükler yerine getirilecek mi?',
                  ];
                  $tpl_data = get_option('quotepress_templates', []);
                  $saved_qs = [];
                  for ($qi = 1; $qi <= 10; $qi++) {
                      $v_q = $tpl_data['ig_q'.$qi] ?? '';
                      if ($qi <= 3) { $saved_qs[] = $v_q ?: ($def_qs[$qi-1] ?? ''); }
                      elseif ($v_q) { $saved_qs[] = $v_q; }
                  }
                  if (empty($saved_qs)) $saved_qs = $def_qs;
                  foreach ($saved_qs as $qi => $qv):
                  ?>
                  <div class="qpt-q-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
                    <input type="text" name="ig_questions[]" value="<?php echo esc_attr($qv); ?>" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Soru metni...">
                    <button type="button" class="qpb danger" onclick="qptRemoveQ(this)" style="padding:5px 8px;flex-shrink:0;">✕</button>
                  </div>
                  <?php endforeach; ?>
                </div>
                <button type="button" onclick="qptAddQ()" style="margin-top:4px;background:#fff;border:1px dashed #93c5fd;border-radius:6px;padding:5px 14px;font-size:12px;color:#1a6eb5;cursor:pointer;font-weight:600;">+ Soru Ekle</button>
                <p class="qpt-hint">Boş bırakılan sorular gösterilmez. Sırayı değiştirmek için sürükleyebilirsiniz.</p>
              </div>

              <div class="qpt-section">
                <h3>Rozet Şeridi</h3>
                <p class="qpt-hint" style="margin-bottom:12px;">Her rozet için ikon ve metin seçin. ✓ yerine emoji kullanabilirsiniz.</p>
                <?php
                $badge_defaults = [
                    ['icon'=>'✓','text'=>'20+ Yıl Tecrübe'],
                    ['icon'=>'✓','text'=>'Bakanlık Onaylı Yetkili Ölçüm Şirketi'],
                    ['icon'=>'✓','text'=>'ISO 9001 Sertifikalı'],
                    ['icon'=>'✓','text'=>'Sıfır Sorun Garantisi'],
                ];
                $tpl_data2 = get_option('quotepress_templates', []);
                for ($bi=1;$bi<=4;$bi++):
                    $b_icon = $tpl_data2['ig_badge'.$bi.'_icon'] ?? ($badge_defaults[$bi-1]['icon']??'✓');
                    $b_text = $tpl_data2['ig_badge'.$bi] ?? ($badge_defaults[$bi-1]['text']??'');
                ?>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                  <select name="ig_badge<?php echo $bi; ?>_icon" style="border:1px solid #d1d5db;border-radius:6px;padding:7px 8px;font-size:14px;width:70px;text-align:center;">
                    <?php
                    $icons = ['✓','✔','★','⭐','🏆','🎯','🔒','💎','🛡️','📋','🔧','⚡','🌟','💡','🏅'];
                    foreach ($icons as $ico) echo '<option value="'.esc_attr($ico).'" '.($b_icon===$ico?'selected':'').'>'.esc_html($ico).'</option>';
                    ?>
                  </select>
                  <input type="text" name="ig_badge<?php echo $bi; ?>" value="<?php echo esc_attr($b_text); ?>" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Rozet <?php echo $bi; ?> metni...">
                </div>
                <?php endfor; ?>
              </div>

              <div class="qpt-section">
                <h3>Taahhüt Kartları</h3>
                <?php
                $default_cards = [
                    ['3','İŞ GÜNÜ','3 İş Günü Rapor Teslimi','Fatura gelir, rapor hazır olur.','Doğalgaz faturasını bize iletmenizden itibaren en geç 3 iş günü içinde tüm bağımsız bölümlere ait ısı gider paylaşım raporları eksiksiz biçimde hazırlanarak size teslim edilir. Bekleme yok, gecikme yok.'],
                    ['PDF','EXCEL · ÇIKTI','Çok Formatlı Dijital Raporlama','Kağıt değil, akıllı raporlama.','Paylaşım sonuçları PDF, Excel ve yazdırmaya hazır çıktı formatında; hem tüm site için toplu hem de her bağımsız bölüm için ayrı ayrı hazırlanır. Dağıtım tamamen elektronik ortamda gerçekleşir.'],
                    ['48','SAAT','48 Saat Teknik Servis','Arıza bildirimi = 48 saat içinde çözüm.','Sayaç arızası veya yerinde müdahale gerektiren her durumda teknik ekibimiz, bildirimin alınmasından itibaren en geç 48 saat içinde sahada.'],
                    ['7/24','ERİŞİM','Web Paneli ile Tam Şeffaflık','Her daire kendi tüketimini görür.','Web paneli üzerinden site yönetimi ve her bağımsız bölüm sakini anlık tüketim verilerini 7/24 görüntüleyebilir.'],
                    ['100%','UYUM','Yasal Mevzuata Tam Uyum','Ceza riski sıfır, uyumluluk tam.','Tüm hesaplamalar, T.C. Çevre, Şehircilik ve İklim Değişikliği Bakanlığı nın Isı Gider Paylaşım Yönetmeliği çerçevesinde eksiksiz yürütülür.'],
                    ['ISO','9001','Güvenli Arşiv ve Anında Erişim','Hiçbir veri kaybolmaz.','Tüm okuma verileri ve paylaşım raporları yasal saklama sürelerine uygun olarak güvenli dijital ortamda arşivlenir.'],
                ];
                foreach ($default_cards as $ci => $dc):
                    $n = $ci+1;
                ?>
                <div class="qpt-card-group">
                  <div style="font-size:11px;font-weight:700;color:#1a6eb5;margin-bottom:8px;">KART <?php echo $n; ?></div>
                  <div class="qpt-row cols-5">
                    <div><label>Büyük Sayı/Kısalt.</label><input name="ig_card<?php echo $n; ?>_num" value="<?php echo $v("ig_card{$n}_num"); ?>" placeholder="<?php echo esc_attr($dc[0]); ?>"></div>
                    <div><label>Birim</label><input name="ig_card<?php echo $n; ?>_unit" value="<?php echo $v("ig_card{$n}_unit"); ?>" placeholder="<?php echo esc_attr($dc[1]); ?>"></div>
                    <div><label>Başlık</label><input name="ig_card<?php echo $n; ?>_title" value="<?php echo $v("ig_card{$n}_title"); ?>" placeholder="<?php echo esc_attr($dc[2]); ?>"></div>
                    <div><label>Alt başlık (italik)</label><input name="ig_card<?php echo $n; ?>_sub" value="<?php echo $v("ig_card{$n}_sub"); ?>" placeholder="<?php echo esc_attr($dc[3]); ?>"></div>
                    <div><label>İçerik metni</label><textarea name="ig_card<?php echo $n; ?>_body" rows="2" placeholder="<?php echo esc_attr(mb_strimwidth($dc[4],0,60,'…')); ?>"><?php echo $t("ig_card{$n}_body"); ?></textarea></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="qpt-section">
                <h3>İstatistik Şeridi</h3>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 1 Rakam</label><input name="ig_stat1_num" value="<?php echo $v('ig_stat1_num'); ?>" placeholder="3 iş günü"></div>
                  <div><label>İstat. 1 Açıklama</label><input name="ig_stat1_label" value="<?php echo $v('ig_stat1_label'); ?>" placeholder="rapor teslim süresi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 2 Rakam</label><input name="ig_stat2_num" value="<?php echo $v('ig_stat2_num'); ?>" placeholder="48 saat"></div>
                  <div><label>İstat. 2 Açıklama</label><input name="ig_stat2_label" value="<?php echo $v('ig_stat2_label'); ?>" placeholder="teknik servis garantisi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 3 Rakam</label><input name="ig_stat3_num" value="<?php echo $v('ig_stat3_num'); ?>" placeholder="20+ yıl"></div>
                  <div><label>İstat. 3 Açıklama</label><input name="ig_stat3_label" value="<?php echo $v('ig_stat3_label'); ?>" placeholder="sektör deneyimi"></div>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Yıllık Toplantı Kutusu</h3>
                <div class="qpt-row">
                  <label>Başlık</label>
                  <input name="ig_annual_title" value="<?php echo $v('ig_annual_title'); ?>" placeholder="Yılda En Az 1 Kez — Yüz Yüze Hizmet Değerlendirmesi">
                </div>
                <div class="qpt-row">
                  <label>Metin</label>
                  <textarea name="ig_annual_body" rows="2" placeholder="Yılda en az bir kez site yönetimiyle yüz yüze veya çevrimiçi toplantı düzenliyoruz..."><?php echo $t('ig_annual_body'); ?></textarea>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Güvence İkonları (4 kutu)</h3>
                <div class="qpt-row cols-2">
                  <div><label>Güvence 1 Başlık</label><input name="ig_gv1_title" value="<?php echo esc_attr(self::get_tpl('ig_gv1_title')); ?>" placeholder="Bakanlık Onaylı"></div>
                  <div><label>Güvence 1 Alt</label><input name="ig_gv1_sub" value="<?php echo esc_attr(self::get_tpl('ig_gv1_sub')); ?>" placeholder="Yetkili Ölçüm Şirketi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>Güvence 2 Başlık</label><input name="ig_gv2_title" value="<?php echo esc_attr(self::get_tpl('ig_gv2_title')); ?>" placeholder="ISO 9001"></div>
                  <div><label>Güvence 2 Alt</label><input name="ig_gv2_sub" value="<?php echo esc_attr(self::get_tpl('ig_gv2_sub')); ?>" placeholder="Sertifikalı Hizmet Süreçleri"></div>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Harekete Geçirici (CTA) & Alt Dipnot</h3>
                <div class="qpt-row">
                  <label>CTA Başlık</label>
                  <input name="ig_cta_title" value="<?php echo $v('ig_cta_title'); ?>" placeholder="Sonraki Adım — Birlikte Başlayalım">
                </div>
                <div class="qpt-row">
                  <label>CTA Metin</label>
                  <textarea name="ig_cta_body" rows="2" placeholder="Bu sezonu sorunsuz kapatmak istiyorsanız, teklifi [GEÇERLİLİK] gün içinde onaylayarak hemen başlayabilirsiniz."><?php echo $t('ig_cta_body'); ?></textarea>
                  <p class="qpt-hint">[GEÇERLİLİK] = geçerlilik gün sayısı</p>
                </div>
                <div class="qpt-row">
                  <label>Alt dipnot</label>
                  <input name="ig_guarantee_note" value="<?php echo $v('ig_guarantee_note'); ?>" placeholder="Sözleşmeye dönüşen teklifler iptal edilemez.">
                </div>
              </div>

            </div><!-- /#qpt-ig -->

            <!-- STANDART ŞABLON -->
            <div class="qpt-panel" id="qpt-std">
              <div class="qpt-section">
                <h3>Standart Format</h3>
                <p style="color:#888;font-size:13px;">Standart kompakt format şu an şirket bilgilerini Settings'ten otomatik alır. İleri versiyonda özelleştirme buraya eklenecektir.</p>
              </div>
            </div>

            <div class="qpt-save-bar">
              <button type="submit" class="qpt-save-btn">💾 Şablonu Kaydet</button>
              <?php if ($saved): ?><span class="qpt-saved">✓ Kaydedildi</span><?php endif; ?>
            </div>
          </form>
        </div>

        <script>
        function qptAddQ() {
            var list = document.getElementById('qpt-questions-list');
            var row = document.createElement('div');
            row.className = 'qpt-q-row';
            row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:6px;';
            row.innerHTML = '<input type="text" name="ig_questions[]" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Soru metni...">'
                          + '<button type="button" class="qpb danger" onclick="qptRemoveQ(this)" style="padding:5px 8px;flex-shrink:0;">&#10005;</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
        }
        function qptRemoveQ(btn) {
            var list = document.getElementById('qpt-questions-list');
            if (list.querySelectorAll('.qpt-q-row').length > 1) {
                btn.closest('.qpt-q-row').remove();
            }
        }
        function qptTab(id, btn) {
            document.querySelectorAll('.qpt-panel').forEach(function(p){ p.classList.remove('active'); });
            document.querySelectorAll('.qpt-tab').forEach(function(b){ b.classList.remove('active'); });
            document.getElementById('qpt-'+id).classList.add('active');
            btn.classList.add('active');
        }
        </script>
        <?php
    }


}
