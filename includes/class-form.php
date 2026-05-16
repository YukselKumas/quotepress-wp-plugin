<?php
defined( 'ABSPATH' ) || exit;

class QuotePress_Form {

    public static function init() {
        add_shortcode( 'quotepress_form',          [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts',           [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_ajax_qp_submit_form',       [ __CLASS__, 'handle_submit' ] );
        add_action( 'wp_ajax_nopriv_qp_submit_form',[ __CLASS__, 'handle_submit' ] );
    }

    /* ── Enqueue ────────────────────────────────────────────── */
    public static function enqueue() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'quotepress_form' ) ) return;

        $colors = QuotePress_Settings::active_theme_colors();

        wp_enqueue_style(
            'quotepress-form',
            QP_URL . 'assets/form.css',
            [],
            QP_VERSION
        );

        // Inject CSS variables for active theme
        $css_vars = ":root {
            --qp-primary:  {$colors['primary']};
            --qp-dark:     {$colors['dark']};
            --qp-light:    {$colors['light']};
            --qp-border:   {$colors['border']};
        }";
        wp_add_inline_style( 'quotepress-form', $css_vars );

        wp_enqueue_script(
            'quotepress-form',
            QP_URL . 'assets/form.js',
            [ 'jquery' ],
            QP_VERSION,
            true
        );

        $cats     = array_values( array_filter( array_map( 'trim', explode( "\n", QuotePress_Settings::get('product_categories') ) ) ) );
        $triggers = array_values( array_filter( array_map( 'trim', explode( "\n", QuotePress_Settings::get('extra_option_trigger') ) ) ) );
        $choices  = array_values( array_filter( array_map( 'trim', explode( "\n", QuotePress_Settings::get('extra_option_items') ) ) ) );

        wp_localize_script( 'quotepress-form', 'qpData', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'qp_form_nonce' ),
            'categories'    => $cats,
            'extraLabel'    => QuotePress_Settings::get( 'extra_option_label' ),
            'extraChoices'  => $choices,
            'extraTriggers' => $triggers,
            'catalog'       => QuotePress_Catalog::flat_list(),
            'i18n'          => [
                'selectProduct'   => __( '-- Select --', 'quotepress' ),
                'addProduct'      => __( '+ Add Product', 'quotepress' ),
                'sending'         => __( 'Sending...', 'quotepress' ),
                'submitBtn'       => __( 'Send Quote Request ›', 'quotepress' ),
                'successMsg'      => __( 'Your quote request has been submitted successfully! A confirmation email has been sent to you.', 'quotepress' ),
                'errorMsg'        => __( 'Something went wrong. Please try again or contact us directly.', 'quotepress' ),
                'connectionError' => __( 'Connection error. Please check your internet connection.', 'quotepress' ),
                'selectComm'      => __( 'Please select an option for: ', 'quotepress' ),
                'pieces'          => __( 'pcs', 'quotepress' ),
                'optional'        => __( 'Optional', 'quotepress' ),
                'modelPlaceholder'=> __( 'Model, spec...', 'quotepress' ),
            ],
        ] );
    }

    /* ── Shortcode render ───────────────────────────────────── */
    public static function render( $atts = [] ) {
        ob_start();
        ?>
        <div class="qp-form-wrap" id="qp-form-wrap">
          <div class="qp-form-header">
            <h2><?php esc_html_e( 'Request a Quote', 'quotepress' ); ?></h2>
            <p><?php esc_html_e( 'Fill in the form below to receive a price quote. We will get back to you as soon as possible.', 'quotepress' ); ?></p>
          </div>
          <div class="qp-form-body">
            <form id="qpForm" novalidate>
              <?php wp_nonce_field( 'qp_form_nonce', 'qp_nonce' ); ?>
              <input class="qp-hp" type="text" name="website_url" tabindex="-1" autocomplete="off">

              <!-- Project -->
              <div class="qp-section"><?php esc_html_e( 'Project Details', 'quotepress' ); ?></div>
              <div class="qp-row">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Project Name', 'quotepress' ); ?> <span class="req">*</span></label>
                  <input type="text" name="project_name" placeholder="<?php esc_attr_e( 'e.g. Office HVAC Upgrade 2025', 'quotepress' ); ?>" required>
                </div>
                <div class="qp-field">
                  <label><?php esc_html_e( 'Expected Delivery Date', 'quotepress' ); ?></label>
                  <input type="date" name="project_date">
                </div>
              </div>
              <div class="qp-row qp-full">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Project Description', 'quotepress' ); ?></label>
                  <textarea name="project_desc" placeholder="<?php esc_attr_e( 'Installation location, application type, special requirements...', 'quotepress' ); ?>"></textarea>
                </div>
              </div>

              <!-- Products -->
              <div class="qp-section"><?php esc_html_e( 'Products / Services', 'quotepress' ); ?></div>

              <!-- Extra option (JS shows/hides) -->
              <div class="qp-extra-row" id="qp-extra-wrap" style="display:none;">
                <div class="qp-field">
                  <label id="qp-extra-label"></label>
                  <div class="qp-radio-group" id="qp-extra-choices"></div>
                </div>
              </div>

              <table class="qp-items-table">
                <thead>
                  <tr>
                    <th><?php esc_html_e( 'Product / Category', 'quotepress' ); ?></th>
                    <th><?php esc_html_e( 'Model / Spec', 'quotepress' ); ?></th>
                    <th><?php esc_html_e( 'Qty', 'quotepress' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'quotepress' ); ?></th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="qp-items-body"></tbody>
              </table>
              <button type="button" class="qp-add-btn" id="qp-add-item"><?php esc_html_e( '+ Add Product', 'quotepress' ); ?></button>

              <!-- Company -->
              <div class="qp-section"><?php esc_html_e( 'Company / Contact Information', 'quotepress' ); ?></div>
              <div class="qp-row">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Company Name', 'quotepress' ); ?> <span class="req">*</span></label>
                  <input type="text" name="company_name" placeholder="<?php esc_attr_e( 'e.g. ABC Industries Ltd.', 'quotepress' ); ?>" required>
                </div>
                <div class="qp-field">
                  <label><?php esc_html_e( 'Tax / VAT Number', 'quotepress' ); ?></label>
                  <input type="text" name="tax_number" placeholder="<?php esc_attr_e( 'Optional', 'quotepress' ); ?>">
                </div>
              </div>
              <div class="qp-row">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Contact Name', 'quotepress' ); ?> <span class="req">*</span></label>
                  <input type="text" name="contact_name" placeholder="<?php esc_attr_e( 'Full Name', 'quotepress' ); ?>" required>
                </div>
                <div class="qp-field">
                  <label><?php esc_html_e( 'Title / Position', 'quotepress' ); ?></label>
                  <input type="text" name="contact_title" placeholder="<?php esc_attr_e( 'e.g. Procurement Manager', 'quotepress' ); ?>">
                </div>
              </div>
              <div class="qp-row">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Email Address', 'quotepress' ); ?> <span class="req">*</span></label>
                  <input type="email" name="email" placeholder="name@company.com" required>
                </div>
                <div class="qp-field">
                  <label><?php esc_html_e( 'Phone', 'quotepress' ); ?></label>
                  <input type="tel" name="phone" placeholder="+1 555 000 0000">
                </div>
              </div>
              <div class="qp-row">
                <div class="qp-field">
                  <label><?php esc_html_e( 'City', 'quotepress' ); ?></label>
                  <input type="text" name="city" placeholder="New York">
                </div>
                <div class="qp-field">
                  <label><?php esc_html_e( 'Country', 'quotepress' ); ?></label>
                  <input type="text" name="country" placeholder="United States">
                </div>
              </div>
              <div class="qp-row qp-full">
                <div class="qp-field">
                  <label><?php esc_html_e( 'Additional Notes', 'quotepress' ); ?></label>
                  <textarea name="extra_note" placeholder="<?php esc_attr_e( 'Delivery address, technical requirements, billing info, etc.', 'quotepress' ); ?>"></textarea>
                </div>
              </div>

              <div class="qp-form-footer">
                <p><?php esc_html_e( '* Required fields', 'quotepress' ); ?></p>
                <button type="submit" class="qp-submit-btn" id="qp-submit-btn">
                  <?php esc_html_e( 'Send Quote Request ›', 'quotepress' ); ?>
                </button>
              </div>
              <div id="qp-form-msg"></div>
            </form>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── AJAX: Handle form submission ───────────────────────── */
    public static function handle_submit() {
        // Honeypot
        if ( ! empty( $_POST['website_url'] ) ) { wp_die( '', 400 ); }

        // Nonce
        if ( ! check_ajax_referer( 'qp_form_nonce', 'qp_nonce', false ) ) {
            wp_send_json_error( 'invalid_nonce' );
        }

        // Required fields
        $required = [ 'project_name', 'company_name', 'contact_name', 'email' ];
        foreach ( $required as $f ) {
            if ( empty( $_POST[ $f ] ) ) {
                wp_send_json_error( 'missing_field' );
            }
        }

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'invalid_email' );
        }

        // Items
        $item_names   = array_map( 'sanitize_text_field', (array) ( $_POST['item_name']  ?? [] ) );
        $item_models  = array_map( 'sanitize_text_field', (array) ( $_POST['item_model'] ?? [] ) );
        $item_qtys    = array_map( 'sanitize_text_field', (array) ( $_POST['item_qty']   ?? [] ) );
        $item_notes   = array_map( 'sanitize_text_field', (array) ( $_POST['item_note']  ?? [] ) );

        $items = [];
        for ( $i = 0; $i < count( $item_names ); $i++ ) {
            if ( ! empty( $item_names[ $i ] ) ) {
                $items[] = [
                    'name'  => $item_names[ $i ],
                    'model' => $item_models[ $i ] ?? '',
                    'qty'   => $item_qtys[ $i ]   ?? '1',
                    'note'  => $item_notes[ $i ]  ?? '',
                    'price' => '',
                ];
            }
        }

        $request_id = QuotePress_Database::insert( [
            'project_name'  => sanitize_text_field(  $_POST['project_name']  ?? '' ),
            'project_date'  => sanitize_text_field(  $_POST['project_date']  ?? '' ),
            'project_desc'  => sanitize_textarea_field( $_POST['project_desc'] ?? '' ),
            'company_name'  => sanitize_text_field(  $_POST['company_name']  ?? '' ),
            'tax_number'    => sanitize_text_field(  $_POST['tax_number']    ?? '' ),
            'contact_name'  => sanitize_text_field(  $_POST['contact_name']  ?? '' ),
            'contact_title'      => sanitize_text_field( $_POST['contact_title']      ?? '' ),
            'contact_salutation' => sanitize_text_field( $_POST['contact_salutation'] ?? 'neutral' ),
            'email'         => $email,
            'phone'         => sanitize_text_field(  $_POST['phone']         ?? '' ),
            'city'          => sanitize_text_field(  $_POST['city']          ?? '' ),
            'country'       => sanitize_text_field(  $_POST['country']       ?? '' ),
            'extra_option'  => sanitize_text_field(  $_POST['extra_option']  ?? '' ),
            'items'         => wp_json_encode( $items, JSON_UNESCAPED_UNICODE ),
            'extra_note'    => sanitize_textarea_field( $_POST['extra_note'] ?? '' ),
            'status'        => 'pending',
            'ip_address'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'created_at'    => current_time( 'mysql' ),
        ] );

        $request = QuotePress_Database::get( $request_id );

        // Send emails
        QuotePress_Mailer::send_notification( $request, $items );
        QuotePress_Mailer::send_confirmation( $request, $items );

        wp_send_json_success( [ 'id' => $request_id ] );
    }
}
