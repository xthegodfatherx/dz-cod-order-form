=== DZ COD Order Form ===
Contributors: nedjai
Tags: cash on delivery, algeria, yalidine, zr express, wilaya
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.1.0
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
* **Delivery companies**: send orders to **Yalidine, Yalitec (Guepex), ZR Express** and **22 Ecotrack couriers** (DHD, Conexlog/UPS, Rex, MSM Go, Rocket, 48hr, Speed Delivery, Golivri, Coyote, World Express, Negmar and more). One click per order, bulk sending from the orders list, or automatic sending when an order reaches a status you choose. Tracking number and label saved on the order and pushed to Google Sheets.
* CSV export of form orders (opens correctly in Excel with Arabic).
* Fires Meta Pixel `Purchase`, TikTok `CompletePayment` and GA4 `purchase` events if those pixels are already on your site, plus a `dzcod:order` JavaScript event for custom tracking.
* Shortcode `[dzcod_form]`, automatic placement on product pages, and an **Elementor widget**.
* Works with full-page caching (nonce is refreshed automatically).
* RTL ready. Compatible with WooCommerce HPOS.

**Developers**

Filters: `dzcod_locations`, `dzcod_is_valid_phone`, `dzcod_antifraud_result`, `dzcod_sheets_row`, `dzcod_auto_insert_for_product`, `dzcod_client_ip`, `dzcod_courier_providers`, `dzcod_courier_parcel`, `dzcod_courier_payload`.
Actions: `dzcod_before_order_save`, `dzcod_order_created`, `dzcod_parcel_created`.

Source code and issues: https://github.com/xthegodfatherx/dz-cod-order-form

== External services ==

This plugin only contacts outside services that you configure yourself:

* **Google Apps Script** (Google): when you enter a web app URL, each new order (name, phone, wilaya, commune, address, products, totals, status) is sent to your own script so it can be written to your Google Sheet. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **Your delivery company**: when you connect one and send an order (manually, in bulk or automatically), the customer name, phone, wilaya, commune, address, products, order number and amount to collect are sent to that company's API so it can create the parcel. Depending on your choice this is Yalidine (api.yalidine.app), Yalitec (api.yalitec.me), ZR Express (procolis.com) or an Ecotrack-based courier (*.ecotrack.dz). Their terms and privacy policies are on each company's website.

Nothing is sent anywhere until you configure these services.

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

= Which delivery companies are supported? =

Yalidine, Yalitec (Guepex), ZR Express, and these Ecotrack couriers: DHD, Conexlog/UPS, MSM Go, Rex Livraison, RB Livraison, Speed Delivery, Areex, Prest, Rocket Delivery, World Express, BA Consult, Packers, 48hr Livraison, Mono Hub, Anderson Delivery, Golivri, Coyote Express, Salva Delivery, Distazero, Fret Direct, TSL Express and Negmar Express. Any other courier on Ecotrack (e.g. EcoRapide Express) works with the generic "Ecotrack" option: paste your courier's Ecotrack address and API token. Maystro and Noest are planned.

= A parcel was refused because of the commune name =

Delivery companies sometimes spell communes differently. Open the order, correct the commune in the shipping address, save, and click Send again. The exact error from the company is shown on the order.

= Does it replace the WooCommerce checkout? =

No. It creates standard WooCommerce orders with the Cash on delivery payment method, so your existing order management, emails and delivery plugins keep working.

== Changelog ==

= 1.1.0 =
* New: Algerian delivery companies — Yalidine, Yalitec, ZR Express and 22 Ecotrack couriers. Send from the order screen, in bulk, or automatically on a chosen status (in the background with Action Scheduler).
* New: tracking number column in the orders list, label link, tracking pushed to Google Sheets.
* New wilayas (59–69) are sent to couriers under their former wilaya code.
* Suspected duplicate orders are never sent automatically.

= 1.0.1 =
* Security: Google Sheets requests use wp_safe_remote_post (no requests to internal addresses).
* Security: orders are refused for draft, private or password-protected products.
* Security: name and address length limits.
* New: "Visitor IP from" setting for sites behind Cloudflare or a proxy, so the rate limit counts real visitors.
* Fix: shop managers can now save the settings.
* Fix: "Sold individually" products are always ordered with quantity 1.

= 1.0.0 =
* First release.
