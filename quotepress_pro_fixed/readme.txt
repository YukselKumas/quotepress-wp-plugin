=== QuotePress – Quote Request Form & Manager ===
Contributors: quotepress
Tags: quote, quotation, request form, price quote, pdf quote, quote manager
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete quote request system with a customizable form, admin panel, PDF generation, and HTML email notifications.

== Description ==

**QuotePress** lets you add a professional quote request form to any WordPress page using a simple shortcode, and manage incoming requests from a dedicated panel — all without leaving your site.

**Key Features:**

* 📋 **Shortcode Form** — Add `[quotepress_form]` to any page or post
* 📦 **Dynamic Product List** — Manage product/service categories from Settings
* 📡 **Conditional Extra Option** — Show an extra field (e.g. communication type) only when specific products are selected
* 💰 **Quote Panel** — Review requests, enter unit prices, select currency & VAT, and send quotes
* 📄 **PDF Quotation** — Automatically generates a branded PDF attached to the quote email
* 📧 **HTML Emails** — Beautiful email notifications to both you and the customer
* 🎨 **5 Color Themes** — Green, Blue, Red, Purple, Orange — or set a custom color
* 🌍 **Fully Translatable** — Uses WordPress i18n system (`quotepress` text domain)
* 🗑️ **Clean Uninstall** — Removes all data when deleted

**How It Works:**

1. Customer fills in the request form on your website
2. You receive an email notification with a link to the panel
3. You open the panel, enter prices, and click "Send Quote"
4. Customer receives a professional PDF quotation by email

**Shortcode:**
`[quotepress_form]`

== Installation ==

1. Upload the `quotepress` folder to `/wp-content/plugins/`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **QuotePress → Settings** and fill in your company information
4. Add `[quotepress_form]` to any page
5. Visit **Settings → Permalinks** and click **Save** to flush rewrite rules
6. Access your quote panel at `yoursite.com/quote-panel/` (slug configurable in Settings)

**For PDF support:**
Install mPDF via Composer: `composer require mpdf/mpdf`
Without mPDF, quote files are sent as HTML attachments.

== Frequently Asked Questions ==

= Where do I manage incoming requests? =
Go to `yoursite.com/quote-panel/` — you must be logged in as an administrator.

= Can I change the panel URL? =
Yes. Go to **QuotePress → Settings** and change the **Panel URL Slug**.

= Can the form display an extra question for certain products? =
Yes. In Settings, fill in **Extra Option Label**, **Extra Option Choices**, and **Show Extra Option When** with the product names that should trigger it.

= Does it generate real PDF files? =
If you install mPDF (`composer require mpdf/mpdf`) on your server, yes. Otherwise it attaches an HTML file which can be printed as PDF.

= Is the plugin translatable? =
Yes, fully. The text domain is `quotepress`. You can translate it via Loco Translate or GlotPress on WordPress.org.

= Will it work with any theme? =
Yes. The form uses its own scoped CSS and does not depend on the active theme.

== Screenshots ==

1. The quote request form with the default Green theme
2. The quote panel — request list and detail view
3. Settings page — company info, mail, pricing, products, design
4. HTML email received by the customer with the PDF quote attached
5. The generated PDF quotation document

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
First public release.
