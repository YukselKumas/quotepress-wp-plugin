<?php
defined( 'ABSPATH' ) || exit;

/**
 * QuotePress PDF — ASİS Enerji Isı Gider Paylaşım teklif formatı
 * Ürün adı "Isı Gider Paylaşım" içeriyorsa 4 sayfalık detaylı format,
 * diğer ürünlerde standart kompakt format kullanılır.
 */
class QuotePress_PDF {

    public static function generate( $request, $payload, $note ) {
        if ( class_exists( '\\Mpdf\\Mpdf' ) ) {
            return self::with_mpdf( $request, $payload, $note );
        }
        return self::as_html( $request, $payload, $note );
    }

    private static function with_mpdf( $request, $payload, $note ) {
        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 0,
            'margin_bottom'     => 0,
            'margin_left'       => 0,
            'margin_right'      => 0,
            'default_font'      => 'dejavusans',
            'default_font_size' => 10,
        ]);
        $mpdf->SetTitle( 'Teklif – ' . $request->project_name );
        $mpdf->SetAuthor( QuotePress_Settings::get('company_name', '') );
        // mPDF: CSS uyumluluk
        $mpdf->use_kwt        = true;  // keep-with-table (sayfa kırışı koruması)
        $mpdf->SetAutoPageBreak( true, 0 );
        $html = self::html( $request, $payload, $note );
        $mpdf->WriteHTML( $html );
        $path = sys_get_temp_dir() . '/qp_' . $request->id . '_' . time() . '.pdf';
        $mpdf->Output( $path, 'F' );
        return $path;
    }

    private static function as_html( $request, $payload, $note ) {
        $path = sys_get_temp_dir() . '/qp_' . $request->id . '_' . time() . '.html';
        file_put_contents( $path, self::html( $request, $payload, $note ) );
        return $path;
    }

    /* ─────────────────────────────────────────────────────────── */
    /*  Teklif türü tespiti                                        */
    /* ─────────────────────────────────────────────────────────── */
    /**
     * Detaylı 4 sayfalık format tetikleyicisi.
     * Settings > "Detaylı Format Tetikleyicisi" alanındaki anahtar kelimeler
     * ürün adında geçiyorsa true döner.
     * Varsayılan anahtar kelimeler: "isi gider", "ısı gider", "heat cost"
     */
    private static function is_isi_gider( $items ) {
        // 1) Katalog üzerinden grup template'ini kontrol et
        $catalog = QuotePress_Catalog::flat_list();
        $cat_map = [];
        foreach ( $catalog as $c ) {
            $cat_map[ $c['label'] ] = $c['template'];
        }
        foreach ( $items as $item ) {
            $name = $item['name'] ?? '';
            if ( isset( $cat_map[ $name ] ) && $cat_map[ $name ] === 'heat_sharing' ) {
                return true;
            }
        }

        // 2) Settings'ten özel tetikleyiciler al (geriye uyumluluk)
        $raw = QuotePress_Settings::get( 'detailed_format_triggers', '' );
        $triggers = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
        if ( empty( $triggers ) ) {
            $triggers = [ 'isi gider', 'ısı gider', 'heat cost', 'isı gider' ];
        }

        foreach ( $items as $item ) {
            $name = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $item['name'] ?? '', 'UTF-8' )
                : strtolower( $item['name'] ?? '' );
            foreach ( $triggers as $trigger ) {
                $trigger_lower = function_exists( 'mb_strtolower' )
                    ? mb_strtolower( $trigger, 'UTF-8' )
                    : strtolower( $trigger );
                if ( strpos( $name, $trigger_lower ) !== false ) {
                    return true;
                }
            }
        }
        return false;
    }

    /* ─────────────────────────────────────────────────────────── */
    /*  Ana HTML yönlendirici                                      */
    /* ─────────────────────────────────────────────────────────── */
    public static function html( $request, $payload, $note ) {
        $items = $payload['items'] ?? [];
        if ( self::is_isi_gider( $items ) ) {
            return self::html_isi_gider( $request, $payload, $note );
        }
        return self::html_standart( $request, $payload, $note );
    }

    /* ═══════════════════════════════════════════════════════════ */
    /*  STANDART KOMPAKT FORMAT (diğer ürünler)                   */
    /* ═══════════════════════════════════════════════════════════ */
    private static function html_standart( $request, $payload, $note ) {
        $c        = QuotePress_Settings::active_theme_colors();
        $s        = function( $k, $d = '' ) { return QuotePress_Settings::get( $k, $d ); };
        $items    = $payload['items']        ?? [];
        $currency = $payload['currency']     ?? 'TL';
        $vat_rate = intval( $payload['vat_rate']    ?? 0 );
        $subtotal = floatval( $payload['subtotal']   ?? 0 );
        $vat_amt  = floatval( $payload['vat_amount'] ?? 0 );
        $grand    = floatval( $payload['grand_total'] ?? 0 );
        $validity = intval( $s('validity_days', 15) );
        $expiry   = date_i18n( get_option('date_format'), strtotime("+{$validity} days") );
        $today    = date_i18n( get_option('date_format') );
        $qn_start  = intval( QuotePress_Settings::get( 'quote_number_start', 1 ) );
        $qn_prefix = QuotePress_Settings::get( 'quote_number_prefix', '' );
        $qn_num    = ( $request->id - 1 ) + $qn_start;
        $quote_no  = esc_html( $qn_prefix ) . str_pad( $qn_num, 4, '0', STR_PAD_LEFT );
        $co   = $s('company_name', 'Firma');
        $em   = $s('company_email');
        $tel1 = $s('company_phone1');
        $tel2 = $s('company_phone2');
        $web  = $s('company_website');
        $primary = $c['primary'];
        $light   = $c['light'];
        $border  = $c['border'];

        // Logo
        $logo_id  = intval( $s('company_logo_id', 0) );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        $logo_tag = $logo_url
            ? '<img src="' . esc_url($logo_url) . '" style="max-height:52px;max-width:180px;display:block;">'
            : '';

        $rows = '';
        foreach ( $items as $i => $item ) {
            $bg    = $i % 2 === 0 ? '#ffffff' : $light;
            $price = floatval( $item['price'] ?? 0 );
            $total = floatval( $item['total'] ?? 0 );
            $qty   = $item['qty'] ?? 1;
            $model = $item['model'] ?? '';
            $desc  = esc_html($item['name'] ?? '');
            if ($model) $desc .= '<br><span style="font-size:11px;color:#888;">' . esc_html($model) . '</span>';
            $rows .= '<tr style="background:' . $bg . ';">
              <td style="padding:10px 14px;border-bottom:1px solid #eee;font-size:12.5px;line-height:1.5;">' . $desc . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:center;font-size:12.5px;">' . esc_html($qty) . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:right;font-size:12.5px;">' . number_format($price,2,',','.') . ' ' . esc_html($currency) . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:right;font-weight:700;font-size:12.5px;color:' . $primary . ';">' . number_format($total,2,',','.') . ' ' . esc_html($currency) . '</td>
            </tr>';
        }
        $vat_row = $vat_rate > 0
            ? '<tr><td colspan="3" style="text-align:right;padding:7px 14px;font-size:12px;color:#666;">KDV (' . $vat_rate . '%)</td><td style="text-align:right;padding:7px 14px;font-size:12px;">' . number_format($vat_amt,2,',','.') . ' ' . esc_html($currency) . '</td></tr>'
            : '';
        $note_block = $note
            ? '<div style="margin:16px 30px 0;padding:13px 16px;background:#f7f7f7;border-left:4px solid ' . $primary . ';font-size:12px;color:#444;line-height:1.8;"><strong style="display:block;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:' . $primary . ';margin-bottom:5px;">NOTLAR</strong>' . nl2br(esc_html($note)) . '</div>'
            : '';
        $c_parts = [];
        if ($em)   $c_parts[] = esc_html($em);
        if ($tel1) $c_parts[] = esc_html($tel1);
        if ($tel2) $c_parts[] = esc_html($tel2);
        $c_line = implode(' &middot; ', $c_parts);

        $summary_box = '';
        if ( count($items) === 1 ) {
            $kdv_label  = $vat_rate > 0 ? 'KDV Hariç' : 'KDV Dahil';
            $summary_box = '<td style="text-align:right;vertical-align:top;white-space:nowrap;padding-left:24px;">' . '<div style="font-size:12px;color:#555;line-height:2.2;">' . '<span style="font-weight:700;font-size:15px;color:#222;">' . esc_html($items[0]['qty']??'1') . '</span>' . ' <span style="font-size:10px;color:#999;">Bağımsız Bölüm</span><br>' . '<span style="font-weight:700;font-size:15px;color:#222;">' . number_format(floatval($items[0]['price']??0),2,',','.') . ' ' . esc_html($currency) . '</span>' . ' <span style="font-size:10px;color:#999;">/ Bölüm &middot; ' . $kdv_label . '</span><br>' . '<span style="font-weight:900;font-size:16px;color:' . $primary . ';">' . number_format($grand,2,',','.') . ' ' . esc_html($currency) . '</span>' . ' <span style="font-size:10px;color:#999;">KDV Dahil Toplam</span>' . '</div></td>';
        }
        $subtitle = $request->project_desc ? nl2br(esc_html($request->project_desc)) : esc_html($request->company_name) . ' i&ccedil;in &ouml;zel olarak hazırlanmıştır.';
        $city = $request->city ? esc_html($request->city) . ($request->country ? ', ' . esc_html($request->country) : '') : '';
        $kdv_notu = $vat_rate > 0 ? 'KDV dahildir.' : 'KDV dahil de&gcirc;ildir.';

        return '<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><style>*{box-sizing:border-box;}body{font-family:Arial,Helvetica,sans-serif;color:#222;margin:0;padding:0;background:#fff;}table{border-collapse:collapse;width:100%;}a{text-decoration:none;}</style></head><body>
<table style="background:' . $primary . ';padding:9px 30px;"><tr><td style="font-size:11px;color:rgba(255,255,255,.92);text-align:center;">' . $c_line . '</td></tr></table>
<table style="padding:16px 30px 14px;border-bottom:3px solid ' . $primary . ';"><tr><td>' . ($logo_tag ?: '<div style="font-size:24px;font-weight:900;color:' . $primary . ';">' . esc_html($co) . '</div>') . '</td><td style="text-align:right;"><div style="display:inline-block;background:' . $primary . ';color:#fff;padding:7px 20px;border-radius:4px;text-align:center;"><div style="font-size:14px;font-weight:700;letter-spacing:.05em;">H&Icirc;ZMET TEKL&Icirc;F&Icirc;</div><div style="font-size:10.5px;opacity:.85;margin-top:2px;">NO: ' . $quote_no . ' &middot; ' . $today . '</div></div></td></tr></table>
<table style="padding:18px 30px 0;"><tr><td style="vertical-align:top;"><div style="font-size:19px;font-weight:700;margin-bottom:5px;">' . esc_html($request->project_name) . '</div><div style="font-size:12.5px;color:#666;">' . $subtitle . '</div></td>' . $summary_box . '</tr></table>
<table style="padding:14px 30px;border-bottom:2px solid #eee;margin-top:14px;"><tr><td style="vertical-align:top;padding-right:30px;"><div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:' . $primary . ';margin-bottom:5px;">HAZIRLANAN F&Icirc;RMA</div><div style="font-size:14px;font-weight:700;margin-bottom:4px;">' . esc_html($request->company_name) . '</div><div style="font-size:12px;color:#555;line-height:1.9;">' . esc_html($request->contact_name) . ($request->contact_title ? ' &ndash; ' . esc_html($request->contact_title) : '') . ($city ? '<br>' . $city : '') . ($request->email ? '<br>' . esc_html($request->email) : '') . ($request->phone ? '<br>' . esc_html($request->phone) : '') . ($request->tax_number ? '<br>Vergi No: ' . esc_html($request->tax_number) : '') . '</div></td>' . ($validity ? '<td style="vertical-align:top;text-align:right;white-space:nowrap;"><div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:' . $primary . ';margin-bottom:5px;">TEKL&Icirc;F GE&Ccedil;ERL&Icirc;L&Icirc;K</div><div style="font-size:13px;font-weight:700;">' . $validity . ' G&uuml;n</div><div style="font-size:11px;color:#888;margin-top:2px;">' . $expiry . ' tarihine kadar</div></td>' : '') . '</tr></table>
<div style="padding:18px 30px 0;"><table style="border:1px solid ' . $border . ';border-radius:6px;overflow:hidden;"><thead><tr style="background:' . $primary . ';"><th style="padding:10px 14px;text-align:left;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Ürün / Hizmet</th><th style="padding:10px 14px;text-align:center;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Miktar</th><th style="padding:10px 14px;text-align:right;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Birim Fiyat</th><th style="padding:10px 14px;text-align:right;color:#fff;font-size:10.5px;font-weight:700;text-transform:uppercase;">Toplam Tutar</th></tr></thead><tbody>' . $rows . '</tbody><tfoot><tr style="background:' . $light . ';"><td colspan="3" style="text-align:right;padding:8px 14px;font-size:12px;color:#666;">Ara Toplam</td><td style="text-align:right;padding:8px 14px;font-size:12px;font-weight:600;">' . number_format($subtotal,2,',','.') . ' ' . esc_html($currency) . '</td></tr>' . $vat_row . '<tr style="background:' . $primary . ';"><td colspan="3" style="text-align:right;padding:11px 14px;color:#fff;font-size:13px;font-weight:700;">GENEL TOPLAM</td><td style="text-align:right;padding:11px 14px;color:#fff;font-size:15px;font-weight:900;">' . number_format($grand,2,',','.') . ' ' . esc_html($currency) . '</td></tr></tfoot></table></div>
' . $note_block . '
<div style="padding:16px 30px 0;"><div style="background:' . $light . ';border:1px solid ' . $border . ';border-radius:6px;padding:12px 16px;font-size:12.5px;color:#444;line-height:1.7;">&#128337; <strong>Teklifin onaylanması halinde s&ouml;zleşme d&uuml;zenlenir.</strong> Belirtilen fiyatlara ' . $kdv_notu . ' Teklif ge&ccedil;erlilik s&uuml;resi: <strong>' . $validity . ' g&uuml;n.</strong></div></div>
<table style="margin-top:22px;padding:13px 30px;background:#f5f5f5;border-top:2px solid #e8e8e8;"><tr><td style="font-size:11px;color:#777;line-height:2;"><strong style="color:' . $primary . ';font-size:12px;">' . esc_html($co) . '</strong><br>' . $c_line . ($web ? '<br><a href="' . esc_url($web) . '" style="color:' . $primary . ';font-weight:600;">' . esc_html($web) . '</a>' : '') . '</td><td style="text-align:right;vertical-align:bottom;font-size:10.5px;color:#aaa;">Teklif No: ' . $quote_no . ' &nbsp;|&nbsp; ' . $today . '</td></tr></table>
</body></html>';
    }

    /* ═══════════════════════════════════════════════════════════ */
    /*  ISI GİDER PAYLAŞIM — 4 SAYFALIK DETAYLI FORMAT           */
    /* ═══════════════════════════════════════════════════════════ */
    private static function html_isi_gider( $request, $payload, $note ) {
        $c        = QuotePress_Settings::active_theme_colors();
        $s        = function( $k, $d = '' ) { return QuotePress_Settings::get( $k, $d ); };
        $items    = $payload['items']        ?? [];
        $currency = $payload['currency']     ?? 'TL';
        $vat_rate = intval( $payload['vat_rate']    ?? 0 );
        $subtotal = floatval( $payload['subtotal']   ?? 0 );
        $vat_amt  = floatval( $payload['vat_amount'] ?? 0 );
        $grand    = floatval( $payload['grand_total'] ?? 0 );
        $validity = intval( $s('validity_days', 15) );
        $expiry   = date_i18n( get_option('date_format'), strtotime("+{$validity} days") );
        $today    = date_i18n( get_option('date_format') );
        $qn_start  = intval( QuotePress_Settings::get( 'quote_number_start', 1 ) );
        $qn_prefix = QuotePress_Settings::get( 'quote_number_prefix', '' );
        $qn_num    = ( $request->id - 1 ) + $qn_start;
        $quote_no  = esc_html( $qn_prefix ) . str_pad( $qn_num, 4, '0', STR_PAD_LEFT );

        $co       = $s('company_name', 'Firma');
        $em       = $s('company_email');
        $tel1     = $s('company_phone1');
        $tel2     = $s('company_phone2');
        $web      = $s('company_website');
        $wp       = $s('company_whatsapp');

        // Logo
        $logo_id  = intval( $s('company_logo_id', 0) );
        $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

        $primary = $c['primary'];
        $light   = $c['light'];
        $border  = $c['border'];
        $dark    = $c['dark'] ?? $primary;

        $c_parts = [];
        if ($em)   $c_parts[] = esc_html($em);
        if ($tel1) $c_parts[] = esc_html($tel1);
        if ($tel2) $c_parts[] = esc_html($tel2);
        $c_line = implode(' &nbsp;&middot;&nbsp; ', $c_parts);

        // İlk kalem bilgileri (ısı gider satırı)
        $main_item  = $items[0] ?? [];
        $unit_qty   = $main_item['qty']   ?? 0;
        $unit_price = floatval($main_item['price'] ?? 0);
        $unit_total = floatval($main_item['total'] ?? 0);

        // Müşteri
        $client      = esc_html($request->company_name);
        $contact     = esc_html($request->contact_name);
        $contact_ttl = $request->contact_title ? $request->contact_title : '';
        $city        = $request->city ? esc_html($request->city) . ($request->country ? ', ' . esc_html($request->country) : '') : '';
        // Cinsiyet bazlı hitap
        $sal_code = $request->contact_salutation ?? 'neutral';
        if ( $contact ) {
            if ( $sal_code === 'female' ) {
                $salutation = 'Sayın ' . esc_html($contact) . ' Hanım,';
            } elseif ( $sal_code === 'male' ) {
                $salutation = 'Sayın ' . esc_html($contact) . ' Bey,';
            } else {
                $salutation = 'Sayın ' . esc_html($contact) . ',';
            }
        } else {
            $salutation = 'Sayın Yetkililer,';
        }

        // Fiyat satırları
        $rows = '';
        foreach ( $items as $i => $item ) {
            $bg    = $i % 2 === 0 ? '#ffffff' : $light;
            $price = floatval( $item['price'] ?? 0 );
            $total = floatval( $item['total'] ?? 0 );
            $qty   = $item['qty'] ?? 1;
            $desc  = esc_html($item['name'] ?? '');
            if ( $item['model'] ?? '' ) {
                $desc .= '<br><span style="font-size:11px;color:#888;">' . esc_html($item['model']) . '</span>';
            }
            $rows .= '<tr style="background:' . $bg . ';">
              <td style="padding:10px 14px;border-bottom:1px solid #eee;font-size:12.5px;line-height:1.5;width:50%;">' . $desc . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:center;font-size:12.5px;">' . esc_html($qty) . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:right;font-size:12.5px;">' . number_format($price,2,',','.') . ' ' . esc_html($currency) . '</td>
              <td style="padding:10px 14px;border-bottom:1px solid #eee;text-align:right;font-weight:700;font-size:12.5px;color:' . $primary . ';">' . number_format($total,2,',','.') . ' ' . esc_html($currency) . '</td>
            </tr>';
        }
        $vat_row = $vat_rate > 0
            ? '<tr><td colspan="3" style="text-align:right;padding:7px 14px;font-size:12px;color:#666;">KDV (' . $vat_rate . '%)</td><td style="text-align:right;padding:7px 14px;font-size:12px;">' . number_format($vat_amt,2,',','.') . ' ' . esc_html($currency) . '</td></tr>'
            : '';

        $note_block = $note
            ? '<div style="margin:16px 0 0;padding:13px 16px;background:#f7f7f7;border-left:4px solid ' . $primary . ';font-size:12px;color:#444;line-height:1.8;"><strong style="display:block;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:' . $primary . ';margin-bottom:5px;">NOTLAR</strong>' . nl2br(esc_html($note)) . '</div>'
            : '';

        // Taahhüt kartları için şablon
        $card = function($num, $unit, $title, $sub, $body) use ($primary, $light, $border) {
            return '<td style="width:50%;vertical-align:top;padding:4px;">
              <table style="width:100%;border:1px solid ' . $border . ';border-radius:6px;border-collapse:collapse;page-break-inside:avoid;">
                <tr>
                  <td style="background:' . $primary . ';padding:10px 12px;border-radius:6px 6px 0 0;">
                    <span style="font-size:22pt;font-weight:700;color:#fff;line-height:1;">' . $num . '</span>
                    <span style="font-size:9pt;font-weight:700;color:rgba(255,255,255,.85);padding-left:5px;">' . $unit . '</span>
                  </td>
                </tr>
                <tr>
                  <td style="background:#fff;padding:10px 12px;">
                    <div style="font-size:11pt;font-weight:700;margin-bottom:4px;">' . $title . '</div>
                    <div style="font-size:9pt;color:' . $primary . ';font-style:italic;margin-bottom:6px;">' . $sub . '</div>
                    <div style="font-size:9.5pt;color:#555;line-height:1.6;">' . $body . '</div>
                  </td>
                </tr>
              </table>
            </td>';
        };

        $kdv_notu = $vat_rate > 0 ? 'KDV dahildir.' : 'KDV dahil değildir.';

        // ───────── HTML BAŞLIYOR ─────────
        ob_start(); ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<style>
/* mPDF uyumlu CSS — flex/grid kullanılmaz */
body { font-family: dejavusans, Arial, sans-serif; color:#222; margin:0; padding:0; background:#fff; font-size:10pt; }
table { border-collapse:collapse; width:100%; }
td, th { vertical-align:top; }
a { text-decoration:none; }
.section { padding:20px 28px; }
.section-title { font-size:14pt; font-weight:700; color:#222; margin:0 0 6px 0; }
.section-sub { font-size:10pt; color:#555; line-height:1.6; margin:0 0 14px 0; }
/* Sayfa kırışı */
.page-break { page-break-before:always; }
.no-break { page-break-inside:avoid; }
/* Kart yapısı - tamamen table bazlı */
.card-table { width:100%; border-collapse:separate; border-spacing:8px; }
.card-num { font-size:22pt; font-weight:700; line-height:1; }
.card-unit { font-size:9pt; font-weight:700; line-height:1.2; }
.card-title { font-size:11pt; font-weight:700; margin-bottom:3px; }
.card-sub { font-size:9pt; font-style:italic; margin-bottom:5px; }
.card-body { font-size:9.5pt; color:#555; line-height:1.6; }
/* Rozet */
.badge-row td { font-size:9pt; font-weight:700; padding:4px 12px; }
/* Tablo stilleri */
.price-table { border-collapse:collapse; width:100%; }
.price-table th { font-size:8.5pt; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; padding:9px 12px; }
.price-table td { font-size:10pt; padding:9px 12px; }
.stat-table { border-collapse:collapse; width:100%; }
.stat-table td { text-align:center; padding:14px 16px; font-size:9pt; }
</style>
</head>
<body>

<!-- ══ ÜST İLETİŞİM ŞERİDİ ══ -->
<table style="background:<?php echo $primary; ?>;padding:9px 30px;">
  <tr>
    <td style="font-size:11px;color:rgba(255,255,255,.92);text-align:center;letter-spacing:.02em;">
      <?php echo $c_line; ?>
    </td>
  </tr>
</table>

<!-- ══ BAŞLIK ══ -->
<table style="padding:16px 30px 14px;border-bottom:3px solid <?php echo $primary; ?>;">
  <tr>
    <td style="vertical-align:middle;">
      <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" style="max-height:52px;max-width:180px;display:block;"><?php else: ?><div style="font-size:24px;font-weight:900;color:<?php echo $primary; ?>;letter-spacing:.01em;"><?php echo esc_html($co); ?></div><?php endif; ?>
    </td>
    <td style="text-align:right;vertical-align:middle;">
      <div style="display:inline-block;background:<?php echo $primary; ?>;color:#fff;padding:7px 20px;border-radius:4px;text-align:center;">
        <div style="font-size:14px;font-weight:700;letter-spacing:.05em;">HİZMET TEKLİFİ</div>
        <div style="font-size:10.5px;opacity:.85;margin-top:2px;">NO: <?php echo $quote_no; ?> &nbsp;&middot;&nbsp; <?php echo $today; ?></div>
      </div>
    </td>
  </tr>
</table>

<!-- ══ HİZMET + ÖZET ══ -->
<table style="padding:18px 30px 0;">
  <tr>
    <td style="vertical-align:top;">
      <div style="font-size:19px;font-weight:700;margin-bottom:5px;"><?php echo esc_html($request->project_name); ?></div>
      <div style="font-size:12.5px;color:#666;"><?php echo $client; ?> için özel olarak hazırlanmıştır.</div>
    </td>
    <td style="text-align:right;vertical-align:top;white-space:nowrap;padding-left:24px;">
      <div style="font-size:12.5px;color:#555;line-height:2.1;">
        <span style="font-weight:700;font-size:15px;color:#222;"><?php echo esc_html($unit_qty); ?></span>
        <span style="font-size:10pt;color:#999;"> Bağımsız Bölüm</span><br>
        <span style="font-weight:700;font-size:15px;color:#222;"><?php echo number_format($unit_price,2,',','.'); ?> <?php echo esc_html($currency); ?></span>
        <span style="font-size:10pt;color:#999;"> / Bölüm · <?php echo $vat_rate > 0 ? 'KDV Hariç' : 'KDV Dahil'; ?></span><br>
        <span style="font-weight:900;font-size:16px;color:<?php echo $primary; ?>;"><?php echo number_format($grand,2,',','.'); ?> <?php echo esc_html($currency); ?></span>
        <span style="font-size:10pt;color:#999;"> KDV Dahil Toplam</span><br>
      </div>
    </td>
  </tr>
</table>

<!-- ══ MÜŞTERİ / GEÇERLİLİK ══ -->
<table style="padding:14px 30px;border-bottom:2px solid #eee;margin-top:14px;">
  <tr>
    <td style="vertical-align:top;padding-right:30px;">
      <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:<?php echo $primary; ?>;margin-bottom:5px;">HAZIRLANAN FİRMA</div>
      <div style="font-size:14px;font-weight:700;margin-bottom:4px;"><?php echo $client; ?></div>
      <div style="font-size:12px;color:#555;line-height:1.9;">
        <?php if ($city) echo $city . '<br>'; ?>
        Yetkili: <?php echo $contact; ?><?php if ($contact_ttl) echo ' &ndash; ' . esc_html($contact_ttl); ?>
        <?php if ($request->email) echo '<br>' . esc_html($request->email); ?>
        <?php if ($request->phone) echo '<br>' . esc_html($request->phone); ?>
      </div>
    </td>
    <?php if ($validity) : ?>
    <td style="vertical-align:top;text-align:right;white-space:nowrap;">
      <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:<?php echo $primary; ?>;margin-bottom:5px;">TEKLİF GEÇERLİLİK</div>
      <div style="font-size:13px;font-weight:700;"><?php echo $validity; ?> Gün</div>
      <div style="font-size:11px;color:#888;margin-top:2px;"><?php echo $expiry; ?> tarihine kadar</div>
    </td>
    <?php endif; ?>
  </tr>
</table>

<!-- ══ ROZET ŞERIDI ══ -->
<?php
$badge_defaults2 = ['20+ Yıl Tecrübe','Bakanlık Onaylı Yetkili Ölçüm Şirketi','ISO 9001 Sertifikalı','Sıfır Sorun Garantisi'];
$badges_html2 = '';
for ($bi2=1;$bi2<=4;$bi2++) {
    $btext2 = QuotePress_Settings::get_tpl('ig_badge'.$bi2, $badge_defaults2[$bi2-1]);
    $bicon2 = QuotePress_Settings::get_tpl('ig_badge'.$bi2.'_icon', '&#10003;');
    if ($btext2) $badges_html2 .= '<td style="font-size:11px;font-weight:700;color:' . $primary . ';padding-right:16px;">' . esc_html($bicon2) . ' ' . esc_html($btext2) . '</td>';
}
?>
<div style="background:<?php echo $light; ?>;border-bottom:1px solid <?php echo $border; ?>;padding:10px 30px;">
  <table><tr><?php echo $badges_html2; ?></tr></table>
</div>

<!-- ══ GİRİŞ MEKTUBU ══ -->
<div class="section">
  <p style="font-size:13px;font-weight:700;margin:0 0 10px;"><?php echo $salutation; ?></p>
  <p style="font-size:12.5px;color:#444;line-height:1.8;margin:0 0 14px;">
    <?php echo $client; ?>'nin ısı gider paylaşım süreçleri için <?php echo esc_html($co); ?>'ye gösterdiğiniz ilgiye teşekkür ederiz. Bu teklif, sitenizin ihtiyaçlarına özel olarak hazırlanmış olup aşağıdaki soruların yanıtlarını içermektedir:
  </p>
  <table style="border:1px solid <?php echo $border; ?>;border-radius:6px;overflow:hidden;margin-bottom:14px;">
    <?php
    $def_qs2 = [
        'Bağımsız bölümlere düşen ısı gideri payı nasıl hesaplanacak, sakinler itiraz ederse ne olacak?',
        'Fatura geldiğinde hesapları kim yapacak, raporları kim dağıtacak?',
        'Arıza yapan sayaçlar nasıl takip edilecek, yasal yükümlülükler yerine getirilecek mi?',
    ];
    $tpl_qs = [];
    for ($qi=1;$qi<=10;$qi++) {
        $qv = QuotePress_Settings::get_tpl('ig_q'.$qi, $qi<=3 ? ($def_qs2[$qi-1]??'') : '');
        if ($qv) $tpl_qs[] = $qv;
    }
    if (empty($tpl_qs)) $tpl_qs = $def_qs2;
    foreach ($tpl_qs as $qi => $q) {
        $bg = $qi % 2 === 0 ? '#fff' : $light;
        echo '<tr style="background:' . $bg . ';">
          <td style="padding:9px 12px;width:28px;font-size:16px;vertical-align:top;">&#10067;</td>
          <td style="padding:9px 14px 9px 0;font-size:12.5px;color:#444;line-height:1.6;">' . esc_html($q) . '</td>
        </tr>';
    }
    ?>
  </table>
  <div style="background:<?php echo $primary; ?>;color:#fff;border-radius:6px;padding:12px 16px;font-size:12.5px;line-height:1.7;">
    <strong>Bu soruların tamamına net bir yanıt veriyoruz:</strong> <?php echo esc_html($co); ?> olarak tüm süreci sizin adınıza yönetiyor, sadece sonuçları size teslim ediyoruz.
  </div>
</div>

<!-- ══ KARŞILAŞTIRMA TABLOSU ══ -->
<div class="section" style="padding-top:0;">
  <div class="section-title">Neden Dışarıdan Hizmet Almak Mantıklı?</div>
  <table style="border:1px solid <?php echo $border; ?>;border-radius:6px;overflow:hidden;">
    <thead>
      <tr style="background:<?php echo $primary; ?>;">
        <th style="padding:9px 14px;text-align:left;color:#fff;font-size:11px;font-weight:700;">Kalem</th>
        <th style="padding:9px 14px;text-align:left;color:#fff;font-size:11px;font-weight:700;">Kendiniz Yaparsanız</th>
        <th style="padding:9px 14px;text-align:left;color:#fff;font-size:11px;font-weight:700;"><?php echo esc_html($co); ?> ile</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $comparison = [
          ['Personel & Zaman', 'Yönetici saatlerce hesap yapar', 'Siz sadece faturayı iletirsiniz'],
          ['Hata Riski', 'Manuel hesap = itiraz riski', 'Otomatik, denetlenebilir hesaplama'],
          ['Yasal Uyum', 'Takip siz yaparsınız', 'Mevzuat takibi bizde'],
          ['Arşiv & Raporlama', 'Sizin sorumluluğunuz', 'Dijital, 7/24 erişilebilir arşiv'],
          ['Aylık Maliyet', 'Tahmin edilemez', esc_html($unit_qty) . ' bölüm × ' . number_format($unit_price,0,',','.') . ' ' . esc_html($currency) . ' = ' . number_format($grand,0,',','.') . ' ' . esc_html($currency) . '/ay sabit'],
      ];
      foreach ($comparison as $ri => $row) {
          $bg = $ri % 2 === 0 ? '#fff' : $light;
          echo '<tr style="background:' . $bg . ';">
            <td style="padding:9px 14px;font-size:12px;font-weight:700;border-bottom:1px solid #eee;">' . esc_html($row[0]) . '</td>
            <td style="padding:9px 14px;font-size:12px;color:#888;border-bottom:1px solid #eee;">' . esc_html($row[1]) . '</td>
            <td style="padding:9px 14px;font-size:12px;font-weight:700;color:' . $primary . ';border-bottom:1px solid #eee;">' . $row[2] . '</td>
          </tr>';
      }
      ?>
    </tbody>
  </table>
</div>

<!-- ══ SAYFA KIRISI ══ -->
<div class="page-break"></div>

<!-- ══ TAAHHÜTLER BAŞLIĞI ══ -->
<div class="section" style="padding-bottom:10px;">
  <div class="section-title">Hizmet Taahhütlerimiz &mdash; &ldquo;Sıfır Sorun&rdquo; Garantisi</div>
  <div class="section-sub"><?php echo esc_html($co); ?> olarak size yalnızca bir hizmet satmıyoruz; tüm ısı gider paylaşım sürecinin sorumluluğunu üstleniyoruz. Aşağıdaki taahhütlerin her biri, sözleşme kapsamında güvence altındadır.</div>
</div>

<!-- ══ TAAHHÜT KARTLARI ══ -->
<div style="padding:0 30px;">
  <table style="width:100%;border-spacing:0;">
    <?php
    // Kart verilerini şablon ayarlarından oku (boşsa varsayılan)
    $tpl_cards = [
        [
            QuotePress_Settings::get_tpl('ig_card1_num','3'),
            QuotePress_Settings::get_tpl('ig_card1_unit','İŞ GÜNÜ'),
            QuotePress_Settings::get_tpl('ig_card1_title','3 İş Günü Rapor Teslimi'),
            QuotePress_Settings::get_tpl('ig_card1_sub','Fatura gelir, rapor hazır olur.'),
            QuotePress_Settings::get_tpl('ig_card1_body','Doğalgaz faturasını bize iletmenizden itibaren en geç 3 iş günü içinde tüm bağımsız bölümlere ait ısı gider paylaşım raporları eksiksiz biçimde hazırlanarak size teslim edilir. Bekleme yok, gecikme yok.'),
        ],
        [
            QuotePress_Settings::get_tpl('ig_card2_num','PDF'),
            QuotePress_Settings::get_tpl('ig_card2_unit','EXCEL · ÇIKTI'),
            QuotePress_Settings::get_tpl('ig_card2_title','Çok Formatlı Dijital Raporlama'),
            QuotePress_Settings::get_tpl('ig_card2_sub','Kağıt değil, akıllı raporlama.'),
            QuotePress_Settings::get_tpl('ig_card2_body','Paylaşım sonuçları PDF, Excel ve yazdırmaya hazır çıktı formatında; hem tüm site için toplu hem de her bağımsız bölüm için ayrı ayrı hazırlanır. Dağıtım tamamen elektronik ortamda gerçekleşir.'),
        ],
        [
            QuotePress_Settings::get_tpl('ig_card3_num','48'),
            QuotePress_Settings::get_tpl('ig_card3_unit','SAAT'),
            QuotePress_Settings::get_tpl('ig_card3_title','48 Saat Teknik Servis'),
            QuotePress_Settings::get_tpl('ig_card3_sub','Arıza bildirimi = 48 saat içinde çözüm.'),
            QuotePress_Settings::get_tpl('ig_card3_body','Sayaç arızası veya yerinde müdahale gerektiren her durumda teknik ekibimiz, bildirimin alınmasından itibaren en geç 48 saat içinde sahada. Ayrıca her okuma döneminde arızalı veya okunamayan sayaçlar teknik servis formuyla size ayrıntılı raporlanır.'),
        ],
        [
            QuotePress_Settings::get_tpl('ig_card4_num','7/24'),
            QuotePress_Settings::get_tpl('ig_card4_unit','ERİŞİM'),
            QuotePress_Settings::get_tpl('ig_card4_title','Web Paneli ile Tam Şeffaflık'),
            QuotePress_Settings::get_tpl('ig_card4_sub','Her daire kendi tüketimini görür.'),
            QuotePress_Settings::get_tpl('ig_card4_body', ($web ?: 'www.asisfatura.com') . ' platformu üzerinden site yönetimi ve her bağımsız bölüm sakini, kişisel kullanıcı bilgileriyle giriş yaparak anlık tüketim verilerini ve geçmiş dönem raporlarını 7/24 görüntüleyebilir. Sakinlerin hesap itirazları minimize olur.'),
        ],
        [
            QuotePress_Settings::get_tpl('ig_card5_num','100%'),
            QuotePress_Settings::get_tpl('ig_card5_unit','UYUM'),
            QuotePress_Settings::get_tpl('ig_card5_title','Yasal Mevzuata Tam Uyum'),
            QuotePress_Settings::get_tpl('ig_card5_sub','Ceza riski sıfır, uyumluluk tam.'),
            QuotePress_Settings::get_tpl('ig_card5_body','Tüm hesaplamalar, T.C. Çevre, Şehircilik ve İklim Değişikliği Bakanlığı\'nın Isı Gider Paylaşım Yönetmeliği çerçevesinde eksiksiz yürütülür. Mevzuattaki değişiklikler düzenli takip edilir; site yönetimi zamanında bilgilendirilerek olası ceza ve uyumsuzlukların önüne geçilir.'),
        ],
        [
            QuotePress_Settings::get_tpl('ig_card6_num','ISO'),
            QuotePress_Settings::get_tpl('ig_card6_unit','9001'),
            QuotePress_Settings::get_tpl('ig_card6_title','Güvenli Arşiv ve Anında Erişim'),
            QuotePress_Settings::get_tpl('ig_card6_sub','Hiçbir veri kaybolmaz.'),
            QuotePress_Settings::get_tpl('ig_card6_body','Tüm okuma verileri ve paylaşım raporları yasal saklama sürelerine uygun olarak güvenli dijital ortamda arşivlenir. Geçmiş herhangi bir döneme ait veriye talep anında erişilebilir. Hesaplama formülasyonu ve metodoloji dokümanı da site yönetimiyle paylaşılır.'),
        ],
    ];
    ?>
    <tr class="no-break">
      <?php echo $card($tpl_cards[0][0],$tpl_cards[0][1],$tpl_cards[0][2],$tpl_cards[0][3],$tpl_cards[0][4]); ?>
      <?php echo $card($tpl_cards[1][0],$tpl_cards[1][1],$tpl_cards[1][2],$tpl_cards[1][3],$tpl_cards[1][4]); ?>
    </tr>
    <tr><td colspan="2" style="height:10px;"></td></tr>
    <tr class="no-break">
      <?php echo $card($tpl_cards[2][0],$tpl_cards[2][1],$tpl_cards[2][2],$tpl_cards[2][3],$tpl_cards[2][4]); ?>
      <?php echo $card($tpl_cards[3][0],$tpl_cards[3][1],$tpl_cards[3][2],$tpl_cards[3][3],$tpl_cards[3][4]); ?>
    </tr>
    <tr><td colspan="2" style="height:10px;"></td></tr>
    <tr class="no-break">
      <?php echo $card($tpl_cards[4][0],$tpl_cards[4][1],$tpl_cards[4][2],$tpl_cards[4][3],$tpl_cards[4][4]); ?>
      <?php echo $card($tpl_cards[5][0],$tpl_cards[5][1],$tpl_cards[5][2],$tpl_cards[5][3],$tpl_cards[5][4]); ?>
    </tr>
  </table>
</div>

<!-- ══ SAYFA KIRISI: Sayfa 2 biter, Sayfa 3 başlar ══ -->
<div class="page-break"></div>

<!-- ══ ÖZET İSTATİSTİK ŞERIDI ══ -->
<div style="padding:22px 30px 0;">
  <table style="border:1px solid <?php echo $border; ?>;border-radius:6px;background:<?php echo $light; ?>;">
    <tr>
      <td style="padding:16px 20px;text-align:center;border-right:1px solid <?php echo $border; ?>;">
        <div style="font-size:20px;font-weight:900;color:<?php echo $primary; ?>;">3 iş günü</div>
        <div style="font-size:11px;color:#666;margin-top:3px;">rapor teslim süresi</div>
      </td>
      <td style="padding:16px 20px;text-align:center;border-right:1px solid <?php echo $border; ?>;">
        <div style="font-size:20px;font-weight:900;color:<?php echo $primary; ?>;">48 saat</div>
        <div style="font-size:11px;color:#666;margin-top:3px;">teknik servis garantisi</div>
      </td>
      <td style="padding:16px 20px;text-align:center;">
        <div style="font-size:20px;font-weight:900;color:<?php echo $primary; ?>;">20+ yıl</div>
        <div style="font-size:11px;color:#666;margin-top:3px;">sektör deneyimi</div>
      </td>
    </tr>
  </table>
</div>

<!-- ══ YILLIK TOPLANTI ══ -->
<div style="padding:14px 30px 16px;">
  <div style="background:#fff;border:1px solid <?php echo $border; ?>;border-radius:6px;padding:14px 18px;font-size:12.5px;color:#444;line-height:1.7;">
    <strong style="color:<?php echo $primary; ?>;">&#128197; Yılda En Az 1 Kez &mdash; Yüz Yüze Hizmet Değerlendirmesi</strong><br>
    Yılda en az bir kez site yönetimiyle yüz yüze veya çevrimiçi toplantı düzenliyoruz. Hizmet kalitesi gözden geçirilir, varsa şikayetler ve iyileştirme önerileri birlikte ele alınır. Siz sadece söyleyin, biz düzeltelim.
  </div>
</div>

<!-- ══ SAYFA KIRISI: Sayfa 3 biter, Sayfa 4 başlar ══ -->
<div class="page-break"></div>

<!-- ══ FİYAT TEKLİFİ BAŞLIĞI ══ -->
<div class="section" style="padding-bottom:10px;">
  <div class="section-title">Fiyat Teklifi</div>
</div>

<div style="padding:0 30px;">
  <table style="border:1px solid <?php echo $border; ?>;border-radius:6px;overflow:hidden;">
    <thead>
      <tr style="background:<?php echo $primary; ?>;">
        <th style="padding:10px 14px;text-align:left;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;width:50%;">Açıklama</th>
        <th style="padding:10px 14px;text-align:center;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">Miktar</th>
        <th style="padding:10px 14px;text-align:right;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">Birim Fiyat</th>
        <th style="padding:10px 14px;text-align:right;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;">Toplam Tutar</th>
      </tr>
    </thead>
    <tbody><?php echo $rows; ?></tbody>
    <tfoot>
      <tr style="background:<?php echo $light; ?>;">
        <td colspan="3" style="text-align:right;padding:8px 14px;font-size:12px;color:#666;">Ara Toplam</td>
        <td style="text-align:right;padding:8px 14px;font-size:12px;font-weight:600;"><?php echo number_format($subtotal,2,',','.'); ?> <?php echo esc_html($currency); ?></td>
      </tr>
      <?php echo $vat_row; ?>
      <tr style="background:<?php echo $primary; ?>;">
        <td colspan="3" style="text-align:right;padding:11px 14px;color:#fff;font-size:13px;font-weight:700;">GENEL TOPLAM</td>
        <td style="text-align:right;padding:11px 14px;color:#fff;font-size:15px;font-weight:900;"><?php echo number_format($grand,2,',','.'); ?> <?php echo esc_html($currency); ?></td>
      </tr>
    </tfoot>
  </table>

  <?php echo $note_block; ?>

  <div style="margin-top:14px;background:<?php echo $light; ?>;border:1px solid <?php echo $border; ?>;border-radius:6px;padding:12px 16px;font-size:12.5px;color:#444;line-height:1.7;">
    &#128161; <strong>Daire başına sabit maliyet: <?php echo number_format($unit_price,0,',','.'); ?> <?php echo esc_html($currency); ?>/ay</strong> &mdash; tüm ısı gider paylaşım sürecinizi profesyonel ellere teslim edin.
  </div>
</div>

<!-- ══ GÜVENCELER ══ -->
<div class="section" style="padding-top:18px;padding-bottom:10px;">
  <div class="section-title" style="margin-bottom:12px;">Güvencelerimiz</div>
  <table>
    <tr>
      <td style="padding:8px 16px 8px 0;font-size:12px;width:25%;vertical-align:top;">
        <div style="font-size:16px;">&#127963;</div>
        <div style="font-weight:700;font-size:12px;margin-top:4px;">Bakanlık Onaylı</div>
        <div style="font-size:11px;color:#666;">Yetkili Ölçüm Şirketi</div>
      </td>
      <td style="padding:8px 16px 8px 0;font-size:12px;width:25%;vertical-align:top;border-left:1px solid <?php echo $border; ?>;padding-left:16px;">
        <div style="font-size:16px;">&#127942;</div>
        <div style="font-weight:700;font-size:12px;margin-top:4px;">ISO 9001</div>
        <div style="font-size:11px;color:#666;">Sertifikalı Hizmet Süreçleri</div>
      </td>
      <td style="padding:8px 16px 8px 0;font-size:12px;width:25%;vertical-align:top;border-left:1px solid <?php echo $border; ?>;padding-left:16px;">
        <div style="font-size:16px;">&#128197;</div>
        <div style="font-weight:700;font-size:12px;margin-top:4px;">Teklif Geçerlilik</div>
        <div style="font-size:11px;color:#666;"><?php echo $validity; ?> Gün</div>
      </td>
      <td style="padding:8px 0;font-size:12px;width:25%;vertical-align:top;border-left:1px solid <?php echo $border; ?>;padding-left:16px;">
        <div style="font-size:16px;">&#128188;</div>
        <div style="font-weight:700;font-size:12px;margin-top:4px;">20+ Yıllık</div>
        <div style="font-size:11px;color:#666;">Sektör Deneyimi</div>
      </td>
    </tr>
  </table>
</div>

<!-- ══ SONRAKI ADIM / CTA ══ -->
<div style="padding:0 30px;">
  <div style="background:<?php echo $primary; ?>;border-radius:8px;padding:18px 22px;color:#fff;">
    <div style="font-size:14px;font-weight:700;margin-bottom:8px;">Sonraki Adım &mdash; Birlikte Başlayalım</div>
    <div style="font-size:12.5px;opacity:.9;line-height:1.7;margin-bottom:12px;">
      Bu sezonu sorunsuz kapatmak istiyorsanız, <strong>teklifi <?php echo $validity; ?> gün içinde onaylayarak hemen başlayabilirsiniz.</strong>
    </div>
    <table><tr>
      <?php if ($tel1 || $tel2) : ?>
      <td style="padding-right:20px;font-size:12px;opacity:.95;">
        &#128222; <?php echo esc_html($tel1 ?: $tel2); ?>
      </td>
      <?php endif; ?>
      <?php if ($em) : ?>
      <td style="padding-right:20px;font-size:12px;opacity:.95;">
        &#9993; <?php echo esc_html($em); ?>
      </td>
      <?php endif; ?>
      <?php if ($web) : ?>
      <td style="font-size:12px;opacity:.95;">
        &#127760; <?php echo esc_html($web); ?>
      </td>
      <?php endif; ?>
    </tr></table>
  </div>
</div>

<!-- ══ FOOTER ══ -->
<table style="margin-top:22px;padding:13px 30px;background:#f5f5f5;border-top:2px solid #e8e8e8;">
  <tr>
    <td style="font-size:11px;color:#777;line-height:2;">
      <strong style="color:<?php echo $primary; ?>;font-size:12px;"><?php echo esc_html($co); ?></strong><br>
      <?php echo $c_line; ?>
      <?php if ($web) echo '<br><a href="' . esc_url($web) . '" style="color:' . $primary . ';font-weight:600;">' . esc_html($web) . '</a>'; ?>
    </td>
    <td style="text-align:right;vertical-align:bottom;font-size:10.5px;color:#aaa;">
      Teklif No: <?php echo $quote_no; ?> &nbsp;|&nbsp; <?php echo $today; ?>
    </td>
  </tr>
</table>
<div style="padding:8px 30px;text-align:center;font-size:10px;color:#aaa;">
  Sözleşmeye dönüşen teklifler iptal edilemez. <?php echo $kdv_notu; ?>
</div>

</body>
</html>
<?php
        return ob_get_clean();
    }
}
