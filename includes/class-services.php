<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Services {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_qp_save_service', [__CLASS__, 'save_service']);
        add_action('admin_post_qp_generate_offer', [__CLASS__, 'generate_offer']);
    }

    public static function admin_menu() {
        add_menu_page('QuotePress Services', 'QuotePress Proposals', 'manage_options', 'quotepress-services', [__CLASS__, 'render_page'], 'dashicons-media-document');
    }

    public static function table($name){
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function render_page() {
        global $wpdb;
        $services = $wpdb->get_results("SELECT * FROM ".self::table('quotepress_services')." ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>Teklif Hizmetleri</h1>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="qp_save_service">
                <?php wp_nonce_field('qp_save_service'); ?>
                <table class="form-table">
                    <tr><th>Hizmet Adı</th><td><input type="text" name="title" class="regular-text" required></td></tr>
                    <tr><th>Şablon</th><td><select name="template_key"><option value="heat-sharing">Isı Gider Paylaşım</option></select></td></tr>
                    <tr><th>Birim</th><td><input type="text" name="unit_label" value="Daire / Ay"></td></tr>
                    <tr><th>Açıklama</th><td><textarea name="description" class="large-text"></textarea></td></tr>
                </table>
                <?php submit_button('Hizmeti Kaydet'); ?>
            </form>
            <hr>
            <h2>Teklif Oluştur</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="qp_generate_offer">
                <?php wp_nonce_field('qp_generate_offer'); ?>
                <table class="form-table">
                    <tr><th>Hizmet</th><td><select name="service_id">
                    <?php foreach($services as $service): ?>
                    <option value="<?php echo esc_attr($service->id); ?>"><?php echo esc_html($service->title); ?></option>
                    <?php endforeach; ?>
                    </select></td></tr>
                    <tr><th>Müşteri</th><td><input type="text" name="customer_name" required></td></tr>
                    <tr><th>Yetkili</th><td><input type="text" name="contact_name" required></td></tr>
                    <tr><th>Adet</th><td><input type="number" name="quantity" required></td></tr>
                    <tr><th>Birim Fiyat</th><td><input type="number" step="0.01" name="unit_price" required></td></tr>
                    <tr><th>E-Posta</th><td><input type="email" name="email" required></td></tr>
                </table>
                <?php submit_button('Teklif Oluştur'); ?>
            </form>
        </div>
        <?php
    }

    public static function save_service(){
        check_admin_referer('qp_save_service');
        global $wpdb;
        $wpdb->insert(self::table('quotepress_services'), [
            'title'=>sanitize_text_field($_POST['title']),
            'template_key'=>sanitize_text_field($_POST['template_key']),
            'unit_label'=>sanitize_text_field($_POST['unit_label']),
            'description'=>sanitize_textarea_field($_POST['description'])
        ]);
        wp_redirect(admin_url('admin.php?page=quotepress-services'));
        exit;
    }

    public static function generate_offer(){
        check_admin_referer('qp_generate_offer');
        global $wpdb;
        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('quotepress_services')." WHERE id=%d", intval($_POST['service_id'])));
        $quantity = intval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $total = $quantity * $unit_price;

        $html = self::render_offer_html($service, $_POST, $total);

        $wpdb->insert(self::table('quotepress_offers'), [
            'service_id'=>$service->id,
            'customer_name'=>sanitize_text_field($_POST['customer_name']),
            'contact_name'=>sanitize_text_field($_POST['contact_name']),
            'quantity'=>$quantity,
            'unit_price'=>$unit_price,
            'total_price'=>$total,
            'offer_html'=>$html
        ]);

        wp_mail(sanitize_email($_POST['email']), 'Teklifiniz Hazırlandı', $html, ['Content-Type: text/html; charset=UTF-8']);
        wp_mail(get_option('admin_email'), 'Yeni Teklif Oluşturuldu', $html, ['Content-Type: text/html; charset=UTF-8']);

        wp_die('Teklif oluşturuldu ve mailler gönderildi.');
    }

    public static function render_offer_html($service, $data, $total){
        ob_start(); ?>
        <div style="font-family:Arial;padding:40px;max-width:900px;margin:auto">
            <h1>HİZMET TEKLİFİ</h1>
            <h2><?php echo esc_html($service->title); ?></h2>
            <p><strong>Müşteri:</strong> <?php echo esc_html($data['customer_name']); ?></p>
            <p><strong>Yetkili:</strong> <?php echo esc_html($data['contact_name']); ?></p>
            <table style="width:100%;border-collapse:collapse">
                <tr>
                    <th style="border:1px solid #ccc;padding:10px">Miktar</th>
                    <th style="border:1px solid #ccc;padding:10px">Açıklama</th>
                    <th style="border:1px solid #ccc;padding:10px">Birim Fiyat</th>
                    <th style="border:1px solid #ccc;padding:10px">Toplam</th>
                </tr>
                <tr>
                    <td style="border:1px solid #ccc;padding:10px"><?php echo intval($data['quantity']); ?></td>
                    <td style="border:1px solid #ccc;padding:10px"><?php echo esc_html($service->title); ?></td>
                    <td style="border:1px solid #ccc;padding:10px"><?php echo number_format(floatval($data['unit_price']),2,',','.'); ?> TL</td>
                    <td style="border:1px solid #ccc;padding:10px"><?php echo number_format($total,2,',','.'); ?> TL</td>
                </tr>
            </table>
            <h3>Hizmet Detayı</h3>
            <p><?php echo nl2br(esc_html($service->description)); ?></p>
        </div>
        <?php return ob_get_clean();
    }
}
