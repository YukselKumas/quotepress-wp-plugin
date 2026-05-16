<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Mailer {

    public static function init() {}

    /* ── Shared helpers ─────────────────────────────────────── */
    private static function colors() {
        return QuotePress_Settings::active_theme_colors();
    }

    private static function setting( $k, $d = '' ) {
        return QuotePress_Settings::get( $k, $d );
    }

    private static function wrap( $inner ) {
        $c = self::colors();
        return '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8">
<title>QuotePress</title></head>
<body style="margin:0;padding:0;background:#f2f2f2;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f2f2f2;padding:28px 16px;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid '.$c['border'].';">
'.$inner.'
</table></td></tr></table></body></html>';
    }

    private static function header( $title, $subtitle = '' ) {
        $c   = self::colors();
        $co  = self::setting( 'company_name', 'QuotePress' );
        $sub = $subtitle ? '<p style="margin:5px 0 0;font-size:13px;color:rgba(255,255,255,.8);">'.esc_html($subtitle).'</p>' : '';
        return '<tr><td style="background:'.$c['primary'].';padding:26px 36px;text-align:center;">
          <h1 style="margin:0;font-size:26px;color:#ffffff;font-weight:900;letter-spacing:.01em;">'.esc_html($co).'</h1>
          '.$sub.'
        </td></tr>
        <tr><td style="background:'.$c['light'].';padding:12px 36px;border-bottom:1px solid '.$c['border'].';">
          <p style="margin:0;font-size:16px;font-weight:700;color:'.$c['dark'].';">'.esc_html($title).'</p>
        </td></tr>';
    }

    private static function footer() {
        $c    = self::colors();
        $web  = self::setting( 'company_website' );
        $text = self::setting( 'mail_footer', __( 'Bu e-posta otomatik olarak gönderilmiştir. Lütfen yanıtlamayınız.', 'quotepress' ) );
        return '<tr><td style="background:'.$c['primary'].';padding:16px 36px;text-align:center;">
          '.($web ? '<a href="'.esc_url($web).'" style="color:#fff;text-decoration:none;font-size:13px;font-weight:600;">'.esc_html($web).'</a>' : '').'
          <p style="margin:4px 0 0;font-size:11px;color:rgba(255,255,255,.55);">'.esc_html($text).'</p>
        </td></tr>';
    }

    private static function contact_block() {
        $c    = self::colors();
        $addr = self::setting( 'company_address' );
        $em   = self::setting( 'company_email' );
        $tel1 = self::setting( 'company_phone1' );
        $tel2 = self::setting( 'company_phone2' );
        $wp   = self::setting( 'company_whatsapp' );

        $rows = '';
        if ( $addr ) $rows .= '<tr><td style="padding-right:10px;color:'.$c['primary'].';vertical-align:top;padding-top:2px;">&#128205;</td><td>'.nl2br(esc_html($addr)).'</td></tr>';
        if ( $em )   $rows .= '<tr><td style="padding-right:10px;color:'.$c['primary'].';padding-top:5px;">&#128231;</td><td style="padding-top:5px;"><a href="mailto:'.esc_attr($em).'" style="color:'.$c['primary'].';text-decoration:none;">'.esc_html($em).'</a></td></tr>';
        if ( $tel1 || $tel2 ) {
            $tel_html = '';
            if ( $tel1 ) $tel_html .= '<a href="tel:'.esc_attr(preg_replace('/\s+/','',$tel1)).'" style="color:#222;text-decoration:none;">'.esc_html($tel1).'</a>';
            if ( $tel2 ) $tel_html .= '<br><a href="tel:'.esc_attr(preg_replace('/\s+/','',$tel2)).'" style="color:#222;text-decoration:none;">'.esc_html($tel2).'</a>';
            $rows .= '<tr><td style="padding-right:10px;color:'.$c['primary'].';padding-top:5px;vertical-align:top;">&#128222;</td><td style="padding-top:5px;">'.$tel_html.'</td></tr>';
        }
        if ( $wp ) {
            $wp_num = preg_replace( '/[^0-9]/', '', $wp );
            $rows  .= '<tr><td style="padding-right:10px;color:#25d366;padding-top:5px;">&#128172;</td><td style="padding-top:5px;"><a href="https://wa.me/'.$wp_num.'" style="color:#25d366;font-weight:700;text-decoration:none;">'.esc_html($wp).'</a> <span style="color:#aaa;font-size:12px;">(WhatsApp)</span></td></tr>';
        }
        if ( ! $rows ) return '';
        return '<tr><td style="padding:0 36px 24px;">
          <div style="background:#f8f8f8;border-radius:8px;padding:18px 22px;">
            <p style="margin:0 0 12px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#666;">' . esc_html__( 'İletişim Bilgileri', 'quotepress' ) . '</p>
            <table cellpadding="0" cellspacing="0" style="font-size:13px;color:#444;line-height:2;">'.$rows.'</table>
          </div>
        </td></tr>';
    }

    private static function items_table_simple( $items ) {
        $c    = self::colors();
        $rows = '';
        foreach ( $items as $i => $item ) {
            $bg    = $i % 2 === 0 ? $c['light'] : '#ffffff';
            $rows .= '<tr style="background:'.$bg.';">
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';font-size:13px;">'.esc_html($item['name']??'').'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';color:#aaa;font-size:13px;">'.esc_html($item['model']??'-').'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';text-align:center;font-weight:700;color:'.$c['primary'].';font-size:13px;">'.esc_html($item['qty']??1).' '.esc_html__('adet','quotepress').'</td>
            </tr>';
        }
        return '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid '.$c['border'].';border-radius:7px;overflow:hidden;font-size:13px;">
          <thead><tr style="background:'.$c['light'].';">
            <th style="padding:9px 12px;text-align:left;color:'.$c['dark'].';font-size:11px;font-weight:700;border-bottom:1px solid '.$c['border'].';">'.esc_html__('Ürün','quotepress').'</th>
            <th style="padding:9px 12px;text-align:left;color:'.$c['dark'].';font-size:11px;font-weight:700;border-bottom:1px solid '.$c['border'].';">'.esc_html__('Model','quotepress').'</th>
            <th style="padding:9px 12px;text-align:center;color:'.$c['dark'].';font-size:11px;font-weight:700;border-bottom:1px solid '.$c['border'].';">'.esc_html__('Adet','quotepress').'</th>
          </tr></thead>
          <tbody>'.$rows.'</tbody>
        </table>';
    }

    private static function items_table_priced( $items, $currency, $vat_rate, $subtotal, $vat_amount, $grand_total ) {
        $c    = self::colors();
        $rows = '';
        foreach ( $items as $i => $item ) {
            $bg    = $i % 2 === 0 ? $c['light'] : '#ffffff';
            $price = floatval( $item['price'] ?? 0 );
            $total = floatval( $item['total'] ?? 0 );
            $rows .= '<tr style="background:'.$bg.';">
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';font-size:13px;">'.esc_html($item['name']??'').'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';color:#aaa;font-size:13px;">'.esc_html($item['model']??'-').'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';text-align:center;font-size:13px;">'.esc_html($item['qty']??1).'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';text-align:right;font-size:13px;">'.number_format($price,2,'.',',').' '.esc_html($currency).'</td>
              <td style="padding:9px 12px;border-bottom:1px solid '.$c['border'].';text-align:right;font-weight:700;color:'.$c['primary'].';font-size:13px;">'.number_format($total,2,'.',',').' '.esc_html($currency).'</td>
            </tr>';
        }
        $vat_row = intval($vat_rate) > 0
            ? '<tr><td colspan="4" style="text-align:right;padding:7px 12px;color:#666;font-size:13px;">'.esc_html__('KDV','quotepress').' ('.intval($vat_rate).'%)</td>
               <td style="text-align:right;padding:7px 12px;font-size:13px;">'.number_format(floatval($vat_amount),2,'.',',').' '.esc_html($currency).'</td></tr>'
            : '';
        return '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid '.$c['border'].';border-radius:8px;overflow:hidden;">
          <thead><tr style="background:'.$c['primary'].';">
            <th style="padding:9px 12px;text-align:left;color:#fff;font-size:11px;font-weight:700;">'.esc_html__('Ürün','quotepress').'</th>
            <th style="padding:9px 12px;text-align:left;color:#fff;font-size:11px;font-weight:700;">'.esc_html__('Model','quotepress').'</th>
            <th style="padding:9px 12px;text-align:center;color:#fff;font-size:11px;font-weight:700;">'.esc_html__('Adet','quotepress').'</th>
            <th style="padding:9px 12px;text-align:right;color:#fff;font-size:11px;font-weight:700;">'.esc_html__('Birim Fiyat','quotepress').'</th>
            <th style="padding:9px 12px;text-align:right;color:#fff;font-size:11px;font-weight:700;">'.esc_html__('Toplam','quotepress').'</th>
          </tr></thead>
          <tbody>'.$rows.'</tbody>
          <tfoot style="background:'.$c['light'].';">
            <tr><td colspan="4" style="text-align:right;padding:8px 12px;color:#666;font-size:13px;">'.esc_html__('Ara Toplam','quotepress').'</td>
                <td style="text-align:right;padding:8px 12px;font-size:13px;font-weight:600;">'.number_format(floatval($subtotal),2,'.',',').' '.esc_html($currency).'</td></tr>
            '.$vat_row.'
            <tr style="background:'.$c['primary'].';">
              <td colspan="4" style="text-align:right;padding:10px 12px;color:#fff;font-size:14px;font-weight:700;">'.esc_html__('GENEL TOPLAM','quotepress').'</td>
              <td style="text-align:right;padding:10px 12px;color:#fff;font-size:15px;font-weight:900;">'.number_format(floatval($grand_total),2,'.',',').' '.esc_html($currency).'</td>
            </tr>
          </tfoot>
        </table>';
    }

    private static function send( $to, $subject, $body, $reply_to = '' ) {
        $from    = self::setting( 'company_name', 'QuotePress' );
        $from_em = self::setting( 'company_email', get_option( 'admin_email' ) );
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from} <{$from_em}>",
        ];
        if ( $reply_to ) {
            $headers[] = "Reply-To: {$reply_to}";
        }
        return wp_mail( $to, $subject, $body, $headers );
    }

    /* ── Yöneticiye bildirim ────────────────────────────────── */
    public static function send_notification( $request, $items ) {
        $c         = self::colors();
        $panel_url = home_url( '/' . QuotePress_Settings::get( 'panel_slug', 'quote-panel' ) . '/?request=' . $request->id );
        $table     = self::items_table_simple( $items );
        $extra_row = $request->extra_option
            ? '<p style="margin:10px 0 0;background:#fff8e8;border:1px solid #f0d080;border-radius:7px;padding:10px 14px;font-size:13px;color:#5a4000;"><strong>'.esc_html(QuotePress_Settings::get('extra_option_label',__('Seçenek','quotepress'))).':</strong> '.esc_html($request->extra_option).'</p>'
            : '';

        $body = self::wrap(
            self::header(
                sprintf( __( 'Yeni Teklif Talebi: %s', 'quotepress' ), $request->project_name ),
                sprintf( __( 'Firma: %s', 'quotepress' ), $request->company_name )
            )
            . '<tr><td style="padding:24px 36px;">
              '.$table.$extra_row.'
              <div style="margin-top:20px;text-align:center;">
                <a href="'.esc_url($panel_url).'" style="display:inline-block;background:'.$c['primary'].';color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;">
                  📄 '.esc_html__('Paneli Aç & Yanıtla','quotepress').'
                </a>
              </div>
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;font-size:13px;">
                <tr><td style="color:#aaa;width:150px;">'.esc_html__('Firma','quotepress').'</td><td style="font-weight:600;">'.esc_html($request->company_name).'</td></tr>
                <tr><td style="color:#aaa;">'.esc_html__('İletişim','quotepress').'</td><td>'.esc_html($request->contact_name).'</td></tr>
                <tr><td style="color:#aaa;">'.esc_html__('E-posta','quotepress').'</td><td><a href="mailto:'.esc_attr($request->email).'" style="color:'.$c['primary'].';">'.esc_html($request->email).'</a></td></tr>
                '.($request->phone ? '<tr><td style="color:#aaa;">'.esc_html__('Telefon','quotepress').'</td><td>'.esc_html($request->phone).'</td></tr>' : '').'
              </table>
            </td></tr>'
            . self::footer()
        );

        $recipient = self::setting( 'recipient_email', get_option( 'admin_email' ) );
        foreach ( array_map( 'trim', explode( ',', $recipient ) ) as $to ) {
            if ( is_email( $to ) ) {
                self::send(
                    $to,
                    sprintf( __( '[QuotePress] Yeni Talep: %s – %s', 'quotepress' ), $request->project_name, $request->company_name ),
                    $body,
                    $request->contact_name . ' <' . $request->email . '>'
                );
            }
        }
    }

    /* ── Talebi onaylama e-postası (müşteriye) ──────────────── */
    public static function send_confirmation( $request, $items ) {
        $c     = self::colors();
        $table = self::items_table_simple( $items );
        $extra = $request->extra_option
            ? '<p style="margin:10px 0 0;font-size:13px;color:#555;">'.esc_html(QuotePress_Settings::get('extra_option_label',__('Seçenek','quotepress'))).': <strong style="color:'.$c['primary'].';">'.esc_html($request->extra_option).'</strong></p>'
            : '';

        $body = self::wrap(
            self::header( __( 'Teklif Talebiniz Alındı', 'quotepress' ) )
            . '<tr><td style="padding:28px 36px 20px;">
              <p style="margin:0 0 13px;font-size:16px;color:#222;">'.esc_html__('Sayın','quotepress').' <strong>'.esc_html($request->contact_name).'</strong>,</p>
              <p style="margin:0 0 13px;font-size:14px;color:#555;line-height:1.8;">
                '.sprintf( esc_html__( '"%s" projesi için teklif talebiniz başarıyla alınmıştır. Ekibimiz talebinizi inceleyecek ve en kısa sürede fiyat teklifiyle geri dönecektir.', 'quotepress' ), esc_html($request->project_name) ).'
              </p>
              <div style="background:'.$c['light'].';border:1px solid '.$c['border'].';border-radius:8px;padding:18px 20px;margin:18px 0;">
                <p style="margin:0 0 12px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:'.$c['primary'].';">'.esc_html__('Talebinizin Özeti','quotepress').'</p>
                '.$table.$extra.'
              </div>
            </td></tr>'
            . self::contact_block()
            . self::footer()
        );

        self::send(
            $request->email,
            sprintf( __( '[QuotePress] Talep Alındı – %s', 'quotepress' ), $request->project_name ),
            $body
        );
    }

    /* ── Fiyat teklifi e-postası (PDF ekli, müşteriye) ─────── */
    public static function send_quote( $request, $payload, $note, $pdf_path = '' ) {
        $c          = self::colors();
        $items      = $payload['items']       ?? [];
        $currency   = $payload['currency']    ?? 'USD';
        $vat_rate   = $payload['vat_rate']    ?? 0;
        $subtotal   = $payload['subtotal']    ?? 0;
        $vat_amount = $payload['vat_amount']  ?? 0;
        $grand      = $payload['grand_total'] ?? 0;
        $validity   = QuotePress_Settings::get( 'validity_days', '30' );
        $quote_no   = 'QP-' . date( 'Y' ) . '-' . str_pad( $request->id, 5, '0', STR_PAD_LEFT );

        $table    = self::items_table_priced( $items, $currency, $vat_rate, $subtotal, $vat_amount, $grand );
        $note_blk = $note
            ? '<div style="margin-top:18px;background:#f5f5f5;border-left:4px solid '.$c['primary'].';border-radius:0 7px 7px 0;padding:13px 16px;font-size:13px;color:#444;line-height:1.7;"><strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:'.$c['primary'].';margin-bottom:4px;">'.esc_html__('Notlar','quotepress').'</strong>'.nl2br(esc_html($note)).'</div>'
            : '';

        $co   = self::setting( 'company_name', 'QuotePress' );
        $body = self::wrap(
            '<tr><td style="background:'.$c['primary'].';padding:22px 36px;">
              <table width="100%"><tr>
                <td><h1 style="margin:0;font-size:22px;color:#fff;font-weight:900;">'.esc_html($co).'</h1></td>
                <td style="text-align:right;">
                  <div style="font-size:15px;font-weight:700;color:#fff;">'.esc_html__('FİYAT TEKLİFİ','quotepress').'</div>
                  <div style="font-size:12px;color:rgba(255,255,255,.8);margin-top:3px;">No: '.esc_html($quote_no).'</div>
                  <div style="font-size:12px;color:rgba(255,255,255,.8);">'.date_i18n( get_option('date_format') ).'</div>
                </td>
              </tr></table>
            </td></tr>
            <tr><td style="background:'.$c['light'].';padding:13px 36px;border-bottom:1px solid '.$c['border'].';">
              <table width="100%"><tr>
                <td><span style="font-size:12px;color:#777;">'.esc_html__('Sayın','quotepress').'</span><br>
                    <strong style="font-size:15px;color:'.$c['dark'].';">'.esc_html($request->contact_name).'</strong>
                    <span style="font-size:13px;color:#aaa;"> / '.esc_html($request->company_name).'</span></td>
                <td style="text-align:right;"><span style="font-size:12px;color:#777;">'.esc_html__('Proje','quotepress').'</span><br>
                    <strong style="font-size:14px;color:'.$c['primary'].';">'.esc_html($request->project_name).'</strong></td>
              </tr></table>
            </td></tr>
            <tr><td style="padding:22px 36px;">
              '.$table.$note_blk.'
              '.($validity ? '<p style="margin-top:14px;font-size:12px;color:#aaa;">'.sprintf( esc_html__('Bu teklif %d gün geçerlidir.','quotepress'), intval($validity) ).'</p>' : '').'
            </td></tr>'
            . self::contact_block()
            . self::footer()
        );

        $from    = self::setting( 'company_name', 'QuotePress' );
        $from_em = self::setting( 'company_email', get_option( 'admin_email' ) );
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from} <{$from_em}>",
        ];

        wp_mail(
            $request->email,
            sprintf( __( '[QuotePress] Fiyat Teklifiniz – %s (%s)', 'quotepress' ), $request->project_name, $quote_no ),
            $body,
            $headers,
            ( $pdf_path && file_exists( $pdf_path ) ) ? [ $pdf_path ] : []
        );
    }
}
