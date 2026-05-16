<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Report {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_export_csv', [ __CLASS__, 'handle_export_csv' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Raporlar', 'quotepress' ),
            '📊 ' . __( 'Raporlar', 'quotepress' ),
            'manage_options',
            'quotepress-reports',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── Helpers ────────────────────────────────────────────── */
    private static function build_where( $status_filter, $search, $date_from, $date_to ) {
        $where  = [];
        $values = [];
        global $wpdb;

        if ( $status_filter ) {
            $where[]  = 'status = %s';
            $values[] = $status_filter;
        }
        if ( $search ) {
            $where[]  = '(project_name LIKE %s OR company_name LIKE %s OR contact_name LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }
        if ( $date_from ) {
            $where[]  = 'DATE(created_at) >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where[]  = 'DATE(created_at) <= %s';
            $values[] = $date_to;
        }

        return [ $where, $values ];
    }

    private static function count_requests( $status_filter, $search, $date_from, $date_to ) {
        global $wpdb;
        $t = QuotePress_Database::table();
        [ $where, $values ] = self::build_where( $status_filter, $search, $date_from, $date_to );

        $sql = "SELECT COUNT(*) FROM {$t}";
        if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );

        return $values
            ? (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) )
            : (int) $wpdb->get_var( $sql );
    }

    private static function query_requests( $status_filter, $search, $date_from, $date_to, $limit = 0, $offset = 0 ) {
        global $wpdb;
        $t = QuotePress_Database::table();
        [ $where, $values ] = self::build_where( $status_filter, $search, $date_from, $date_to );

        $sql = "SELECT * FROM {$t}";
        if ( $where ) $sql .= ' WHERE ' . implode( ' AND ', $where );
        $sql .= ' ORDER BY created_at DESC';

        if ( $limit > 0 ) {
            $sql      .= ' LIMIT %d OFFSET %d';
            $values[]  = $limit;
            $values[]  = $offset;
        }

        return $values
            ? $wpdb->get_results( $wpdb->prepare( $sql, $values ) )
            : $wpdb->get_results( $sql );
    }

    /* ── Render ─────────────────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $status_filter = sanitize_text_field( $_GET['status']    ?? '' );
        $search        = sanitize_text_field( $_GET['search']     ?? '' );
        $date_from     = sanitize_text_field( $_GET['date_from']  ?? '' );
        $date_to       = sanitize_text_field( $_GET['date_to']    ?? '' );
        $def_currency  = QuotePress_Settings::get( 'default_currency', 'USD' );

        $per_page_opts = [ 20, 50, 100 ];
        $per_page      = in_array( (int) ( $_GET['per_page'] ?? 20 ), $per_page_opts, true ) ? (int) $_GET['per_page'] : 20;
        $current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset        = ( $current_page - 1 ) * $per_page;

        // All matching (for stats + project grouping)
        $all_requests  = self::query_requests( $status_filter, $search, $date_from, $date_to );
        $total_count   = count( $all_requests );
        $total_pages   = (int) ceil( $total_count / $per_page );

        // Paginated subset (for table)
        $page_requests = self::query_requests( $status_filter, $search, $date_from, $date_to, $per_page, $offset );

        $pending_count  = 0;
        $quoted_count   = 0;
        $won_count      = 0;
        $lost_count     = 0;
        $total_value    = 0.0;
        $project_groups = [];

        foreach ( $all_requests as $r ) {
            $qd    = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
            $grand = floatval( $qd['grand_total'] ?? 0 );
            $pname = $r->project_name ?: __( '(Proje adı yok)', 'quotepress' );

            switch ( $r->status ) {
                case 'pending': $pending_count++; break;
                case 'quoted':  $quoted_count++;  break;
                case 'won':     $won_count++;     break;
                case 'lost':    $lost_count++;    break;
            }

            if ( in_array( $r->status, [ 'quoted', 'won', 'lost' ], true ) && $grand > 0 ) {
                $total_value += $grand;
            }

            if ( ! isset( $project_groups[ $pname ] ) ) {
                $project_groups[ $pname ] = [ 'count' => 0, 'quoted' => 0, 'value' => 0.0, 'currency' => $def_currency ];
            }
            $project_groups[ $pname ]['count']++;
            if ( $grand > 0 ) {
                $project_groups[ $pname ]['quoted']++;
                $project_groups[ $pname ]['value']   += $grand;
                $project_groups[ $pname ]['currency'] = $qd['currency'] ?? $def_currency;
            }
        }

        $colors  = QuotePress_Settings::active_theme_colors();
        $nonce   = wp_create_nonce( 'qp_export_csv' );

        $base_args = array_filter( [
            'page'      => 'quotepress-reports',
            'status'    => $status_filter,
            'search'    => $search,
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'per_page'  => $per_page !== 20 ? $per_page : '',
        ] );

        $csv_url = add_query_arg( array_merge( $base_args, [
            'action'   => 'qp_export_csv',
            '_wpnonce' => $nonce,
        ] ), admin_url( 'admin-ajax.php' ) );

        $badge_map = [
            'pending' => [ 'cls' => 'pending', 'lbl' => 'Beklemede' ],
            'quoted'  => [ 'cls' => 'quoted',  'lbl' => 'Tekliflendirildi' ],
            'won'     => [ 'cls' => 'won',      'lbl' => '✓ Kabul' ],
            'lost'    => [ 'cls' => 'lost',     'lbl' => '✗ Reddedildi' ],
        ];
        $badge = fn( $s ) =>
            '<span class="qpr-badge ' . ( $badge_map[$s]['cls'] ?? 'pending' ) . '">'
            . esc_html( $badge_map[$s]['lbl'] ?? $s ) . '</span>';

        ?>
        <div class="wrap" style="max-width:1100px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Raporlar', 'quotepress' ); ?>
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qpr-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:22px;}
        .qpr-stat{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:14px 16px;}
        .qpr-stat .n{font-size:26px;font-weight:800;color:var(--qp-primary);line-height:1.1;}
        .qpr-stat .n.orange{color:#e65100;}
        .qpr-stat .n.green{color:#2e7d32;}
        .qpr-stat .n.red{color:#c62828;}
        .qpr-stat .l{font-size:11px;color:#aaa;margin-top:4px;}
        .qpr-filters{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
        .qpr-filters label{font-size:11px;font-weight:700;color:#666;display:block;margin-bottom:4px;}
        .qpr-filters input,.qpr-filters select{border:1px solid #ccc;border-radius:6px;padding:7px 10px;font-size:13px;}
        .qpr-filters input:focus,.qpr-filters select:focus{outline:none;border-color:var(--qp-primary);}
        .qpr-btn{background:var(--qp-primary);color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;line-height:1.5;}
        .qpr-btn:hover{opacity:.88;color:#fff;}
        .qpr-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qpr-btn.sec:hover{background:#f5f5f5;color:#333;}
        .qpr-card{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:20px 24px;margin-bottom:18px;}
        .qpr-card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid var(--qp-light);}
        .qpr-table{width:100%;border-collapse:collapse;font-size:13px;}
        .qpr-table thead th{background:var(--qp-light);padding:9px 12px;text-align:left;color:var(--qp-dark);font-weight:700;border-bottom:2px solid var(--qp-border);white-space:nowrap;}
        .qpr-table tbody td{padding:9px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle;}
        .qpr-table tbody tr:hover td{background:var(--qp-light);}
        .qpr-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
        .qpr-badge.pending{background:#fff3e0;color:#e65100;}
        .qpr-badge.quoted{background:var(--qp-light);color:var(--qp-dark);}
        .qpr-badge.won{background:#e8f5e9;color:#2e7d32;}
        .qpr-badge.lost{background:#fdecea;color:#c62828;}
        .qpr-empty{text-align:center;padding:40px;color:#ccc;font-size:14px;}
        .qpr-pager{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;flex-wrap:wrap;}
        @media(max-width:900px){.qpr-stats{grid-template-columns:1fr 1fr;}.qpr-filters{flex-direction:column;}}
        </style>

        <!-- Özet kartlar -->
        <div class="qpr-stats">
            <div class="qpr-stat">
                <div class="n"><?php echo $total_count; ?></div>
                <div class="l">Toplam Talep</div>
            </div>
            <div class="qpr-stat">
                <div class="n orange"><?php echo $pending_count; ?></div>
                <div class="l">Beklemede</div>
            </div>
            <div class="qpr-stat">
                <div class="n"><?php echo $quoted_count; ?></div>
                <div class="l">Tekliflendirildi</div>
            </div>
            <div class="qpr-stat">
                <div class="n green"><?php echo $won_count; ?></div>
                <div class="l">✓ Kabul Edildi</div>
            </div>
            <div class="qpr-stat">
                <div class="n red"><?php echo $lost_count; ?></div>
                <div class="l">✗ Reddedildi</div>
            </div>
        </div>

        <!-- Filtreler -->
        <form method="get" class="qpr-filters">
            <input type="hidden" name="page" value="quotepress-reports">
            <div>
                <label>Ara</label>
                <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>"
                    placeholder="Proje veya firma..." style="width:200px;">
            </div>
            <div>
                <label>Durum</label>
                <select name="status">
                    <option value="">Tümü</option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>>Beklemede</option>
                    <option value="quoted"  <?php selected( $status_filter, 'quoted'  ); ?>>Tekliflendirildi</option>
                    <option value="won"     <?php selected( $status_filter, 'won'     ); ?>>Kabul Edildi</option>
                    <option value="lost"    <?php selected( $status_filter, 'lost'    ); ?>>Reddedildi</option>
                </select>
            </div>
            <div>
                <label>Başlangıç Tarihi</label>
                <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
            </div>
            <div>
                <label>Bitiş Tarihi</label>
                <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
            </div>
            <div>
                <label>Sayfa Başına</label>
                <select name="per_page" onchange="this.form.submit()">
                    <?php foreach ( $per_page_opts as $opt ) : ?>
                    <option value="<?php echo $opt; ?>" <?php selected( $per_page, $opt ); ?>><?php echo $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="qpr-btn">Filtrele</button>
                <a href="?page=quotepress-reports" class="qpr-btn sec">Temizle</a>
            </div>
        </form>

        <!-- Proje özeti -->
        <?php if ( ! empty( $project_groups ) ) : ?>
        <div class="qpr-card">
            <div class="qpr-card-title">Proje Adına Göre Özet</div>
            <div style="overflow-x:auto;">
            <table class="qpr-table">
                <thead><tr>
                    <th>Proje Adı</th>
                    <th style="text-align:center;">Talepler</th>
                    <th style="text-align:center;">Tekliflendi</th>
                    <th style="text-align:right;">Toplam Tutar</th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $project_groups as $pname => $pg ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $pname ); ?></strong></td>
                        <td style="text-align:center;"><?php echo $pg['count']; ?></td>
                        <td style="text-align:center;"><?php echo $pg['quoted']; ?></td>
                        <td style="text-align:right;font-weight:600;color:var(--qp-primary);">
                            <?php echo $pg['value'] > 0
                                ? number_format( $pg['value'], 2, '.', ',' ) . ' ' . esc_html( $pg['currency'] )
                                : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tüm talepler (sayfalı) -->
        <div class="qpr-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <div class="qpr-card-title" style="margin:0;border:0;padding:0;">
                    Tüm Talepler
                    <span style="font-weight:400;color:#aaa;font-size:12px;margin-left:4px;">(<?php echo $total_count; ?>)</span>
                </div>
                <a href="<?php echo esc_url( $csv_url ); ?>" class="qpr-btn sec" style="font-size:12px;padding:6px 14px;">
                    ↓ CSV İndir
                </a>
            </div>

            <?php if ( empty( $all_requests ) ) : ?>
                <div class="qpr-empty">Talep bulunamadı.</div>
            <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="qpr-table">
                <thead><tr>
                    <th>#</th>
                    <th>Proje Adı</th>
                    <th>Firma</th>
                    <th>İletişim</th>
                    <th style="text-align:center;">Ürünler</th>
                    <th>Durum</th>
                    <th style="text-align:right;">Teklif Tutarı</th>
                    <th>Tarih</th>
                </tr></thead>
                <tbody>
                <?php
                $slug = QuotePress_Settings::get( 'panel_slug', 'quote-panel' );
                foreach ( $page_requests as $r ) :
                    $r_items  = json_decode( $r->items, true ) ?: [];
                    $qd       = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
                    $grand    = floatval( $qd['grand_total'] ?? 0 );
                    $currency = $qd['currency'] ?? $def_currency;
                    $panel_url = home_url( '/' . $slug . '/?request=' . $r->id );
                ?>
                <tr>
                    <td style="color:#aaa;font-size:12px;"><?php echo $r->id; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $panel_url ); ?>"
                           style="color:var(--qp-primary);font-weight:600;text-decoration:none;">
                            <?php echo esc_html( $r->project_name ?: '—' ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( $r->company_name ); ?></td>
                    <td>
                        <?php echo esc_html( $r->contact_name ); ?>
                        <?php if ( $r->email ) : ?>
                        <br><span style="font-size:11px;color:#aaa;"><?php echo esc_html( $r->email ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;color:var(--qp-primary);">
                        <?php echo count( $r_items ); ?>
                    </td>
                    <td><?php echo $badge( $r->status ); ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--qp-primary);">
                        <?php echo $grand > 0
                            ? number_format( $grand, 2, '.', ',' ) . ' ' . esc_html( $currency )
                            : '—'; ?>
                    </td>
                    <td style="color:#aaa;white-space:nowrap;font-size:12px;">
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $r->created_at ) ) ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ( $total_pages > 1 ) :
                $prev_url = add_query_arg( array_merge( $base_args, [ 'paged' => $current_page - 1 ] ), admin_url( 'admin.php' ) );
                $next_url = add_query_arg( array_merge( $base_args, [ 'paged' => $current_page + 1 ] ), admin_url( 'admin.php' ) );
            ?>
            <div class="qpr-pager">
                <?php if ( $current_page > 1 ) : ?>
                <a href="<?php echo esc_url( $prev_url ); ?>" class="qpr-btn sec" style="padding:6px 14px;font-size:12px;">← Önceki</a>
                <?php endif; ?>
                <span style="font-size:13px;color:#666;padding:6px 4px;"><?php echo $current_page; ?> / <?php echo $total_pages; ?> sayfa</span>
                <?php if ( $current_page < $total_pages ) : ?>
                <a href="<?php echo esc_url( $next_url ); ?>" class="qpr-btn sec" style="padding:6px 14px;font-size:12px;">Sonraki →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        </div>
        <?php
    }

    /* ── CSV export (tüm filtrelenmiş sonuçlar) ─────────────── */
    public static function handle_export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz', 403 );
        }
        if ( ! check_ajax_referer( 'qp_export_csv', '_wpnonce', false ) ) {
            wp_die( 'Geçersiz nonce', 403 );
        }

        $status_filter = sanitize_text_field( $_GET['status']    ?? '' );
        $search        = sanitize_text_field( $_GET['search']     ?? '' );
        $date_from     = sanitize_text_field( $_GET['date_from']  ?? '' );
        $date_to       = sanitize_text_field( $_GET['date_to']    ?? '' );
        $def_currency  = QuotePress_Settings::get( 'default_currency', 'USD' );

        $requests = self::query_requests( $status_filter, $search, $date_from, $date_to );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="quotepress-rapor-' . date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel uyumluluğu)

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [
            'ID', 'Proje Adı', 'Firma', 'İletişim Kişisi', 'E-posta', 'Telefon',
            'Şehir', 'Ülke', 'Ürünler', 'Durum', 'Teklif Tutarı', 'Para Birimi', 'Tarih',
        ] );

        foreach ( $requests as $r ) {
            $r_items   = json_decode( $r->items, true ) ?: [];
            $qd        = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
            $grand     = floatval( $qd['grand_total'] ?? 0 );
            $currency  = $qd['currency'] ?? $def_currency;
            $item_list = implode( '; ', array_map( fn( $i ) => ( $i['name'] ?? '' ) . ' ×' . ( $i['qty'] ?? 1 ), $r_items ) );

            fputcsv( $out, [
                $r->id,
                $r->project_name,
                $r->company_name,
                $r->contact_name,
                $r->email,
                $r->phone,
                $r->city,
                $r->country,
                $item_list,
                $r->status,
                $grand > 0 ? number_format( $grand, 2, '.', ',' ) : '',
                $grand > 0 ? $currency : '',
                date_i18n( 'Y-m-d H:i', strtotime( $r->created_at ) ),
            ] );
        }

        fclose( $out );
        exit;
    }
}
