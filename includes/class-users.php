<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Users {

    const CAP = 'quotepress_user';

    public static function init() {
        add_action( 'admin_menu',                 [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_qp_toggle_user',     [ __CLASS__, 'handle_toggle' ] );
        // Restrict admin menu for plugin-only users
        add_action( 'admin_menu',                 [ __CLASS__, 'restrict_admin_menu' ], 999 );
        // Redirect plugin-only users away from WP dashboard
        add_action( 'admin_init',                 [ __CLASS__, 'redirect_if_no_manage' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'quotepress-settings',
            __( 'Kullanıcılar', 'quotepress' ),
            '👤 ' . __( 'Kullanıcılar', 'quotepress' ),
            'manage_options',
            'quotepress-users',
            [ __CLASS__, 'render' ]
        );
    }

    /* ── Hide all admin menus for plugin-only users ────────── */
    public static function restrict_admin_menu() {
        if ( current_user_can( 'manage_options' ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        global $menu, $submenu;
        // Remove everything except the QuotePress menu
        foreach ( $menu as $pos => $item ) {
            $slug = $item[2] ?? '';
            if ( $slug !== 'quotepress-settings' ) {
                remove_menu_page( $slug );
            }
        }
        // Remove sub-menus that are not QuotePress
        foreach ( $submenu as $parent => $items ) {
            if ( $parent !== 'quotepress-settings' ) {
                unset( $submenu[ $parent ] );
            }
        }
        // Also hide users/profile menu items that manage other users
        remove_submenu_page( 'quotepress-settings', 'quotepress-users' );
    }

    /* ── Redirect plugin-only users from wp-admin dashboard ── */
    public static function redirect_if_no_manage() {
        if ( current_user_can( 'manage_options' ) ) return;
        if ( ! current_user_can( self::CAP ) ) return;

        $screen = get_current_screen();
        if ( ! $screen ) return;
        // Allow QuotePress pages and their AJAX
        $allowed_bases = [ 'quotepress', 'admin-ajax' ];
        $is_qp = false;
        foreach ( $allowed_bases as $base ) {
            if ( strpos( $screen->base, $base ) !== false || strpos( $screen->id, $base ) !== false ) {
                $is_qp = true;
                break;
            }
        }
        if ( ! $is_qp && $screen->base !== 'dashboard' ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'quotepress' ), 403 );
        }
        if ( $screen->base === 'dashboard' ) {
            wp_redirect( admin_url( 'admin.php?page=quotepress-settings' ) );
            exit;
        }
    }

    /* ── AJAX: toggle capability ───────────────────────────── */
    public static function handle_toggle() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_users_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $user_id = intval( $_POST['user_id'] ?? 0 );
        $action  = sanitize_text_field( $_POST['toggle_action'] ?? '' );
        if ( ! $user_id || ! in_array( $action, [ 'grant', 'revoke' ], true ) ) {
            wp_send_json_error( 'invalid_params' );
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) { wp_send_json_error( 'user_not_found' ); }

        // Don't allow demoting another admin
        if ( $user->has_cap( 'manage_options' ) ) {
            wp_send_json_error( 'cannot_change_admin' );
        }

        if ( $action === 'grant' ) {
            $user->add_cap( self::CAP );
        } else {
            $user->remove_cap( self::CAP );
        }

        wp_send_json_success( [ 'user_id' => $user_id, 'action' => $action ] );
    }

    /* ── Render admin page ─────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $colors = QuotePress_Settings::active_theme_colors();
        $nonce  = wp_create_nonce( 'qp_users_nonce' );
        $users  = get_users( [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 200 ] );
        ?>
        <div class="wrap" style="max-width:900px;">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:var(--qp-primary);color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            Kullanıcılar
        </h1>

        <style>
        :root{
          --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
          --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
          --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
          --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
        }
        .qp-usr-info{background:var(--qp-light);border:1px solid var(--qp-border);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:var(--qp-dark);line-height:1.6;}
        .qp-usr-table{width:100%;border-collapse:collapse;font-size:13px;}
        .qp-usr-table thead th{background:var(--qp-light);padding:10px 14px;text-align:left;font-weight:700;color:var(--qp-dark);border-bottom:2px solid var(--qp-border);}
        .qp-usr-table tbody td{padding:10px 14px;border-bottom:1px solid #f5f5f5;vertical-align:middle;}
        .qp-usr-table tbody tr:hover td{background:#fafafa;}
        .qp-usr-name{font-weight:700;color:var(--qp-dark);}
        .qp-usr-role{display:inline-block;background:#f0f0f0;border-radius:4px;padding:1px 7px;font-size:11px;color:#666;font-weight:600;}
        .qp-usr-cap{display:inline-block;background:var(--qp-light);border:1px solid var(--qp-border);border-radius:4px;padding:1px 8px;font-size:11px;color:var(--qp-dark);font-weight:700;}
        .qp-toggle-btn{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s;line-height:1.4;}
        .qp-toggle-btn.grant{background:var(--qp-light);color:var(--qp-dark);border:1px solid var(--qp-border);}
        .qp-toggle-btn.grant:hover{background:var(--qp-primary);color:#fff;}
        .qp-toggle-btn.revoke{background:#fdecea;color:#c62828;border:1px solid #ef9a9a;}
        .qp-toggle-btn.revoke:hover{background:#ffcdd2;}
        </style>

        <div class="qp-usr-info">
            <strong>ℹ Nasıl Çalışır?</strong><br>
            <strong>QuotePress Erişimi</strong> verilen WordPress kullanıcıları yalnızca bu eklentinin sayfalarını görebilir.
            Site yönetimi, diğer eklentiler veya temaya erişemezler.<br>
            Site yöneticileri (admin) listede görünür ama yetkilerini değiştiremezsiniz.
        </div>

        <div style="background:#fff;border:1px solid var(--qp-border);border-radius:8px;overflow:hidden;">
        <table class="qp-usr-table">
            <thead><tr>
                <th>Kullanıcı</th>
                <th>E-posta</th>
                <th>Rol</th>
                <th>QuotePress Erişimi</th>
                <th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $users as $user ) :
                $has_cap    = $user->has_cap( self::CAP );
                $is_admin   = $user->has_cap( 'manage_options' );
                $roles_disp = implode( ', ', array_map( function( $r ) {
                    $role_obj = get_role( $r );
                    return $role_obj ? translate_user_role( $role_obj->name ) : $r;
                }, (array) $user->roles ) );
            ?>
            <tr id="qp-usr-row-<?php echo (int) $user->ID; ?>">
                <td>
                    <?php echo get_avatar( $user->ID, 32, '', '', [ 'class' => '', 'style' => 'border-radius:50%;vertical-align:middle;margin-right:8px;' ] ); ?>
                    <span class="qp-usr-name"><?php echo esc_html( $user->display_name ); ?></span>
                </td>
                <td style="color:#666;font-size:12px;"><?php echo esc_html( $user->user_email ); ?></td>
                <td><span class="qp-usr-role"><?php echo esc_html( $roles_disp ); ?></span></td>
                <td id="qp-usr-cap-<?php echo (int) $user->ID; ?>">
                    <?php if ( $is_admin ) : ?>
                    <span class="qp-usr-cap">✓ Tam Yönetici</span>
                    <?php elseif ( $has_cap ) : ?>
                    <span class="qp-usr-cap">✓ QuotePress</span>
                    <?php else : ?>
                    <span style="font-size:12px;color:#ccc;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( $is_admin ) : ?>
                    <span style="font-size:12px;color:#aaa;">Değiştirilemez</span>
                    <?php elseif ( $has_cap ) : ?>
                    <button class="qp-toggle-btn revoke" onclick="qpToggleUser(<?php echo (int) $user->ID; ?>, 'revoke')">
                        ✕ Erişimi Kaldır
                    </button>
                    <?php else : ?>
                    <button class="qp-toggle-btn grant" onclick="qpToggleUser(<?php echo (int) $user->ID; ?>, 'grant')">
                        + Erişim Ver
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div style="margin-top:14px;font-size:12px;color:#aaa;">
            Yeni WordPress kullanıcısı eklemek için:
            <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" style="color:var(--qp-primary);">Kullanıcı → Yeni Ekle →</a>
        </div>
        </div>

        <script>
        var qpUsrNonce = '<?php echo esc_js( $nonce ); ?>';
        var qpAjaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

        function qpToggleUser(userId, action) {
            var fd = new FormData();
            fd.append('action',        'qp_toggle_user');
            fd.append('user_id',       userId);
            fd.append('toggle_action', action);
            fd.append('_wpnonce',      qpUsrNonce);

            fetch(qpAjaxUrl, {method:'POST', body:fd})
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.success) {
                    location.reload();
                } else {
                    alert('Hata: ' + (d.data || 'Bilinmeyen hata'));
                }
            });
        }
        </script>
        <?php
    }
}
