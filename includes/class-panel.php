<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Panel {

    public static function init() {
        add_action( 'init',              [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'add_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
        add_action( 'wp_ajax_qp_send_quote',     [ __CLASS__, 'handle_send_quote' ] );
        add_action( 'wp_ajax_qp_delete_request', [ __CLASS__, 'handle_delete' ] );
        add_action( 'wp_ajax_qp_update_status',  [ __CLASS__, 'handle_update_status' ] );
    }

    public static function register_rewrite() {
        $slug = QuotePress_Settings::get( 'panel_slug', 'quote-panel' );
        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?qp_panel=1', 'top' );
    }

    public static function add_query_var( $vars ) {
        $vars[] = 'qp_panel';
        return $vars;
    }

    public static function maybe_render() {
        if ( ! get_query_var( 'qp_panel' ) ) return;
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( home_url( '/' . QuotePress_Settings::get( 'panel_slug', 'quote-panel' ) . '/' ) ) );
            exit;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'quotepress' ) );
        }
        self::render();
        exit;
    }

    /* ── AJAX: Send quote ──────────────────────────────────── */
    public static function handle_send_quote() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_panel_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }

        $id       = intval( $_POST['request_id'] ?? 0 );
        $note     = sanitize_textarea_field( wp_unslash( $_POST['quote_note'] ?? '' ) );
        $data_str = wp_unslash( $_POST['quote_data'] ?? '{}' );
        $payload  = json_decode( $data_str, true ) ?: [];

        $request = QuotePress_Database::get( $id );
        if ( ! $request ) { wp_send_json_error( 'not_found' ); }

        QuotePress_Database::update( $id, [
            'status'             => 'quoted',
            'quote_note'         => $note,
            'quote_data'         => $data_str,
            'contact_salutation' => sanitize_text_field( wp_unslash( $_POST['contact_salutation'] ?? 'neutral' ) ),
        ] );

        $pdf_path = QuotePress_PDF::generate( $request, $payload, $note );
        QuotePress_Mailer::send_quote( $request, $payload, $note, $pdf_path );

        if ( $pdf_path && file_exists( $pdf_path ) ) {
            @unlink( $pdf_path );
        }

        wp_send_json_success( 'sent' );
    }

    /* ── AJAX: Delete request ──────────────────────────────── */
    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_panel_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }
        $id = intval( $_POST['request_id'] ?? 0 );
        global $wpdb;
        $wpdb->delete( QuotePress_Database::table(), [ 'id' => $id ] );
        wp_send_json_success( 'deleted' );
    }

    /* ── AJAX: Update status (won / lost / quoted) ─────────── */
    public static function handle_update_status() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'permission_denied' ); }
        if ( ! check_ajax_referer( 'qp_panel_nonce', '_wpnonce', false ) ) { wp_send_json_error( 'invalid_nonce' ); }
        $id     = intval( $_POST['request_id'] ?? 0 );
        $status = sanitize_text_field( $_POST['new_status'] ?? '' );
        if ( ! in_array( $status, [ 'pending', 'quoted', 'won', 'lost' ], true ) ) {
            wp_send_json_error( 'invalid_status' );
        }
        QuotePress_Database::update( $id, [ 'status' => $status ] );
        wp_send_json_success( [ 'status' => $status ] );
    }

    /* ── Render panel page ─────────────────────────────────── */
    public static function render() {
        $colors       = QuotePress_Settings::active_theme_colors();
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $selected_id   = intval( $_GET['request'] ?? 0 );
        $requests      = QuotePress_Database::get_all( $status_filter );
        $request       = $selected_id ? QuotePress_Database::get( $selected_id ) : null;
        $items         = $request ? ( json_decode( $request->items, true ) ?: [] ) : [];
        $total         = QuotePress_Database::count();
        $pending       = QuotePress_Database::count( 'pending' );
        $quoted        = QuotePress_Database::count( 'quoted' );
        $won           = QuotePress_Database::count( 'won' );
        $lost          = QuotePress_Database::count( 'lost' );

        $currencies   = array_values( array_filter( array_map( 'trim', explode( "\n", QuotePress_Settings::get( 'currencies', 'USD' ) ) ) ) );
        $def_currency = QuotePress_Settings::get( 'default_currency', 'USD' );
        $def_vat      = QuotePress_Settings::get( 'default_vat', '0' );
        $validity     = QuotePress_Settings::get( 'validity_days', '30' );
        $panel_nonce  = wp_create_nonce( 'qp_panel_nonce' );
        $company      = QuotePress_Settings::get( 'company_name' );
        $slug         = QuotePress_Settings::get( 'panel_slug', 'quote-panel' );

        $badge_map = [
            'pending' => [ 'cls' => 'pending', 'lbl' => 'Beklemede' ],
            'quoted'  => [ 'cls' => 'quoted',  'lbl' => 'Tekliflendirildi' ],
            'won'     => [ 'cls' => 'won',      'lbl' => '✓ Kabul Edildi' ],
            'lost'    => [ 'cls' => 'lost',     'lbl' => '✗ Reddedildi' ],
        ];
        $badge = fn( $s, $lg = false ) =>
            '<span class="badge ' . ( $badge_map[$s]['cls'] ?? 'pending' ) . '"' . ( $lg ? ' style="font-size:13px;padding:5px 14px;"' : '' ) . '>'
            . esc_html( $badge_map[$s]['lbl'] ?? $s ) . '</span>';

        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $company ); ?> – Teklif Paneli</title>
