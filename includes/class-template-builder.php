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
        add_action( 'wp_ajax_qp_save_template', [ __CLASS__, 'ajax_save' ] );
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

}
