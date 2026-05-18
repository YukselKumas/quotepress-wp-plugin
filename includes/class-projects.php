<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Projects {

    public static function init() {
        add_action( 'admin_menu',                    [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_save_project',       [ __CLASS__, 'handle_save' ] );
        add_action( 'wp_ajax_qp_delete_project',     [ __CLASS__, 'handle_delete' ] );
        add_action( 'wp_ajax_qp_assign_project',     [ __CLASS__, 'handle_assign' ] );
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

    /* ── AJAX: save project (create or update) ─────────────── */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_projects_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id          = intval( $_POST['project_id'] ?? 0 );
        $name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $client_name = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) );
        $status      = sanitize_text_field( $_POST['status'] ?? 'active' );
        $deadline    = sanitize_text_field( $_POST['deadline'] ?? '' );
        $budget_raw  = $_POST['budget'] ?? '';
        $budget      = $budget_raw !== '' ? floatval( $budget_raw ) : null;

        if ( ! $name ) { wp_send_json_error( 'name_required' ); }
        if ( ! in_array( $status, [ 'active', 'completed', 'cancelled' ], true ) ) {
            $status = 'active';
        }

        $data = [
            'name'        => $name,
            'description' => $description,
            'client_name' => $client_name,
            'status'      => $status,
            'deadline'    => $deadline ?: null,
            'budget'      => $budget,
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

    /* ── Render admin page ─────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $colors  = QuotePress_Settings::active_theme_colors();
        $nonce   = wp_create_nonce( 'qp_projects_nonce' );
        $projects = QuotePress_Database::get_projects();
        $def_currency = QuotePress_Settings::get( 'default_currency', 'USD' );

        // Build per-project stats from requests
        $stats = [];
        global $wpdb;
        $t  = QuotePress_Database::table();
        $rows = $wpdb->get_results(
            "SELECT project_id, status, quote_data
             FROM {$t}
             WHERE project_id IS NOT NULL"
        );
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
            'active'    => [ 'lbl' => 'Aktif',       'cls' => 'active' ],
            'completed' => [ 'lbl' => 'Tamamlandı',  'cls' => 'completed' ],
            'cancelled' => [ 'lbl' => 'İptal Edildi','cls' => 'cancelled' ],
        ];
        ?>
        <div class="wrap" style="max-width:1100px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Projeler', 'quotepress' ); ?>
            <button id="qpNewProjBtn" class="qp-proj-btn" style="margin-left:auto;" onclick="qpOpenForm(0)">+ Yeni Proje</button>
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qp-proj-btn{background:var(--qp-primary);color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;}
        .qp-proj-btn:hover{opacity:.88;}
        .qp-proj-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qp-proj-btn.sec:hover{background:#f5f5f5;}
        .qp-proj-btn.danger{background:#fdecea;color:#c62828;border:1px solid #ef9a9a;}
        .qp-proj-btn.danger:hover{background:#ffcdd2;}
        .qp-proj-card{background:#fff;border:1px solid var(--qp-border);border-radius:8px;margin-bottom:14px;overflow:hidden;}
        .qp-proj-head{display:flex;align-items:center;gap:12px;padding:14px 18px;cursor:pointer;user-select:none;}
        .qp-proj-head:hover{background:var(--qp-light);}
        .qp-proj-name{font-size:15px;font-weight:700;color:var(--qp-dark);flex:1;}
        .qp-proj-meta{font-size:12px;color:#aaa;flex-shrink:0;}
        .qp-proj-body{border-top:1px solid var(--qp-border);padding:16px 18px;display:none;}
        .qp-proj-body.open{display:block;}
        .qp-proj-kv{display:grid;grid-template-columns:140px 1fr;gap:5px 10px;font-size:13px;margin-bottom:12px;}
        .qp-proj-kv .k{color:#aaa;}
        .qp-proj-kv .v{color:#222;font-weight:500;}
        .qp-proj-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
        .qp-proj-stat{background:var(--qp-light);border-radius:6px;padding:8px 14px;font-size:12px;text-align:center;}
        .qp-proj-stat .n{font-size:20px;font-weight:800;color:var(--qp-primary);line-height:1.2;}
        .qp-proj-stat .l{color:#888;margin-top:2px;}
        .qp-proj-status{display:inline-block;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;}
        .qp-proj-status.active{background:#e8f5e9;color:#2e7d32;}
        .qp-proj-status.completed{background:var(--qp-light);color:var(--qp-dark);}
        .qp-proj-status.cancelled{background:#fdecea;color:#c62828;}
        .qp-mini-badge{display:inline-block;padding:1px 7px;border-radius:8px;font-size:11px;font-weight:600;}
        .qp-mini-badge.pending{background:#fff3e0;color:#e65100;}
        .qp-mini-badge.quoted{background:var(--qp-light);color:var(--qp-dark);}
        .qp-mini-badge.won{background:#e8f5e9;color:#2e7d32;}
        .qp-mini-badge.lost{background:#fdecea;color:#c62828;}
        /* Modal */
        #qpModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
        #qpModal.open{display:flex;}
        .qp-modal-box{background:#fff;border-radius:10px;padding:28px 30px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;}
        .qp-modal-box h2{font-size:16px;font-weight:700;margin-bottom:18px;color:var(--qp-dark);}
        .qp-form-row{margin-bottom:13px;}
        .qp-form-row label{display:block;font-size:12px;font-weight:700;color:#666;margin-bottom:5px;}
        .qp-form-row input,.qp-form-row textarea,.qp-form-row select{width:100%;padding:8px 11px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;}
        .qp-form-row input:focus,.qp-form-row textarea:focus,.qp-form-row select:focus{outline:none;border-color:var(--qp-primary);}
        .qp-form-row textarea{resize:vertical;min-height:70px;}
        .qp-form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .qp-modal-actions{display:flex;gap:10px;margin-top:20px;}
        .qp-empty-state{text-align:center;padding:60px 20px;color:#ccc;font-size:14px;}
        </style>

        <?php if ( empty( $projects ) ) : ?>
        <div class="qp-empty-state">
            <div style="font-size:48px;margin-bottom:12px;">📁</div>
            <p>Henüz proje tanımlanmamış.</p>
            <p style="margin-top:8px;font-size:13px;color:#bbb;">Yeni Proje butonuna tıklayarak başlayın.</p>
        </div>
        <?php else : ?>

        <?php foreach ( $projects as $p ) :
            $pid  = (int) $p->id;
            $pst  = $stats[ $pid ] ?? [ 'count' => 0, 'pending' => 0, 'quoted' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0, 'currency' => $def_currency ];
            $slbl = $status_labels[ $p->status ] ?? $status_labels['active'];
            $proj_requests_url = add_query_arg( [ 'page' => 'quotepress-reports', 'view' => 'requests', 'qp_project_id' => $pid ], admin_url( 'admin.php' ) );
        ?>
        <div class="qp-proj-card" id="qp-proj-<?php echo $pid; ?>">
            <div class="qp-proj-head" onclick="qpToggle(<?php echo $pid; ?>)">
                <span class="qp-proj-status <?php echo esc_attr( $slbl['cls'] ); ?>"><?php echo esc_html( $slbl['lbl'] ); ?></span>
                <div class="qp-proj-name"><?php echo esc_html( $p->name ); ?></div>
                <?php if ( $p->client_name ) : ?>
                <div class="qp-proj-meta"><?php echo esc_html( $p->client_name ); ?></div>
                <?php endif; ?>
                <div class="qp-proj-meta" style="font-weight:700;color:var(--qp-primary);"><?php echo (int) $pst['count']; ?> talep</div>
                <span style="color:#ccc;font-size:18px;">▸</span>
            </div>
            <div class="qp-proj-body" id="qp-proj-body-<?php echo $pid; ?>">

                <!-- Stats row -->
                <div class="qp-proj-stats">
                    <div class="qp-proj-stat">
                        <div class="n"><?php echo (int) $pst['count']; ?></div>
                        <div class="l">Toplam</div>
                    </div>
                    <?php foreach ( [ 'pending' => 'Beklemede', 'quoted' => 'Tekliflendirildi', 'won' => '✓ Kabul', 'lost' => '✗ Reddedildi' ] as $sk => $sl ) : ?>
                    <?php if ( $pst[ $sk ] > 0 ) : ?>
                    <div class="qp-proj-stat">
                        <div class="n" style="<?php echo $sk === 'won' ? 'color:#2e7d32' : ( $sk === 'lost' ? 'color:#c62828' : ( $sk === 'pending' ? 'color:#e65100' : '' ) ); ?>">
                            <?php echo (int) $pst[ $sk ]; ?>
                        </div>
                        <div class="l"><?php echo esc_html( $sl ); ?></div>
                    </div>
                    <?php endif; endforeach; ?>
                    <?php if ( $pst['value'] > 0 ) : ?>
                    <div class="qp-proj-stat">
                        <div class="n" style="font-size:15px;"><?php echo esc_html( number_format( $pst['value'], 2, '.', ',' ) . ' ' . $pst['currency'] ); ?></div>
                        <div class="l">Toplam Tutar</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Project details -->
                <div class="qp-proj-kv">
                    <?php if ( $p->client_name ) : ?>
                    <span class="k">Müşteri / Firma</span><span class="v"><?php echo esc_html( $p->client_name ); ?></span>
                    <?php endif; ?>
                    <?php if ( $p->description ) : ?>
                    <span class="k">Açıklama</span><span class="v"><?php echo nl2br( esc_html( $p->description ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $p->deadline ) : ?>
                    <span class="k">Termin</span><span class="v"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->deadline ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $p->budget ) : ?>
                    <span class="k">Hedef Bütçe</span><span class="v"><?php echo esc_html( number_format( (float) $p->budget, 2, '.', ',' ) . ' ' . $def_currency ); ?></span>
                    <?php endif; ?>
                    <span class="k">Oluşturulma</span><span class="v"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->created_at ) ) ); ?></span>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <a href="<?php echo esc_url( $proj_requests_url ); ?>" class="qp-proj-btn sec" style="text-decoration:none;font-size:12px;padding:6px 14px;">📋 Talepleri Gör</a>
                    <button class="qp-proj-btn sec" style="font-size:12px;padding:6px 14px;" onclick="qpOpenForm(<?php echo $pid; ?>)">✏ Düzenle</button>
                    <button class="qp-proj-btn danger" style="font-size:12px;padding:6px 14px;" onclick="qpDeleteProject(<?php echo $pid; ?>)">🗑 Sil</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

        </div><!-- .wrap -->

        <!-- Modal form -->
        <div id="qpModal">
            <div class="qp-modal-box">
                <h2 id="qpModalTitle">Yeni Proje</h2>
                <input type="hidden" id="qpFormId" value="0">

                <div class="qp-form-row">
                    <label>Proje Adı <span style="color:#c62828;">*</span></label>
                    <input type="text" id="qpFormName" placeholder="Proje adını girin...">
                </div>
                <div class="qp-form-row">
                    <label>Müşteri / Firma Adı</label>
                    <input type="text" id="qpFormClient" placeholder="Opsiyonel">
                </div>
                <div class="qp-form-row">
                    <label>Açıklama / Not</label>
                    <textarea id="qpFormDesc" placeholder="Proje hakkında kısa not..."></textarea>
                </div>
                <div class="qp-form-row-2">
                    <div class="qp-form-row" style="margin:0;">
                        <label>Durum</label>
                        <select id="qpFormStatus">
                            <option value="active">Aktif</option>
                            <option value="completed">Tamamlandı</option>
                            <option value="cancelled">İptal Edildi</option>
                        </select>
                    </div>
                    <div class="qp-form-row" style="margin:0;">
                        <label>Termin Tarihi</label>
                        <input type="date" id="qpFormDeadline">
                    </div>
                </div>
                <div class="qp-form-row" style="margin-top:13px;">
                    <label>Hedef Bütçe (<?php echo esc_html( $def_currency ); ?>)</label>
                    <input type="number" id="qpFormBudget" placeholder="0.00" min="0" step="0.01">
                </div>

                <div id="qpModalMsg" style="display:none;padding:9px 12px;border-radius:6px;font-size:13px;margin-top:10px;"></div>

                <div class="qp-modal-actions">
                    <button class="qp-proj-btn" id="qpModalSave" onclick="qpSaveProject()">Kaydet</button>
                    <button class="qp-proj-btn sec" onclick="qpCloseForm()">İptal</button>
                </div>
            </div>
        </div>

        <script>
        var qpProjNonce = '<?php echo esc_js( $nonce ); ?>';
        var qpAjaxUrl   = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

        var qpProjData = <?php echo wp_json_encode( array_map( function( $p ) {
            return [
                'id'          => (int) $p->id,
                'name'        => $p->name,
                'description' => $p->description,
                'client_name' => $p->client_name,
                'status'      => $p->status,
                'deadline'    => $p->deadline ?? '',
                'budget'      => $p->budget ?? '',
            ];
        }, $projects ), JSON_UNESCAPED_UNICODE ); ?>;

        function qpToggle(id) {
            var body = document.getElementById('qp-proj-body-' + id);
            if (!body) return;
            body.classList.toggle('open');
            var arrow = body.previousElementSibling.querySelector('span:last-child');
            if (arrow) arrow.textContent = body.classList.contains('open') ? '▾' : '▸';
        }

        function qpOpenForm(id) {
            document.getElementById('qpModalMsg').style.display = 'none';
            if (id === 0) {
                document.getElementById('qpModalTitle').textContent = 'Yeni Proje';
                document.getElementById('qpFormId').value     = '0';
                document.getElementById('qpFormName').value   = '';
                document.getElementById('qpFormClient').value = '';
                document.getElementById('qpFormDesc').value   = '';
                document.getElementById('qpFormStatus').value = 'active';
                document.getElementById('qpFormDeadline').value = '';
                document.getElementById('qpFormBudget').value = '';
            } else {
                var p = qpProjData.find(function(x){ return x.id === id; });
                if (!p) return;
                document.getElementById('qpModalTitle').textContent = 'Projeyi Düzenle';
                document.getElementById('qpFormId').value     = p.id;
                document.getElementById('qpFormName').value   = p.name;
                document.getElementById('qpFormClient').value = p.client_name;
                document.getElementById('qpFormDesc').value   = p.description;
                document.getElementById('qpFormStatus').value = p.status;
                document.getElementById('qpFormDeadline').value = p.deadline || '';
                document.getElementById('qpFormBudget').value = p.budget || '';
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

        function qpSaveProject() {
            var name = document.getElementById('qpFormName').value.trim();
            if (!name) {
                var msg = document.getElementById('qpModalMsg');
                msg.style.display = 'block';
                msg.style.background = '#fdecea';
                msg.style.color = '#c62828';
                msg.style.border = '1px solid #ef9a9a';
                msg.textContent = 'Proje adı zorunludur.';
                return;
            }

            var btn = document.getElementById('qpModalSave');
            btn.disabled = true;
            btn.textContent = '⏳';

            var fd = new FormData();
            fd.append('action',      'qp_save_project');
            fd.append('project_id',  document.getElementById('qpFormId').value);
            fd.append('name',        name);
            fd.append('client_name', document.getElementById('qpFormClient').value);
            fd.append('description', document.getElementById('qpFormDesc').value);
            fd.append('status',      document.getElementById('qpFormStatus').value);
            fd.append('deadline',    document.getElementById('qpFormDeadline').value);
            fd.append('budget',      document.getElementById('qpFormBudget').value);
            fd.append('_wpnonce',    qpProjNonce);

            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    location.reload();
                } else {
                    var msg = document.getElementById('qpModalMsg');
                    msg.style.display = 'block';
                    msg.style.background = '#fdecea';
                    msg.style.color = '#c62828';
                    msg.textContent = 'Hata: ' + (d.data || 'Bilinmeyen hata');
                    btn.disabled = false;
                    btn.textContent = 'Kaydet';
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = 'Kaydet';
            });
        }

        function qpDeleteProject(id) {
            if (!confirm('Bu projeyi silmek istediğinizden emin misiniz?\nBağlı talepler projeden ayrılacak ancak silinmeyecek.')) return;
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
