=== DZ COD Order Form ===
Contributors: nedjai
Tags: cash on delivery, algeria, order form, cod, wilaya
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-page cash-on-delivery order form for Algerian WooCommerce stores: 69 wilayas, all communes, per-wilaya delivery prices and fake-order protection.

== Description ==

Most Algerian online stores sell with cash on delivery (COD). Customers do not want a cart, an account or a long checkout: they want to type their name and phone, pick their wilaya and commune, and confirm. This plugin puts that form directly on the product page and creates a normal WooCommerce order.

**Features**

* All **69 wilayas** (including the 11 created in 2025) and **1541 communes**, in Arabic or Latin script.
* Commune list filtered by the chosen wilaya.
* **Delivery price per wilaya**, separately for home delivery and stop desk (office pickup). Disable wilayas you do not deliver to. Optional free delivery above an amount.
* Live order summary: product, delivery, total.
* Simple and variable products, quantity selector.
* **Anti-fraud**: Algerian phone validation (+213 accepted), duplicate detection by phone (block, or accept and put On hold), blocked phone list, per-IP rate limit, bot honeypot.
* **Google Sheets export** through a small Google Apps Script (no Google account connection needed). Status changes update the sheet.
* CSV export of form orders (opens correctly in Excel with Arabic).
* Fires Meta Pixel `Purchase`, TikTok `CompletePayment` and GA4 `purchase` events if those pixels are already on your site, plus a `dzcod:order` JavaScript event for custom tracking.
* Shortcode `[dzcod_form]`, automatic placement on product pages, and an **Elementor widget**.
* Works with full-page caching (nonce is refreshed automatically).
* RTL ready. Compatible with WooCommerce HPOS.

**Developers**

Filters: `dzcod_locations`, `dzcod_is_valid_phone`, `dzcod_antifraud_result`, `dzcod_sheets_row`, `dzcod_auto_insert_for_product`, `dzcod_client_ip`.
Actions: `dzcod_before_order_save`, `dzcod_order_created`.

Source code and issues: https://github.com/xthegodfatherx/dz-cod-order-form

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin and activate it.
3. Go to WooCommerce → DZ COD Form and set your delivery prices.
4. Open any product: the form is there. Or place `[dzcod_form product_id="123"]` / the Elementor widget wherever you want.

== Frequently Asked Questions ==

= Where do the wilaya and commune names come from? =

From the geoalgeria dataset (MIT License), which follows the 69-wilaya administrative division. A copy of its license is in `data/LICENSE-geoalgeria.txt`.

= How do I connect Google Sheets? =

Follow the steps on the "Google Sheets & export" tab. The script to paste is in `google-apps-script/Code.gs`.

= Does it replace the WooCommerce checkout? =

No. It creates standard WooCommerce orders with the Cash on delivery payment method, so your existing order management, emails and delivery plugins keep working.

== Changelog ==

= 1.0.1 =
* Security: Google Sheets requests use wp_safe_remote_post (no requests to internal addresses).
* Security: orders are refused for draft, private or password-protected products.
* Security: name and address length limits.
* New: "Visitor IP from" setting for sites behind Cloudflare or a proxy, so the rate limit counts real visitors.
* Fix: shop managers can now save the settings.
* Fix: "Sold individually" products are always ordered with quantity 1.

= 1.0.0 =
* First release.
