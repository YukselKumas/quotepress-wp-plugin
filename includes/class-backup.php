<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Backup {

    const CRON_HOOK    = 'quotepress_daily_backup';
    const BACKUP_DIR   = 'quotepress-backups';
    const MAX_FILES    = 30; // keep last 30 daily backups

    public static function init() {
        add_action( 'admin_menu',                        [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_qp_download_backup',     [ __CLASS__, 'handle_download' ] );
        add_action( 'admin_post_qp_import_backup',       [ __CLASS__, 'handle_import' ] );
        add_action( self::CRON_HOOK,                     [ __CLASS__, 'run_scheduled_backup' ] );

        // Schedule daily backup if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Yedekleme', 'quotepress' ),
            '💾 ' . __( 'Yedekleme', 'quotepress' ),
            'manage_options',
            'quotepress-backup',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── Build backup data array ───────────────────────────── */
    public static function build_backup() {
        global $wpdb;

        $tables = [
            'requests'  => QuotePress_Database::table(),
            'projects'  => QuotePress_Database::projects_table(),
            'products'  => QuotePress_Database::products_table(),
            'variants'  => QuotePress_Database::variants_table(),
            'services'  => $wpdb->prefix . 'quotepress_services',
            'offers'    => $wpdb->prefix . 'quotepress_offers',
        ];

        $data = [
            'version'     => QP_VERSION,
            'exported_at' => gmdate( 'Y-m-d H:i:s' ),
            'site_url'    => get_site_url(),
            'tables'      => [],
            'options'     => [
                'quotepress_settings'           => get_option( 'quotepress_settings' ),
                'quotepress_templates'          => get_option( 'quotepress_templates' ),
                'quotepress_project_categories' => get_option( 'quotepress_project_categories' ),
            ],
        ];

        foreach ( $tables as $key => $table ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
            $data['tables'][ $key ] = $rows ?: [];
        }

        return $data;
    }

    /* ── Save backup to file ───────────────────────────────── */
    public static function save_to_file() {
        $upload_dir  = wp_upload_dir();
        $backup_path = trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;

        if ( ! file_exists( $backup_path ) ) {
            wp_mkdir_p( $backup_path );
            // Protect directory from direct web access
            file_put_contents( $backup_path . '/.htaccess', "Deny from all\n" );
            file_put_contents( $backup_path . '/index.php', "<?php // Silence" );
        }

        $data     = self::build_backup();
        $filename = 'quotepress-backup-' . gmdate( 'Y-m-d_H-i-s' ) . '.json';
        $filepath = $backup_path . '/' . $filename;

        file_put_contents( $filepath, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

        // Prune old backups
        $files = glob( $backup_path . '/quotepress-backup-*.json' );
        if ( is_array( $files ) && count( $files ) > self::MAX_FILES ) {
            usort( $files, function( $a, $b ) { return strcmp( $a, $b ); } );
            $to_delete = array_slice( $files, 0, count( $files ) - self::MAX_FILES );
            foreach ( $to_delete as $old ) { @unlink( $old ); }
        }

        return $filepath;
    }

    /* ── Scheduled backup ──────────────────────────────────── */
    public static function run_scheduled_backup() {
        self::save_to_file();
    }

    /* ── Download backup (admin POST) ─────────────────────── */
    public static function handle_download() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz', 403 );
        check_admin_referer( 'qp_backup_download' );

        $data = self::build_backup();
        $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="quotepress-backup-' . gmdate( 'Y-m-d_H-i-s' ) . '.json"' );
        header( 'Pragma: no-cache' );
        header( 'Content-Length: ' . strlen( $json ) );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /* ── Import backup (admin POST) ───────────────────────── */
    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz', 403 );
        check_admin_referer( 'qp_backup_import' );

        if ( empty( $_FILES['backup_file']['tmp_name'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=quotepress-backup&import_error=no_file' ) );
            exit;
        }

        $tmp  = $_FILES['backup_file']['tmp_name'];
        $json = file_get_contents( $tmp );
        $data = json_decode( $json, true );

        if ( ! is_array( $data ) || empty( $data['tables'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=quotepress-backup&import_error=invalid_json' ) );
            exit;
        }

        // Save a pre-import backup first
        self::save_to_file();

        global $wpdb;

        $table_map = [
            'requests' => QuotePress_Database::table(),
            'projects' => QuotePress_Database::projects_table(),
            'products' => QuotePress_Database::products_table(),
            'variants' => QuotePress_Database::variants_table(),
            'services' => $wpdb->prefix . 'quotepress_services',
            'offers'   => $wpdb->prefix . 'quotepress_offers',
        ];

        foreach ( $data['tables'] as $key => $rows ) {
            if ( ! isset( $table_map[ $key ] ) || ! is_array( $rows ) ) continue;
            $table = $table_map[ $key ];
            $wpdb->query( "TRUNCATE TABLE {$table}" );
            foreach ( $rows as $row ) {
                $wpdb->insert( $table, $row );
            }
        }

        // Restore options
        if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
            foreach ( $data['options'] as $opt_key => $opt_val ) {
                if ( strpos( $opt_key, 'quotepress' ) === 0 ) {
                    update_option( $opt_key, $opt_val );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=quotepress-backup&imported=1' ) );
        exit;
    }

    /* ── List stored backup files ──────────────────────────── */
    private static function list_backups() {
        $upload_dir  = wp_upload_dir();
        $backup_path = trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;
        if ( ! file_exists( $backup_path ) ) return [];
        $files = glob( $backup_path . '/quotepress-backup-*.json' );
        if ( ! is_array( $files ) ) return [];
        rsort( $files ); // newest first
        return $files;
    }

    /* ── Render admin page ─────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $colors      = QuotePress_Settings::active_theme_colors();
        $backups     = self::list_backups();
        $next_cron   = wp_next_scheduled( self::CRON_HOOK );
        $imported    = isset( $_GET['imported'] );
        $import_err  = sanitize_text_field( $_GET['import_error'] ?? '' );

        $upload_dir  = wp_upload_dir();
        $backup_path = trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR;
        ?>
        <div class="wrap" style="max-width:860px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            Yedekleme
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qp-bk-card{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:20px 24px;margin-bottom:18px;}
        .qp-bk-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid var(--qp-light);}
        .qp-bk-btn{background:var(--qp-primary);color:#fff;border:none;border-radius:6px;padding:9px 20px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;line-height:1.5;}
        .qp-bk-btn:hover{opacity:.88;color:#fff;}
        .qp-bk-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qp-bk-btn.sec:hover{background:#f5f5f5;color:#333;}
        .qp-bk-file-list{list-style:none;margin:0;padding:0;}
        .qp-bk-file{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f5f5f5;font-size:13px;}
        .qp-bk-file:last-child{border-bottom:none;}
        .qp-bk-file .name{font-weight:600;color:#555;}
        .qp-bk-file .size{font-size:11px;color:#aaa;margin-left:8px;}
        </style>

        <?php if ( $imported ) : ?>
        <div class="notice notice-success is-dismissible"><p>✓ Yedek başarıyla geri yüklendi. Lütfen sitenizi kontrol edin.</p></div>
        <?php endif; ?>
        <?php if ( $import_err ) :
            $err_msgs = [
                'no_file'      => 'Dosya seçilmedi.',
                'invalid_json' => 'Geçersiz yedek dosyası. Doğru QuotePress yedek dosyasını seçtiğinizden emin olun.',
            ];
            $msg = $err_msgs[ $import_err ] ?? 'Bilinmeyen hata.';
        ?>
        <div class="notice notice-error is-dismissible"><p>⚠ <?php echo esc_html( $msg ); ?></p></div>
        <?php endif; ?>

        <!-- Manual download -->
        <div class="qp-bk-card">
            <div class="qp-bk-title">⬇ Manuel Yedek Al</div>
            <p style="font-size:13px;color:#555;margin-bottom:14px;line-height:1.6;">
                Tüm proje, teklif, ürün verilerini ve ayarları tek bir JSON dosyasına aktarır.
                Eklenti silinse bile bu dosyadan her şeyi geri yükleyebilirsiniz.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="qp_download_backup">
                <?php wp_nonce_field( 'qp_backup_download' ); ?>
                <button type="submit" class="qp-bk-btn">⬇ Yedek Dosyasını İndir</button>
            </form>
        </div>

        <!-- Scheduled backup -->
        <div class="qp-bk-card">
            <div class="qp-bk-title">⏰ Otomatik Yedekleme</div>
            <p style="font-size:13px;color:#555;margin-bottom:10px;line-height:1.6;">
                Günlük otomatik yedekleme aktif. Son <?php echo (int) self::MAX_FILES; ?> yedek korunur, eskiler silinir.<br>
                Yedek konumu: <code><?php echo esc_html( $backup_path ); ?></code>
            </p>
            <?php if ( $next_cron ) : ?>
            <div style="font-size:12px;color:#888;">
                Sonraki otomatik yedek: <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_cron ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stored backups -->
        <div class="qp-bk-card">
            <div class="qp-bk-title">📂 Mevcut Yedekler (<?php echo count( $backups ); ?>)</div>
            <?php if ( empty( $backups ) ) : ?>
            <p style="font-size:13px;color:#ccc;">Henüz yedek dosyası yok. Yukarıdan ilk yedeği oluşturun.</p>
            <?php else : ?>
            <ul class="qp-bk-file-list">
                <?php foreach ( $backups as $file ) :
                    $fname = basename( $file );
                    $fsize = file_exists( $file ) ? size_format( filesize( $file ) ) : '?';
                    // Parse date from filename
                    preg_match( '/(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/', $fname, $m );
                    $date_str = isset( $m[1] ) ? $m[1] . ' ' . str_replace( '-', ':', $m[2] ) : '';
                ?>
                <li class="qp-bk-file">
                    <div>
                        <span class="name">📄 <?php echo esc_html( $date_str ?: $fname ); ?></span>
                        <span class="size"><?php echo esc_html( $fsize ); ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Import -->
        <div class="qp-bk-card">
            <div class="qp-bk-title">⬆ Yedekten Geri Yükle</div>
            <div style="background:#fff8e8;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;font-size:13px;color:#78350f;margin-bottom:14px;">
                ⚠ <strong>Dikkat:</strong> Geri yükleme mevcut tüm verilerin üzerine yazar.
                İşlem öncesi otomatik olarak bir yedek alınır.
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="qp_import_backup">
                <?php wp_nonce_field( 'qp_backup_import' ); ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="file" name="backup_file" accept=".json" required style="font-size:13px;">
                    <button type="submit" class="qp-bk-btn" style="background:#e65100;"
                        onclick="return confirm('Mevcut tüm veriler yedekle değiştirilecek. Emin misiniz?');">
                        ⬆ Geri Yükle
                    </button>
                </div>
                <p style="font-size:12px;color:#aaa;margin-top:8px;">Yalnızca QuotePress tarafından oluşturulan .json yedek dosyalarını kullanın.</p>
            </form>
        </div>

        </div><!-- .wrap -->
        <?php
    }
}
