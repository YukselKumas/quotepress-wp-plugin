<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Projects {

    const CAT_OPTION = 'quotepress_project_categories';

    public static function init() {
        add_action( 'admin_menu',                    [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_save_project',       [ __CLASS__, 'handle_save' ] );
        add_action( 'wp_ajax_qp_delete_project',     [ __CLASS__, 'handle_delete' ] );
        add_action( 'wp_ajax_qp_assign_project',     [ __CLASS__, 'handle_assign' ] );
        add_action( 'wp_ajax_qp_save_proj_cats',     [ __CLASS__, 'handle_save_cats' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Projeler', 'quotepress' ),
            '📁 ' . __( 'Projeler', 'quotepress' ),
            'manage_options',
            'quotepress-projects',
            [ __CLASS__, 'render' ]
        );
    }

    public static function get_categories() {
        $cats = get_option( self::CAT_OPTION );
        if ( ! is_array( $cats ) || empty( $cats ) ) {
            return [ 'Firma', 'Site Yönetimi' ];
        }
        return $cats;
    }

    /* ── AJAX: save project ────────────────────────────────── */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_projects_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id          = intval( $_POST['project_id'] ?? 0 );
        $name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) { wp_send_json_error( 'name_required' ); }

        $status     = sanitize_text_field( $_POST['status'] ?? 'active' );
        if ( ! in_array( $status, [ 'active', 'completed', 'cancelled' ], true ) ) $status = 'active';

        $deadline   = sanitize_text_field( $_POST['deadline'] ?? '' );
        $budget_raw = $_POST['budget'] ?? '';

        // Contacts JSON
        $contacts_raw = wp_unslash( $_POST['contacts'] ?? '[]' );
        $contacts     = json_decode( $contacts_raw, true );
        if ( ! is_array( $contacts ) ) $contacts = [];
        foreach ( $contacts as &$c ) {
            $c['name']   = sanitize_text_field( $c['name']   ?? '' );
            $c['title']  = sanitize_text_field( $c['title']  ?? '' );
            $c['email']  = sanitize_email(      $c['email']  ?? '' );
            $c['phone']  = sanitize_text_field( $c['phone']  ?? '' );
            $c['mobile'] = sanitize_text_field( $c['mobile'] ?? '' );
        }
        unset( $c );

        $data = [
            'name'        => $name,
            'category'    => sanitize_text_field( wp_unslash( $_POST['category']    ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'client_name' => sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) ),
            'address'     => sanitize_textarea_field( wp_unslash( $_POST['address']     ?? '' ) ),
            'tax_office'  => sanitize_text_field( wp_unslash( $_POST['tax_office']  ?? '' ) ),
            'tax_number'  => sanitize_text_field( wp_unslash( $_POST['tax_number']  ?? '' ) ),
            'contacts'    => wp_json_encode( $contacts, JSON_UNESCAPED_UNICODE ),
            'status'      => $status,
            'deadline'    => $deadline ?: null,
            'budget'      => $budget_raw !== '' ? floatval( $budget_raw ) : null,
        ];

        if ( $id ) {
            QuotePress_Database::update_project( $id, $data );
            wp_send_json_success( [ 'action' => 'updated', 'id' => $id ] );
        } else {
            $new_id = QuotePress_Database::insert_project( $data );
            wp_send_json_success( [ 'action' => 'created', 'id' => $new_id ] );
        }
    }

    /* ── AJAX: delete project ──────────────────────────────── */
    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_projects_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id = intval( $_POST['project_id'] ?? 0 );
        if ( ! $id ) { wp_send_json_error( 'invalid_id' ); }
        QuotePress_Database::delete_project( $id );
        wp_send_json_success( 'deleted' );
    }

    /* ── AJAX: assign request to project ───────────────────── */
    public static function handle_assign() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_panel_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $request_id = intval( $_POST['request_id'] ?? 0 );
        $project_id = intval( $_POST['project_id'] ?? 0 );
        if ( ! $request_id ) { wp_send_json_error( 'invalid_request' ); }

        QuotePress_Database::update( $request_id, [
            'project_id' => $project_id > 0 ? $project_id : null,
        ] );

        $project_name = '';
        if ( $project_id > 0 ) {
            $proj = QuotePress_Database::get_project( $project_id );
            if ( $proj ) $project_name = $proj->name;
        }
        wp_send_json_success( [ 'project_name' => $project_name ] );
    }

    /* ── AJAX: save project categories ────────────────────── */
    public static function handle_save_cats() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_projects_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $cats_raw = wp_unslash( $_POST['categories'] ?? '[]' );
        $cats     = json_decode( $cats_raw, true );
        if ( ! is_array( $cats ) ) $cats = [];
        $cats = array_values( array_filter( array_map( 'sanitize_text_field', $cats ) ) );
        update_option( self::CAT_OPTION, $cats );
        wp_send_json_success( $cats );
    }

    /* ── Render admin page ─────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $colors       = QuotePress_Settings::active_theme_colors();
        $nonce        = wp_create_nonce( 'qp_projects_nonce' );
        $projects     = QuotePress_Database::get_projects();
        $categories   = self::get_categories();
        $def_currency = QuotePress_Settings::get( 'default_currency', 'USD' );

        // Per-project stats
        $stats = [];
        global $wpdb;
        $t     = QuotePress_Database::table();
        $rows  = $wpdb->get_results( "SELECT project_id, status, quote_data FROM {$t} WHERE project_id IS NOT NULL" );
        foreach ( $rows as $r ) {
            $pid = (int) $r->project_id;
            if ( ! isset( $stats[ $pid ] ) ) {
                $stats[ $pid ] = [ 'count' => 0, 'pending' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0, 'currency' => $def_currency ];
            }
            $stats[ $pid ]['count']++;
            if ( isset( $stats[ $pid ][ $r->status ] ) ) $stats[ $pid ][ $r->status ]++;
            if ( $r->quote_data ) {
                $qd    = json_decode( $r->quote_data, true ) ?: [];
                $grand = floatval( $qd['grand_total'] ?? 0 );
                if ( $grand > 0 ) {
                    $stats[ $pid ]['value']   += $grand;
                    $stats[ $pid ]['currency'] = $qd['currency'] ?? $def_currency;
                }
            }
        }

        $status_labels = [
            'active'    => [ 'lbl' => 'Aktif',        'cls' => 'active' ],
            'completed' => [ 'lbl' => 'Tamamlandı',   'cls' => 'completed' ],
            'cancelled' => [ 'lbl' => 'İptal Edildi', 'cls' => 'cancelled' ],
        ];
        ?>
        <div class="wrap" style="max-width:1140px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Projeler', 'quotepress' ); ?>
            <button class="qp-proj-btn" style="margin-left:auto;" onclick="qpOpenForm(0)">+ Yeni Proje</button>
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qp-proj-btn{background:var(--qp-primary);color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;line-height:1.5;}
        .qp-proj-btn:hover{opacity:.88;}
        .qp-proj-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qp-proj-btn.sec:hover{background:#f5f5f5;}
        .qp-proj-btn.sm{padding:5px 12px;font-size:12px;}
        .qp-proj-btn.danger{background:#fdecea;color:#c62828;border:1px solid #ef9a9a;}
        .qp-proj-btn.danger:hover{background:#ffcdd2;}

        /* Category bar */
        .qp-cat-bar{background:#fff;border:1px solid var(--qp-border);border-radius:8px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;}
        .qp-cat-bar .label{font-size:12px;font-weight:700;color:#666;margin-right:4px;}
        .qp-cat-chip{display:inline-flex;align-items:center;gap:4px;background:var(--qp-light);border:1px solid var(--qp-border);border-radius:20px;padding:3px 8px 3px 12px;font-size:12px;font-weight:600;color:var(--qp-dark);}
        .qp-cat-chip button{background:none;border:none;cursor:pointer;color:var(--qp-primary);font-size:14px;line-height:1;padding:0 1px;opacity:.7;}
        .qp-cat-chip button:hover{opacity:1;}
        .qp-cat-add{display:flex;gap:6px;}
        .qp-cat-add input{padding:4px 9px;border:1px solid #ddd;border-radius:6px;font-size:12px;width:150px;}
        .qp-cat-add input:focus{outline:none;border-color:var(--qp-primary);}

        /* Project cards */
        .qp-proj-card{background:#fff;border:1px solid var(--qp-border);border-radius:8px;margin-bottom:12px;overflow:hidden;}
        .qp-proj-head{display:flex;align-items:center;gap:10px;padding:13px 18px;cursor:pointer;user-select:none;}
        .qp-proj-head:hover{background:var(--qp-light);}
        .qp-proj-name{font-size:15px;font-weight:700;color:var(--qp-dark);flex:1;}
        .qp-proj-cat{font-size:11px;background:#f0f0f0;border-radius:4px;padding:1px 7px;color:#666;font-weight:600;}
        .qp-proj-meta{font-size:12px;color:#aaa;flex-shrink:0;}
        .qp-proj-body{border-top:1px solid var(--qp-border);padding:16px 18px;display:none;}
        .qp-proj-body.open{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .qp-proj-col{}
        .qp-proj-kv{display:grid;grid-template-columns:110px 1fr;gap:4px 10px;font-size:13px;margin-bottom:10px;}
        .qp-proj-kv .k{color:#aaa;font-size:12px;padding-top:1px;}
        .qp-proj-kv .v{color:#222;font-weight:500;}
        .qp-proj-stats{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
        .qp-proj-stat{background:var(--qp-light);border-radius:6px;padding:7px 12px;font-size:12px;text-align:center;min-width:60px;}
        .qp-proj-stat .n{font-size:19px;font-weight:800;color:var(--qp-primary);line-height:1.2;}
        .qp-proj-stat .l{color:#888;margin-top:1px;}
        .qp-proj-status{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;}
        .qp-proj-status.active{background:#e8f5e9;color:#2e7d32;}
        .qp-proj-status.completed{background:var(--qp-light);color:var(--qp-dark);}
        .qp-proj-status.cancelled{background:#fdecea;color:#c62828;}

        /* Contacts */
        .qp-contacts-list{border:1px solid var(--qp-border);border-radius:6px;overflow:hidden;margin-bottom:10px;}
        .qp-contact-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;padding:10px 12px;font-size:13px;border-bottom:1px solid #f5f5f5;}
        .qp-contact-row:last-child{border-bottom:none;}
        .qp-contact-row .cn{font-weight:700;color:var(--qp-dark);}
        .qp-contact-row .ct{font-size:11px;color:#aaa;}

        /* Modal */
        #qpModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
        #qpModal.open{display:flex;}
        .qp-modal-box{background:#fff;border-radius:10px;padding:28px 30px;width:680px;max-width:96vw;max-height:92vh;overflow-y:auto;}
        .qp-modal-box h2{font-size:16px;font-weight:700;margin-bottom:18px;color:var(--qp-dark);}
        .qp-modal-section{margin-bottom:16px;}
        .qp-modal-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);border-bottom:2px solid var(--qp-light);padding-bottom:6px;margin-bottom:12px;}
        .qp-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .qp-form-row{margin-bottom:0;}
        .qp-form-row label{display:block;font-size:11px;font-weight:700;color:#666;margin-bottom:4px;}
        .qp-form-row input,.qp-form-row textarea,.qp-form-row select{width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;}
        .qp-form-row input:focus,.qp-form-row textarea:focus,.qp-form-row select:focus{outline:none;border-color:var(--qp-primary);}
        .qp-form-row textarea{resize:vertical;min-height:62px;}
        .qp-form-full{grid-column:1/-1;}
        .qp-modal-actions{display:flex;gap:10px;margin-top:20px;}

        /* Contact rows in modal */
        .qp-contact-form-row{background:#f9f9f9;border:1px solid #e8e8e8;border-radius:6px;padding:10px 12px;margin-bottom:8px;}
        .qp-contact-form-grid{display:grid;grid-template-columns:2fr 1.5fr 2fr 1fr 1fr auto;gap:6px;align-items:end;}
        .qp-contact-form-grid label{font-size:10px;color:#aaa;display:block;margin-bottom:3px;font-weight:600;}
        .qp-contact-form-grid input{padding:6px 8px;border:1px solid #ddd;border-radius:5px;font-size:12px;width:100%;}
        .qp-empty-state{text-align:center;padding:60px 20px;color:#ccc;font-size:14px;}
        </style>

        <!-- Category management bar -->
        <div class="qp-cat-bar">
            <span class="label">📂 Proje Kategorileri:</span>
            <div id="qpCatChips" style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php foreach ( $categories as $cat ) : ?>
                <span class="qp-cat-chip" data-cat="<?php echo esc_attr( $cat ); ?>">
                    <?php echo esc_html( $cat ); ?>
                    <button type="button" onclick="qpRemoveCat(this)" title="Kaldır">×</button>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="qp-cat-add">
                <input type="text" id="qpNewCat" placeholder="Yeni kategori..." maxlength="60"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();qpAddCat();}">
                <button class="qp-proj-btn sm" onclick="qpAddCat()">+ Ekle</button>
            </div>
        </div>

        <?php if ( empty( $projects ) ) : ?>
        <div class="qp-empty-state">
            <div style="font-size:48px;margin-bottom:12px;">📁</div>
            <p>Henüz proje tanımlanmamış.</p>
            <p style="margin-top:8px;font-size:13px;color:#bbb;">Yeni Proje butonuna tıklayarak başlayın.</p>
        </div>
        <?php else : ?>

        <?php foreach ( $projects as $p ) :
            $pid     = (int) $p->id;
            $pst     = $stats[ $pid ] ?? [ 'count' => 0, 'pending' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0, 'currency' => $def_currency ];
            $slbl    = $status_labels[ $p->status ] ?? $status_labels['active'];
            $contacts = $p->contacts ? ( json_decode( $p->contacts, true ) ?: [] ) : [];
            $req_url  = add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'requests', 'qp_project_id' => $pid ], admin_url( 'admin.php' ) );
        ?>
        <div class="qp-proj-card" id="qp-proj-<?php echo $pid; ?>">
            <div class="qp-proj-head" onclick="qpToggle(<?php echo $pid; ?>)">
                <span class="qp-proj-status <?php echo esc_attr( $slbl['cls'] ); ?>"><?php echo esc_html( $slbl['lbl'] ); ?></span>
                <?php if ( $p->category ) : ?>
                <span class="qp-proj-cat"><?php echo esc_html( $p->category ); ?></span>
                <?php endif; ?>
                <div class="qp-proj-name"><?php echo esc_html( $p->name ); ?></div>
                <?php if ( $p->client_name ) : ?>
                <div class="qp-proj-meta"><?php echo esc_html( $p->client_name ); ?></div>
                <?php endif; ?>
                <div class="qp-proj-meta" style="font-weight:700;color:var(--qp-primary);"><?php echo (int) $pst['count']; ?> talep</div>
                <span style="color:#ccc;font-size:18px;margin-left:4px;" id="qp-arrow-<?php echo $pid; ?>">▸</span>
            </div>
            <div class="qp-proj-body" id="qp-proj-body-<?php echo $pid; ?>">
                <!-- Left column -->
                <div class="qp-proj-col">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);margin-bottom:8px;">📊 İstatistikler</div>
                    <div class="qp-proj-stats">
                        <div class="qp-proj-stat"><div class="n"><?php echo (int) $pst['count']; ?></div><div class="l">Toplam</div></div>
                        <?php if ( $pst['pending'] > 0 ) : ?><div class="qp-proj-stat"><div class="n" style="color:#e65100"><?php echo (int) $pst['pending']; ?></div><div class="l">Beklemede</div></div><?php endif; ?>
                        <?php if ( $pst['quoted'] > 0 ) : ?><div class="qp-proj-stat"><div class="n"><?php echo (int) $pst['quoted']; ?></div><div class="l">Tekliflendi</div></div><?php endif; ?>
                        <?php if ( $pst['won'] > 0 ) : ?><div class="qp-proj-stat"><div class="n" style="color:#2e7d32"><?php echo (int) $pst['won']; ?></div><div class="l">✓ Kabul</div></div><?php endif; ?>
                        <?php if ( $pst['lost'] > 0 ) : ?><div class="qp-proj-stat"><div class="n" style="color:#c62828"><?php echo (int) $pst['lost']; ?></div><div class="l">✗ Red</div></div><?php endif; ?>
                        <?php if ( $pst['value'] > 0 ) : ?><div class="qp-proj-stat"><div class="n" style="font-size:14px;"><?php echo esc_html( number_format( $pst['value'], 0, ',', '.' ) . ' ' . $pst['currency'] ); ?></div><div class="l">Tutar</div></div><?php endif; ?>
                    </div>

                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);margin-bottom:8px;margin-top:4px;">🏢 Proje Bilgileri</div>
                    <div class="qp-proj-kv">
                        <?php if ( $p->client_name ) : ?><span class="k">Firma</span><span class="v"><?php echo esc_html( $p->client_name ); ?></span><?php endif; ?>
                        <?php if ( $p->address ) : ?><span class="k">Adres</span><span class="v" style="white-space:pre-line;"><?php echo esc_html( $p->address ); ?></span><?php endif; ?>
                        <?php if ( $p->tax_office ) : ?><span class="k">Vergi Dairesi</span><span class="v"><?php echo esc_html( $p->tax_office ); ?></span><?php endif; ?>
                        <?php if ( $p->tax_number ) : ?><span class="k">Vergi No</span><span class="v"><?php echo esc_html( $p->tax_number ); ?></span><?php endif; ?>
                        <?php if ( $p->deadline ) : ?><span class="k">Termin</span><span class="v"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->deadline ) ) ); ?></span><?php endif; ?>
                        <?php if ( $p->budget ) : ?><span class="k">Hedef Bütçe</span><span class="v"><?php echo esc_html( number_format( (float) $p->budget, 2, ',', '.' ) . ' ' . $def_currency ); ?></span><?php endif; ?>
                        <?php if ( $p->description ) : ?><span class="k">Açıklama</span><span class="v" style="white-space:pre-line;"><?php echo nl2br( esc_html( $p->description ) ); ?></span><?php endif; ?>
                    </div>
                </div>

                <!-- Right column: Contacts -->
                <div class="qp-proj-col">
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);margin-bottom:8px;">👥 Yetkili Kişiler</div>
                    <?php if ( empty( $contacts ) ) : ?>
                    <div style="font-size:13px;color:#ccc;padding:10px 0;">Henüz yetkili kişi eklenmemiş.</div>
                    <?php else : ?>
                    <div class="qp-contacts-list">
                        <?php foreach ( $contacts as $c ) :
                            if ( ! trim( $c['name'] ?? '' ) ) continue; ?>
                        <div class="qp-contact-row">
                            <div>
                                <div class="cn"><?php echo esc_html( $c['name'] ); ?></div>
                                <?php if ( $c['title'] ?? '' ) : ?><div class="ct"><?php echo esc_html( $c['title'] ); ?></div><?php endif; ?>
                            </div>
                            <div style="font-size:12px;color:#555;">
                                <?php if ( $c['email'] ?? '' ) echo '<div>✉ ' . esc_html( $c['email'] ) . '</div>'; ?>
                            </div>
                            <div style="font-size:12px;color:#555;">
                                <?php if ( $c['phone'] ?? '' ) echo '<div>📞 ' . esc_html( $c['phone'] ) . '</div>'; ?>
                                <?php if ( $c['mobile'] ?? '' ) echo '<div>📱 ' . esc_html( $c['mobile'] ) . '</div>'; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions row (full width) -->
                <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;padding-top:4px;border-top:1px solid var(--qp-border);">
                    <a href="<?php echo esc_url( $req_url ); ?>" class="qp-proj-btn sec sm" style="text-decoration:none;">📋 Talepleri Gör</a>
                    <button class="qp-proj-btn sec sm" onclick="qpOpenForm(<?php echo $pid; ?>)">✏ Düzenle</button>
                    <button class="qp-proj-btn danger sm" onclick="qpDeleteProject(<?php echo $pid; ?>)">🗑 Sil</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
        </div><!-- .wrap -->

        <!-- ─── Project Modal ─────────────────────────────── -->
        <div id="qpModal">
            <div class="qp-modal-box">
                <h2 id="qpModalTitle">Yeni Proje</h2>
                <input type="hidden" id="qpFormId" value="0">

                <!-- Basic info -->
                <div class="qp-modal-section">
                    <div class="qp-modal-section-title">📋 Temel Bilgiler</div>
                    <div class="qp-form-grid">
                        <div class="qp-form-row qp-form-full">
                            <label>Proje Adı <span style="color:#c62828;">*</span></label>
                            <input type="text" id="qpFormName" placeholder="Proje adını girin...">
                        </div>
                        <div class="qp-form-row">
                            <label>Kategori</label>
                            <select id="qpFormCategory">
                                <option value="">— Seçiniz —</option>
                                <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $cat ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qp-form-row">
                            <label>Durum</label>
                            <select id="qpFormStatus">
                                <option value="active">Aktif</option>
                                <option value="completed">Tamamlandı</option>
                                <option value="cancelled">İptal Edildi</option>
                            </select>
                        </div>
                        <div class="qp-form-row">
                            <label>Termin Tarihi</label>
                            <input type="date" id="qpFormDeadline">
                        </div>
                        <div class="qp-form-row">
                            <label>Hedef Bütçe (<?php echo esc_html( $def_currency ); ?>)</label>
                            <input type="number" id="qpFormBudget" placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div class="qp-form-row qp-form-full">
                            <label>Açıklama / Not</label>
                            <textarea id="qpFormDesc" placeholder="Proje hakkında kısa not..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Client info -->
                <div class="qp-modal-section">
                    <div class="qp-modal-section-title">🏢 Firma / Müşteri Bilgileri</div>
                    <div class="qp-form-grid">
                        <div class="qp-form-row">
                            <label>Firma Adı</label>
                            <input type="text" id="qpFormClient" placeholder="Müşteri firma adı">
                        </div>
                        <div class="qp-form-row"></div>
                        <div class="qp-form-row qp-form-full">
                            <label>Adres</label>
                            <textarea id="qpFormAddress" placeholder="Sokak, mahalle, ilçe, şehir..." rows="2"></textarea>
                        </div>
                        <div class="qp-form-row">
                            <label>Vergi Dairesi</label>
                            <input type="text" id="qpFormTaxOffice" placeholder="">
                        </div>
                        <div class="qp-form-row">
                            <label>Vergi Numarası</label>
                            <input type="text" id="qpFormTaxNumber" placeholder="">
                        </div>
                    </div>
                </div>

                <!-- Contacts -->
                <div class="qp-modal-section">
                    <div class="qp-modal-section-title" style="display:flex;align-items:center;justify-content:space-between;">
                        <span>👥 Yetkili Kişiler</span>
                        <button type="button" class="qp-proj-btn sm" onclick="qpAddContact()" style="font-size:11px;padding:3px 10px;">+ Kişi Ekle</button>
                    </div>
                    <div id="qpContactRows"></div>
                    <div id="qpNoContacts" style="font-size:13px;color:#ccc;padding:8px 0;">Henüz kişi eklenmedi.</div>
                </div>

                <div id="qpModalMsg" style="display:none;padding:9px 12px;border-radius:6px;font-size:13px;margin-top:10px;"></div>

                <div class="qp-modal-actions">
                    <button class="qp-proj-btn" id="qpModalSave" onclick="qpSaveProject()">💾 Kaydet</button>
                    <button class="qp-proj-btn sec" onclick="qpCloseForm()">İptal</button>
                </div>
            </div>
        </div>

        <script>
        var qpProjNonce = '<?php echo esc_js( $nonce ); ?>';
        var qpAjaxUrl   = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
        var qpProjCats  = <?php echo wp_json_encode( $categories, JSON_UNESCAPED_UNICODE ); ?>;

        var qpProjData = <?php echo wp_json_encode( array_map( function( $p ) {
            return [
                'id'          => (int) $p->id,
                'name'        => $p->name,
                'category'    => $p->category ?? '',
                'description' => $p->description,
                'client_name' => $p->client_name,
                'address'     => $p->address ?? '',
                'tax_office'  => $p->tax_office ?? '',
                'tax_number'  => $p->tax_number ?? '',
                'contacts'    => $p->contacts ? json_decode( $p->contacts, true ) : [],
                'status'      => $p->status,
                'deadline'    => $p->deadline ?? '',
                'budget'      => $p->budget ?? '',
            ];
        }, $projects ), JSON_UNESCAPED_UNICODE ); ?>;

        /* ── Toggle expand ──────────────────────────────── */
        function qpToggle(id) {
            var body  = document.getElementById('qp-proj-body-' + id);
            var arrow = document.getElementById('qp-arrow-' + id);
            if (!body) return;
            body.classList.toggle('open');
            if (arrow) arrow.textContent = body.classList.contains('open') ? '▾' : '▸';
        }

        /* ── Category management ────────────────────────── */
        function qpRemoveCat(btn) {
            var cat = btn.parentElement.dataset.cat;
            qpProjCats = qpProjCats.filter(function(c){ return c !== cat; });
            btn.parentElement.remove();
            qpSaveCats();
            qpRebuildCatSelect();
        }
        function qpAddCat() {
            var inp = document.getElementById('qpNewCat');
            var val = inp.value.trim();
            if (!val || qpProjCats.indexOf(val) !== -1) return;
            qpProjCats.push(val);
            var chip = document.createElement('span');
            chip.className = 'qp-cat-chip';
            chip.dataset.cat = val;
            chip.innerHTML = val + '<button type="button" onclick="qpRemoveCat(this)" title="Kaldır">×</button>';
            document.getElementById('qpCatChips').appendChild(chip);
            inp.value = '';
            qpSaveCats();
            qpRebuildCatSelect();
        }
        function qpSaveCats() {
            var fd = new FormData();
            fd.append('action',     'qp_save_proj_cats');
            fd.append('categories', JSON.stringify(qpProjCats));
            fd.append('_wpnonce',   qpProjNonce);
            fetch(qpAjaxUrl, {method:'POST', body:fd});
        }
        function qpRebuildCatSelect() {
            var sel = document.getElementById('qpFormCategory');
            if (!sel) return;
            var prev = sel.value;
            sel.innerHTML = '<option value="">— Seçiniz —</option>';
            qpProjCats.forEach(function(c) {
                var o = document.createElement('option');
                o.value = c; o.textContent = c;
                if (c === prev) o.selected = true;
                sel.appendChild(o);
            });
        }

        /* ── Modal open/close ───────────────────────────── */
        function qpOpenForm(id) {
            document.getElementById('qpModalMsg').style.display = 'none';
            document.getElementById('qpContactRows').innerHTML = '';
            if (id === 0) {
                document.getElementById('qpModalTitle').textContent = 'Yeni Proje';
                document.getElementById('qpFormId').value       = '0';
                document.getElementById('qpFormName').value     = '';
                document.getElementById('qpFormCategory').value = '';
                document.getElementById('qpFormClient').value   = '';
                document.getElementById('qpFormAddress').value  = '';
                document.getElementById('qpFormTaxOffice').value = '';
                document.getElementById('qpFormTaxNumber').value = '';
                document.getElementById('qpFormDesc').value     = '';
                document.getElementById('qpFormStatus').value   = 'active';
                document.getElementById('qpFormDeadline').value = '';
                document.getElementById('qpFormBudget').value   = '';
                qpSyncContactVisibility();
            } else {
                var p = qpProjData.find(function(x){ return x.id === id; });
                if (!p) return;
                document.getElementById('qpModalTitle').textContent = 'Projeyi Düzenle';
                document.getElementById('qpFormId').value       = p.id;
                document.getElementById('qpFormName').value     = p.name;
                document.getElementById('qpFormCategory').value = p.category || '';
                document.getElementById('qpFormClient').value   = p.client_name;
                document.getElementById('qpFormAddress').value  = p.address || '';
                document.getElementById('qpFormTaxOffice').value = p.tax_office || '';
                document.getElementById('qpFormTaxNumber').value = p.tax_number || '';
                document.getElementById('qpFormDesc').value     = p.description;
                document.getElementById('qpFormStatus').value   = p.status;
                document.getElementById('qpFormDeadline').value = p.deadline || '';
                document.getElementById('qpFormBudget').value   = p.budget || '';
                (p.contacts || []).forEach(function(c) { qpAddContactRow(c); });
                qpSyncContactVisibility();
            }
            document.getElementById('qpModal').classList.add('open');
            document.getElementById('qpFormName').focus();
        }
        function qpCloseForm() {
            document.getElementById('qpModal').classList.remove('open');
        }
        document.getElementById('qpModal').addEventListener('click', function(e) {
            if (e.target === this) qpCloseForm();
        });

        /* ── Contacts ───────────────────────────────────── */
        function qpAddContact() {
            var rows = document.getElementById('qpContactRows').querySelectorAll('.qp-contact-form-row');
            if (rows.length >= 10) { alert('En fazla 10 yetkili kişi eklenebilir.'); return; }
            qpAddContactRow({name:'',title:'',email:'',phone:'',mobile:''});
            qpSyncContactVisibility();
        }
        function qpAddContactRow(c) {
            var div = document.createElement('div');
            div.className = 'qp-contact-form-row';
            div.innerHTML =
                '<div class="qp-contact-form-grid">' +
                '<div><label>Ad Soyad</label><input type="text" data-role="name" value="'+qpEsc(c.name||'')+'" placeholder="Ad Soyad"></div>' +
                '<div><label>Ünvan / Görev</label><input type="text" data-role="title" value="'+qpEsc(c.title||'')+'" placeholder="Ünvan"></div>' +
                '<div><label>E-posta</label><input type="email" data-role="email" value="'+qpEsc(c.email||'')+'" placeholder="ornek@firma.com"></div>' +
                '<div><label>Telefon</label><input type="text" data-role="phone" value="'+qpEsc(c.phone||'')+'" placeholder="+90 ..."></div>' +
                '<div><label>Cep Telefonu</label><input type="text" data-role="mobile" value="'+qpEsc(c.mobile||'')+'" placeholder="+90 5xx"></div>' +
                '<div style="display:flex;align-items:flex-end;"><button type="button" onclick="qpRemoveContact(this)" style="background:#fdecea;color:#c62828;border:1px solid #ef9a9a;border-radius:5px;padding:6px 8px;cursor:pointer;font-size:13px;">✕</button></div>' +
                '</div>';
            document.getElementById('qpContactRows').appendChild(div);
        }
        function qpRemoveContact(btn) {
            btn.closest('.qp-contact-form-row').remove();
            qpSyncContactVisibility();
        }
        function qpSyncContactVisibility() {
            var rows = document.getElementById('qpContactRows').querySelectorAll('.qp-contact-form-row');
            document.getElementById('qpNoContacts').style.display = rows.length === 0 ? 'block' : 'none';
        }
        function qpCollectContacts() {
            var contacts = [];
            document.getElementById('qpContactRows').querySelectorAll('.qp-contact-form-row').forEach(function(row) {
                contacts.push({
                    name:   row.querySelector('[data-role=name]').value.trim(),
                    title:  row.querySelector('[data-role=title]').value.trim(),
                    email:  row.querySelector('[data-role=email]').value.trim(),
                    phone:  row.querySelector('[data-role=phone]').value.trim(),
                    mobile: row.querySelector('[data-role=mobile]').value.trim(),
                });
            });
            return contacts.filter(function(c){ return c.name; });
        }

        /* ── Save project ───────────────────────────────── */
        function qpEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        function qpSaveProject() {
            var name = document.getElementById('qpFormName').value.trim();
            if (!name) {
                var msg = document.getElementById('qpModalMsg');
                msg.style.cssText = 'display:block;background:#fdecea;color:#c62828;border:1px solid #ef9a9a;padding:9px 12px;border-radius:6px;font-size:13px;';
                msg.textContent = 'Proje adı zorunludur.';
                return;
            }
            var btn = document.getElementById('qpModalSave');
            btn.disabled = true; btn.textContent = '⏳';

            var fd = new FormData();
            fd.append('action',      'qp_save_project');
            fd.append('project_id',  document.getElementById('qpFormId').value);
            fd.append('name',        name);
            fd.append('category',    document.getElementById('qpFormCategory').value);
            fd.append('client_name', document.getElementById('qpFormClient').value);
            fd.append('address',     document.getElementById('qpFormAddress').value);
            fd.append('tax_office',  document.getElementById('qpFormTaxOffice').value);
            fd.append('tax_number',  document.getElementById('qpFormTaxNumber').value);
            fd.append('description', document.getElementById('qpFormDesc').value);
            fd.append('status',      document.getElementById('qpFormStatus').value);
            fd.append('deadline',    document.getElementById('qpFormDeadline').value);
            fd.append('budget',      document.getElementById('qpFormBudget').value);
            fd.append('contacts',    JSON.stringify(qpCollectContacts()));
            fd.append('_wpnonce',    qpProjNonce);

            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) { location.reload(); }
                else {
                    var msg = document.getElementById('qpModalMsg');
                    msg.style.cssText = 'display:block;background:#fdecea;color:#c62828;border:1px solid #ef9a9a;padding:9px 12px;border-radius:6px;font-size:13px;';
                    msg.textContent = 'Hata: ' + (d.data || 'Bilinmeyen hata');
                    btn.disabled = false; btn.textContent = '💾 Kaydet';
                }
            })
            .catch(function(){ btn.disabled = false; btn.textContent = '💾 Kaydet'; });
        }

        /* ── Delete project ─────────────────────────────── */
        function qpDeleteProject(id) {
            if (!confirm('Bu projeyi silmek istediğinizden emin misiniz?\nBağlı talepler projeden ayrılacak ama silinmeyecek.')) return;
            var fd = new FormData();
            fd.append('action',     'qp_delete_project');
            fd.append('project_id', id);
            fd.append('_wpnonce',   qpProjNonce);
            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.success) location.reload(); });
        }
        </script>
        <?php
    }
}