<style>
:root{
  --qp-primary:<?php echo esc_attr( $colors['primary'] ); ?>;
  --qp-dark:<?php echo esc_attr( $colors['dark'] ); ?>;
  --qp-light:<?php echo esc_attr( $colors['light'] ); ?>;
  --qp-border:<?php echo esc_attr( $colors['border'] ); ?>;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f0;color:#222;font-size:14px;}
.qp-top{background:var(--qp-primary);color:#fff;height:52px;display:flex;align-items:center;justify-content:space-between;padding:0 20px;gap:12px;}
.qp-top h1{font-size:16px;font-weight:700;}
.qp-top-links{display:flex;gap:12px;align-items:center;}
.qp-top a{color:rgba(255,255,255,.8);text-decoration:none;font-size:13px;}
.qp-top a:hover{color:#fff;}
.qp-top a.btn{background:rgba(255,255,255,.15);padding:5px 13px;border-radius:6px;}
.qp-layout{display:grid;grid-template-columns:300px 1fr;min-height:calc(100vh - 52px);}
.qp-sidebar{background:#fff;border-right:1px solid #e0e0e0;display:flex;flex-direction:column;}
.qp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:#e0e0e0;}
.qp-stats.two{grid-template-columns:repeat(2,1fr);}
.qp-stat{background:#fff;padding:10px 8px;text-align:center;}
.qp-stat .n{font-size:20px;font-weight:700;color:var(--qp-primary);}
.qp-stat .n.orange{color:#e65100;}
.qp-stat .n.green{color:#2e7d32;}
.qp-stat .n.red{color:#c62828;}
.qp-stat .l{font-size:10px;color:#aaa;margin-top:2px;}
.qp-filters{display:flex;gap:1px;background:#e0e0e0;flex-wrap:wrap;}
.qp-filters a{flex:1;min-width:0;text-align:center;padding:8px 2px;background:#fafafa;font-size:11px;font-weight:600;color:#555;text-decoration:none;transition:background .12s;white-space:nowrap;}
.qp-filters a.active,.qp-filters a:hover{background:var(--qp-light);color:var(--qp-primary);}
.qp-filters a.active{border-bottom:2px solid var(--qp-primary);}
.qp-list{overflow-y:auto;flex:1;}
.qp-item{padding:12px 14px;border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s;}
.qp-item:hover{background:var(--qp-light);}
.qp-item.active{background:var(--qp-light);border-left:3px solid var(--qp-primary);}
.qp-item .co{font-weight:600;font-size:13px;margin-bottom:2px;}
.qp-item .pr{font-size:12px;color:#777;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.qp-item .mt{display:flex;align-items:center;justify-content:space-between;}
.qp-item .dt{font-size:11px;color:#bbb;}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;}
.badge.pending{background:#fff3e0;color:#e65100;}
.badge.quoted{background:var(--qp-light);color:var(--qp-dark);}
.badge.won{background:#e8f5e9;color:#2e7d32;}
.badge.lost{background:#fdecea;color:#c62828;}
.qp-empty-list{padding:28px;text-align:center;color:#ccc;font-size:13px;}
.qp-detail{padding:22px 28px;overflow-y:auto;}
.qp-detail-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#ddd;gap:10px;}
.qp-dh{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:12px;flex-wrap:wrap;}
.qp-dh h2{font-size:18px;font-weight:700;color:var(--qp-dark);}
.qp-dh .sub{font-size:13px;color:#aaa;margin-top:3px;}
.qp-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:16px 20px;margin-bottom:14px;}
.qp-card-t{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--qp-primary);margin-bottom:12px;}
.qp-kv{display:grid;grid-template-columns:140px 1fr;gap:5px 10px;font-size:13px;}
.qp-kv .k{color:#aaa;}
.qp-kv .v{color:#222;font-weight:500;}
.qp-itable{width:100%;border-collapse:collapse;font-size:13px;}
.qp-itable thead th{background:var(--qp-light);padding:8px 10px;text-align:left;color:var(--qp-dark);font-weight:600;border-bottom:1px solid var(--qp-border);}
.qp-itable tbody td{padding:8px 10px;border-bottom:1px solid #f5f5f5;}
/* Status result card */
.qp-result{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:13px 18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.qp-result.won{border-color:#a5d6a7;background:#f1faf1;}
.qp-result.lost{border-color:#ef9a9a;background:#fff5f5;}
.qp-sbtn{padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s;line-height:1.4;}
.qp-sbtn.win{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;}
.qp-sbtn.win:hover{background:#c8e6c9;}
.qp-sbtn.lose{background:#fdecea;color:#c62828;border:1px solid #ef9a9a;}
.qp-sbtn.lose:hover{background:#ffcdd2;}
.qp-sbtn.undo{background:#fff;color:#666;border:1px solid #ddd;}
.qp-sbtn.undo:hover{background:#f5f5f5;}
/* Quote form */
.qp-qform{background:#fff;border:2px solid var(--qp-primary);border-radius:8px;padding:20px 22px;}
.qp-qform h3{font-size:14px;font-weight:700;color:var(--qp-primary);margin-bottom:14px;}
.qp-pb-row{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
.qp-pb-btn{padding:5px 13px;border:1px solid #ddd;border-radius:6px;background:#fafafa;font-size:13px;cursor:pointer;transition:all .14s;}
.qp-pb-btn.active{border-color:var(--qp-primary);background:var(--qp-light);color:var(--qp-primary);font-weight:600;}
.qp-vat-row{display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;}
.qp-vat-row select{padding:5px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;}
.qp-price-grid{display:grid;grid-template-columns:2.5fr 1.5fr 1fr 1.2fr;gap:6px;margin-bottom:5px;font-size:12px;}
.qp-price-grid .hdr{font-weight:600;color:#aaa;padding:0 6px;}
.qp-price-grid input{padding:7px 9px;border:1px solid #ddd;border-radius:6px;font-size:13px;text-align:right;width:100%;}
.qp-price-grid input:focus{outline:none;border-color:var(--qp-primary);}
.qp-price-grid .uname{padding:7px 10px;background:#f5f5f5;border-radius:6px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.qp-price-grid .tcell{padding:7px 10px;background:var(--qp-light);border-radius:6px;text-align:right;font-weight:700;color:var(--qp-primary);font-size:13px;}
.qp-summary{margin-top:12px;background:var(--qp-light);border-radius:7px;padding:12px 14px;font-size:13px;}
.qp-summary table{width:100%;}
.qp-summary td{padding:4px 0;}
.qp-summary td:last-child{text-align:right;font-weight:600;}
.qp-summary .gt td{font-size:15px;font-weight:700;color:var(--qp-primary);border-top:2px solid var(--qp-border);padding-top:9px;}
.qp-note-field{width:100%;padding:8px 11px;border:1px solid #ddd;border-radius:6px;font-size:13px;resize:vertical;min-height:70px;font-family:inherit;margin-top:12px;}
.qp-note-field:focus{outline:none;border-color:var(--qp-primary);}
.qp-hint{font-size:12px;color:#aaa;margin-top:4px;}
.qp-send-btn{display:flex;align-items:center;gap:7px;background:var(--qp-primary);color:#fff;border:none;padding:11px 26px;font-size:14px;font-weight:700;border-radius:7px;cursor:pointer;margin-top:14px;transition:background .15s;}
.qp-send-btn:hover{background:var(--qp-dark);}
.qp-send-btn:disabled{opacity:.5;cursor:default;}
.qp-del-btn{background:none;border:1px solid #ddd;color:#c0392b;padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;margin-left:8px;}
.qp-del-btn:hover{background:#fdecea;}
#qp-panel-msg{display:none;padding:11px 14px;border-radius:7px;font-size:13px;font-weight:500;margin-top:10px;}
#qp-panel-msg.ok{background:#eaf6ea;color:var(--qp-dark);border:1px solid var(--qp-border);}
#qp-panel-msg.err{background:#fdecea;color:#c0392b;border:1px solid #f5b7b1;}
@media(max-width:860px){.qp-layout{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="qp-top">
  <h1><?php echo esc_html( $company ); ?> &mdash; Teklif Paneli</h1>
  <div class="qp-top-links">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=quotepress-settings' ) ); ?>" class="btn">⚙ Ayarlar</a>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">← Siteye Dön</a>
  </div>
</div>

<div class="qp-layout">
  <!-- Sidebar -->
  <div class="qp-sidebar">
    <div class="qp-stats">
      <div class="qp-stat"><div class="n"><?php echo $total; ?></div><div class="l">Toplam</div></div>
      <div class="qp-stat"><div class="n orange"><?php echo $pending; ?></div><div class="l">Beklemede</div></div>
      <div class="qp-stat"><div class="n"><?php echo $quoted; ?></div><div class="l">Tekliflendirildi</div></div>
    </div>
    <div class="qp-stats two" style="border-top:1px solid #f0f0f0;">
      <div class="qp-stat"><div class="n green"><?php echo $won; ?></div><div class="l">✓ Kabul Edildi</div></div>
      <div class="qp-stat"><div class="n red"><?php echo $lost; ?></div><div class="l">✗ Reddedildi</div></div>
    </div>
    <div class="qp-filters">
      <?php
      $base    = $selected_id ? 'request=' . $selected_id . '&' : '';
      $filters = [
          ''        => 'Tümü',
          'pending' => 'Beklemede',
          'quoted'  => 'Tekliflendirildi',
          'won'     => 'Kabul',
          'lost'    => 'Reddedildi',
      ];
      foreach ( $filters as $val => $lbl ) {
          $active = $status_filter === $val ? 'active' : '';
          $href   = '?' . $base . ( $val ? 'status=' . urlencode( $val ) : '' );
          echo '<a href="' . esc_url( $href ) . '" class="' . $active . '">' . esc_html( $lbl ) . '</a>';
      }
      ?>
    </div>
    <div class="qp-list">
      <?php if ( empty( $requests ) ) : ?>
        <div class="qp-empty-list">Henüz talep yok.</div>
      <?php else : foreach ( $requests as $r ) :
          $active = $selected_id == $r->id ? 'active' : '';
          $href   = '?' . ( $status_filter ? 'status=' . urlencode( $status_filter ) . '&' : '' ) . 'request=' . $r->id;
      ?>
        <div class="qp-item <?php echo $active; ?>" onclick="location.href='<?php echo esc_url( $href ); ?>'">
          <div class="co"><?php echo esc_html( $r->company_name ); ?></div>
          <div class="pr"><?php echo esc_html( $r->project_name ); ?></div>
          <div class="mt">
            <span class="dt"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->created_at ) ) ); ?></span>
            <?php echo $badge( $r->status ); ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Detail -->
  <div class="qp-detail">
    <?php if ( ! $request ) : ?>
      <div class="qp-detail-empty">
        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="color:#ddd"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <p style="color:#ccc;font-size:14px;">Listeden bir talep seçin.</p>
      </div>
    <?php else : ?>

      <div class="qp-dh">
        <div>
          <h2><?php echo esc_html( $request->project_name ); ?></h2>
          <div class="sub"><?php echo esc_html( $request->company_name ); ?> &mdash; <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $request->created_at ) ) ); ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <?php echo $badge( $request->status, true ); ?>
          <button class="qp-del-btn" onclick="qpDelete(<?php echo $request->id; ?>)">🗑 Sil</button>
        </div>
      </div>

      <!-- Firma bilgileri -->
      <div class="qp-card">
        <div class="qp-card-t">Firma / İletişim</div>
        <div class="qp-kv">
          <span class="k">Firma</span><span class="v"><?php echo esc_html( $request->company_name ); ?></span>
          <?php if ( $request->tax_number ) : ?>
            <span class="k">Vergi No</span><span class="v"><?php echo esc_html( $request->tax_number ); ?></span>
          <?php endif; ?>
          <span class="k">İletişim Kişisi</span>
          <span class="v"><?php echo esc_html( $request->contact_name ); ?><?php if ( $request->contact_title ) echo ' <span style="color:#aaa;font-size:12px;">(' . esc_html( $request->contact_title ) . ')</span>'; ?></span>
          <span class="k">E-posta</span>
          <span class="v"><a href="mailto:<?php echo esc_attr( $request->email ); ?>" style="color:var(--qp-primary);"><?php echo esc_html( $request->email ); ?></a></span>
          <?php if ( $request->phone ) : ?>
            <span class="k">Telefon</span><span class="v"><?php echo esc_html( $request->phone ); ?></span>
          <?php endif; ?>
          <?php if ( $request->city ) : ?>
            <span class="k">Şehir / Ülke</span>
            <span class="v"><?php echo esc_html( $request->city ); ?><?php if ( $request->country ) echo ' / ' . esc_html( $request->country ); ?></span>
          <?php endif; ?>
          <?php if ( $request->extra_option ) : ?>
            <span class="k"><?php echo esc_html( QuotePress_Settings::get( 'extra_option_label', 'Seçenek' ) ); ?></span>
            <span class="v" style="color:var(--qp-primary);font-weight:700;"><?php echo esc_html( $request->extra_option ); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Talep edilen ürünler -->
      <div class="qp-card">
        <div class="qp-card-t">Talep Edilen Ürünler</div>
        <table class="qp-itable">
          <thead><tr>
            <th>Ürün</th><th>Model</th>
            <th style="text-align:center">Adet</th><th>Not</th>
          </tr></thead>
          <tbody>
            <?php foreach ( $items as $item ) : ?>
            <tr>
              <td><?php echo esc_html( $item['name'] ?? '' ); ?></td>
              <td style="color:#aaa;"><?php echo esc_html( $item['model'] ?: '-' ); ?></td>
              <td style="text-align:center;font-weight:700;color:var(--qp-primary);"><?php echo esc_html( $item['qty'] ?? 1 ); ?></td>
              <td style="color:#ccc;"><?php echo esc_html( $item['note'] ?: '-' ); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ( $request->project_desc ) : ?>
          <div style="margin-top:10px;padding:10px 13px;background:#f9f9f9;border-radius:7px;font-size:13px;color:#666;line-height:1.7;">
            <strong style="display:block;font-size:11px;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Proje Açıklaması</strong>
            <?php echo nl2br( esc_html( $request->project_desc ) ); ?>
          </div>
        <?php endif; ?>
        <?php if ( $request->extra_note ) : ?>
          <div style="margin-top:8px;padding:10px 13px;background:#fff8e8;border-left:3px solid #f0b030;border-radius:0 7px 7px 0;font-size:13px;color:#5a4000;line-height:1.7;">
            <strong>Ek Notlar:</strong> <?php echo nl2br( esc_html( $request->extra_note ) ); ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Teklif sonucu (teklif gönderildikten sonra) -->
      <?php if ( $request->status !== 'pending' ) : ?>
      <div class="qp-result <?php echo esc_attr( $request->status ); ?>">
        <span style="font-size:13px;font-weight:600;color:#555;">🎯 Teklif Sonucu:</span>
        <?php echo $badge( $request->status, true ); ?>
        <div style="display:flex;gap:8px;margin-left:auto;flex-wrap:wrap;">
          <?php if ( $request->status !== 'won' ) : ?>
            <button class="qp-sbtn win" onclick="qpUpdateStatus(<?php echo $request->id; ?>, 'won')">✓ Kabul Edildi</button>
          <?php endif; ?>
          <?php if ( $request->status !== 'lost' ) : ?>
            <button class="qp-sbtn lose" onclick="qpUpdateStatus(<?php echo $request->id; ?>, 'lost')">✗ Reddedildi</button>
          <?php endif; ?>
          <?php if ( $request->status !== 'quoted' ) : ?>
            <button class="qp-sbtn undo" onclick="qpUpdateStatus(<?php echo $request->id; ?>, 'quoted')">↩ Tekliflendirildi'ye Al</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Teklif formu -->
      <div class="qp-qform">
        <h3>📄 Teklif Hazırla & Gönder</h3>

        <?php if ( $request->status !== 'pending' ) : ?>
          <div style="padding:10px 13px;background:var(--qp-light);border:1px solid var(--qp-border);border-radius:7px;font-size:13px;color:var(--qp-dark);margin-bottom:14px;">
            Bu talep daha önce tekliflendirildi. Yeni teklif gönderebilirsiniz.
          </div>
        <?php endif; ?>

        <!-- Para birimi -->
        <div style="margin-bottom:11px;">
          <div style="font-size:12px;font-weight:600;color:#aaa;margin-bottom:6px;">Para Birimi</div>
          <div class="qp-pb-row" id="qpCurrRow">
            <?php foreach ( $currencies as $c ) :
                $active = $c === $def_currency ? 'active' : '';
                $sym    = [ 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'TRY' => '₺' ][$c] ?? '';
            ?>
              <button type="button" class="qp-pb-btn <?php echo $active; ?>" onclick="qpSetCurrency('<?php echo esc_js( $c ); ?>',this)">
                <?php if ( $sym ) echo $sym . ' '; echo esc_html( $c ); ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
        <input type="hidden" id="qpCurrency" value="<?php echo esc_attr( $def_currency ); ?>">

        <!-- Hitap -->
        <div class="qp-vat-row" style="margin-bottom:10px;">
          <label style="font-size:13px;font-weight:600;color:#666;">Hitap:</label>
          <select id="qpSalutation" style="padding:5px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px;">
            <option value="neutral" <?php selected( $request->contact_salutation ?? 'neutral', 'neutral' ); ?>>Sayın [Ad Soyad]</option>
            <option value="female"  <?php selected( $request->contact_salutation ?? 'neutral', 'female' );  ?>>Sayın [Ad Soyad] Hanım</option>
            <option value="male"    <?php selected( $request->contact_salutation ?? 'neutral', 'male' );    ?>>Sayın [Ad Soyad] Bey</option>
          </select>
          <span style="font-size:11px;color:#aaa;margin-left:4px;"><?php echo esc_html( $request->contact_name ); ?></span>
        </div>

        <!-- KDV -->
        <div class="qp-vat-row">
          <label for="qpVat" style="font-size:13px;font-weight:600;color:#666;">KDV Oranı:</label>
          <select id="qpVat" onchange="qpCalc()">
            <option value="0"  <?php selected( $def_vat, '0' );  ?>>KDV Yok</option>
            <option value="5"  <?php selected( $def_vat, '5' );  ?>>5%</option>
            <option value="8"  <?php selected( $def_vat, '8' );  ?>>8%</option>
            <option value="10" <?php selected( $def_vat, '10' ); ?>>10%</option>
            <option value="18" <?php selected( $def_vat, '18' ); ?>>18%</option>
            <option value="20" <?php selected( $def_vat, '20' ); ?>>20%</option>
            <option value="21" <?php selected( $def_vat, '21' ); ?>>21%</option>
            <option value="25" <?php selected( $def_vat, '25' ); ?>>25%</option>
          </select>
        </div>

        <!-- Başlıklar -->
        <div class="qp-price-grid" style="margin-bottom:8px;">
          <div class="hdr">Ürün</div>
          <div class="hdr" style="text-align:right;">
            Birim Fiyat
            <select id="qpPriceType" onchange="qpCalc()" style="font-size:10px;border:1px solid #ddd;border-radius:4px;padding:2px 4px;margin-left:4px;font-weight:400;color:#666;cursor:pointer;">
              <option value="excl">KDV Hariç</option>
              <option value="incl">KDV Dahil</option>
            </select>
          </div>
          <div class="hdr" style="text-align:center;">Adet</div>
          <div class="hdr" style="text-align:right;">Toplam</div>
        </div>

        <!-- Fiyat satırları -->
        <div id="qpPriceRows">
          <?php foreach ( $items as $idx => $item ) :
              $prev_price = '';
              if ( $request->quote_data ) {
                  $qd         = json_decode( $request->quote_data, true );
                  $prev_price = $qd['items'][$idx]['price'] ?? '';
              }
          ?>
          <div class="qp-price-grid" data-idx="<?php echo $idx; ?>">
            <div class="uname" title="<?php echo esc_attr( $item['name'] ?? '' ); ?><?php if ( ! empty( $item['model'] ) ) echo ' – ' . esc_attr( $item['model'] ); ?>">
              <?php echo esc_html( $item['name'] ?? '' ); ?>
              <?php if ( ! empty( $item['model'] ) ) : ?><span style="color:#ccc;font-size:11px;"> <?php echo esc_html( $item['model'] ); ?></span><?php endif; ?>
            </div>
            <input type="number" class="qp-unit-price" data-idx="<?php echo $idx; ?>"
                   placeholder="0.00" min="0" step="0.01" oninput="qpCalc()"
                   value="<?php echo esc_attr( $prev_price ); ?>">
            <input type="number" class="qp-qty-input" data-idx="<?php echo $idx; ?>"
                   min="1" step="1" oninput="qpCalc()" value="<?php echo esc_attr( $item['qty'] ?? 1 ); ?>"
                   style="padding:7px 9px;border:1px solid #ddd;border-radius:6px;font-size:13px;text-align:center;width:100%;">
            <div class="tcell" id="qpSt<?php echo $idx; ?>">0.00</div>
          </div>
          <?php endforeach; ?>
        </div>

        <div id="qpPriceNote" style="display:none;font-size:11px;color:#888;padding:4px 8px;background:#fffbeb;border-radius:4px;margin-bottom:6px;border:1px solid #fde68a;"></div>
        <div class="qp-summary">
          <table>
            <tr><td style="color:#666;">Ara Toplam</td><td id="qpSubtotal">0.00 <span class="qp-curr-label"><?php echo esc_html( $def_currency ); ?></span></td></tr>
            <tr id="qpVatRow"><td style="color:#666;">KDV (<span id="qpVatPct"><?php echo esc_html( $def_vat ); ?>%</span>)</td><td id="qpVatAmt">0.00</td></tr>
            <tr class="gt"><td>Genel Toplam</td><td id="qpGrandTotal">0.00</td></tr>
          </table>
        </div>

        <textarea class="qp-note-field" id="qpNote" placeholder="Teklif notu, geçerlilik süresi, ödeme koşulları vb."><?php echo esc_textarea( $request->quote_note ?? '' ); ?></textarea>
        <p class="qp-hint">Varsayılan geçerlilik: <?php echo intval( $validity ); ?> gün</p>

        <button class="qp-send-btn" id="qpSendBtn" onclick="qpSendQuote(<?php echo $request->id; ?>)">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
          PDF Oluştur & Teklif Gönder
        </button>
        <div id="qp-panel-msg"></div>
      </div>

    <?php endif; ?>
  </div>
</div>

<script>
var qpItems      = <?php echo wp_json_encode( $items, JSON_UNESCAPED_UNICODE ); ?>;
var qpCurrency   = '<?php echo esc_js( $def_currency ); ?>';
var qpPanelNonce = '<?php echo esc_js( $panel_nonce ); ?>';
var qpAjaxUrl    = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

function qpSetCurrency(c, btn) {
    qpCurrency = c;
    document.querySelectorAll('.qp-pb-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    document.querySelectorAll('.qp-curr-label').forEach(function(el){ el.textContent = c; });
    qpCalc();
}

function qpFmt(n) { return n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

function qpCalc() {
    var sub = 0;
    document.querySelectorAll('.qp-unit-price').forEach(function(inp) {
        var idx    = parseInt(inp.dataset.idx);
        var qtyInp = document.querySelectorAll('.qp-qty-input')[idx];
        var qty    = qtyInp ? (parseInt(qtyInp.value)||1) : 1;
        var price  = parseFloat(inp.value)||0;
        var line   = price * qty;
        sub += line;
        var el = document.getElementById('qpSt'+idx);
        if (el) el.textContent = qpFmt(line);
    });
    var vat    = parseInt(document.getElementById('qpVat').value)||0;
    var vatAmt = sub * (vat/100);
    var grand  = sub + vatAmt;
    document.getElementById('qpSubtotal').innerHTML = qpFmt(sub)+' <span class="qp-curr-label">'+qpCurrency+'</span>';
    document.getElementById('qpVatAmt').textContent  = qpFmt(vatAmt)+' '+qpCurrency;
    document.getElementById('qpVatPct').textContent  = vat+'%';
    document.getElementById('qpGrandTotal').textContent = qpFmt(grand)+' '+qpCurrency;
    document.getElementById('qpVatRow').style.display = vat > 0 ? '' : 'none';
    var ptEl   = document.getElementById('qpPriceType');
    var noteEl = document.getElementById('qpPriceNote');
    if (ptEl && noteEl) {
        if (ptEl.value === 'incl' && vat > 0) {
            var net = sub / (1 + vat/100);
            noteEl.textContent = 'Birim fiyatlar KDV dahil. Net: ' + qpFmt(net) + ' + KDV: ' + qpFmt(sub - net);
            noteEl.style.display = '';
        } else {
            noteEl.style.display = 'none';
        }
    }
}

function qpSendQuote(id) {
    var btn  = document.getElementById('qpSendBtn');
    var msg  = document.getElementById('qp-panel-msg');
    var rows = [];
    document.querySelectorAll('.qp-unit-price').forEach(function(inp) {
        var idx    = parseInt(inp.dataset.idx);
        var item   = qpItems[idx]||{};
        var qtyInp = document.querySelectorAll('.qp-qty-input')[idx];
        var qty    = qtyInp ? (parseInt(qtyInp.value)||1) : 1;
        var price  = parseFloat(inp.value)||0;
        rows.push({name:item.name||'',model:item.model||'',qty:qty,note:item.note||'',price:price,total:price*qty});
    });
    var vat       = parseInt(document.getElementById('qpVat').value)||0;
    var sub       = rows.reduce(function(s,r){return s+r.total;},0);
    var vatAmt    = sub*(vat/100);
    var priceType = document.getElementById('qpPriceType') ? document.getElementById('qpPriceType').value : 'excl';
    var payload   = {items:rows,currency:qpCurrency,vat_rate:vat,subtotal:sub,vat_amount:vatAmt,grand_total:sub+vatAmt,price_type:priceType};

    btn.disabled    = true;
    btn.textContent = '⏳ Gönderiliyor...';
    msg.style.display = 'none';

    var fd = new FormData();
    fd.append('action',             'qp_send_quote');
    fd.append('request_id',         id);
    fd.append('quote_note',         document.getElementById('qpNote').value);
    fd.append('quote_data',         JSON.stringify(payload));
    fd.append('contact_salutation', document.getElementById('qpSalutation').value);
    fd.append('_wpnonce',           qpPanelNonce);

    fetch(qpAjaxUrl, {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
        msg.style.display = 'block';
        if (d.success) {
            msg.className   = 'ok';
            msg.textContent = '✔ Teklif başarıyla gönderildi!';
            setTimeout(function(){ location.reload(); }, 2000);
        } else {
            msg.className   = 'err';
            msg.textContent = 'Hata: '+(d.data||'Bilinmeyen hata');
        }
        btn.disabled  = false;
        btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> PDF Oluştur & Teklif Gönder';
    })
    .catch(function(){
        msg.style.display = 'block';
        msg.className     = 'err';
        msg.textContent   = 'Bağlantı hatası.';
        btn.disabled      = false;
    });
}

function qpDelete(id) {
    if (!confirm('Bu talebi silmek istediğinizden emin misiniz?')) return;
    var fd = new FormData();
    fd.append('action',     'qp_delete_request');
    fd.append('request_id', id);
    fd.append('_wpnonce',   qpPanelNonce);
    fetch(qpAjaxUrl, {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){ if (d.success) location.href='<?php echo esc_url( home_url( '/' . $slug . '/' ) ); ?>'; });
}

function qpUpdateStatus(id, status) {
    var fd = new FormData();
    fd.append('action',     'qp_update_status');
    fd.append('request_id', id);
    fd.append('new_status', status);
    fd.append('_wpnonce',   qpPanelNonce);
    fetch(qpAjaxUrl, {method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(d){ if (d.success) location.reload(); });
}

qpCalc();
</script>
</body></html>
<?php
    }
}
