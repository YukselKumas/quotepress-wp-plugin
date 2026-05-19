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
            QuotePress_Users::CAP,
            'quotepress-reports',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── Helpers ────────────────────────────────────────────── */
    private static function build_where( $status_filter, $search, $date_from, $date_to, $project_filter = '', $proj_id_filter = 0 ) {
        $where  = [];
        $values = [];
        global $wpdb;

        if ( $status_filter ) {
            $where[]  = 'status = %s';
            $values[] = $status_filter;
        }
        if ( $proj_id_filter > 0 ) {
            $where[]  = 'project_id = %d';
            $values[] = $proj_id_filter;
        } elseif ( $project_filter ) {
            $where[]  = 'project_name = %s';
            $values[] = $project_filter;
        } elseif ( $search ) {
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

        return array( $where, $values );
    }

    private static function query_requests( $status_filter, $search, $date_from, $date_to, $limit = 0, $offset = 0, $project_filter = '', $proj_id_filter = 0 ) {
        global $wpdb;
        $t = QuotePress_Database::table();
        list( $where, $values ) = self::build_where( $status_filter, $search, $date_from, $date_to, $project_filter, $proj_id_filter );

        $sql = "SELECT * FROM {$t}";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
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
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) return;

        $status_filter     = sanitize_text_field( $_GET['status']         ?? '' );
        $search            = sanitize_text_field( $_GET['search']         ?? '' );
        $date_from         = sanitize_text_field( $_GET['date_from']      ?? '' );
        $date_to           = sanitize_text_field( $_GET['date_to']        ?? '' );
        $project_filter    = sanitize_text_field( $_GET['project']        ?? '' );
        $proj_id_filter    = isset( $_GET['qp_project_id'] ) ? (int) $_GET['qp_project_id'] : 0;
        $view              = sanitize_text_field( $_GET['view']           ?? 'requests' );
        $filter_il         = sanitize_text_field( $_GET['il']             ?? '' );
        $filter_ilce       = sanitize_text_field( $_GET['ilce']           ?? '' );
        $filter_proj_status = sanitize_text_field( $_GET['proj_status']   ?? '' );
        $def_currency      = QuotePress_Settings::get( 'default_currency', 'USD' );

        $per_page_opts = array( 20, 50, 100 );
        $per_page_raw  = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 20;
        $per_page      = in_array( $per_page_raw, $per_page_opts, true ) ? $per_page_raw : 20;
        $current_page  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $offset        = ( $current_page - 1 ) * $per_page;

        // All matching records (for stats + project grouping)
        $all_requests = self::query_requests( $status_filter, $search, $date_from, $date_to, 0, 0, $project_filter, $proj_id_filter );
        $total_count  = count( $all_requests );
        $total_pages  = $total_count > 0 ? (int) ceil( $total_count / $per_page ) : 1;

        // Paginated subset for the table
        $page_requests = self::query_requests( $status_filter, $search, $date_from, $date_to, $per_page, $offset, $project_filter, $proj_id_filter );

        $pending_count  = 0;
        $quoted_count   = 0;
        $won_count      = 0;
        $lost_count     = 0;
        $total_value    = 0.0;
        $project_groups = array();

        foreach ( $all_requests as $r ) {
            $qd    = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: array() ) : array();
            $grand = floatval( isset( $qd['grand_total'] ) ? $qd['grand_total'] : 0 );
            $pname = $r->project_name ? $r->project_name : __( '(Proje adı yok)', 'quotepress' );

            switch ( $r->status ) {
                case 'pending': $pending_count++; break;
                case 'quoted':  $quoted_count++;  break;
                case 'won':     $won_count++;     break;
                case 'lost':    $lost_count++;    break;
            }

            if ( in_array( $r->status, array( 'quoted', 'won', 'lost' ), true ) && $grand > 0 ) {
                $total_value += $grand;
            }

            if ( ! isset( $project_groups[ $pname ] ) ) {
                $project_groups[ $pname ] = array(
                    'count'   => 0,
                    'pending' => 0,
                    'quoted'  => 0,
                    'won'     => 0,
                    'lost'    => 0,
                    'value'   => 0.0,
                    'currency'=> $def_currency,
                );
            }
            $project_groups[ $pname ]['count']++;
            if ( isset( $project_groups[ $pname ][ $r->status ] ) ) {
                $project_groups[ $pname ][ $r->status ]++;
            }
            if ( $grand > 0 ) {
                $project_groups[ $pname ]['value']    += $grand;
                $project_groups[ $pname ]['currency']  = isset( $qd['currency'] ) ? $qd['currency'] : $def_currency;
            }
        }

        // Sort projects by total request count desc
        uasort( $project_groups, function( $a, $b ) { return $b['count'] - $a['count']; } );

        $colors  = QuotePress_Settings::active_theme_colors();
        $nonce   = wp_create_nonce( 'qp_export_csv' );
        $slug    = QuotePress_Settings::get( 'panel_slug', 'quote-panel' );

        $base_args = array_filter( array(
            'page'        => 'quotepress-reports',
            'status'      => $status_filter,
            'search'      => $search,
            'date_from'   => $date_from,
            'date_to'     => $date_to,
            'project'     => $project_filter,
            'il'          => $filter_il,
            'ilce'        => $filter_ilce,
            'proj_status' => $filter_proj_status,
            'per_page'    => $per_page !== 20 ? $per_page : '',
        ) );

        $csv_url = add_query_arg( array_merge( $base_args, array(
            'action'   => 'qp_export_csv',
            '_wpnonce' => $nonce,
        ) ), admin_url( 'admin-ajax.php' ) );

        $badge_map = array(
            'pending' => array( 'cls' => 'pending', 'lbl' => 'Beklemede' ),
            'quoted'  => array( 'cls' => 'quoted',  'lbl' => 'Tekliflendirildi' ),
            'won'     => array( 'cls' => 'won',      'lbl' => '✓ Kabul' ),
            'lost'    => array( 'cls' => 'lost',     'lbl' => '✗ Reddedildi' ),
        );

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
        .qpr-tabs{display:flex;gap:0;margin-bottom:18px;border-bottom:2px solid var(--qp-border);}
        .qpr-tab{padding:9px 20px;font-size:13px;font-weight:600;color:#777;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer;}
        .qpr-tab:hover{color:var(--qp-primary);}
        .qpr-tab.active{color:var(--qp-primary);border-bottom-color:var(--qp-primary);}
        .qpr-filters{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
        .qpr-filters label{font-size:11px;font-weight:700;color:#666;display:block;margin-bottom:4px;}
        .qpr-filters input,.qpr-filters select{border:1px solid #ccc;border-radius:6px;padding:7px 10px;font-size:13px;}
        .qpr-filters input:focus,.qpr-filters select:focus{outline:none;border-color:var(--qp-primary);}
        .qpr-filter-badge{display:inline-flex;align-items:center;gap:6px;background:var(--qp-light);border:1px solid var(--qp-border);color:var(--qp-dark);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;margin-bottom:12px;}
        .qpr-filter-badge a{color:var(--qp-dark);text-decoration:none;font-weight:700;margin-left:2px;}
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
        .qpr-table .proj-link{color:var(--qp-primary);font-weight:700;text-decoration:none;}
        .qpr-table .proj-link:hover{text-decoration:underline;}
        .qpr-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
        .qpr-badge.pending{background:#fff3e0;color:#e65100;}
        .qpr-badge.quoted{background:var(--qp-light);color:var(--qp-dark);}
        .qpr-badge.won{background:#e8f5e9;color:#2e7d32;}
        .qpr-badge.lost{background:#fdecea;color:#c62828;}
        .qpr-mini{font-size:11px;padding:1px 7px;border-radius:8px;font-weight:600;display:inline-block;}
        .qpr-mini.pending{background:#fff3e0;color:#e65100;}
        .qpr-mini.quoted{background:var(--qp-light);color:var(--qp-dark);}
        .qpr-mini.won{background:#e8f5e9;color:#2e7d32;}
        .qpr-mini.lost{background:#fdecea;color:#c62828;}
        .qpr-empty{text-align:center;padding:40px;color:#ccc;font-size:14px;}
        .qpr-pager{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:16px;flex-wrap:wrap;}
        @media(max-width:900px){.qpr-stats{grid-template-columns:1fr 1fr;}.qpr-filters{flex-direction:column;}}
        </style>

        <!-- Özet kartlar -->
        <div class="qpr-stats">
            <div class="qpr-stat">
                <div class="n"><?php echo (int) $total_count; ?></div>
                <div class="l">Toplam Talep</div>
            </div>
            <div class="qpr-stat">
                <div class="n orange"><?php echo (int) $pending_count; ?></div>
                <div class="l">Beklemede</div>
            </div>
            <div class="qpr-stat">
                <div class="n"><?php echo (int) $quoted_count; ?></div>
                <div class="l">Tekliflendirildi</div>
            </div>
            <div class="qpr-stat">
                <div class="n green"><?php echo (int) $won_count; ?></div>
                <div class="l">✓ Kabul Edildi</div>
            </div>
            <div class="qpr-stat">
                <div class="n red"><?php echo (int) $lost_count; ?></div>
                <div class="l">✗ Reddedildi</div>
            </div>
        </div>

        <!-- Sekmeler -->
        <?php
        $tab_requests_url = add_query_arg( array_merge( $base_args, array( 'view' => 'requests' ) ), admin_url( 'admin.php' ) );
        $tab_projects_url = add_query_arg( array_merge( $base_args, array( 'view' => 'projects' ) ), admin_url( 'admin.php' ) );
        ?>
        <div class="qpr-tabs">
            <a href="<?php echo esc_url( $tab_requests_url ); ?>" class="qpr-tab <?php echo $view === 'requests' ? 'active' : ''; ?>">📋 Talepler</a>
            <a href="<?php echo esc_url( $tab_projects_url ); ?>" class="qpr-tab <?php echo $view === 'projects' ? 'active' : ''; ?>">📁 Projeler</a>
        </div>

        <?php if ( $view === 'projects' ) : ?>

        <!-- PROJELER GÖRÜNÜMÜ -->
        <?php
        global $wpdb;
        $pt          = QuotePress_Database::projects_table();
        $req_table   = QuotePress_Database::table();

        // Collect distinct il/ilce values for filter dropdowns
        $all_il   = $wpdb->get_col( "SELECT DISTINCT il   FROM {$pt} WHERE il   != '' ORDER BY il   ASC" );
        $all_ilce = $wpdb->get_col( "SELECT DISTINCT ilce FROM {$pt} WHERE ilce != '' ORDER BY ilce ASC" );

        // Build master projects query with optional location + status filter
        $proj_where  = [];
        $proj_values = [];
        if ( $filter_il ) {
            $proj_where[]  = 'il = %s';
            $proj_values[] = $filter_il;
        }
        if ( $filter_ilce ) {
            $proj_where[]  = 'ilce = %s';
            $proj_values[] = $filter_ilce;
        }
        if ( $filter_proj_status ) {
            $proj_where[]  = 'status = %s';
            $proj_values[] = $filter_proj_status;
        }
        $proj_sql = "SELECT * FROM {$pt}";
        if ( $proj_where ) {
            $proj_sql .= ' WHERE ' . implode( ' AND ', $proj_where );
        }
        $proj_sql .= ' ORDER BY created_at DESC';
        $master_projects = $proj_values
            ? $wpdb->get_results( $wpdb->prepare( $proj_sql, $proj_values ) )
            : $wpdb->get_results( $proj_sql );

        // Build stats per project_id: count, status breakdown, value, last request date, last request status
        $proj_rows = $wpdb->get_results(
            "SELECT project_id, status, quote_data, created_at FROM {$req_table} WHERE project_id IS NOT NULL"
        );
        $pstats = [];
        foreach ( $proj_rows as $pr ) {
            $pid = (int) $pr->project_id;
            if ( ! isset( $pstats[ $pid ] ) ) {
                $pstats[ $pid ] = [
                    'count'       => 0, 'pending' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0,
                    'value'       => 0.0, 'currency' => $def_currency,
                    'last_date'   => '', 'last_status' => '',
                ];
            }
            $pstats[ $pid ]['count']++;
            if ( isset( $pstats[ $pid ][ $pr->status ] ) ) $pstats[ $pid ][ $pr->status ]++;
            if ( $pr->quote_data ) {
                $qd    = json_decode( $pr->quote_data, true ) ?: [];
                $grand = floatval( $qd['grand_total'] ?? 0 );
                if ( $grand > 0 ) {
                    $pstats[ $pid ]['value']   += $grand;
                    $pstats[ $pid ]['currency'] = $qd['currency'] ?? $def_currency;
                }
            }
            if ( $pr->created_at > $pstats[ $pid ]['last_date'] ) {
                $pstats[ $pid ]['last_date']   = $pr->created_at;
                $pstats[ $pid ]['last_status'] = $pr->status;
            }
        }

        $unassigned_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$req_table} WHERE project_id IS NULL" );

        $proj_status_labels = [
            'active'    => [ 'lbl' => 'Aktif',      'cls' => 'won' ],
            'completed' => [ 'lbl' => 'Tamamlandı', 'cls' => 'quoted' ],
            'cancelled' => [ 'lbl' => 'İptal',      'cls' => 'lost' ],
        ];
        $req_status_labels = [
            'pending' => [ 'lbl' => 'Beklemede',      'cls' => 'pending' ],
            'quoted'  => [ 'lbl' => 'Tekliflendirildi','cls' => 'quoted' ],
            'won'     => [ 'lbl' => '✓ Kabul',        'cls' => 'won' ],
            'lost'    => [ 'lbl' => '✗ Reddedildi',   'cls' => 'lost' ],
        ];
        ?>

        <!-- Proje filtreleri -->
        <form method="get" class="qpr-filters">
            <input type="hidden" name="page"  value="quotepress-reports">
            <input type="hidden" name="view"  value="projects">
            <div>
                <label>İl</label>
                <select name="il">
                    <option value="">Tümü</option>
                    <?php foreach ( $all_il as $il_val ) : ?>
                    <option value="<?php echo esc_attr( $il_val ); ?>" <?php selected( $filter_il, $il_val ); ?>><?php echo esc_html( $il_val ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>İlçe</label>
                <select name="ilce">
                    <option value="">Tümü</option>
                    <?php foreach ( $all_ilce as $ilce_val ) : ?>
                    <option value="<?php echo esc_attr( $ilce_val ); ?>" <?php selected( $filter_ilce, $ilce_val ); ?>><?php echo esc_html( $ilce_val ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Proje Durumu</label>
                <select name="proj_status">
                    <option value="">Tümü</option>
                    <option value="active"    <?php selected( $filter_proj_status, 'active' ); ?>>Aktif</option>
                    <option value="completed" <?php selected( $filter_proj_status, 'completed' ); ?>>Tamamlandı</option>
                    <option value="cancelled" <?php selected( $filter_proj_status, 'cancelled' ); ?>>İptal Edildi</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="qpr-btn">Filtrele</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-reports&view=projects' ) ); ?>" class="qpr-btn sec">Temizle</a>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-projects' ) ); ?>" class="qpr-btn" style="margin-left:auto;font-size:12px;padding:7px 16px;">+ Proje Yönet</a>
        </form>

        <?php
        // Active filter badges
        $clear_il_url   = add_query_arg( array_merge( $base_args, [ 'view' => 'projects', 'il'          => '' ] ), admin_url( 'admin.php' ) );
        $clear_ilce_url = add_query_arg( array_merge( $base_args, [ 'view' => 'projects', 'ilce'        => '' ] ), admin_url( 'admin.php' ) );
        $clear_pst_url  = add_query_arg( array_merge( $base_args, [ 'view' => 'projects', 'proj_status' => '' ] ), admin_url( 'admin.php' ) );
        if ( $filter_il || $filter_ilce || $filter_proj_status ) : ?>
        <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ( $filter_il ) : ?>
            <span class="qpr-filter-badge">📍 İl: <?php echo esc_html( $filter_il ); ?> <a href="<?php echo esc_url( $clear_il_url ); ?>">✕</a></span>
            <?php endif; ?>
            <?php if ( $filter_ilce ) : ?>
            <span class="qpr-filter-badge">📍 İlçe: <?php echo esc_html( $filter_ilce ); ?> <a href="<?php echo esc_url( $clear_ilce_url ); ?>">✕</a></span>
            <?php endif; ?>
            <?php if ( $filter_proj_status ) :
                $fpl = $proj_status_labels[ $filter_proj_status ] ?? [ 'lbl' => $filter_proj_status ];
            ?>
            <span class="qpr-filter-badge">Durum: <?php echo esc_html( $fpl['lbl'] ); ?> <a href="<?php echo esc_url( $clear_pst_url ); ?>">✕</a></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="qpr-card">
            <div class="qpr-card-title">
                Projeler
                <span style="font-weight:400;color:#aaa;font-size:12px;margin-left:4px;">(<?php echo count( $master_projects ); ?>)</span>
            </div>

            <?php if ( empty( $master_projects ) ) : ?>
                <div class="qpr-empty">
                    Proje bulunamadı.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-projects' ) ); ?>" style="color:var(--qp-primary);">Proje ekle →</a>
                </div>
            <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="qpr-table">
                <thead><tr>
                    <th>Proje Adı</th>
                    <th>Firma</th>
                    <th>İlçe / İl</th>
                    <th>Proje Durumu</th>
                    <th style="text-align:center;">Talep</th>
                    <th style="text-align:center;">Beklemede</th>
                    <th style="text-align:center;">Tekliflendi</th>
                    <th style="text-align:center;">✓ Kabul</th>
                    <th style="text-align:center;">✗ Red</th>
                    <th style="text-align:right;">Toplam Tutar</th>
                    <th>Son Talep</th>
                    <th>Son Teklif Durumu</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $master_projects as $mp ) :
                    $mpid    = (int) $mp->id;
                    $mst     = $pstats[ $mpid ] ?? [ 'count' => 0, 'pending' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0, 'currency' => $def_currency, 'last_date' => '', 'last_status' => '' ];
                    $slbl    = $proj_status_labels[ $mp->status ] ?? $proj_status_labels['active'];
                    $last_st = $mst['last_status'] ? ( $req_status_labels[ $mst['last_status'] ] ?? null ) : null;
                    $req_url = add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'requests', 'qp_project_id' => $mpid ], admin_url( 'admin.php' ) );
                    $loc     = trim( ( $mp->ilce ?? '' ) . ( ( $mp->ilce ?? '' ) && ( $mp->il ?? '' ) ? ' / ' : '' ) . ( $mp->il ?? '' ) );

                    $ilce_url = $mp->ilce ? add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'projects', 'ilce' => $mp->ilce ], admin_url( 'admin.php' ) ) : '';
                    $il_url   = $mp->il   ? add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'projects', 'il'   => $mp->il   ], admin_url( 'admin.php' ) ) : '';
                ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( $req_url ); ?>" class="proj-link"><?php echo esc_html( $mp->name ); ?></a>
                        <?php if ( $mp->category ) : ?>
                        <br><span style="font-size:11px;background:#f0f0f0;padding:1px 5px;border-radius:3px;color:#666;"><?php echo esc_html( $mp->category ); ?></span>
                        <?php endif; ?>
                        <?php if ( $mp->deadline ) : ?>
                        <br><span style="font-size:11px;color:#aaa;">📅 <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $mp->deadline ) ) ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#666;font-size:12px;"><?php echo esc_html( $mp->client_name ?: '—' ); ?></td>
                    <td style="font-size:12px;white-space:nowrap;">
                        <?php if ( $mp->ilce ) : ?>
                        <a href="<?php echo esc_url( $ilce_url ); ?>" style="color:var(--qp-primary);text-decoration:none;font-weight:600;"><?php echo esc_html( $mp->ilce ); ?></a>
                        <?php endif; ?>
                        <?php if ( $mp->ilce && $mp->il ) echo ' / '; ?>
                        <?php if ( $mp->il ) : ?>
                        <a href="<?php echo esc_url( $il_url ); ?>" style="color:#555;text-decoration:none;"><?php echo esc_html( $mp->il ); ?></a>
                        <?php endif; ?>
                        <?php if ( ! $loc ) echo '<span style="color:#ccc;">—</span>'; ?>
                    </td>
                    <td><span class="qpr-mini <?php echo esc_attr( $slbl['cls'] ); ?>"><?php echo esc_html( $slbl['lbl'] ); ?></span></td>
                    <td style="text-align:center;font-weight:700;"><?php echo (int) $mst['count']; ?></td>
                    <td style="text-align:center;"><?php echo $mst['pending'] > 0 ? '<span class="qpr-mini pending">' . (int) $mst['pending'] . '</span>' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo $mst['quoted']  > 0 ? '<span class="qpr-mini quoted">'  . (int) $mst['quoted']  . '</span>' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo $mst['won']     > 0 ? '<span class="qpr-mini won">'     . (int) $mst['won']     . '</span>' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo $mst['lost']    > 0 ? '<span class="qpr-mini lost">'    . (int) $mst['lost']    . '</span>' : '—'; ?></td>
                    <td style="text-align:right;font-weight:600;color:var(--qp-primary);">
                        <?php echo $mst['value'] > 0 ? esc_html( number_format( $mst['value'], 2, '.', ',' ) . ' ' . $mst['currency'] ) : '—'; ?>
                    </td>
                    <td style="color:#aaa;white-space:nowrap;font-size:12px;">
                        <?php echo $mst['last_date'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $mst['last_date'] ) ) ) : '—'; ?>
                    </td>
                    <td>
                        <?php if ( $last_st ) : ?>
                        <span class="qpr-mini <?php echo esc_attr( $last_st['cls'] ); ?>"><?php echo esc_html( $last_st['lbl'] ); ?></span>
                        <?php else : ?>
                        <span style="color:#ccc;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if ( $unassigned_count > 0 ) : ?>
            <div style="margin-top:14px;padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;color:#78350f;">
                ⚠ <strong><?php echo (int) $unassigned_count; ?></strong> talep henüz bir projeye bağlanmamış.
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'requests' ], admin_url( 'admin.php' ) ) ); ?>" style="color:var(--qp-primary);margin-left:6px;">Talepleri Gör →</a>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php else : ?>

        <!-- TALEPLER GÖRÜNÜMÜ -->

        <!-- Filtreler -->
        <form method="get" class="qpr-filters">
            <input type="hidden" name="page" value="quotepress-reports">
            <input type="hidden" name="view" value="requests">
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
                    <option value="<?php echo (int) $opt; ?>" <?php selected( $per_page, $opt ); ?>><?php echo (int) $opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="qpr-btn">Filtrele</button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-reports&view=requests' ) ); ?>" class="qpr-btn sec">Temizle</a>
            </div>
        </form>

        <?php
        $active_proj_label = '';
        if ( $proj_id_filter > 0 ) {
            $fp = QuotePress_Database::get_project( $proj_id_filter );
            if ( $fp ) $active_proj_label = $fp->name;
        } elseif ( $project_filter ) {
            $active_proj_label = $project_filter;
        }
        ?>
        <?php if ( $active_proj_label ) : ?>
        <div style="margin-bottom:12px;">
            <span class="qpr-filter-badge">
                📁 Proje: <?php echo esc_html( $active_proj_label ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-reports&view=requests' ) ); ?>">✕</a>
            </span>
        </div>
        <?php endif; ?>

        <!-- Tüm talepler (sayfalı) -->
        <div class="qpr-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <div class="qpr-card-title" style="margin:0;border:0;padding:0;">
                    Tüm Talepler
                    <span style="font-weight:400;color:#aaa;font-size:12px;margin-left:4px;">(<?php echo (int) $total_count; ?>)</span>
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
                <?php foreach ( $page_requests as $r ) :
                    $r_items   = json_decode( $r->items, true ) ?: array();
                    $qd        = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: array() ) : array();
                    $grand     = floatval( isset( $qd['grand_total'] ) ? $qd['grand_total'] : 0 );
                    $currency  = isset( $qd['currency'] ) ? $qd['currency'] : $def_currency;
                    $panel_url = home_url( '/' . $slug . '/?request=' . $r->id );
                    $r_project = $r->project_name ? $r->project_name : '';
                    $proj_url  = $r_project
                        ? add_query_arg( array( 'page' => 'quotepress-reports', 'project' => $r_project, 'view' => 'requests' ), admin_url( 'admin.php' ) )
                        : '';
                    $bm = isset( $badge_map[ $r->status ] ) ? $badge_map[ $r->status ] : $badge_map['pending'];
                ?>
                <tr>
                    <td style="color:#aaa;font-size:12px;"><?php echo (int) $r->id; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $panel_url ); ?>"
                           style="color:var(--qp-primary);font-weight:600;text-decoration:none;">
                            <?php echo esc_html( $r->project_name ? $r->project_name : '—' ); ?>
                        </a>
                        <?php if ( $r_project && ! $project_filter ) : ?>
                        <br><a href="<?php echo esc_url( $proj_url ); ?>" style="font-size:11px;color:#aaa;text-decoration:none;">📁 <?php echo esc_html( $r_project ); ?></a>
                        <?php endif; ?>
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
                    <td>
                        <span class="qpr-badge <?php echo esc_attr( $bm['cls'] ); ?>">
                            <?php echo esc_html( $bm['lbl'] ); ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-weight:600;color:var(--qp-primary);">
                        <?php echo $grand > 0
                            ? esc_html( number_format( $grand, 2, '.', ',' ) ) . ' ' . esc_html( $currency )
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
                $prev_url = add_query_arg( array_merge( $base_args, array( 'paged' => $current_page - 1 ) ), admin_url( 'admin.php' ) );
                $next_url = add_query_arg( array_merge( $base_args, array( 'paged' => $current_page + 1 ) ), admin_url( 'admin.php' ) );
            ?>
            <div class="qpr-pager">
                <?php if ( $current_page > 1 ) : ?>
                <a href="<?php echo esc_url( $prev_url ); ?>" class="qpr-btn sec" style="padding:6px 14px;font-size:12px;">← Önceki</a>
                <?php endif; ?>
                <span style="font-size:13px;color:#666;padding:6px 4px;"><?php echo (int) $current_page; ?> / <?php echo (int) $total_pages; ?> sayfa</span>
                <?php if ( $current_page < $total_pages ) : ?>
                <a href="<?php echo esc_url( $next_url ); ?>" class="qpr-btn sec" style="padding:6px 14px;font-size:12px;">Sonraki →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php endif; // end view === requests ?>

        </div>
        <?php
    }

    /* ── CSV export (tüm filtrelenmiş sonuçlar) ─────────────── */
    public static function handle_export_csv() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) {
            wp_die( 'Yetkisiz', 403 );
        }
        if ( ! check_ajax_referer( 'qp_export_csv', '_wpnonce', false ) ) {
            wp_die( 'Geçersiz nonce', 403 );
        }

        $status_filter  = sanitize_text_field( isset( $_GET['status'] )    ? $_GET['status']    : '' );
        $search         = sanitize_text_field( isset( $_GET['search'] )    ? $_GET['search']    : '' );
        $date_from      = sanitize_text_field( isset( $_GET['date_from'] ) ? $_GET['date_from'] : '' );
        $date_to        = sanitize_text_field( isset( $_GET['date_to'] )   ? $_GET['date_to']   : '' );
        $project_filter = sanitize_text_field( isset( $_GET['project'] )   ? $_GET['project']   : '' );
        $def_currency   = QuotePress_Settings::get( 'default_currency', 'USD' );

        $requests = self::query_requests( $status_filter, $search, $date_from, $date_to, 0, 0, $project_filter );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="quotepress-rapor-' . date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel uyumluluğu)

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array(
            'ID', 'Proje Adı', 'Firma', 'İlçe (Proje)', 'İl (Proje)', 'İletişim Kişisi', 'E-posta', 'Telefon',
            'Ürünler', 'Talep Durumu', 'Teklif Tutarı', 'Para Birimi', 'Tarih',
        ) );

        // Build project location lookup
        global $wpdb;
        $pt           = QuotePress_Database::projects_table();
        $proj_locs    = $wpdb->get_results( "SELECT id, ilce, il FROM {$pt}", OBJECT_K );
        $status_map   = [ 'pending' => 'Beklemede', 'quoted' => 'Tekliflendirildi', 'won' => 'Kabul Edildi', 'lost' => 'Reddedildi' ];

        foreach ( $requests as $r ) {
            $r_items   = json_decode( $r->items, true ) ?: [];
            $qd        = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
            $grand     = floatval( $qd['grand_total'] ?? 0 );
            $currency  = $qd['currency'] ?? $def_currency;
            $item_list = implode( '; ', array_map(
                fn( $i ) => ( $i['name'] ?? '' ) . ' x' . ( $i['qty'] ?? 1 ),
                $r_items
            ) );
            $proj_loc  = $r->project_id && isset( $proj_locs[ $r->project_id ] ) ? $proj_locs[ $r->project_id ] : null;

            fputcsv( $out, [
                $r->id,
                $r->project_name,
                $r->company_name,
                $proj_loc ? $proj_loc->ilce : '',
                $proj_loc ? $proj_loc->il   : '',
                $r->contact_name,
                $r->email,
                $r->phone,
                $item_list,
                $status_map[ $r->status ] ?? $r->status,
                $grand > 0 ? number_format( $grand, 2, '.', ',' ) : '',
                $grand > 0 ? $currency : '',
                date_i18n( 'Y-m-d H:i', strtotime( $r->created_at ) ),
            ] );
        }

        fclose( $out );
        exit;
    }
}
