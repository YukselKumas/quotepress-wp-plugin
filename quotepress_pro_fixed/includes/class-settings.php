<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Settings {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_menu',            [ __CLASS__, 'register_catalog_menu' ] );
        add_action( 'admin_menu',            [ __CLASS__, 'register_template_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'save' ] );
        add_action( 'admin_post_qp_save_templates', [ __CLASS__, 'save_templates' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    /* ── Menu ───────────────────────────────────────────────── */
    public static function register_catalog_menu() {
        add_submenu_page(
            'quotepress-settings',
            'Ürün & Hizmet Kataloğu',
            '📦 Katalog',
            'manage_options',
            'quotepress-catalog',
            [ 'QuotePress_Catalog', 'render_page' ]
        );
    }

    public static function register_template_menu() {
        add_submenu_page(
            'quotepress-settings',
            'Teklif Şablonları',
            '📝 Şablonlar',
            'manage_options',
            'quotepress-templates',
            [ __CLASS__, 'render_templates_page' ]
        );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'QuotePress', 'quotepress' ),
            __( 'QuotePress', 'quotepress' ),
            'manage_options',
            'quotepress-settings',
            [ __CLASS__, 'render' ],
            'dashicons-clipboard',
            58
        );
        add_submenu_page(
            'quotepress-settings',
            __( 'Settings', 'quotepress' ),
            __( 'Settings', 'quotepress' ),
            'manage_options',
            'quotepress-settings',
            [ __CLASS__, 'render' ]
        );
        add_submenu_page(
            'quotepress-settings',
            __( 'Quote Panel', 'quotepress' ),
            __( 'Quote Panel', 'quotepress' ),
            'manage_options',
            'quotepress-panel-link',
            [ __CLASS__, 'redirect_panel' ]
        );
    }

    public static function redirect_panel() {
        $slug = self::get( 'panel_slug', 'quote-panel' );
        wp_redirect( home_url( '/' . $slug . '/' ) );
        exit;
    }

    /* ── Enqueue (color picker) ─────────────────────────────── */
    public static function enqueue( $hook ) {
        // Medya kütüphanesi (katalog sayfası için)
        if ( strpos( $hook, 'quotepress' ) !== false ) {
            wp_enqueue_media();
        }
        if ( strpos( $hook, 'quotepress' ) === false ) return;
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){ $(".qp-color-picker").wpColorPicker(); });' );
    }

    /* ── Save ───────────────────────────────────────────────── */
    public static function save() {
        if ( ! isset( $_POST['qp_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['qp_settings_nonce'], 'qp_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $fields = [
            'company_name', 'company_address', 'company_email',
            'company_phone1', 'company_phone2', 'company_whatsapp', 'company_website',
            'recipient_email', 'default_currency', 'currencies', 'default_vat',
            'validity_days', 'mail_footer', 'color_theme', 'custom_color',
            'product_categories', 'extra_option_label', 'extra_option_items',
            'extra_option_trigger', 'panel_slug', 'detailed_format_triggers',
            'quote_number_start', 'quote_number_prefix',
        ];

        $current = get_option( 'quotepress_settings', [] );
        foreach ( $fields as $f ) {
            $current[ $f ] = sanitize_textarea_field( wp_unslash( $_POST[ $f ] ?? '' ) );
        }

        // Logo: WordPress medya kütüphanesi attachment ID
        if ( isset( $_POST['company_logo_id'] ) ) {
            $current['company_logo_id'] = absint( $_POST['company_logo_id'] );
        }

        update_option( 'quotepress_settings', $current );

        // Re-flush rewrite for panel slug changes
        QuotePress_Panel::register_rewrite();
        flush_rewrite_rules();

        add_settings_error( 'qp_msg', 'qp_saved', __( 'Settings saved successfully.', 'quotepress' ), 'success' );
    }

    /* ── Get single setting ─────────────────────────────────── */
    public static function get( $key, $default = '' ) {
        $s = get_option( 'quotepress_settings', [] );
        return $s[ $key ] ?? $default;
    }

    /* ── Theme colors ───────────────────────────────────────── */
    public static function themes() {
        return [
            'green'  => [ 'label' => __( 'Green',  'quotepress' ), 'primary' => '#2e8b2e', 'dark' => '#1a5e1a', 'light' => '#eef7ee', 'border' => '#d0e4d0' ],
            'blue'   => [ 'label' => __( 'Blue',   'quotepress' ), 'primary' => '#1a6eb5', 'dark' => '#0f4a80', 'light' => '#e8f2fb', 'border' => '#c0d8f0' ],
            'red'    => [ 'label' => __( 'Red',    'quotepress' ), 'primary' => '#c0392b', 'dark' => '#8e1a10', 'light' => '#fdecea', 'border' => '#f5b7b1' ],
            'purple' => [ 'label' => __( 'Purple', 'quotepress' ), 'primary' => '#7b2d8b', 'dark' => '#511c5e', 'light' => '#f5eef8', 'border' => '#dbb8e4' ],
            'orange' => [ 'label' => __( 'Orange', 'quotepress' ), 'primary' => '#d35400', 'dark' => '#943a00', 'light' => '#fef0e7', 'border' => '#f5cba7' ],
            'custom' => [ 'label' => __( 'Custom', 'quotepress' ), 'primary' => '', 'dark' => '', 'light' => '', 'border' => '' ],
        ];
    }

    public static function active_theme_colors() {
        $theme  = self::get( 'color_theme', 'green' );
        $themes = self::themes();
        $colors = $themes[ $theme ] ?? $themes['green'];

        if ( $theme === 'custom' ) {
            $hex           = self::get( 'custom_color', '#2e8b2e' );
            $colors['primary'] = $hex;
            $colors['dark']    = self::darken( $hex, 30 );
            $colors['light']   = self::lighten( $hex, 90 );
            $colors['border']  = self::lighten( $hex, 70 );
        }
        return $colors;
    }

    /* ── Color helpers ──────────────────────────────────────── */
    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [ hexdec( substr($hex,0,2) ), hexdec( substr($hex,2,2) ), hexdec( substr($hex,4,2) ) ];
    }
    private static function darken( $hex, $pct ) {
        [$r,$g,$b] = self::hex_to_rgb( $hex );
        $f = ( 100 - $pct ) / 100;
        return sprintf( '#%02x%02x%02x', max(0,round($r*$f)), max(0,round($g*$f)), max(0,round($b*$f)) );
    }
    private static function lighten( $hex, $pct ) {
        [$r,$g,$b] = self::hex_to_rgb( $hex );
        $f = $pct / 100;
        return sprintf( '#%02x%02x%02x', min(255,round($r+(255-$r)*$f)), min(255,round($g+(255-$g)*$f)), min(255,round($b+(255-$b)*$f)) );
    }

    /* ── Render ─────────────────────────────────────────────── */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        settings_errors( 'qp_msg' );
        $s      = get_option( 'quotepress_settings', QuotePress_Database::default_settings() );
        $themes = self::themes();
        $slug   = $s['panel_slug'] ?? 'quote-panel';
        ?>
        <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <span style="background:<?php echo esc_attr( self::active_theme_colors()['primary'] ); ?>;color:#fff;padding:4px 14px;border-radius:6px;font-size:15px;">QuotePress</span>
            <?php esc_html_e( 'Settings', 'quotepress' ); ?>
        </h1>

        <form method="post" action="">
        <?php wp_nonce_field( 'qp_save_settings', 'qp_settings_nonce' ); ?>

        <style>
        .qp-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:4px;}
        .qp-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;}
        .qp-card h2{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:<?php echo esc_attr( self::active_theme_colors()['primary'] ); ?>;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid <?php echo esc_attr( self::active_theme_colors()['light'] ); ?>;}
        .qp-card table{width:100%;}
        .qp-card th{text-align:left;font-size:13px;color:#555;font-weight:600;padding:8px 0 2px;width:180px;vertical-align:top;padding-top:11px;}
        .qp-card td{padding:3px 0 9px;}
        .qp-card input[type=text],.qp-card input[type=email],.qp-card input[type=number],.qp-card select{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;}
        .qp-card textarea{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;resize:vertical;}
        .qp-card input:focus,.qp-card textarea:focus,.qp-card select:focus{outline:none;border-color:<?php echo esc_attr( self::active_theme_colors()['primary'] ); ?>;}
        .qp-hint{font-size:11px;color:#999;margin-top:3px;line-height:1.5;}
        .qp-full{grid-column:1/-1;}
        .qp-theme-grid{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;}
        .qp-theme-btn{border:2px solid #ddd;border-radius:8px;padding:8px 12px;cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;background:#fff;transition:all .15s;}
        .qp-theme-btn:hover,.qp-theme-btn.active{border-color:var(--qp-primary);box-shadow:0 0 0 3px rgba(0,0,0,.06);}
        .qp-swatch{width:16px;height:16px;border-radius:50%;display:inline-block;}
        .qp-info-bar{margin-top:18px;background:<?php echo esc_attr( self::active_theme_colors()['light'] ); ?>;border:1px solid <?php echo esc_attr( self::active_theme_colors()['border'] ); ?>;border-radius:8px;padding:14px 18px;font-size:13px;color:<?php echo esc_attr( self::active_theme_colors()['dark'] ); ?>;}
        .qp-info-bar a{color:<?php echo esc_attr( self::active_theme_colors()['primary'] ); ?>;font-weight:600;}
        @media(max-width:900px){.qp-grid{grid-template-columns:1fr;}}
        </style>

        <div class="qp-grid">

          <!-- Company -->
          <div class="qp-card">
            <h2>🏢 <?php esc_html_e( 'Company Information', 'quotepress' ); ?></h2>
            <table>
              <tr><th><?php esc_html_e( 'Company Name', 'quotepress' ); ?></th><td><input type="text" name="company_name" value="<?php echo esc_attr( $s['company_name'] ?? '' ); ?>"></td></tr>
              <tr><th><?php esc_html_e( 'Address', 'quotepress' ); ?></th><td><textarea name="company_address" rows="2"><?php echo esc_textarea( $s['company_address'] ?? '' ); ?></textarea></td></tr>
              <tr><th><?php esc_html_e( 'Email', 'quotepress' ); ?></th><td><input type="text" name="company_email" value="<?php echo esc_attr( $s['company_email'] ?? '' ); ?>"></td></tr>
              <tr><th><?php esc_html_e( 'Phone 1', 'quotepress' ); ?></th><td><input type="text" name="company_phone1" value="<?php echo esc_attr( $s['company_phone1'] ?? '' ); ?>"></td></tr>
              <tr><th><?php esc_html_e( 'Phone 2', 'quotepress' ); ?></th><td><input type="text" name="company_phone2" value="<?php echo esc_attr( $s['company_phone2'] ?? '' ); ?>"></td></tr>
              <tr><th><?php esc_html_e( 'WhatsApp', 'quotepress' ); ?></th><td><input type="text" name="company_whatsapp" value="<?php echo esc_attr( $s['company_whatsapp'] ?? '' ); ?>"></td></tr>
              <tr><th><?php esc_html_e( 'Website', 'quotepress' ); ?></th><td><input type="text" name="company_website" value="<?php echo esc_attr( $s['company_website'] ?? '' ); ?>"></td></tr>
              <tr>
                <th>Logo</th>
                <td>
                  <?php
                  $logo_id  = intval( $s['company_logo_id'] ?? 0 );
                  $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
                  ?>
                  <div id="qp-logo-preview" style="margin-bottom:8px;">
                    <?php if ( $logo_url ) : ?>
                      <img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;max-width:200px;border:1px solid #ddd;border-radius:4px;padding:4px;">
                    <?php endif; ?>
                  </div>
                  <input type="hidden" name="company_logo_id" id="qp-logo-id" value="<?php echo esc_attr( $logo_id ); ?>">
                  <button type="button" class="button" id="qp-logo-btn">Logo Sec</button>
                  <?php if ( $logo_id ) : ?>
                    <button type="button" class="button" id="qp-logo-remove" style="margin-left:6px;color:#a00;">Kaldir</button>
                  <?php endif; ?>
                  <p class="qp-hint">PNG veya SVG, seffaf arka plan onerilir. Teklif PDF'inin sol ust kosesinde gorunur.</p>
                </td>
              </tr>
            </table>
          </div>
          <script>
          jQuery(function($){
            var frame;
            $('#qp-logo-btn').on('click', function(){
              if (frame) { frame.open(); return; }
              frame = wp.media({ title: 'Logo Sec', button: { text: 'Kullan' }, multiple: false });
              frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                $('#qp-logo-id').val(att.id);
                var prev = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                $('#qp-logo-preview').html('<img src="'+prev+'" style="max-height:60px;max-width:200px;border:1px solid #ddd;border-radius:4px;padding:4px;">');
                if (!$('#qp-logo-remove').length) {
                  $('#qp-logo-btn').after('<button type="button" class="button" id="qp-logo-remove" style="margin-left:6px;color:#a00;">Kaldir</button>');
                  attachRemove();
                }
              });
              frame.open();
            });
            function attachRemove(){
              $('#qp-logo-remove').on('click', function(){
                $('#qp-logo-id').val('');
                $('#qp-logo-preview').html('');
                $(this).remove();
              });
            }
            attachRemove();
          });
          </script>

          <!-- Mail -->
          <div class="qp-card">
            <h2>📧 <?php esc_html_e( 'Email Settings', 'quotepress' ); ?></h2>
            <table>
              <tr>
                <th><?php esc_html_e( 'Recipient Email', 'quotepress' ); ?></th>
                <td>
                  <input type="text" name="recipient_email" value="<?php echo esc_attr( $s['recipient_email'] ?? '' ); ?>">
                  <p class="qp-hint"><?php esc_html_e( 'Quote requests will be sent here. Separate multiple addresses with commas.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Mail Footer', 'quotepress' ); ?></th>
                <td><textarea name="mail_footer" rows="3"><?php echo esc_textarea( $s['mail_footer'] ?? '' ); ?></textarea></td>
              </tr>
            </table>
          </div>

          <!-- Pricing -->
          <div class="qp-card">
            <h2>💰 <?php esc_html_e( 'Pricing', 'quotepress' ); ?></h2>
            <table>
              <tr>
                <th><?php esc_html_e( 'Currencies', 'quotepress' ); ?></th>
                <td>
                  <textarea name="currencies" rows="4"><?php echo esc_textarea( $s['currencies'] ?? "USD\nEUR\nGBP\nTRY" ); ?></textarea>
                  <p class="qp-hint"><?php esc_html_e( 'One currency code per line.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Default Currency', 'quotepress' ); ?></th>
                <td><input type="text" name="default_currency" value="<?php echo esc_attr( $s['default_currency'] ?? 'USD' ); ?>"></td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Default VAT (%)', 'quotepress' ); ?></th>
                <td>
                  <input type="number" name="default_vat" value="<?php echo esc_attr( $s['default_vat'] ?? '0' ); ?>" min="0" max="100">
                  <p class="qp-hint"><?php esc_html_e( 'Set to 0 to disable VAT by default.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Validity (days)', 'quotepress' ); ?></th>
                <td>
                  <input type="number" name="validity_days" value="<?php echo esc_attr( $s['validity_days'] ?? '30' ); ?>" min="1">
                  <p class="qp-hint"><?php esc_html_e( 'Shown on the PDF quote as the expiry date.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th>Teklif No</th>
                <td>
                  <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="quote_number_prefix" value="<?php echo esc_attr( $s['quote_number_prefix'] ?? '' ); ?>" style="width:80px;" placeholder="&Ouml;nek">
                    <input type="number" name="quote_number_start" value="<?php echo esc_attr( $s['quote_number_start'] ?? '1' ); ?>" style="width:100px;" min="1">
                  </div>
                  <p class="qp-hint">&Ouml;nek + numara. &Ouml;rn: TKL- + 100 &rarr; TKL-0100. Mevcut teklifler etkilenmez.</p>
                </td>
              </tr>
            </table>
          </div>

          <!-- Products -->
          <div class="qp-card">
            <h2>📦 <?php esc_html_e( 'Products & Options', 'quotepress' ); ?></h2>
            <table>
              <tr>
                <th><?php esc_html_e( 'Product Categories', 'quotepress' ); ?></th>
                <td>
                  <textarea name="product_categories" rows="6"><?php echo esc_textarea( $s['product_categories'] ?? '' ); ?></textarea>
                  <p class="qp-hint"><?php esc_html_e( 'One product per line. These appear in the form dropdown.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th>Detaylı Format Tetikleyicisi</th>
                <td>
                  <textarea name="detailed_format_triggers" rows="4" placeholder="ısı gider&#10;heat cost"><?php echo esc_textarea( $s['detailed_format_triggers'] ?? '' ); ?></textarea>
                  <p class="qp-hint">Her satıra bir anahtar kelime. Bu kelimelerden biri ürün adında geçiyorsa 4 sayfalık <strong>Isı Gider Paylaşım</strong> formatı otomatik kullanılır.<br>Boş bırakırsanız varsayılan: <code>ısı gider</code>, <code>isi gider</code></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Extra Option Label', 'quotepress' ); ?></th>
                <td>
                  <input type="text" name="extra_option_label" value="<?php echo esc_attr( $s['extra_option_label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Communication Type', 'quotepress' ); ?>">
                  <p class="qp-hint"><?php esc_html_e( 'Optional extra question shown when specific products are selected. Leave blank to disable.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Extra Option Choices', 'quotepress' ); ?></th>
                <td>
                  <textarea name="extra_option_items" rows="4"><?php echo esc_textarea( $s['extra_option_items'] ?? '' ); ?></textarea>
                  <p class="qp-hint"><?php esc_html_e( 'One choice per line.', 'quotepress' ); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Show Extra Option When', 'quotepress' ); ?></th>
                <td>
                  <textarea name="extra_option_trigger" rows="3"><?php echo esc_textarea( $s['extra_option_trigger'] ?? '' ); ?></textarea>
                  <p class="qp-hint"><?php esc_html_e( 'Product names (one per line) that trigger the extra option. Must match categories exactly.', 'quotepress' ); ?></p>
                </td>
              </tr>
            </table>
          </div>

          <!-- Design -->
          <div class="qp-card qp-full">
            <h2>🎨 <?php esc_html_e( 'Design & Appearance', 'quotepress' ); ?></h2>
            <table>
              <tr>
                <th><?php esc_html_e( 'Color Theme', 'quotepress' ); ?></th>
                <td>
                  <div class="qp-theme-grid">
                    <?php foreach ( $themes as $key => $t ) :
                        $color  = $key === 'custom' ? ( $s['custom_color'] ?? '#2e8b2e' ) : $t['primary'];
                        $active = ( $s['color_theme'] ?? 'green' ) === $key ? 'active' : '';
                    ?>
                    <label class="qp-theme-btn <?php echo $active; ?>" style="--qp-primary:<?php echo esc_attr($color); ?>">
                      <input type="radio" name="color_theme" value="<?php echo esc_attr($key); ?>"
                             <?php checked( $s['color_theme'] ?? 'green', $key ); ?> style="display:none">
                      <span class="qp-swatch" style="background:<?php echo esc_attr($color); ?>;"></span>
                      <?php echo esc_html( $t['label'] ); ?>
                    </label>
                    <?php endforeach; ?>
                  </div>
                  <div style="margin-top:12px;" id="qp-custom-color-wrap" <?php echo ( ($s['color_theme']??'green') !== 'custom' ) ? 'style="display:none"' : ''; ?>>
                    <input type="text" name="custom_color" class="qp-color-picker"
                           value="<?php echo esc_attr( $s['custom_color'] ?? '#2e8b2e' ); ?>">
                  </div>
                  <script>
                  document.querySelectorAll('input[name="color_theme"]').forEach(function(r){
                    r.addEventListener('change',function(){
                      var w = document.getElementById('qp-custom-color-wrap');
                      w.style.display = this.value === 'custom' ? 'block' : 'none';
                      document.querySelectorAll('.qp-theme-btn').forEach(function(b){ b.classList.remove('active'); });
                      this.closest('.qp-theme-btn').classList.add('active');
                    });
                  });
                  </script>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e( 'Panel URL Slug', 'quotepress' ); ?></th>
                <td>
                  <input type="text" name="panel_slug" value="<?php echo esc_attr( $slug ); ?>" style="max-width:280px;">
                  <p class="qp-hint"><?php printf( esc_html__( 'Panel will be accessible at: %s', 'quotepress' ), '<strong>' . esc_url( home_url('/'.$slug.'/') ) . '</strong>' ); ?></p>
                </td>
              </tr>
            </table>
          </div>

        </div><!-- /grid -->

        <div style="margin-top:20px;">
          <?php submit_button( __( 'Save Settings', 'quotepress' ), 'primary large' ); ?>
        </div>

        </form>

        <div class="qp-info-bar">
          <strong><?php esc_html_e( 'Form Shortcode:', 'quotepress' ); ?></strong>
          <code>[quotepress_form]</code> &nbsp;—&nbsp;
          <?php esc_html_e( 'Add this shortcode to any page to display the request form.', 'quotepress' ); ?>
          &nbsp;|&nbsp;
          <strong><?php esc_html_e( 'Quote Panel:', 'quotepress' ); ?></strong>
          <a href="<?php echo esc_url( home_url('/'.$slug.'/') ); ?>" target="_blank">
            <?php echo esc_url( home_url('/'.$slug.'/') ); ?> →
          </a>
        </div>
        </div>
        <?php
    }
    /* ── Şablon kaydet ──────────────────────────────────────── */
    public static function save_templates() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz' );
        check_admin_referer( 'qp_save_templates' );

        $tpl = get_option( 'quotepress_templates', [] );

        // Isı gider şablonu alanları
        // Dinamik sorular
        $questions = array_values( array_filter( array_map( 'sanitize_text_field', (array)( $_POST['ig_questions'] ?? [] ) ) ) );
        for ( $qi = 1; $qi <= 10; $qi++ ) {
            $tpl[ 'ig_q' . $qi ] = $questions[ $qi - 1 ] ?? '';
        }

        // Rozet iconları
        for ( $bi = 1; $bi <= 4; $bi++ ) {
            $tpl[ 'ig_badge' . $bi . '_icon' ] = sanitize_text_field( $_POST[ 'ig_badge' . $bi . '_icon' ] ?? '&#10003;' );
        }

        $fields_ig = [
            'ig_salutation_text', 'ig_intro_text', 'ig_answer_text',
            'ig_comparison_title', 'ig_commitments_title', 'ig_commitments_sub',
            'ig_card1_num','ig_card1_unit','ig_card1_title','ig_card1_sub','ig_card1_body',
            'ig_card2_num','ig_card2_unit','ig_card2_title','ig_card2_sub','ig_card2_body',
            'ig_card3_num','ig_card3_unit','ig_card3_title','ig_card3_sub','ig_card3_body',
            'ig_card4_num','ig_card4_unit','ig_card4_title','ig_card4_sub','ig_card4_body',
            'ig_card5_num','ig_card5_unit','ig_card5_title','ig_card5_sub','ig_card5_body',
            'ig_card6_num','ig_card6_unit','ig_card6_title','ig_card6_sub','ig_card6_body',
            'ig_stat1_num','ig_stat1_label','ig_stat2_num','ig_stat2_label','ig_stat3_num','ig_stat3_label',
            'ig_annual_title','ig_annual_body',
            'ig_cta_title','ig_cta_body',
            'ig_badge1','ig_badge2','ig_badge3','ig_badge4',
            'ig_price_note','ig_guarantee_note',
        ];
        foreach ( $fields_ig as $f ) {
            $tpl[$f] = sanitize_textarea_field( wp_unslash( $_POST[$f] ?? '' ) );
        }

        // Genel sorular
        foreach ( ['ig_q1','ig_q2','ig_q3'] as $qf ) {
            $tpl[$qf] = sanitize_text_field( wp_unslash( $_POST[$qf] ?? '' ) );
        }

        update_option( 'quotepress_templates', $tpl );
        wp_redirect( admin_url( 'admin.php?page=quotepress-templates&saved=1' ) );
        exit;
    }

    public static function get_tpl( $key, $default = '' ) {
        $tpl = get_option( 'quotepress_templates', [] );
        return $tpl[$key] ?? $default;
    }

    /* ── Şablon sayfası ─────────────────────────────────────── */
    public static function render_templates_page() {
        $saved = isset($_GET['saved']);
        $t = fn($k, $d='') => esc_textarea( self::get_tpl($k, $d) );
        $v = fn($k, $d='') => esc_attr( self::get_tpl($k, $d) );
        ?>
        <style>
        .qpt-wrap{max-width:900px;}
        .qpt-tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:24px;}
        .qpt-tab{padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;border:none;background:none;color:#888;border-bottom:2px solid transparent;margin-bottom:-2px;}
        .qpt-tab.active{color:#1a6eb5;border-bottom-color:#1a6eb5;}
        .qpt-panel{display:none;} .qpt-panel.active{display:block;}
        .qpt-section{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px 20px;margin-bottom:16px;}
        .qpt-section h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1a6eb5;margin:0 0 14px;padding-bottom:8px;border-bottom:1px solid #e8f0fb;}
        .qpt-row{display:grid;gap:10px;margin-bottom:10px;}
        .qpt-row.cols-2{grid-template-columns:1fr 1fr;}
        .qpt-row.cols-3{grid-template-columns:80px 1fr 1fr;}
        .qpt-row.cols-5{grid-template-columns:70px 100px 1fr 1fr 2fr;}
        .qpt-row label{font-size:11px;font-weight:600;color:#666;display:block;margin-bottom:3px;}
        .qpt-row input,.qpt-row textarea{width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;font-family:inherit;}
        .qpt-row textarea{resize:vertical;min-height:60px;}
        .qpt-card-group{border:1px solid #e8f0fb;border-radius:8px;padding:12px;margin-bottom:10px;background:#f8fbff;}
        .qpt-card-num{font-size:22px;font-weight:900;color:#1a6eb5;text-align:center;padding:4px 0;}
        .qpt-hint{font-size:11px;color:#aaa;margin:2px 0 0;font-style:italic;}
        .qpt-save-bar{position:sticky;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding:14px 0;margin-top:24px;display:flex;gap:10px;align-items:center;}
        .qpt-save-btn{background:#1a6eb5;color:#fff;border:none;border-radius:7px;padding:10px 24px;font-size:14px;font-weight:700;cursor:pointer;}
        .qpt-save-btn:hover{background:#155e99;}
        .qpt-saved{color:#16a34a;font-weight:600;font-size:13px;}
        </style>

        <div class="qpt-wrap wrap">
          <h1 style="margin-bottom:4px;">📝 Teklif Şablonları</h1>
          <p style="color:#666;font-size:13px;margin-bottom:20px;">PDF tekliflerinde görünen tüm metinleri buradan özelleştirin. Boş bırakılan alanlar varsayılan metni kullanır.</p>

          <?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>✓ Şablon kaydedildi.</p></div><?php endif; ?>

          <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="qp_save_templates">
            <?php wp_nonce_field('qp_save_templates'); ?>

            <!-- SEKME BAŞLIKLARI -->
            <div class="qpt-tabs">
              <button type="button" class="qpt-tab active" onclick="qptTab('ig',this)">🔥 Isı Gider Paylaşım</button>
              <button type="button" class="qpt-tab" onclick="qptTab('std',this)">📄 Standart Kompakt</button>
            </div>

            <!-- ISI GİDER ŞABLONU -->
            <div class="qpt-panel active" id="qpt-ig">

              <div class="qpt-section">
                <h3>Giriş Mektubu</h3>
                <div class="qpt-row">
                  <label>Giriş paragrafı</label>
                  <textarea name="ig_intro_text" rows="3" placeholder="[MÜŞTERİ]&#039;nin ısı gider paylaşım süreçleri için [ŞİRKET]&#039;ye gösterdiğiniz ilgiye teşekkür ederiz..."><?php echo $t('ig_intro_text'); ?></textarea>
                  <p class="qpt-hint">[MÜŞTERİ] = firma adı, [ŞİRKET] = şirket adınız/web adresiniz</p>
                </div>
                <div class="qpt-row">
                  <label>Cevap kutusu metni</label>
                  <textarea name="ig_answer_text" rows="2" placeholder="Bu soruların tamamına net bir yanıt veriyoruz..."><?php echo $t('ig_answer_text'); ?></textarea>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Müşteri Soruları <span style="font-size:10px;font-weight:400;color:#aaa;">(artırılabilir/azaltılabilir)</span></h3>
                <div id="qpt-questions-list">
                  <?php
                  $def_qs = [
                      'Bağımsız bölümlere düşen ısı gideri payı nasıl hesaplanacak, sakinler itiraz ederse ne olacak?',
                      'Fatura geldiğinde hesapları kim yapacak, raporları kim dağıtacak?',
                      'Arıza yapan sayaçlar nasıl takip edilecek, yasal yükümlülükler yerine getirilecek mi?',
                  ];
                  $tpl_data = get_option('quotepress_templates', []);
                  $saved_qs = [];
                  for ($qi = 1; $qi <= 10; $qi++) {
                      $v_q = $tpl_data['ig_q'.$qi] ?? '';
                      if ($qi <= 3) { $saved_qs[] = $v_q ?: ($def_qs[$qi-1] ?? ''); }
                      elseif ($v_q) { $saved_qs[] = $v_q; }
                  }
                  if (empty($saved_qs)) $saved_qs = $def_qs;
                  foreach ($saved_qs as $qi => $qv):
                  ?>
                  <div class="qpt-q-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;">
                    <input type="text" name="ig_questions[]" value="<?php echo esc_attr($qv); ?>" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Soru metni...">
                    <button type="button" class="qpb danger" onclick="qptRemoveQ(this)" style="padding:5px 8px;flex-shrink:0;">✕</button>
                  </div>
                  <?php endforeach; ?>
                </div>
                <button type="button" onclick="qptAddQ()" style="margin-top:4px;background:#fff;border:1px dashed #93c5fd;border-radius:6px;padding:5px 14px;font-size:12px;color:#1a6eb5;cursor:pointer;font-weight:600;">+ Soru Ekle</button>
                <p class="qpt-hint">Boş bırakılan sorular gösterilmez. Sırayı değiştirmek için sürükleyebilirsiniz.</p>
              </div>

              <div class="qpt-section">
                <h3>Rozet Şeridi</h3>
                <p class="qpt-hint" style="margin-bottom:12px;">Her rozet için ikon ve metin seçin. ✓ yerine emoji kullanabilirsiniz.</p>
                <?php
                $badge_defaults = [
                    ['icon'=>'✓','text'=>'20+ Yıl Tecrübe'],
                    ['icon'=>'✓','text'=>'Bakanlık Onaylı Yetkili Ölçüm Şirketi'],
                    ['icon'=>'✓','text'=>'ISO 9001 Sertifikalı'],
                    ['icon'=>'✓','text'=>'Sıfır Sorun Garantisi'],
                ];
                $tpl_data2 = get_option('quotepress_templates', []);
                for ($bi=1;$bi<=4;$bi++):
                    $b_icon = $tpl_data2['ig_badge'.$bi.'_icon'] ?? ($badge_defaults[$bi-1]['icon']??'✓');
                    $b_text = $tpl_data2['ig_badge'.$bi] ?? ($badge_defaults[$bi-1]['text']??'');
                ?>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                  <select name="ig_badge<?php echo $bi; ?>_icon" style="border:1px solid #d1d5db;border-radius:6px;padding:7px 8px;font-size:14px;width:70px;text-align:center;">
                    <?php
                    $icons = ['✓','✔','★','⭐','🏆','🎯','🔒','💎','🛡️','📋','🔧','⚡','🌟','💡','🏅'];
                    foreach ($icons as $ico) echo '<option value="'.esc_attr($ico).'" '.($b_icon===$ico?'selected':'').'>'.esc_html($ico).'</option>';
                    ?>
                  </select>
                  <input type="text" name="ig_badge<?php echo $bi; ?>" value="<?php echo esc_attr($b_text); ?>" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Rozet <?php echo $bi; ?> metni...">
                </div>
                <?php endfor; ?>
              </div>

              <div class="qpt-section">
                <h3>Taahhüt Kartları</h3>
                <?php
                $default_cards = [
                    ['3','İŞ GÜNÜ','3 İş Günü Rapor Teslimi','Fatura gelir, rapor hazır olur.','Doğalgaz faturasını bize iletmenizden itibaren en geç 3 iş günü içinde tüm bağımsız bölümlere ait ısı gider paylaşım raporları eksiksiz biçimde hazırlanarak size teslim edilir. Bekleme yok, gecikme yok.'],
                    ['PDF','EXCEL · ÇIKTI','Çok Formatlı Dijital Raporlama','Kağıt değil, akıllı raporlama.','Paylaşım sonuçları PDF, Excel ve yazdırmaya hazır çıktı formatında; hem tüm site için toplu hem de her bağımsız bölüm için ayrı ayrı hazırlanır. Dağıtım tamamen elektronik ortamda gerçekleşir.'],
                    ['48','SAAT','48 Saat Teknik Servis','Arıza bildirimi = 48 saat içinde çözüm.','Sayaç arızası veya yerinde müdahale gerektiren her durumda teknik ekibimiz, bildirimin alınmasından itibaren en geç 48 saat içinde sahada.'],
                    ['7/24','ERİŞİM','Web Paneli ile Tam Şeffaflık','Her daire kendi tüketimini görür.','Web paneli üzerinden site yönetimi ve her bağımsız bölüm sakini anlık tüketim verilerini 7/24 görüntüleyebilir.'],
                    ['100%','UYUM','Yasal Mevzuata Tam Uyum','Ceza riski sıfır, uyumluluk tam.','Tüm hesaplamalar, T.C. Çevre, Şehircilik ve İklim Değişikliği Bakanlığı nın Isı Gider Paylaşım Yönetmeliği çerçevesinde eksiksiz yürütülür.'],
                    ['ISO','9001','Güvenli Arşiv ve Anında Erişim','Hiçbir veri kaybolmaz.','Tüm okuma verileri ve paylaşım raporları yasal saklama sürelerine uygun olarak güvenli dijital ortamda arşivlenir.'],
                ];
                foreach ($default_cards as $ci => $dc):
                    $n = $ci+1;
                ?>
                <div class="qpt-card-group">
                  <div style="font-size:11px;font-weight:700;color:#1a6eb5;margin-bottom:8px;">KART <?php echo $n; ?></div>
                  <div class="qpt-row cols-5">
                    <div><label>Büyük Sayı/Kısalt.</label><input name="ig_card<?php echo $n; ?>_num" value="<?php echo $v("ig_card{$n}_num"); ?>" placeholder="<?php echo esc_attr($dc[0]); ?>"></div>
                    <div><label>Birim</label><input name="ig_card<?php echo $n; ?>_unit" value="<?php echo $v("ig_card{$n}_unit"); ?>" placeholder="<?php echo esc_attr($dc[1]); ?>"></div>
                    <div><label>Başlık</label><input name="ig_card<?php echo $n; ?>_title" value="<?php echo $v("ig_card{$n}_title"); ?>" placeholder="<?php echo esc_attr($dc[2]); ?>"></div>
                    <div><label>Alt başlık (italik)</label><input name="ig_card<?php echo $n; ?>_sub" value="<?php echo $v("ig_card{$n}_sub"); ?>" placeholder="<?php echo esc_attr($dc[3]); ?>"></div>
                    <div><label>İçerik metni</label><textarea name="ig_card<?php echo $n; ?>_body" rows="2" placeholder="<?php echo esc_attr(mb_strimwidth($dc[4],0,60,'…')); ?>"><?php echo $t("ig_card{$n}_body"); ?></textarea></div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>

              <div class="qpt-section">
                <h3>İstatistik Şeridi</h3>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 1 Rakam</label><input name="ig_stat1_num" value="<?php echo $v('ig_stat1_num'); ?>" placeholder="3 iş günü"></div>
                  <div><label>İstat. 1 Açıklama</label><input name="ig_stat1_label" value="<?php echo $v('ig_stat1_label'); ?>" placeholder="rapor teslim süresi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 2 Rakam</label><input name="ig_stat2_num" value="<?php echo $v('ig_stat2_num'); ?>" placeholder="48 saat"></div>
                  <div><label>İstat. 2 Açıklama</label><input name="ig_stat2_label" value="<?php echo $v('ig_stat2_label'); ?>" placeholder="teknik servis garantisi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>İstat. 3 Rakam</label><input name="ig_stat3_num" value="<?php echo $v('ig_stat3_num'); ?>" placeholder="20+ yıl"></div>
                  <div><label>İstat. 3 Açıklama</label><input name="ig_stat3_label" value="<?php echo $v('ig_stat3_label'); ?>" placeholder="sektör deneyimi"></div>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Yıllık Toplantı Kutusu</h3>
                <div class="qpt-row">
                  <label>Başlık</label>
                  <input name="ig_annual_title" value="<?php echo $v('ig_annual_title'); ?>" placeholder="Yılda En Az 1 Kez — Yüz Yüze Hizmet Değerlendirmesi">
                </div>
                <div class="qpt-row">
                  <label>Metin</label>
                  <textarea name="ig_annual_body" rows="2" placeholder="Yılda en az bir kez site yönetimiyle yüz yüze veya çevrimiçi toplantı düzenliyoruz..."><?php echo $t('ig_annual_body'); ?></textarea>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Güvence İkonları (4 kutu)</h3>
                <div class="qpt-row cols-2">
                  <div><label>Güvence 1 Başlık</label><input name="ig_gv1_title" value="<?php echo esc_attr(self::get_tpl('ig_gv1_title')); ?>" placeholder="Bakanlık Onaylı"></div>
                  <div><label>Güvence 1 Alt</label><input name="ig_gv1_sub" value="<?php echo esc_attr(self::get_tpl('ig_gv1_sub')); ?>" placeholder="Yetkili Ölçüm Şirketi"></div>
                </div>
                <div class="qpt-row cols-2">
                  <div><label>Güvence 2 Başlık</label><input name="ig_gv2_title" value="<?php echo esc_attr(self::get_tpl('ig_gv2_title')); ?>" placeholder="ISO 9001"></div>
                  <div><label>Güvence 2 Alt</label><input name="ig_gv2_sub" value="<?php echo esc_attr(self::get_tpl('ig_gv2_sub')); ?>" placeholder="Sertifikalı Hizmet Süreçleri"></div>
                </div>
              </div>

              <div class="qpt-section">
                <h3>Harekete Geçirici (CTA) & Alt Dipnot</h3>
                <div class="qpt-row">
                  <label>CTA Başlık</label>
                  <input name="ig_cta_title" value="<?php echo $v('ig_cta_title'); ?>" placeholder="Sonraki Adım — Birlikte Başlayalım">
                </div>
                <div class="qpt-row">
                  <label>CTA Metin</label>
                  <textarea name="ig_cta_body" rows="2" placeholder="Bu sezonu sorunsuz kapatmak istiyorsanız, teklifi [GEÇERLİLİK] gün içinde onaylayarak hemen başlayabilirsiniz."><?php echo $t('ig_cta_body'); ?></textarea>
                  <p class="qpt-hint">[GEÇERLİLİK] = geçerlilik gün sayısı</p>
                </div>
                <div class="qpt-row">
                  <label>Alt dipnot</label>
                  <input name="ig_guarantee_note" value="<?php echo $v('ig_guarantee_note'); ?>" placeholder="Sözleşmeye dönüşen teklifler iptal edilemez.">
                </div>
              </div>

            </div><!-- /#qpt-ig -->

            <!-- STANDART ŞABLON -->
            <div class="qpt-panel" id="qpt-std">
              <div class="qpt-section">
                <h3>Standart Format</h3>
                <p style="color:#888;font-size:13px;">Standart kompakt format şu an şirket bilgilerini Settings'ten otomatik alır. İleri versiyonda özelleştirme buraya eklenecektir.</p>
              </div>
            </div>

            <div class="qpt-save-bar">
              <button type="submit" class="qpt-save-btn">💾 Şablonu Kaydet</button>
              <?php if ($saved): ?><span class="qpt-saved">✓ Kaydedildi</span><?php endif; ?>
            </div>
          </form>
        </div>

        <script>
        function qptAddQ() {
            var list = document.getElementById('qpt-questions-list');
            var row = document.createElement('div');
            row.className = 'qpt-q-row';
            row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:6px;';
            row.innerHTML = '<input type="text" name="ig_questions[]" style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:12px;" placeholder="Soru metni...">'
                          + '<button type="button" class="qpb danger" onclick="qptRemoveQ(this)" style="padding:5px 8px;flex-shrink:0;">&#10005;</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
        }
        function qptRemoveQ(btn) {
            var list = document.getElementById('qpt-questions-list');
            if (list.querySelectorAll('.qpt-q-row').length > 1) {
                btn.closest('.qpt-q-row').remove();
            }
        }
        function qptTab(id, btn) {
            document.querySelectorAll('.qpt-panel').forEach(function(p){ p.classList.remove('active'); });
            document.querySelectorAll('.qpt-tab').forEach(function(b){ b.classList.remove('active'); });
            document.getElementById('qpt-'+id).classList.add('active');
            btn.classList.add('active');
        }
        </script>
        <?php
    }


}
