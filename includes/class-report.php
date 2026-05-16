<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Report {

    public static function init() {
        add_action( 'admin_menu',           [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_export_csv',[ __CLASS__, 'handle_export_csv' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Reports', 'quotepress' ),
            '📊 ' . __( 'Reports', 'quotepress' ),
            'manage_options',
            'quotepress-reports',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── Helpers ────────────────────────────────────────────── */
    private static function query_requests( $status_filter, $search, $date_from, $date_to ) {
        global $wpdb;
        $t      = QuotePress_Database::table();
        $where  = [];
        $values = [];

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

        $sql = "SELECT * FROM {$t}";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY created_at DESC';

        return $where
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

        $requests = self::query_requests( $status_filter, $search, $date_from, $date_to );

        $total_count   = count( $requests );
        $pending_count = 0;
        $quoted_count  = 0;
        $total_value   = 0.0;
        $project_groups = [];

        foreach ( $requests as $r ) {
            $qd    = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
            $grand = floatval( $qd['grand_total'] ?? 0 );
            $pname = $r->project_name ?: __( '(No project name)', 'quotepress' );

            if ( $r->status === 'pending' ) {
                $pending_count++;
            } else {
                $quoted_count++;
                $total_value += $grand;
            }

            if ( ! isset( $project_groups[ $pname ] ) ) {
                $project_groups[ $pname ] = [ 'count' => 0, 'quoted' => 0, 'value' => 0.0, 'currency' => $def_currency ];
            }
            $project_groups[ $pname ]['count']++;
            if ( $r->status === 'quoted' && $grand > 0 ) {
                $project_groups[ $pname ]['quoted']++;
                $project_groups[ $pname ]['value']   += $grand;
                $project_groups[ $pname ]['currency'] = $qd['currency'] ?? $def_currency;
            }
        }

        $colors  = QuotePress_Settings::active_theme_colors();
        $primary = $colors['primary'];
        $light   = $colors['light'];
        $border  = $colors['border'];
        $dark    = $colors['dark'];
        $nonce   = wp_create_nonce( 'qp_export_csv' );

        $csv_url = add_query_arg( [
            'action'    => 'qp_export_csv',
            '_wpnonce'  => $nonce,
            'status'    => $status_filter,
            'search'    => $search,
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ], admin_url( 'admin-ajax.php' ) );

        ?>
        <div class="wrap" style="max-width:1100px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:<?php echo esc_attr($primary); ?>;color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Reports', 'quotepress' ); ?>
        </h1>

        <style>
        .qpr-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
        .qpr-stat{background:#fff;border:1px solid <?php echo esc_attr($border); ?>;border-radius:8px;padding:16px 20px;}
        .qpr-stat .n{font-size:28px;font-weight:800;color:<?php echo esc_attr($primary); ?>;line-height:1.1;}
        .qpr-stat .n.orange{color:#e65100;}
        .qpr-stat .l{font-size:12px;color:#aaa;margin-top:4px;}
        .qpr-filters{background:#fff;border:1px solid <?php echo esc_attr($border); ?>;border-radius:8px;padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
        .qpr-filters label{font-size:11px;font-weight:700;color:#666;display:block;margin-bottom:4px;}
        .qpr-filters input,.qpr-filters select{border:1px solid #ccc;border-radius:6px;padding:7px 10px;font-size:13px;}
        .qpr-filters input:focus,.qpr-filters select:focus{outline:none;border-color:<?php echo esc_attr($primary); ?>;}
        .qpr-btn{background:<?php echo esc_attr($primary); ?>;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;line-height:1.5;}
        .qpr-btn:hover{opacity:.88;color:#fff;}
        .qpr-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qpr-btn.sec:hover{background:#f5f5f5;color:#333;}
        .qpr-card{background:#fff;border:1px solid <?php echo esc_attr($border); ?>;border-radius:8px;padding:20px 24px;margin-bottom:18px;}
        .qpr-card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:<?php echo esc_attr($primary); ?>;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid <?php echo esc_attr($light); ?>;}
        .qpr-table{width:100%;border-collapse:collapse;font-size:13px;}
        .qpr-table thead th{background:<?php echo esc_attr($light); ?>;padding:9px 12px;text-align:left;color:<?php echo esc_attr($dark); ?>;font-weight:700;border-bottom:2px solid <?php echo esc_attr($border); ?>;white-space:nowrap;}
        .qpr-table tbody td{padding:9px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle;}
        .qpr-table tbody tr:hover td{background:<?php echo esc_attr($light); ?>;}
        .qpr-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;}
        .qpr-badge.pending{background:#fff3e0;color:#e65100;}
        .qpr-badge.quoted{background:<?php echo esc_attr($light); ?>;color:<?php echo esc_attr($dark); ?>;}
        .qpr-empty{text-align:center;padding:40px;color:#ccc;font-size:14px;}
        @media(max-width:800px){.qpr-stats{grid-template-columns:1fr 1fr;}.qpr-filters{flex-direction:column;}}
        </style>

        <!-- Summary stats -->
        <div class="qpr-stats">
            <div class="qpr-stat">
                <div class="n"><?php echo $total_count; ?></div>
                <div class="l"><?php esc_html_e( 'Total Requests', 'quotepress' ); ?></div>
            </div>
            <div class="qpr-stat">
                <div class="n orange"><?php echo $pending_count; ?></div>
                <div class="l"><?php esc_html_e( 'Pending', 'quotepress' ); ?></div>
            </div>
            <div class="qpr-stat">
                <div class="n"><?php echo $quoted_count; ?></div>
                <div class="l"><?php esc_html_e( 'Quoted', 'quotepress' ); ?></div>
            </div>
            <div class="qpr-stat">
                <div class="n" style="font-size:18px;"><?php echo number_format( $total_value, 2, '.', ',' ); ?></div>
                <div class="l"><?php esc_html_e( 'Total Quoted Value', 'quotepress' ); ?> (<?php echo esc_html( $def_currency ); ?>)</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="get" class="qpr-filters">
            <input type="hidden" name="page" value="quotepress-reports">
            <div>
                <label><?php esc_html_e( 'Search', 'quotepress' ); ?></label>
                <input type="text" name="search" value="<?php echo esc_attr( $search ); ?>"
                    placeholder="<?php esc_attr_e( 'Project or company...', 'quotepress' ); ?>" style="width:200px;">
            </div>
            <div>
                <label><?php esc_html_e( 'Status', 'quotepress' ); ?></label>
                <select name="status">
                    <option value=""><?php esc_html_e( 'All', 'quotepress' ); ?></option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>><?php esc_html_e( 'Pending', 'quotepress' ); ?></option>
                    <option value="quoted"  <?php selected( $status_filter, 'quoted'  ); ?>><?php esc_html_e( 'Quoted',  'quotepress' ); ?></option>
                </select>
            </div>
            <div>
                <label><?php esc_html_e( 'From', 'quotepress' ); ?></label>
                <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
            </div>
            <div>
                <label><?php esc_html_e( 'To', 'quotepress' ); ?></label>
                <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="qpr-btn"><?php esc_html_e( 'Filter', 'quotepress' ); ?></button>
                <a href="?page=quotepress-reports" class="qpr-btn sec"><?php esc_html_e( 'Reset', 'quotepress' ); ?></a>
            </div>
        </form>

        <!-- Project name summary -->
        <?php if ( ! empty( $project_groups ) ) : ?>
        <div class="qpr-card">
            <div class="qpr-card-title"><?php esc_html_e( 'Summary by Project Name', 'quotepress' ); ?></div>
            <div style="overflow-x:auto;">
            <table class="qpr-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Project Name', 'quotepress' ); ?></th>
                        <th style="text-align:center;"><?php esc_html_e( 'Requests', 'quotepress' ); ?></th>
                        <th style="text-align:center;"><?php esc_html_e( 'Quoted', 'quotepress' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Total Quoted Value', 'quotepress' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $project_groups as $pname => $pg ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $pname ); ?></strong></td>
                        <td style="text-align:center;"><?php echo $pg['count']; ?></td>
                        <td style="text-align:center;"><?php echo $pg['quoted']; ?></td>
                        <td style="text-align:right;font-weight:600;color:<?php echo esc_attr($primary); ?>;">
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

        <!-- Full request list -->
        <div class="qpr-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                <div class="qpr-card-title" style="margin:0;border:0;padding:0;">
                    <?php esc_html_e( 'All Requests', 'quotepress' ); ?>
                    <span style="font-weight:400;color:#aaa;font-size:12px;margin-left:4px;">(<?php echo $total_count; ?>)</span>
                </div>
                <a href="<?php echo esc_url( $csv_url ); ?>" class="qpr-btn sec" style="font-size:12px;padding:6px 14px;">
                    ↓ <?php esc_html_e( 'Export CSV', 'quotepress' ); ?>
                </a>
            </div>

            <?php if ( empty( $requests ) ) : ?>
                <div class="qpr-empty"><?php esc_html_e( 'No requests found.', 'quotepress' ); ?></div>
            <?php else : ?>
            <div style="overflow-x:auto;">
            <table class="qpr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Project Name', 'quotepress' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'quotepress' ); ?></th>
                        <th><?php esc_html_e( 'Contact', 'quotepress' ); ?></th>
                        <th style="text-align:center;"><?php esc_html_e( 'Items', 'quotepress' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'quotepress' ); ?></th>
                        <th style="text-align:right;"><?php esc_html_e( 'Quoted Total', 'quotepress' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'quotepress' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $slug = QuotePress_Settings::get( 'panel_slug', 'quote-panel' );
                foreach ( $requests as $r ) :
                    $items     = json_decode( $r->items, true ) ?: [];
                    $qd        = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
                    $grand     = floatval( $qd['grand_total'] ?? 0 );
                    $currency  = $qd['currency'] ?? $def_currency;
                    $panel_url = home_url( '/' . $slug . '/?request=' . $r->id );
                ?>
                <tr>
                    <td style="color:#aaa;font-size:12px;"><?php echo $r->id; ?></td>
                    <td>
                        <a href="<?php echo esc_url( $panel_url ); ?>"
                           style="color:<?php echo esc_attr($primary); ?>;font-weight:600;text-decoration:none;">
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
                    <td style="text-align:center;font-weight:700;color:<?php echo esc_attr($primary); ?>;">
                        <?php echo count( $items ); ?>
                    </td>
                    <td>
                        <?php if ( $r->status === 'pending' ) : ?>
                            <span class="qpr-badge pending"><?php esc_html_e( 'Pending', 'quotepress' ); ?></span>
                        <?php else : ?>
                            <span class="qpr-badge quoted"><?php esc_html_e( 'Quoted', 'quotepress' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:600;color:<?php echo esc_attr($primary); ?>;">
                        <?php echo $grand > 0
                            ? number_format( $grand, 2, '.', ',' ) . ' ' . esc_html( $currency )
                            : '—'; ?>
                    </td>
                    <td style="color:#aaa;white-space:nowrap;font-size:12px;">
                        <?php echo esc_html( date_i18n( get_option('date_format'), strtotime( $r->created_at ) ) ); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        </div>
        <?php
    }

    /* ── CSV export ─────────────────────────────────────────── */
    public static function handle_export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        if ( ! check_ajax_referer( 'qp_export_csv', '_wpnonce', false ) ) {
            wp_die( 'Invalid nonce', 403 );
        }

        $status_filter = sanitize_text_field( $_GET['status']    ?? '' );
        $search        = sanitize_text_field( $_GET['search']     ?? '' );
        $date_from     = sanitize_text_field( $_GET['date_from']  ?? '' );
        $date_to       = sanitize_text_field( $_GET['date_to']    ?? '' );
        $def_currency  = QuotePress_Settings::get( 'default_currency', 'USD' );

        $requests = self::query_requests( $status_filter, $search, $date_from, $date_to );

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="quotepress-report-' . date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [
            'ID', 'Project Name', 'Company', 'Contact', 'Email', 'Phone',
            'City', 'Country', 'Items', 'Status', 'Quoted Total', 'Currency', 'Date',
        ] );

        foreach ( $requests as $r ) {
            $items     = json_decode( $r->items, true ) ?: [];
            $qd        = $r->quote_data ? ( json_decode( $r->quote_data, true ) ?: [] ) : [];
            $grand     = floatval( $qd['grand_total'] ?? 0 );
            $currency  = $qd['currency'] ?? $def_currency;
            $item_list = implode( '; ', array_map( fn($i) => ( $i['name'] ?? '' ) . ' ×' . ( $i['qty'] ?? 1 ), $items ) );

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
