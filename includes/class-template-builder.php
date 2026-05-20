<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Template_Builder {

    const NONCE   = 'qp_template_nonce';
    const OPT_KEY = 'qp_template_';   // appended with 'ig' or 'std'

    /* ── Default sections ───────────────────────────────────────── */

    public static function default_sections( $type ) {
        if ( $type === 'ig' ) {
            return [
                [ 'key' => 'header',           'label' => 'Başlık & Logo',              'hidden' => false, 'locked' => true  ],
                [ 'key' => 'client_info',       'label' => 'Müşteri Bilgileri',          'hidden' => false, 'locked' => false ],
                [ 'key' => 'badge_strip',       'label' => 'Rozet Şeridi',               'hidden' => false, 'locked' => false ],
                [ 'key' => 'intro_letter',      'label' => 'Giriş Mektubu',             'hidden' => false, 'locked' => false ],
                [ 'key' => 'comparison_table',  'label' => 'Karşılaştırma Tablosu',     'hidden' => false, 'locked' => false ],
                [ 'key' => 'commitment_cards',  'label' => 'Taahhüt Kartları',          'hidden' => false, 'locked' => false ],
                [ 'key' => 'stats_strip',       'label' => 'İstatistik Şeridi',         'hidden' => false, 'locked' => false ],
                [ 'key' => 'annual_meeting',    'label' => 'Yıllık Toplantı Kutusu',   'hidden' => false, 'locked' => false ],
                [ 'key' => 'price_table',       'label' => 'Fiyat Tablosu',             'hidden' => false, 'locked' => true  ],
                [ 'key' => 'guarantees',        'label' => 'Güvence İkonları',          'hidden' => false, 'locked' => false ],
                [ 'key' => 'cta',               'label' => 'Harekete Geçirici (CTA)',   'hidden' => false, 'locked' => false ],
                [ 'key' => 'footer_contact',    'label' => 'Alt İletişim Bloğu',        'hidden' => false, 'locked' => true  ],
            ];
        }

        // std
        return [
            [ 'key' => 'header',        'label' => 'Başlık & Logo',       'hidden' => false, 'locked' => true  ],
            [ 'key' => 'client_info',   'label' => 'Müşteri Bilgileri',   'hidden' => false, 'locked' => false ],
            [ 'key' => 'price_table',   'label' => 'Fiyat Tablosu',       'hidden' => false, 'locked' => true  ],
            [ 'key' => 'note_block',    'label' => 'Teklif Notu',         'hidden' => false, 'locked' => false ],
            [ 'key' => 'price_terms',   'label' => 'Fiyat Koşulları',     'hidden' => false, 'locked' => false ],
            [ 'key' => 'footer_contact','label' => 'Alt İletişim Bloğu',  'hidden' => false, 'locked' => true  ],
        ];
    }

    /* ── Get / Save ─────────────────────────────────────────────── */

    public static function get_template( $type ) {
        $saved = get_option( self::OPT_KEY . $type, null );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            return self::default_sections( $type );
        }
        return $saved;
    }

    public static function save_template( $type, $data ) {
        if ( ! in_array( $type, [ 'ig', 'std' ], true ) ) {
            return false;
        }
        update_option( self::OPT_KEY . $type, $data, false );
        return true;
    }

    /* ── Hooks ──────────────────────────────────────────────────── */

    public static function init() {
        add_action( 'wp_ajax_qp_save_template',  [ __CLASS__, 'ajax_save'      ] );
        add_action( 'admin_menu',                [ __CLASS__, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts',     [ __CLASS__, 'enqueue'        ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            'Şablon Düzenleyici',
            '🎛 Şablon Düzenleyici',
            'manage_options',
            'quotepress-template-builder',
            [ __CLASS__, 'render' ]
        );
    }

    public static function enqueue( $hook ) {
        if ( strpos( $hook, 'quotepress-template-builder' ) === false ) {
            return;
        }
        wp_enqueue_script(
            'qp-template-builder',
            QP_URL . 'assets/template-builder.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            QP_VERSION,
            true
        );
        wp_localize_script(
            'qp-template-builder',
            'qpTemplateData',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::NONCE ),
                'ig'       => self::get_template( 'ig' ),
                'std'      => self::get_template( 'std' ),
            ]
        );
    }

    /* ── AJAX: save ─────────────────────────────────────────────── */

    public static function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Yetersiz yetki.' ], 403 );
        }

        if ( ! check_ajax_referer( self::NONCE, '_wpnonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Güvenlik doğrulaması başarısız.' ], 403 );
        }

        $type = sanitize_key( wp_unslash( $_POST['template_type'] ?? '' ) );
        if ( ! in_array( $type, [ 'ig', 'std' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Geçersiz şablon türü.' ] );
        }

        $raw = wp_unslash( $_POST['sections'] ?? '' );
        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            // Empty sections → fall back to defaults
            self::save_template( $type, self::default_sections( $type ) );
            wp_send_json_success( [ 'message' => 'Varsayılana sıfırlandı.' ] );
        }

        $sanitized = [];
        foreach ( $decoded as $sec ) {
            if ( ! is_array( $sec ) ) continue;
            $sanitized[] = [
                'key'    => sanitize_key( $sec['key']    ?? '' ),
                'label'  => sanitize_text_field( $sec['label']  ?? '' ),
                'hidden' => (bool) ( $sec['hidden'] ?? false ),
                'locked' => (bool) ( $sec['locked'] ?? false ),
            ];
        }

        self::save_template( $type, $sanitized );
        wp_send_json_success( [ 'message' => 'Kaydedildi.' ] );
    }

    /* ── Admin page render ──────────────────────────────────────── */

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $c       = QuotePress_Settings::active_theme_colors();
        $primary = esc_attr( $c['primary'] );
        $dark    = esc_attr( $c['dark'] );
        $light   = esc_attr( $c['light'] );
        $border  = esc_attr( $c['border'] );
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:<?php echo $primary; ?>;color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            🎛 Şablon Düzenleyici
        </h1>

        <p style="color:#666;font-size:13px;margin-bottom:20px;">
            PDF bölümlerinin sırasını sürükle-bırak ile düzenleyin. Kilitli bölümler zorunludur ve gizlenemez.
            Diğer bölümleri göz simgesiyle gizleyebilir, satırları sürükleyerek yeniden sıralayabilirsiniz.
        </p>

        <style>
        :root {
            --qptb-primary: <?php echo $primary; ?>;
            --qptb-dark:    <?php echo $dark; ?>;
            --qptb-light:   <?php echo $light; ?>;
            --qptb-border:  <?php echo $border; ?>;
        }
        .qptb-wrap { max-width: 760px; }
        .qptb-tabs { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:24px; }
        .qptb-tab  { padding:9px 20px; font-size:13px; font-weight:600; cursor:pointer;
                     border:none; background:none; color:#888;
                     border-bottom:3px solid transparent; margin-bottom:-2px;
                     transition:color .15s, border-color .15s; }
        .qptb-tab.active  { color:var(--qptb-primary); border-bottom-color:var(--qptb-primary); }
        .qptb-panel       { display:none; }
        .qptb-panel.active{ display:block; }

        .qptb-list { list-style:none; margin:0; padding:0; }
        .qptb-item {
            display:flex; align-items:center; gap:10px;
            background:#fff; border:1px solid #e2e8f0; border-radius:8px;
            padding:10px 14px; margin-bottom:8px; cursor:grab;
            transition:box-shadow .15s, opacity .15s;
            user-select:none;
        }
        .qptb-item:active { cursor:grabbing; }
        .qptb-item.ui-sortable-helper { box-shadow:0 6px 24px rgba(0,0,0,.14); z-index:9999; }
        .qptb-item.ui-sortable-placeholder { border:2px dashed var(--qptb-border); background:var(--qptb-light); visibility:visible !important; }

        .qptb-item.hidden-sec { opacity:.55; }
        .qptb-item.hidden-sec .qptb-label { text-decoration:line-through; color:#aaa; }

        .qptb-handle { font-size:18px; color:#bbb; cursor:grab; flex-shrink:0; line-height:1; }
        .qptb-num    { min-width:24px; height:24px; border-radius:50%;
                       background:var(--qptb-primary); color:#fff;
                       font-size:12px; font-weight:700; text-align:center; line-height:24px;
                       flex-shrink:0; }
        .qptb-label  { flex:1; font-size:13px; font-weight:600; color:#333; }
        .qptb-locked { background:#f1f5f9; border:1px solid #cbd5e1; border-radius:4px;
                       padding:2px 8px; font-size:10px; font-weight:700; color:#64748b;
                       text-transform:uppercase; letter-spacing:.05em; flex-shrink:0; }
        .qptb-vis-btn { background:none; border:1px solid #e2e8f0; border-radius:6px;
                        padding:4px 10px; font-size:14px; cursor:pointer; flex-shrink:0;
                        transition:background .12s, border-color .12s; line-height:1; }
        .qptb-vis-btn:hover { background:var(--qptb-light); border-color:var(--qptb-primary); }

        .qptb-actions { display:flex; gap:10px; align-items:center; margin-top:20px; }
        .qptb-save  { background:var(--qptb-primary); color:#fff; border:none; border-radius:7px;
                      padding:10px 26px; font-size:14px; font-weight:700; cursor:pointer;
                      transition:opacity .15s; }
        .qptb-save:hover { opacity:.85; }
        .qptb-reset { background:#fff; color:#888; border:1px solid #d1d5db; border-radius:7px;
                      padding:10px 20px; font-size:13px; font-weight:600; cursor:pointer;
                      transition:background .12s; }
        .qptb-reset:hover { background:#f8f8f8; }
        .qptb-msg   { font-size:13px; font-weight:600; color:#16a34a; display:none; }

        .qptb-info  { background:var(--qptb-light); border:1px solid var(--qptb-border);
                      border-radius:8px; padding:13px 17px; font-size:12px;
                      color:var(--qptb-dark); margin-bottom:20px; line-height:1.7; }
        .qptb-info strong { color:var(--qptb-primary); }
        </style>

        <div class="qptb-wrap">

            <div class="qptb-info">
                <strong>Kilitli bölümler</strong> zorunludur ve gizlenemez (başlık, fiyat tablosu, alt iletişim).
                Diğer bölümleri <strong>👁 göz simgesiyle</strong> PDF'ten gizleyebilirsiniz.
                Sırayı değiştirmek için satırları <strong>sürükleyip bırakın</strong>.
            </div>

            <!-- Tabs -->
            <div class="qptb-tabs">
                <button type="button" class="qptb-tab active"
                        onclick="qptbTab('ig', this)">🔥 Isı Gider Paylaşım</button>
                <button type="button" class="qptb-tab"
                        onclick="qptbTab('std', this)">📄 Standart Kompakt</button>
            </div>

            <!-- IG Panel -->
            <div class="qptb-panel active" id="qptb-ig">
                <ul class="qptb-list" id="qptb-list-ig"></ul>
                <div class="qptb-actions">
                    <button type="button" class="qptb-save"  onclick="qptbSave('ig')">💾 Kaydet</button>
                    <button type="button" class="qptb-reset" onclick="qptbReset('ig')">↺ Sıfırla</button>
                    <span class="qptb-msg" id="qptb-msg-ig">✓ Kaydedildi</span>
                </div>
            </div>

            <!-- STD Panel -->
            <div class="qptb-panel" id="qptb-std">
                <ul class="qptb-list" id="qptb-list-std"></ul>
                <div class="qptb-actions">
                    <button type="button" class="qptb-save"  onclick="qptbSave('std')">💾 Kaydet</button>
                    <button type="button" class="qptb-reset" onclick="qptbReset('std')">↺ Sıfırla</button>
                    <span class="qptb-msg" id="qptb-msg-std">✓ Kaydedildi</span>
                </div>
            </div>

        </div><!-- /.qptb-wrap -->
        </div><!-- /.wrap -->
        <?php
    }
}
