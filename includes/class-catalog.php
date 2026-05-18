<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Catalog {

    const OPTION_GROUPS   = 'quotepress_catalog_groups';
    const OPTION_ITEMS    = 'quotepress_catalog_items';
    const OPTION_VARIANTS = 'quotepress_catalog_variants';

    public static function init() {
        add_action( 'wp_ajax_qp_cat_save_group',    [ __CLASS__, 'ajax_save_group'    ] );
        add_action( 'wp_ajax_qp_cat_delete_group',  [ __CLASS__, 'ajax_delete_group'  ] );
        add_action( 'wp_ajax_qp_cat_save_item',     [ __CLASS__, 'ajax_save_item'     ] );
        add_action( 'wp_ajax_qp_cat_delete_item',   [ __CLASS__, 'ajax_delete_item'   ] );
        add_action( 'wp_ajax_qp_cat_save_variants', [ __CLASS__, 'ajax_save_variants' ] );
        add_action( 'wp_ajax_qp_cat_import_cpt',    [ __CLASS__, 'ajax_import_cpt'    ] );
    }

    /* ── Veri okuma ─────────────────────────────────────────── */

    public static function get_groups() {
        return (array) get_option( self::OPTION_GROUPS, [] );
    }

    public static function get_items( $group_id = null ) {
        $all = (array) get_option( self::OPTION_ITEMS, [] );
        if ( $group_id === null ) return $all;
        return array_values( array_filter( $all, fn($i) => ( $i['group_id'] ?? '' ) === $group_id ) );
    }

    public static function get_variants( $item_id ) {
        $all = (array) get_option( self::OPTION_VARIANTS, [] );
        return $all[ $item_id ] ?? [];
    }

    public static function flat_list() {
        $out = [];
        foreach ( self::get_groups() as $g ) {
            foreach ( self::get_items( $g['id'] ) as $it ) {
                if ( ! ( $it['active'] ?? true ) ) continue;
                $out[] = [
                    'id'         => $it['id'],
                    'label'      => $it['name'],
                    'group_id'   => $g['id'],
                    'group_name' => $g['name'],
                    'template'   => $g['template'],
                    'unit'       => $it['unit']          ?? '',
                    'price'      => $it['default_price'] ?? '',
                    'desc'       => $it['desc']          ?? '',
                    'variants'   => self::get_variants( $it['id'] ),
                ];
            }
        }
        return $out;
    }

    public static function templates() {
        return [
            'default'      => [ 'label' => 'Standart Kompakt', 'desc' => '1 sayfa, fiyat tablosu', 'icon' => '📄' ],
            'heat_sharing' => [ 'label' => 'Isı Gider Paylaşım', 'desc' => '4 sayfa, detaylı teklif', 'icon' => '🔥' ],
        ];
    }

    /* ── AJAX ───────────────────────────────────────────────── */

    public static function ajax_save_group() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $groups = self::get_groups();
        $id     = sanitize_text_field( $_POST['id'] ?? '' );
        $name   = sanitize_text_field( $_POST['name'] ?? '' );

        if ( ! $name ) wp_send_json_error( 'name_required' );

        $new = [
            'id'       => $id ?: 'g_' . uniqid(),
            'name'     => $name,
            'template' => sanitize_text_field( $_POST['template'] ?? 'default' ),
            'color'    => sanitize_hex_color( $_POST['color'] ?? '' ) ?: '#1a6eb5',
            'icon'     => sanitize_text_field( $_POST['icon'] ?? '🔧' ),
            'desc'     => sanitize_textarea_field( $_POST['desc'] ?? '' ),
        ];

        $found = false;
        foreach ( $groups as &$g ) {
            if ( $g['id'] === $id ) { $g = $new; $found = true; break; }
        }
        unset($g);
        if ( ! $found ) $groups[] = $new;

        update_option( self::OPTION_GROUPS, array_values($groups) );
        wp_send_json_success( $new );
    }

    public static function ajax_delete_group() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $id = sanitize_text_field( $_POST['id'] ?? '' );
        update_option( self::OPTION_GROUPS, array_values( array_filter( self::get_groups(), fn($g) => $g['id'] !== $id ) ) );
        update_option( self::OPTION_ITEMS,  array_values( array_filter( self::get_items(),  fn($i) => ( $i['group_id'] ?? '' ) !== $id ) ) );
        self::sync_categories();
        wp_send_json_success();
    }

    public static function ajax_save_item() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $items    = self::get_items();
        $id       = sanitize_text_field( $_POST['id'] ?? '' );
        $group_id = sanitize_text_field( $_POST['group_id'] ?? '' );
        $name     = sanitize_text_field( $_POST['name'] ?? '' );

        if ( ! $name || ! $group_id ) wp_send_json_error( 'missing_fields' );

        $new = [
            'id'            => $id ?: 'i_' . uniqid(),
            'group_id'      => $group_id,
            'name'          => $name,
            'unit'          => sanitize_text_field( $_POST['unit'] ?? '' ),
            'default_price' => floatval( $_POST['default_price'] ?? 0 ),
            'desc'          => sanitize_textarea_field( $_POST['desc'] ?? '' ),
            'active'        => ! empty( $_POST['active'] ),
            'image_id'      => absint( $_POST['image_id'] ?? 0 ),
        ];

        $found = false;
        foreach ( $items as &$it ) {
            if ( $it['id'] === $id ) { $it = $new; $found = true; break; }
        }
        unset($it);
        if ( ! $found ) $items[] = $new;

        update_option( self::OPTION_ITEMS, array_values($items) );
        self::sync_categories();
        wp_send_json_success( $new );
    }

    public static function ajax_delete_item() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $id = sanitize_text_field( $_POST['id'] ?? '' );
        update_option( self::OPTION_ITEMS, array_values( array_filter( self::get_items(), fn($i) => $i['id'] !== $id ) ) );
        self::sync_categories();
        wp_send_json_success();
    }

    public static function ajax_save_variants() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $item_id  = sanitize_text_field( $_POST['item_id'] ?? '' );
        $raw      = $_POST['variants'] ?? [];
        $variants = [];

        foreach ( $raw as $v ) {
            $label = sanitize_text_field( $v['label'] ?? '' );
            if ( ! $label ) continue;
            $variants[] = [
                'label'         => $label,
                'price_delta'   => floatval( $v['price_delta'] ?? 0 ),
                'is_default'    => ! empty( $v['is_default'] ),
            ];
        }

        $all = (array) get_option( self::OPTION_VARIANTS, [] );
        $all[ $item_id ] = $variants;
        update_option( self::OPTION_VARIANTS, $all );
        wp_send_json_success( $variants );
    }

    public static function ajax_import_cpt() {
        check_ajax_referer( 'qp_cat_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauthorized' );

        $post_type = sanitize_text_field( $_POST['post_type'] ?? 'product' );
        $group_id  = sanitize_text_field( $_POST['group_id']  ?? '' );
        if ( ! $group_id ) wp_send_json_error( 'no_group' );

        $posts = get_posts( [ 'post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 200 ] );
        if ( empty( $posts ) ) wp_send_json_error( 'no_posts' );

        $items    = self::get_items();
        $existing = array_column( $items, 'name' );
        $imported = 0;

        foreach ( $posts as $p ) {
            if ( in_array( $p->post_title, $existing, true ) ) continue;
            $price = 0;
            if ( function_exists( 'wc_get_product' ) ) {
                $wc = wc_get_product( $p->ID );
                if ( $wc ) $price = (float) $wc->get_price();
            }
            $thumb_id = (int) get_post_thumbnail_id( $p->ID );
            $items[] = [
                'id'            => 'i_' . uniqid(),
                'group_id'      => $group_id,
                'name'          => sanitize_text_field( $p->post_title ),
                'unit'          => '',
                'default_price' => $price,
                'desc'          => wp_strip_all_tags( $p->post_excerpt ),
                'active'        => true,
                'image_id'      => $thumb_id,
            ];
            $existing[] = $p->post_title;
            $imported++;
        }

        update_option( self::OPTION_ITEMS, array_values($items) );
        self::sync_categories();
        wp_send_json_success( [ 'imported' => $imported ] );
    }

    private static function sync_categories() {
        $names = array_column( array_filter( self::get_items(), fn($i) => $i['active'] ?? true ), 'name' );
        $s = get_option( 'quotepress_settings', [] );
        $s['product_categories'] = implode( "\n", $names );
        update_option( 'quotepress_settings', $s );
    }

    /* ── Katalog sayfası ────────────────────────────────────── */

    public static function render_page() {
        $groups    = self::get_groups();
        $items_all = self::get_items();
        $templates = self::templates();
        $nonce     = wp_create_nonce( 'qp_cat_nonce' );
        $cpt_list  = array_keys( get_post_types( [ 'public' => true, '_builtin' => false ], 'names' ) );
        if ( post_type_exists( 'product' ) ) array_unshift( $cpt_list, 'product' );
        $ajax_url  = admin_url( 'admin-ajax.php' );
        ?>
        <style>
        .qpc-wrap{max-width:960px;}
        .qpc-topbar{display:flex;align-items:center;gap:12px;margin-bottom:6px;}
        .qpc-topbar h1{margin:0;font-size:22px;flex:1;}
        .qpc-desc{color:#666;font-size:13px;margin-bottom:20px;}
        .qpc-group{background:#fff;border:1px solid #e0e0e0;border-radius:10px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
        .qpc-group-head{display:flex;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid #f0f0f0;cursor:pointer;}
        .qpc-group-icon{font-size:22px;min-width:28px;}
        .qpc-group-info{flex:1;}
        .qpc-group-name{font-size:15px;font-weight:700;margin:0 0 2px;}
        .qpc-group-meta{font-size:11px;color:#888;}
        .qpc-tpl-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#e8f4e8;color:#276e27;margin-right:4px;}
        .qpc-tpl-pill.heat{background:#fff0e0;color:#c05000;}
        .qpc-cnt{background:#f0f0f0;color:#666;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;}
        .qpc-gact{display:flex;gap:5px;}
        .qpb{background:none;border:1px solid #ddd;border-radius:6px;cursor:pointer;padding:5px 9px;font-size:12px;color:#555;line-height:1;}
        .qpb:hover{background:#f5f5f5;border-color:#bbb;}
        .qpb.danger:hover{background:#fef2f2;border-color:#fca5a5;color:#dc2626;}
        .qpb.primary{background:#1a6eb5;color:#fff;border-color:#1a6eb5;}
        .qpb.primary:hover{background:#155e99;}
        .qpc-body{padding:0 16px 14px;}
        /* Ürün satırı */
        .qpc-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:7px;margin-top:6px;background:#fafafa;border:1px solid #f0f0f0;}
        .qpc-item:hover{background:#f0f6ff;border-color:#c5d9f5;}
        .qpc-thumb{width:40px;height:40px;min-width:40px;border-radius:6px;overflow:hidden;border:1px solid #e8e8e8;background:#f5f5f5;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:18px;}
        .qpc-thumb img{width:40px;height:40px;object-fit:cover;}
        .qpc-item-info{flex:1;min-width:0;}
        .qpc-item-name{font-size:13px;font-weight:600;}
        .qpc-item-sub{font-size:10px;color:#aaa;margin-top:1px;}
        .qpc-item-unit{font-size:11px;color:#888;min-width:60px;}
        .qpc-item-price{font-size:12px;font-weight:600;color:#1a6eb5;min-width:80px;text-align:right;}
        .qpc-item-status{font-size:10px;min-width:50px;text-align:center;}
        .qpc-on{color:#16a34a;font-weight:700;}
        .qpc-off{color:#aaa;}
        .qpc-var-count{font-size:10px;color:#888;background:#f0f0f0;border-radius:8px;padding:1px 6px;margin-left:4px;}
        /* Inline form */
        .qpc-iform{background:#f0f6ff;border:1px dashed #93c5fd;border-radius:8px;padding:12px 14px;margin-top:10px;display:none;}
        .qpc-iform.open{display:block;}
        .qpc-frow{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px;}
        .qpc-frow label{font-size:11px;color:#666;display:block;margin-bottom:3px;font-weight:600;}
        .qpc-frow input,.qpc-frow select,.qpc-frow textarea{border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;font-family:inherit;}
        .qpc-frow .fn{flex:2;min-width:150px;}.qpc-frow .fn input{width:100%;}
        .qpc-frow .fu{width:90px;}.qpc-frow .fu input{width:100%;}
        .qpc-frow .fp{width:110px;}.qpc-frow .fp input{width:100%;}
        .qpc-frow .fd{flex:3;}.qpc-frow .fd input{width:100%;}
        .qpc-fact{display:flex;gap:8px;margin-top:6px;align-items:center;}
        .qpc-save-btn{background:#1a6eb5;color:#fff;border:none;border-radius:6px;padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;}
        .qpc-save-btn:hover{background:#155e99;}
        .qpc-cancel-btn{background:#fff;color:#555;border:1px solid #ddd;border-radius:6px;padding:7px 12px;font-size:13px;cursor:pointer;}
        .qpc-add-btn{margin-top:8px;background:#fff;border:1px dashed #93c5fd;border-radius:6px;padding:6px 14px;font-size:12px;color:#1a6eb5;cursor:pointer;font-weight:600;}
        .qpc-add-btn:hover{background:#f0f6ff;}
        /* Yeni grup paneli */
        .qpc-ngpanel{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;margin-bottom:16px;display:none;}
        .qpc-ngpanel.open{display:block;}
        .qpc-ngrow{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px;}
        .qpc-ngrow label{font-size:11px;color:#666;display:block;margin-bottom:3px;font-weight:600;}
        .qpc-ngrow input,.qpc-ngrow select{border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;font-size:13px;font-family:inherit;}
        .qpc-tpl-cards{display:flex;gap:10px;flex-wrap:wrap;}
        .qpc-tpl-card{border:2px solid #e2e8f0;border-radius:8px;padding:10px 14px;cursor:pointer;min-width:140px;}
        .qpc-tpl-card:hover{border-color:#93c5fd;background:#f0f6ff;}
        .qpc-tpl-card.sel{border-color:#1a6eb5;background:#eff6ff;}
        .qpc-tpl-card .ti{font-size:20px;margin-bottom:4px;}
        .qpc-tpl-card .tl{font-size:12px;font-weight:700;}
        .qpc-tpl-card .td{font-size:10px;color:#888;margin-top:2px;}
        /* Varyant paneli */
        .qpc-vpanel{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 14px;margin-top:8px;display:none;}
        .qpc-vpanel.open{display:block;}
        .qpc-vrow{display:flex;gap:6px;align-items:center;margin-bottom:6px;}
        .qpc-vrow input{border:1px solid #d1d5db;border-radius:5px;padding:6px 8px;font-size:12px;}
        .qpc-vrow .vl{flex:2;}
        .qpc-vrow .vp{width:90px;}
        .qpc-vrow .vd{font-size:11px;color:#888;}
        /* Import */
        .qpc-import{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .qpc-import label{font-size:12px;font-weight:600;color:#166534;}
        .qpc-import select{border:1px solid #d1d5db;border-radius:6px;padding:5px 8px;font-size:12px;}
        .qpc-import-btn{background:#16a34a;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;}
        /* Boş */
        .qpc-empty{text-align:center;padding:50px 20px;border:2px dashed #e2e8f0;border-radius:12px;color:#aaa;}
        /* Img preview */
        .qpc-img-prev{width:56px;height:56px;border-radius:8px;border:1px solid #e0e0e0;background:#f5f5f5;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:22px;flex-shrink:0;}
        .qpc-img-prev img{width:56px;height:56px;object-fit:cover;}
        .qpc-err{color:#dc2626;font-size:12px;font-weight:600;margin-top:4px;}
        </style>

        <div class="qpc-wrap wrap">
          <div class="qpc-topbar">
            <h1>📦 Ürün & Hizmet Kataloğu</h1>
            <button type="button" class="button button-primary" onclick="qpcToggleNG()">+ Yeni Grup</button>
          </div>
          <p class="qpc-desc">Gruplar → ürünler → varyantlar hiyerarşisi. Teklif formundaki açılır menü buradan otomatik güncellenir.</p>

          <!-- Yeni Grup Paneli -->
          <div class="qpc-ngpanel" id="qpc-ng">
            <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;">Yeni Grup</h3>
            <div class="qpc-ngrow">
              <div><label>İkon</label><input type="text" id="ng-icon" value="🔧" style="width:58px;font-size:18px;text-align:center;"></div>
              <div style="flex:1;"><label>Grup Adı *</label><input type="text" id="ng-name" style="width:100%;" placeholder="Örn: Isı Gider Hizmetleri"></div>
              <div><label>Renk</label><input type="color" id="ng-color" value="#1a6eb5" style="width:46px;height:36px;border:none;padding:0;cursor:pointer;"></div>
            </div>
            <div style="margin-bottom:10px;">
              <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:6px;">Teklif Şablonu</label>
              <div class="qpc-tpl-cards" id="ng-tpls">
                <?php foreach ( $templates as $tk => $tv ) : ?>
                <div class="qpc-tpl-card <?php echo $tk==='default'?'sel':''; ?>" onclick="qpcSelTpl(this,'<?php echo esc_js($tk); ?>')" data-tpl="<?php echo esc_attr($tk); ?>">
                  <div class="ti"><?php echo esc_html($tv['icon']); ?></div>
                  <div class="tl"><?php echo esc_html($tv['label']); ?></div>
                  <div class="td"><?php echo esc_html($tv['desc']); ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" id="ng-tpl" value="default">
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
              <button type="button" class="qpc-save-btn" onclick="qpcSaveGroup()">✓ Oluştur</button>
              <button type="button" class="qpc-cancel-btn" onclick="qpcToggleNG()">İptal</button>
              <span id="ng-err" class="qpc-err"></span>
            </div>
          </div>

          <?php if ( ! empty($cpt_list) && ! empty($groups) ) : ?>
          <div class="qpc-import">
            <label>🔄 WordPress ürünleri:</label>
            <select id="qpc-cpt"><?php foreach($cpt_list as $c) echo '<option value="'.esc_attr($c).'">'.esc_html($c).'</option>'; ?></select>
            <span style="font-size:12px;color:#555;">→</span>
            <select id="qpc-imp-grp">
              <option value="">— Grup —</option>
              <?php foreach($groups as $g) echo '<option value="'.esc_attr($g['id']).'">'.esc_html($g['icon']??'').' '.esc_html($g['name']).'</option>'; ?>
            </select>
            <button type="button" class="qpc-import-btn" onclick="qpcImport()">İçe Aktar</button>
            <span id="qpc-imp-msg" style="font-size:12px;color:#166534;font-weight:600;"></span>
          </div>
          <?php endif; ?>

          <!-- Grup Listesi -->
          <div id="qpc-list">
            <?php if ( empty($groups) ) : ?>
              <div class="qpc-empty">
                <div style="font-size:44px;margin-bottom:10px;">📦</div>
                <div style="font-size:15px;font-weight:600;margin-bottom:4px;color:#888;">Henüz grup yok</div>
                <div style="font-size:12px;">Yukarıdaki "+ Yeni Grup" butonuyla başlayın.<br>Her grup bir hizmet kategorisini temsil eder.</div>
              </div>
            <?php else : ?>
              <?php foreach ( $groups as $g ) :
                $g_items = array_values( array_filter( $items_all, fn($i) => ($i['group_id']??'') === $g['id'] ) );
                $tpl     = $templates[ $g['template'] ] ?? $templates['default'];
                $is_heat = ($g['template'] ?? '') === 'heat_sharing';
              ?>
              <div class="qpc-group">
                <div class="qpc-group-head" onclick="qpcToggleBody('<?php echo esc_js($g['id']); ?>')">
                  <div class="qpc-group-icon"><?php echo esc_html($g['icon']??'🔧'); ?></div>
                  <div class="qpc-group-info">
                    <div class="qpc-group-name"><?php echo esc_html($g['name']); ?></div>
                    <div class="qpc-group-meta">
                      <span class="qpc-tpl-pill <?php echo $is_heat?'heat':''; ?>"><?php echo esc_html($tpl['icon']); ?> <?php echo esc_html($tpl['label']); ?></span>
                      <span class="qpc-cnt"><?php echo count($g_items); ?> ürün</span>
                    </div>
                  </div>
                  <div class="qpc-gact" onclick="event.stopPropagation()">
                    <button type="button" class="qpb" onclick="qpcOpenEditGroup('<?php echo esc_js($g['id']); ?>','<?php echo esc_js($g['name']); ?>','<?php echo esc_js($g['template']??'default'); ?>','<?php echo esc_js($g['color']??'#1a6eb5'); ?>','<?php echo esc_js($g['icon']??'🔧'); ?>')">✏️ Düzenle</button>
                    <button type="button" class="qpb danger" onclick="qpcDelGroup('<?php echo esc_js($g['id']); ?>','<?php echo esc_js($g['name']); ?>')">🗑️</button>
                  </div>
                  <span style="color:#ccc;margin-left:6px;" id="qpc-arr-<?php echo esc_attr($g['id']); ?>">▾</span>
                </div>

                <div class="qpc-body" id="qpc-body-<?php echo esc_attr($g['id']); ?>">
                  <?php if ( empty($g_items) ) : ?>
                    <div style="color:#bbb;font-size:12px;padding:8px 0;">Henüz ürün yok.</div>
                  <?php else : ?>
                    <?php foreach ( $g_items as $it ) :
                      $img_id  = intval($it['image_id'] ?? 0);
                      $img_url = $img_id ? wp_get_attachment_image_url($img_id,[48,48]) : '';
                      $variants = self::get_variants($it['id']);
                    ?>
                    <div class="qpc-item" id="qpci-<?php echo esc_attr($it['id']); ?>">
                      <div class="qpc-thumb"><?php if($img_url): ?><img src="<?php echo esc_url($img_url); ?>" alt=""><?php else: ?>📦<?php endif; ?></div>
                      <div class="qpc-item-info">
                        <div class="qpc-item-name"><?php echo esc_html($it['name']); ?><?php if(!empty($variants)): ?><span class="qpc-var-count"><?php echo count($variants); ?> varyant</span><?php endif; ?></div>
                        <?php if($it['desc']??'') echo '<div class="qpc-item-sub">'.esc_html(mb_strimwidth($it['desc'],0,70,'...')).'</div>'; ?>
                      </div>
                      <span class="qpc-item-unit"><?php echo esc_html($it['unit']??'—'); ?></span>
                      <span class="qpc-item-price"><?php echo $it['default_price'] ? number_format((float)$it['default_price'],2,',','.') : '—'; ?></span>
                      <span class="qpc-item-status <?php echo ($it['active']??true)?'qpc-on':'qpc-off'; ?>"><?php echo ($it['active']??true)?'✓':'Pasif'; ?></span>
                      <div style="display:flex;gap:4px;">
                        <button type="button" class="qpb" onclick="qpcEditItem('<?php echo esc_js($it['id']); ?>','<?php echo esc_js($g['id']); ?>','<?php echo esc_js($it['name']); ?>','<?php echo esc_js($it['unit']??''); ?>','<?php echo esc_js($it['default_price']??''); ?>','<?php echo esc_js($it['desc']??''); ?>',<?php echo ($it['active']??true)?'true':'false'; ?>,<?php echo $img_id; ?>,'<?php echo esc_js($img_url); ?>')" title="Düzenle">✏️</button>
                        <button type="button" class="qpb" onclick="qpcToggleVariants('<?php echo esc_js($it['id']); ?>')" title="Varyantlar">⚙️</button>
                        <button type="button" class="qpb danger" onclick="qpcDelItem('<?php echo esc_js($it['id']); ?>','<?php echo esc_js($it['name']); ?>')" title="Sil">🗑️</button>
                      </div>
                    </div>

                    <!-- Varyant Paneli -->
                    <div class="qpc-vpanel" id="qpc-vp-<?php echo esc_attr($it['id']); ?>">
                      <div style="font-size:12px;font-weight:700;color:#b45309;margin-bottom:8px;">⚙️ Varyantlar — <?php echo esc_html($it['name']); ?></div>
                      <div style="font-size:11px;color:#888;margin-bottom:8px;">Kablolu/Kablosuz, M-Bus/Pulse gibi seçenekler. Fiyat farkı + veya - olabilir.</div>
                      <div id="qpc-vrows-<?php echo esc_attr($it['id']); ?>">
                        <?php foreach ( $variants as $vi => $v ) : ?>
                        <div class="qpc-vrow" id="qpcvr-<?php echo esc_attr($it['id']); ?>-<?php echo $vi; ?>">
                          <input type="text" class="vl" placeholder="Varyant adı (örn: Kablolu)" value="<?php echo esc_attr($v['label']); ?>" data-field="label">
                          <input type="number" class="vp" placeholder="±Fiyat farkı" step="0.01" value="<?php echo esc_attr($v['price_delta']??0); ?>" data-field="price_delta">
                          <label class="vd"><input type="checkbox" <?php echo ($v['is_default']??false)?'checked':''; ?> data-field="is_default"> Varsayılan</label>
                          <button type="button" class="qpb danger" onclick="this.closest('.qpc-vrow').remove()" style="padding:4px 7px;">✕</button>
                        </div>
                        <?php endforeach; ?>
                      </div>
                      <div style="display:flex;gap:8px;margin-top:8px;">
                        <button type="button" class="qpb" onclick="qpcAddVRow('<?php echo esc_js($it['id']); ?>')">+ Varyant Ekle</button>
                        <button type="button" class="qpc-save-btn" style="font-size:12px;padding:5px 12px;" onclick="qpcSaveVariants('<?php echo esc_js($it['id']); ?>')">💾 Kaydet</button>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  <?php endif; ?>

                  <!-- Inline Ürün Formu -->
                  <div class="qpc-iform" id="qpc-iform-<?php echo esc_attr($g['id']); ?>">
                    <div style="font-size:12px;font-weight:700;color:#1a6eb5;margin-bottom:8px;" id="qpc-iform-title-<?php echo esc_attr($g['id']); ?>">+ Yeni Ürün / Hizmet</div>
                    <input type="hidden" id="qpc-fi-id-<?php echo esc_attr($g['id']); ?>">
                    <div class="qpc-frow">
                      <div class="fn"><label>Ad *</label><input type="text" id="qpc-fi-name-<?php echo esc_attr($g['id']); ?>" placeholder="Isı sayacı, kurulum hizmeti..."></div>
                      <div class="fu"><label>Birim</label><input type="text" id="qpc-fi-unit-<?php echo esc_attr($g['id']); ?>" placeholder="Adet, Ay..."></div>
                      <div class="fp"><label>Varsayılan Fiyat</label><input type="number" id="qpc-fi-price-<?php echo esc_attr($g['id']); ?>" step="0.01" min="0" placeholder="0.00"></div>
                    </div>
                    <div class="qpc-frow">
                      <div class="fd"><label>Açıklama</label><input type="text" id="qpc-fi-desc-<?php echo esc_attr($g['id']); ?>" placeholder="Kısa açıklama..."></div>
                      <div style="min-width:80px;"><label>Durum</label><br><label style="font-size:13px;"><input type="checkbox" id="qpc-fi-active-<?php echo esc_attr($g['id']); ?>" checked> Aktif</label></div>
                    </div>
                    <!-- Görsel -->
                    <div class="qpc-frow" style="align-items:center;gap:12px;">
                      <div class="qpc-img-prev" id="qpc-fi-prev-<?php echo esc_attr($g['id']); ?>">📦</div>
                      <div>
                        <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:4px;">Ürün Görseli</label>
                        <input type="hidden" id="qpc-fi-imgid-<?php echo esc_attr($g['id']); ?>">
                        <button type="button" class="qpb" onclick="qpcPickImg('<?php echo esc_js($g['id']); ?>')">📷 Seç</button>
                        <button type="button" class="qpb" id="qpc-fi-imgrm-<?php echo esc_attr($g['id']); ?>" onclick="qpcRmImg('<?php echo esc_js($g['id']); ?>')" style="display:none;">✕</button>
                      </div>
                    </div>
                    <div class="qpc-fact">
                      <button type="button" class="qpc-save-btn" onclick="qpcSaveItem('<?php echo esc_js($g['id']); ?>')">✓ Kaydet</button>
                      <button type="button" class="qpc-cancel-btn" onclick="qpcCloseIform('<?php echo esc_js($g['id']); ?>')">İptal</button>
                      <span id="qpc-fi-err-<?php echo esc_attr($g['id']); ?>" class="qpc-err"></span>
                    </div>
                  </div>

                  <button type="button" class="qpc-add-btn" onclick="qpcOpenIform('<?php echo esc_js($g['id']); ?>')">+ Ürün / Hizmet Ekle</button>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Grup Düzenle Modal -->
        <div id="qpc-emodal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
          <div style="background:#fff;border-radius:12px;padding:24px 28px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2);">
            <h3 style="margin:0 0 14px;">Grubu Düzenle</h3>
            <input type="hidden" id="eg-id">
            <div class="qpc-ngrow">
              <div><label>İkon</label><input type="text" id="eg-icon" style="width:58px;font-size:18px;text-align:center;"></div>
              <div style="flex:1;"><label>Ad</label><input type="text" id="eg-name" style="width:100%;"></div>
              <div><label>Renk</label><input type="color" id="eg-color" style="width:46px;height:36px;border:none;padding:0;cursor:pointer;"></div>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:6px;">Şablon</label>
              <div class="qpc-tpl-cards" id="eg-tpls">
                <?php foreach ( $templates as $tk => $tv ) : ?>
                <div class="qpc-tpl-card" onclick="qpcSelTplE(this,'<?php echo esc_js($tk); ?>')" data-tpl="<?php echo esc_attr($tk); ?>">
                  <div class="ti"><?php echo esc_html($tv['icon']); ?></div>
                  <div class="tl"><?php echo esc_html($tv['label']); ?></div>
                  <div class="td"><?php echo esc_html($tv['desc']); ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" id="eg-tpl">
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
              <button type="button" class="qpc-cancel-btn" onclick="qpcCloseEModal()">İptal</button>
              <button type="button" class="qpc-save-btn" onclick="qpcUpdateGroup()">Kaydet</button>
            </div>
          </div>
        </div>

        <script>
        var QPC = {
            nonce: '<?php echo esc_js($nonce); ?>',
            ajax:  '<?php echo esc_js($ajax_url); ?>',
            mediaFrames: {}
        };

        function qpcPost(data, cb) {
            var fd = new FormData();
            fd.append('nonce', QPC.nonce);
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
            fetch(QPC.ajax, {method:'POST', body:fd})
              .then(function(r){ return r.json(); })
              .then(function(res){ if(cb) cb(res); })
              .catch(function(e){ console.error('QPC error', e); });
        }

        // ── Yeni Grup ─────────────────────────────────────────
        function qpcToggleNG() {
            var p = document.getElementById('qpc-ng');
            p.classList.toggle('open');
            if (p.classList.contains('open')) { document.getElementById('ng-name').focus(); }
        }
        function qpcSelTpl(el, tpl) {
            document.querySelectorAll('#ng-tpls .qpc-tpl-card').forEach(function(c){ c.classList.remove('sel'); });
            el.classList.add('sel');
            document.getElementById('ng-tpl').value = tpl;
        }
        function qpcSaveGroup() {
            var name = document.getElementById('ng-name').value.trim();
            var err  = document.getElementById('ng-err');
            err.textContent = '';
            if (!name) { err.textContent = 'Ad zorunlu!'; document.getElementById('ng-name').focus(); return; }
            qpcPost({
                action:   'qp_cat_save_group',
                id:       '',
                name:     name,
                template: document.getElementById('ng-tpl').value,
                color:    document.getElementById('ng-color').value,
                icon:     document.getElementById('ng-icon').value,
                desc:     ''
            }, function(res) {
                if (res.success) { location.reload(); }
                else { err.textContent = 'Hata: ' + (res.data || 'bilinmeyen'); }
            });
        }

        // ── Grup Düzenle ──────────────────────────────────────
        function qpcOpenEditGroup(id, name, tpl, color, icon) {
            document.getElementById('eg-id').value    = id;
            document.getElementById('eg-name').value  = name;
            document.getElementById('eg-color').value = color;
            document.getElementById('eg-icon').value  = icon;
            document.getElementById('eg-tpl').value   = tpl;
            document.querySelectorAll('#eg-tpls .qpc-tpl-card').forEach(function(c){
                c.classList.toggle('sel', c.dataset.tpl === tpl);
            });
            document.getElementById('qpc-emodal').style.display = 'flex';
        }
        function qpcCloseEModal() { document.getElementById('qpc-emodal').style.display = 'none'; }
        function qpcSelTplE(el, tpl) {
            document.querySelectorAll('#eg-tpls .qpc-tpl-card').forEach(function(c){ c.classList.remove('sel'); });
            el.classList.add('sel');
            document.getElementById('eg-tpl').value = tpl;
        }
        function qpcUpdateGroup() {
            qpcPost({
                action:   'qp_cat_save_group',
                id:       document.getElementById('eg-id').value,
                name:     document.getElementById('eg-name').value,
                template: document.getElementById('eg-tpl').value,
                color:    document.getElementById('eg-color').value,
                icon:     document.getElementById('eg-icon').value,
                desc:     ''
            }, function(res){ if(res.success) location.reload(); });
        }
        function qpcDelGroup(id, name) {
            if (!confirm('"'+name+'" grubunu ve tüm ürünlerini silmek istediğinizden emin misiniz?')) return;
            qpcPost({action:'qp_cat_delete_group', id:id}, function(res){ if(res.success) location.reload(); });
        }

        // ── Gövde aç/kapat ────────────────────────────────────
        function qpcToggleBody(id) {
            var b = document.getElementById('qpc-body-'+id);
            var a = document.getElementById('qpc-arr-'+id);
            if (!b) return;
            var open = b.style.display !== 'none' && b.style.display !== '';
            b.style.display = open ? 'none' : 'block';
            if (a) a.textContent = open ? '▸' : '▾';
        }

        // ── Ürün Formu ────────────────────────────────────────
        function qpcOpenIform(gid) {
            document.querySelectorAll('.qpc-iform').forEach(function(f){ f.classList.remove('open'); });
            var f = document.getElementById('qpc-iform-'+gid);
            f.classList.add('open');
            document.getElementById('qpc-fi-id-'+gid).value    = '';
            document.getElementById('qpc-fi-name-'+gid).value  = '';
            document.getElementById('qpc-fi-unit-'+gid).value  = '';
            document.getElementById('qpc-fi-price-'+gid).value = '';
            document.getElementById('qpc-fi-desc-'+gid).value  = '';
            document.getElementById('qpc-fi-active-'+gid).checked = true;
            document.getElementById('qpc-fi-imgid-'+gid).value = '';
            document.getElementById('qpc-fi-prev-'+gid).innerHTML = '📦';
            var rm = document.getElementById('qpc-fi-imgrm-'+gid);
            if (rm) rm.style.display = 'none';
            document.getElementById('qpc-iform-title-'+gid).textContent = '+ Yeni Ürün / Hizmet';
            document.getElementById('qpc-fi-name-'+gid).focus();
            f.scrollIntoView({behavior:'smooth', block:'nearest'});
        }
        function qpcEditItem(id, gid, name, unit, price, desc, active, imgId, imgUrl) {
            qpcOpenIform(gid);
            document.getElementById('qpc-fi-id-'+gid).value    = id;
            document.getElementById('qpc-fi-name-'+gid).value  = name;
            document.getElementById('qpc-fi-unit-'+gid).value  = unit;
            document.getElementById('qpc-fi-price-'+gid).value = price;
            document.getElementById('qpc-fi-desc-'+gid).value  = desc;
            document.getElementById('qpc-fi-active-'+gid).checked = active;
            if (imgId) {
                document.getElementById('qpc-fi-imgid-'+gid).value = imgId;
                if (imgUrl) document.getElementById('qpc-fi-prev-'+gid).innerHTML = '<img src="'+imgUrl+'">';
                var rm = document.getElementById('qpc-fi-imgrm-'+gid);
                if (rm) rm.style.display = '';
            }
            document.getElementById('qpc-iform-title-'+gid).textContent = '✏️ Ürünü Düzenle';
        }
        function qpcCloseIform(gid) { document.getElementById('qpc-iform-'+gid).classList.remove('open'); }
        function qpcSaveItem(gid) {
            var name = document.getElementById('qpc-fi-name-'+gid).value.trim();
            var err  = document.getElementById('qpc-fi-err-'+gid);
            err.textContent = '';
            if (!name) { err.textContent = 'Ad zorunlu!'; document.getElementById('qpc-fi-name-'+gid).focus(); return; }
            qpcPost({
                action:        'qp_cat_save_item',
                group_id:      gid,
                id:            document.getElementById('qpc-fi-id-'+gid).value,
                name:          name,
                unit:          document.getElementById('qpc-fi-unit-'+gid).value,
                default_price: document.getElementById('qpc-fi-price-'+gid).value,
                desc:          document.getElementById('qpc-fi-desc-'+gid).value,
                active:        document.getElementById('qpc-fi-active-'+gid).checked ? '1' : '',
                image_id:      document.getElementById('qpc-fi-imgid-'+gid).value || '0',
            }, function(res){ if(res.success) location.reload(); else { err.textContent = 'Hata: '+(res.data||'?'); }});
        }
        function qpcDelItem(id, name) {
            if (!confirm('"'+name+'" silinsin mi?')) return;
            qpcPost({action:'qp_cat_delete_item', id:id}, function(res){ if(res.success) location.reload(); });
        }

        // ── Görsel ────────────────────────────────────────────
        function qpcPickImg(gid) {
            if (!QPC.mediaFrames[gid]) {
                QPC.mediaFrames[gid] = wp.media({title:'Görsel Seç', button:{text:'Seç'}, multiple:false, library:{type:'image'}});
                QPC.mediaFrames[gid].on('select', function(){
                    var att = QPC.mediaFrames[gid].state().get('selection').first().toJSON();
                    var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    document.getElementById('qpc-fi-imgid-'+gid).value = att.id;
                    document.getElementById('qpc-fi-prev-'+gid).innerHTML = '<img src="'+url+'">';
                    var rm = document.getElementById('qpc-fi-imgrm-'+gid);
                    if (rm) rm.style.display = '';
                });
            }
            QPC.mediaFrames[gid].open();
        }
        function qpcRmImg(gid) {
            document.getElementById('qpc-fi-imgid-'+gid).value = '';
            document.getElementById('qpc-fi-prev-'+gid).innerHTML = '📦';
            var rm = document.getElementById('qpc-fi-imgrm-'+gid);
            if (rm) rm.style.display = 'none';
        }

        // ── Varyantlar ────────────────────────────────────────
        function qpcToggleVariants(itemId) {
            var p = document.getElementById('qpc-vp-'+itemId);
            if (!p) return;
            p.classList.toggle('open');
        }
        function qpcAddVRow(itemId) {
            var c = document.getElementById('qpc-vrows-'+itemId);
            var idx = c.children.length;
            var row = document.createElement('div');
            row.className = 'qpc-vrow';
            row.id = 'qpcvr-'+itemId+'-'+idx;
            row.innerHTML = '<input type="text" class="vl" placeholder="Varyant adı (örn: Kablolu)" data-field="label">'
                          + '<input type="number" class="vp" placeholder="±Fiyat farkı" step="0.01" data-field="price_delta">'
                          + '<label class="vd"><input type="checkbox" data-field="is_default"> Varsayılan</label>'
                          + '<button type="button" class="qpb danger" onclick="this.closest(\'.qpc-vrow\').remove()" style="padding:4px 7px;">✕</button>';
            c.appendChild(row);
            row.querySelector('input.vl').focus();
        }
        function qpcSaveVariants(itemId) {
            var rows = document.querySelectorAll('#qpc-vrows-'+itemId+' .qpc-vrow');
            var variants = [];
            rows.forEach(function(row){
                var label = row.querySelector('[data-field="label"]').value.trim();
                if (!label) return;
                variants.push({
                    label:       label,
                    price_delta: row.querySelector('[data-field="price_delta"]').value || '0',
                    is_default:  row.querySelector('[data-field="is_default"]').checked ? '1' : ''
                });
            });
            var fd = new FormData();
            fd.append('nonce', QPC.nonce);
            fd.append('action', 'qp_cat_save_variants');
            fd.append('item_id', itemId);
            variants.forEach(function(v, i){
                fd.append('variants['+i+'][label]', v.label);
                fd.append('variants['+i+'][price_delta]', v.price_delta);
                fd.append('variants['+i+'][is_default]', v.is_default);
            });
            fetch(QPC.ajax, {method:'POST', body:fd})
              .then(function(r){return r.json();})
              .then(function(res){ if(res.success) { alert('Varyantlar kaydedildi.'); location.reload(); } });
        }

        // ── Import ────────────────────────────────────────────
        function qpcImport() {
            var gid = document.getElementById('qpc-imp-grp') ? document.getElementById('qpc-imp-grp').value : '';
            var cpt = document.getElementById('qpc-cpt') ? document.getElementById('qpc-cpt').value : '';
            if (!gid) { alert('Grup seçin.'); return; }
            var btn = document.querySelector('.qpc-import-btn');
            btn.disabled = true; btn.textContent = '...';
            qpcPost({action:'qp_cat_import_cpt', post_type:cpt, group_id:gid}, function(res){
                btn.disabled = false; btn.textContent = 'İçe Aktar';
                if (res.success) {
                    document.getElementById('qpc-imp-msg').textContent = '✓ '+res.data.imported+' eklendi';
                    setTimeout(function(){ location.reload(); }, 700);
                } else { alert('Hata: '+(res.data||'?')); }
            });
        }

        // Enter ile kaydet
        document.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                var form = e.target.closest('.qpc-iform');
                if (form) {
                    var gid = form.id.replace('qpc-iform-','');
                    qpcSaveItem(gid);
                    e.preventDefault();
                }
                if (e.target.id === 'ng-name') { qpcSaveGroup(); e.preventDefault(); }
            }
        });
        </script>
        <?php
    }
}
