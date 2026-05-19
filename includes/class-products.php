<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Products {

    public static function init() {
        add_action( 'admin_menu',                  [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_save_product',     [ __CLASS__, 'handle_save' ] );
        add_action( 'wp_ajax_qp_delete_product',   [ __CLASS__, 'handle_delete' ] );
        add_action( 'wp_ajax_qp_get_product',      [ __CLASS__, 'handle_get' ] );

        // WooCommerce sync — only when WC is active
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_new_product',    [ __CLASS__, 'sync_from_wc' ], 10, 1 );
            add_action( 'woocommerce_update_product', [ __CLASS__, 'sync_from_wc' ], 10, 1 );
        }
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Ürünler', 'quotepress' ),
            '📦 ' . __( 'Ürünler', 'quotepress' ),
            QuotePress_Users::CAP,
            'quotepress-products',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── WooCommerce sync ──────────────────────────────────── */
    public static function sync_from_wc( $product_id ) {
        $wc_product = wc_get_product( $product_id );
        if ( ! $wc_product ) return;

        global $wpdb;
        $pt  = QuotePress_Database::products_table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$pt} WHERE wc_product_id=%d", $product_id ) );

        $data = [
            'name'         => $wc_product->get_name(),
            'sku'          => $wc_product->get_sku() ?: '',
            'description'  => wp_strip_all_tags( $wc_product->get_short_description() ?: $wc_product->get_description() ),
            'base_price'   => (float) $wc_product->get_price() ?: null,
            'wc_product_id'=> $product_id,
        ];

        if ( $row ) {
            QuotePress_Database::update_product( (int) $row->id, $data );
        } else {
            QuotePress_Database::insert_product( $data );
        }
    }

    /* ── AJAX: get single product (for edit modal) ─────────── */
    public static function handle_get() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) { wp_send_json_error( 'permission_denied' ); }
        $id      = intval( $_GET['product_id'] ?? 0 );
        $product = QuotePress_Database::get_product( $id );
        if ( ! $product ) { wp_send_json_error( 'not_found' ); }
        $variants = QuotePress_Database::get_variants( $id );
        wp_send_json_success( [ 'product' => $product, 'variants' => $variants ] );
    }

    /* ── AJAX: save product ────────────────────────────────── */
    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_products_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id       = intval( $_POST['product_id'] ?? 0 );
        $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) { wp_send_json_error( 'name_required' ); }

        $budget_raw = $_POST['base_price'] ?? '';

        $data = [
            'group_id'    => intval( $_POST['group_id'] ?? 0 ),
            'name'        => $name,
            'sku'         => sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) ),
            'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'unit'        => sanitize_text_field( wp_unslash( $_POST['unit'] ?? '' ) ),
            'base_price'  => $budget_raw !== '' ? floatval( $budget_raw ) : null,
        ];

        if ( $id ) {
            QuotePress_Database::update_product( $id, $data );
        } else {
            $id = QuotePress_Database::insert_product( $data );
        }

        // Variants
        $variants_raw = wp_unslash( $_POST['variants'] ?? '[]' );
        $variants     = json_decode( $variants_raw, true );
        if ( is_array( $variants ) ) {
            QuotePress_Database::save_variants( $id, $variants );
        }

        wp_send_json_success( [ 'id' => $id ] );
    }

    /* ── AJAX: delete product ──────────────────────────────── */
    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_products_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id = intval( $_POST['product_id'] ?? 0 );
        if ( ! $id ) { wp_send_json_error( 'invalid_id' ); }
        QuotePress_Database::delete_product( $id );
        wp_send_json_success( 'deleted' );
    }

    /* ── Render admin page ─────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( QuotePress_Users::CAP ) ) return;

        global $wpdb;
        $colors       = QuotePress_Settings::active_theme_colors();
        $nonce        = wp_create_nonce( 'qp_products_nonce' );
        $def_currency = QuotePress_Settings::get( 'default_currency', 'USD' );

        // Groups from existing services table
        $services_table = $wpdb->prefix . 'quotepress_services';
        $groups = $wpdb->get_results( "SELECT id, title FROM {$services_table} ORDER BY title ASC" );

        $selected_group = isset( $_GET['group'] ) ? intval( $_GET['group'] ) : 0;

        // Products (with group join)
        $products_table = QuotePress_Database::products_table();
        if ( $selected_group > 0 ) {
            $products = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.*, s.title as group_title
                 FROM {$products_table} p
                 LEFT JOIN {$services_table} s ON s.id=p.group_id
                 WHERE p.group_id=%d
                 ORDER BY p.sort_order ASC, p.name ASC", $selected_group
            ) );
        } else {
            $products = $wpdb->get_results(
                "SELECT p.*, s.title as group_title
                 FROM {$products_table} p
                 LEFT JOIN {$services_table} s ON s.id=p.group_id
                 ORDER BY p.group_id ASC, p.sort_order ASC, p.name ASC"
            );
        }

        $wc_active = class_exists( 'WooCommerce' );
        ?>
        <div class="wrap" style="max-width:1100px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            Ürünler
            <button class="qp-prd-btn" style="margin-left:auto;" onclick="qpPrdOpenForm(0)">+ Yeni Ürün</button>
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qp-prd-btn{background:var(--qp-primary);color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;line-height:1.5;}
        .qp-prd-btn:hover{opacity:.88;}
        .qp-prd-btn.sec{background:#fff;color:#555;border:1px solid #ddd;}
        .qp-prd-btn.sec:hover{background:#f5f5f5;}
        .qp-prd-btn.sm{padding:5px 10px;font-size:12px;}
        .qp-prd-btn.danger{background:#fdecea;color:#c62828;border:1px solid #ef9a9a;}
        .qp-prd-layout{display:grid;grid-template-columns:220px 1fr;gap:18px;margin-top:4px;}
        .qp-prd-sidebar{background:#fff;border:1px solid var(--qp-border);border-radius:8px;overflow:hidden;}
        .qp-prd-group{padding:10px 14px;font-size:13px;font-weight:600;color:#555;cursor:pointer;border-bottom:1px solid #f5f5f5;text-decoration:none;display:block;}
        .qp-prd-group:hover,.qp-prd-group.active{background:var(--qp-light);color:var(--qp-primary);}
        .qp-prd-group.active{border-left:3px solid var(--qp-primary);}
        .qp-prd-group .cnt{font-size:11px;font-weight:400;color:#aaa;margin-left:4px;}
        .qp-prd-main{background:#fff;border:1px solid var(--qp-border);border-radius:8px;overflow:hidden;}
        .qp-prd-table{width:100%;border-collapse:collapse;font-size:13px;}
        .qp-prd-table thead th{background:var(--qp-light);padding:10px 14px;text-align:left;font-weight:700;color:var(--qp-dark);border-bottom:2px solid var(--qp-border);}
        .qp-prd-table tbody td{padding:10px 14px;border-bottom:1px solid #f5f5f5;vertical-align:middle;}
        .qp-prd-table tbody tr:hover td{background:#fafafa;}
        .qp-prd-name{font-weight:700;color:var(--qp-dark);}
        .qp-prd-sku{font-size:11px;color:#aaa;}
        .qp-prd-vars{display:flex;flex-wrap:wrap;gap:4px;}
        .qp-var-chip{display:inline-block;background:#f0f0f0;border-radius:4px;padding:1px 7px;font-size:11px;color:#555;}
        .qp-wc-badge{display:inline-block;background:#7f54b3;color:#fff;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;margin-left:4px;}
        .qp-prd-empty{padding:48px;text-align:center;color:#ccc;font-size:14px;}
        /* Modal */
        #qpPrdModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
        #qpPrdModal.open{display:flex;}
        .qp-prd-modal-box{background:#fff;border-radius:10px;padding:28px 30px;width:640px;max-width:96vw;max-height:92vh;overflow-y:auto;}
        .qp-prd-modal-box h2{font-size:16px;font-weight:700;margin-bottom:18px;color:var(--qp-dark);}
        .qp-prd-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
        .qp-prd-form-row{margin-bottom:0;}
        .qp-prd-form-row label{display:block;font-size:11px;font-weight:700;color:#666;margin-bottom:4px;}
        .qp-prd-form-row input,.qp-prd-form-row textarea,.qp-prd-form-row select{width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;}
        .qp-prd-form-row input:focus,.qp-prd-form-row textarea:focus,.qp-prd-form-row select:focus{outline:none;border-color:var(--qp-primary);}
        .qp-prd-form-row textarea{resize:vertical;min-height:60px;}
        .qp-prd-form-full{grid-column:1/-1;}
        .qp-prd-section{margin-bottom:16px;}
        .qp-prd-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--qp-primary);border-bottom:2px solid var(--qp-light);padding-bottom:6px;margin-bottom:12px;}
        .qp-var-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:7px;}
        .qp-var-row label{font-size:10px;color:#aaa;display:block;margin-bottom:3px;font-weight:600;}
        .qp-var-row input{padding:6px 8px;border:1px solid #ddd;border-radius:5px;font-size:12px;width:100%;}
        .qp-modal-actions{display:flex;gap:10px;margin-top:20px;}
        @media(max-width:700px){.qp-prd-layout{grid-template-columns:1fr;}}
        </style>

        <?php if ( $wc_active ) : ?>
        <div style="background:#f0eaff;border:1px solid #c4a8e8;border-radius:7px;padding:10px 16px;margin-bottom:14px;font-size:13px;color:#5a0fa0;display:flex;align-items:center;gap:8px;">
            <span class="qp-wc-badge" style="font-size:12px;padding:2px 8px;">WC</span>
            WooCommerce aktif — yeni ürünler otomatik senkronize edilir.
        </div>
        <?php endif; ?>

        <div class="qp-prd-layout">
            <!-- Sidebar: Groups -->
            <div class="qp-prd-sidebar">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-products' ) ); ?>"
                   class="qp-prd-group <?php echo $selected_group === 0 ? 'active' : ''; ?>">
                   Tüm Ürünler
                   <span class="qp-prd-group cnt">(<?php echo count( $products ); ?>)</span>
                </a>
                <?php foreach ( $groups as $g ) :
                    $gc  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$products_table} WHERE group_id=%d", $g->id ) );
                    $url = add_query_arg( [ 'page' => 'quotepress-products', 'group' => $g->id ], admin_url( 'admin.php' ) );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="qp-prd-group <?php echo $selected_group === (int)$g->id ? 'active' : ''; ?>">
                   <?php echo esc_html( $g->title ); ?>
                   <span class="qp-prd-group cnt">(<?php echo $gc; ?>)</span>
                </a>
                <?php endforeach; ?>
                <div style="padding:12px 14px;border-top:1px solid #f0f0f0;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-catalog' ) ); ?>"
                       style="font-size:12px;color:var(--qp-primary);text-decoration:none;">⚙ Grupları Yönet →</a>
                </div>
            </div>

            <!-- Main: Products table -->
            <div class="qp-prd-main">
                <?php if ( empty( $products ) ) : ?>
                <div class="qp-prd-empty">
                    <div style="font-size:40px;margin-bottom:10px;">📦</div>
                    <p>Henüz ürün eklenmemiş.</p>
                    <p style="font-size:13px;color:#bbb;margin-top:6px;">
                        <?php if ( $wc_active ) echo 'WooCommerce\'den ürünler otomatik gelecek veya elle ekleyebilirsiniz.'; ?>
                    </p>
                    <button class="qp-prd-btn" style="margin-top:14px;" onclick="qpPrdOpenForm(0)">+ Ürün Ekle</button>
                </div>
                <?php else : ?>
                <table class="qp-prd-table">
                    <thead><tr>
                        <th>Ürün Adı</th>
                        <th>Grup</th>
                        <th>Birim Fiyat</th>
                        <th>Varyasyonlar</th>
                        <th>İşlem</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $products as $prd ) :
                        $variants = QuotePress_Database::get_variants( (int) $prd->id );
                    ?>
                    <tr>
                        <td>
                            <div class="qp-prd-name">
                                <?php echo esc_html( $prd->name ); ?>
                                <?php if ( $prd->wc_product_id ) : ?><span class="qp-wc-badge">WC</span><?php endif; ?>
                            </div>
                            <?php if ( $prd->sku ) : ?><div class="qp-prd-sku">SKU: <?php echo esc_html( $prd->sku ); ?></div><?php endif; ?>
                            <?php if ( $prd->description ) : ?><div style="font-size:11px;color:#aaa;margin-top:2px;"><?php echo esc_html( mb_strimwidth( $prd->description, 0, 60, '…' ) ); ?></div><?php endif; ?>
                        </td>
                        <td style="color:#666;font-size:12px;"><?php echo esc_html( $prd->group_title ?: '—' ); ?></td>
                        <td style="font-weight:600;color:var(--qp-primary);">
                            <?php echo $prd->base_price
                                ? esc_html( number_format( (float) $prd->base_price, 2, ',', '.' ) . ' ' . $def_currency )
                                : '—'; ?>
                            <?php if ( $prd->unit ) echo '<div style="font-size:11px;color:#aaa;">/ ' . esc_html( $prd->unit ) . '</div>'; ?>
                        </td>
                        <td>
                            <?php if ( empty( $variants ) ) : ?>
                            <span style="color:#ccc;font-size:12px;">—</span>
                            <?php else : ?>
                            <div class="qp-prd-vars">
                                <?php foreach ( $variants as $v ) : ?>
                                <span class="qp-var-chip"><?php echo esc_html( $v->name ); ?><?php if ( $v->price ) echo ' · ' . esc_html( number_format( (float) $v->price, 0, ',', '.' ) ); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <button class="qp-prd-btn sec sm" onclick="qpPrdOpenForm(<?php echo (int)$prd->id; ?>)">✏</button>
                            <button class="qp-prd-btn danger sm" onclick="qpPrdDelete(<?php echo (int)$prd->id; ?>)" style="margin-left:4px;">🗑</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        </div><!-- .wrap -->

        <!-- Product modal -->
        <div id="qpPrdModal">
            <div class="qp-prd-modal-box">
                <h2 id="qpPrdModalTitle">Yeni Ürün</h2>
                <input type="hidden" id="qpPrdId" value="0">

                <div class="qp-prd-section">
                    <div class="qp-prd-section-title">📦 Ürün Bilgileri</div>
                    <div class="qp-prd-form-grid">
                        <div class="qp-prd-form-row qp-prd-form-full">
                            <label>Ürün Adı <span style="color:#c62828;">*</span></label>
                            <input type="text" id="qpPrdName" placeholder="Ürün adını girin...">
                        </div>
                        <div class="qp-prd-form-row">
                            <label>Grup</label>
                            <select id="qpPrdGroup">
                                <option value="0">— Grup Yok —</option>
                                <?php foreach ( $groups as $g ) : ?>
                                <option value="<?php echo (int) $g->id; ?>"><?php echo esc_html( $g->title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="qp-prd-form-row">
                            <label>SKU / Ürün Kodu</label>
                            <input type="text" id="qpPrdSku" placeholder="">
                        </div>
                        <div class="qp-prd-form-row">
                            <label>Birim Fiyat (<?php echo esc_html( $def_currency ); ?>)</label>
                            <input type="number" id="qpPrdPrice" placeholder="0.00" min="0" step="0.01">
                        </div>
                        <div class="qp-prd-form-row">
                            <label>Birim (adet, m², m, kg...)</label>
                            <input type="text" id="qpPrdUnit" placeholder="adet">
                        </div>
                        <div class="qp-prd-form-row qp-prd-form-full">
                            <label>Açıklama</label>
                            <textarea id="qpPrdDesc" placeholder="Ürün açıklaması..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="qp-prd-section">
                    <div class="qp-prd-section-title" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>🔀 Varyasyonlar <span style="font-weight:400;color:#aaa;">(maks. 10)</span></span>
                        <button type="button" class="qp-prd-btn sm" onclick="qpPrdAddVar()" style="font-size:11px;padding:3px 10px;">+ Ekle</button>
                    </div>
                    <div id="qpPrdVarRows"></div>
                    <div id="qpPrdNoVar" style="font-size:13px;color:#ccc;padding:6px 0;">Varyasyon eklenmedi — tüm tekliflerde temel fiyat kullanılır.</div>
                </div>

                <div id="qpPrdMsg" style="display:none;padding:9px 12px;border-radius:6px;font-size:13px;margin-top:10px;"></div>

                <div class="qp-modal-actions">
                    <button class="qp-prd-btn" id="qpPrdSave" onclick="qpPrdSave()">💾 Kaydet</button>
                    <button class="qp-prd-btn sec" onclick="qpPrdClose()">İptal</button>
                </div>
            </div>
        </div>

        <script>
        var qpPrdNonce  = '<?php echo esc_js( $nonce ); ?>';
        var qpAjaxUrl   = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

        function qpPrdOpenForm(id) {
            document.getElementById('qpPrdMsg').style.display = 'none';
            document.getElementById('qpPrdVarRows').innerHTML = '';
            if (id === 0) {
                document.getElementById('qpPrdModalTitle').textContent = 'Yeni Ürün';
                document.getElementById('qpPrdId').value    = '0';
                document.getElementById('qpPrdName').value  = '';
                document.getElementById('qpPrdGroup').value = '0';
                document.getElementById('qpPrdSku').value   = '';
                document.getElementById('qpPrdPrice').value = '';
                document.getElementById('qpPrdUnit').value  = '';
                document.getElementById('qpPrdDesc').value  = '';
                qpPrdSyncVarVis();
                document.getElementById('qpPrdModal').classList.add('open');
                document.getElementById('qpPrdName').focus();
            } else {
                fetch(qpAjaxUrl + '?action=qp_get_product&product_id=' + id)
                .then(function(r){return r.json();})
                .then(function(d){
                    if (!d.success) return;
                    var p = d.data.product;
                    var vs = d.data.variants;
                    document.getElementById('qpPrdModalTitle').textContent = 'Ürünü Düzenle';
                    document.getElementById('qpPrdId').value    = p.id;
                    document.getElementById('qpPrdName').value  = p.name;
                    document.getElementById('qpPrdGroup').value = p.group_id || '0';
                    document.getElementById('qpPrdSku').value   = p.sku || '';
                    document.getElementById('qpPrdPrice').value = p.base_price || '';
                    document.getElementById('qpPrdUnit').value  = p.unit || '';
                    document.getElementById('qpPrdDesc').value  = p.description || '';
                    vs.forEach(function(v){ qpPrdAddVarRow(v); });
                    qpPrdSyncVarVis();
                    document.getElementById('qpPrdModal').classList.add('open');
                });
            }
        }
        function qpPrdClose() {
            document.getElementById('qpPrdModal').classList.remove('open');
        }
        document.getElementById('qpPrdModal').addEventListener('click', function(e){
            if (e.target===this) qpPrdClose();
        });

        function qpPrdAddVar() {
            var rows = document.getElementById('qpPrdVarRows').querySelectorAll('.qp-var-row').length;
            if (rows >= 10) { alert('En fazla 10 varyasyon eklenebilir.'); return; }
            qpPrdAddVarRow({name:'',sku:'',price:''});
            qpPrdSyncVarVis();
        }
        function qpPrdAddVarRow(v) {
            var div = document.createElement('div');
            div.className = 'qp-var-row';
            div.innerHTML =
                '<div><label>Varyasyon Adı</label><input type="text" data-role="vname" value="'+qpPrdEsc(v.name||'')+'" placeholder="örn. 12.000 BTU"></div>' +
                '<div><label>SKU</label><input type="text" data-role="vsku" value="'+qpPrdEsc(v.sku||'')+'" placeholder=""></div>' +
                '<div><label>Fiyat</label><input type="number" data-role="vprice" value="'+qpPrdEsc(v.price||'')+'" placeholder="0.00" min="0" step="0.01"></div>' +
                '<div><button type="button" onclick="this.closest(\'.qp-var-row\').remove();qpPrdSyncVarVis();" style="background:#fdecea;color:#c62828;border:1px solid #ef9a9a;border-radius:5px;padding:6px 8px;cursor:pointer;margin-top:14px;">✕</button></div>';
            document.getElementById('qpPrdVarRows').appendChild(div);
        }
        function qpPrdSyncVarVis() {
            var rows = document.getElementById('qpPrdVarRows').querySelectorAll('.qp-var-row').length;
            document.getElementById('qpPrdNoVar').style.display = rows === 0 ? 'block' : 'none';
        }
        function qpPrdCollectVars() {
            var vars = [];
            document.getElementById('qpPrdVarRows').querySelectorAll('.qp-var-row').forEach(function(row){
                vars.push({
                    name:  row.querySelector('[data-role=vname]').value.trim(),
                    sku:   row.querySelector('[data-role=vsku]').value.trim(),
                    price: row.querySelector('[data-role=vprice]').value.trim(),
                });
            });
            return vars.filter(function(v){ return v.name; });
        }

        function qpPrdEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        function qpPrdSave() {
            var name = document.getElementById('qpPrdName').value.trim();
            if (!name) {
                var msg = document.getElementById('qpPrdMsg');
                msg.style.cssText = 'display:block;background:#fdecea;color:#c62828;border:1px solid #ef9a9a;padding:9px 12px;border-radius:6px;font-size:13px;';
                msg.textContent = 'Ürün adı zorunludur.';
                return;
            }
            var btn = document.getElementById('qpPrdSave');
            btn.disabled = true; btn.textContent = '⏳';

            var fd = new FormData();
            fd.append('action',     'qp_save_product');
            fd.append('product_id', document.getElementById('qpPrdId').value);
            fd.append('name',       name);
            fd.append('group_id',   document.getElementById('qpPrdGroup').value);
            fd.append('sku',        document.getElementById('qpPrdSku').value);
            fd.append('base_price', document.getElementById('qpPrdPrice').value);
            fd.append('unit',       document.getElementById('qpPrdUnit').value);
            fd.append('description',document.getElementById('qpPrdDesc').value);
            fd.append('variants',   JSON.stringify(qpPrdCollectVars()));
            fd.append('_wpnonce',   qpPrdNonce);

            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.success) { location.reload(); }
                else {
                    var msg = document.getElementById('qpPrdMsg');
                    msg.style.cssText = 'display:block;background:#fdecea;color:#c62828;border:1px solid #ef9a9a;padding:9px 12px;border-radius:6px;font-size:13px;';
                    msg.textContent = 'Hata: ' + (d.data||'Bilinmeyen hata');
                    btn.disabled = false; btn.textContent = '💾 Kaydet';
                }
            })
            .catch(function(){ btn.disabled = false; btn.textContent = '💾 Kaydet'; });
        }

        function qpPrdDelete(id) {
            if (!confirm('Bu ürünü silmek istediğinizden emin misiniz?')) return;
            var fd = new FormData();
            fd.append('action',     'qp_delete_product');
            fd.append('product_id', id);
            fd.append('_wpnonce',   qpPrdNonce);
            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(d){ if (d.success) location.reload(); });
        }
        </script>
        <?php
    }
}
