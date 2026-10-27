=== DZ COD Order Form ===
Contributors: nedjai
Tags: cash on delivery, algeria, yalidine, zr express, wilaya
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cash-on-delivery order form for Algerian WooCommerce stores: 69 wilayas, couriers, parcel tracking, call confirmation and ads tracking.

== Description ==

Most Algerian online stores sell with cash on delivery (COD). Customers do not want a cart, an account or a long checkout: they want to type their name and phone, pick their wilaya and commune, and confirm. This plugin puts that form directly on the product page and creates a normal WooCommerce order.

**Features**

* All **69 wilayas** (including the 11 created in 2025) and **1541 communes**, in Arabic or Latin script.
* Commune list filtered by the chosen wilaya.
* **Delivery price per wilaya**, separately for home delivery and stop desk (office pickup). Disable wilayas you do not deliver to. Optional free delivery above an amount.
* **Form fields you control**: show/hide, required, label and placeholder for every field, plus optional second phone, email and order note, and up to 6 **custom fields** (text, long text, dropdown, checkbox) saved on the order.
* **Import delivery prices automatically** from Yalidine, ZR Express or your Ecotrack courier, or from a **Google Sheet** (template included, optional daily sync). New wilayas get their former wilaya's price.
* **Quantity offers** per product: clickable packs like "1 for 3,500 · 2 for 6,000 · 3 for 8,000", with a fixed pack price or a % discount, optional badge ("Best value") and free delivery per pack. Prices are always recalculated on the server.
* **Abandoned orders**: visitors who type a valid phone number but do not confirm are saved (after a 15-minute grace period) in WooCommerce → Abandoned orders, with call and WhatsApp buttons and one-click "Create order". Automatically deleted after a period you choose.
* **Confirmation desk** (WooCommerce → Confirm orders): call new orders and record the outcome in one click — confirmed, no answer, call later, cancelled — with WhatsApp messages, automatic cancel after N unanswered calls, and per-agent statistics.
* **Parcel tracking**: delivered / returned / in transit pulled from Yalidine, ZR Express and Ecotrack every 3 hours; orders move to Completed or the new "Returned (refused)" status automatically.
* **Customer history and repeat refusers**: every order shows how many earlier parcels the phone number accepted or refused; repeat refusers can be put on hold or refused.
* **Meta Conversions API and TikTok Events API**: server-side Purchase events deduplicated with the browser pixels, with the option to count a purchase only when the order is confirmed by phone.
* **Free delivery per product**: choose which products ship free — home, stop desk or both, everywhere or only in the wilayas you pick. The form shows a "Free delivery" badge and the fee is removed on the server.
* **Request a feature** screen: ask for features or another delivery company, or report a problem, straight from WordPress.
* **One-click upsell** after the order: add a discounted product to the same order with one tap.
* **COD dashboard**: orders, confirmation rate, delivery rate, cash collected, losses on returns, best products and wilayas.
* **Arabic and French** included.
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

Filters: `dzcod_free_delivery`, `dzcod_ecotrack_hosts`, `dzcod_leads_per_hour`, `dzcod_meta_api_version`, `dzcod_locations`, `dzcod_is_valid_phone`, `dzcod_antifraud_result`, `dzcod_sheets_row`, `dzcod_auto_insert_for_product`, `dzcod_client_ip`, `dzcod_courier_providers`, `dzcod_courier_parcel`, `dzcod_courier_payload`, `dzcod_courier_logo`, `dzcod_offers`.
Actions: `dzcod_before_order_save`, `dzcod_order_created`, `dzcod_order_confirmed`, `dzcod_parcel_created`, `dzcod_parcel_status`, `dzcod_lead_recovered`, `dzcod_upsell_accepted`.

Source code, build-free (no compiled files), and issues: https://github.com/xthegodfatherx/dz-cod-order-form

== External services ==

This plugin only contacts outside services that you configure yourself:

* **Google Apps Script** (Google): when you enter a web app URL, each new order (name, phone, wilaya, commune, address, products, totals, status) is sent to your own script so it can be written to your Google Sheet. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **Your delivery company**: when you connect one and send an order (manually, in bulk or automatically), the customer name, phone, wilaya, commune, address, products, order number and amount to collect are sent to that company's API so it can create the parcel. Depending on your choice this is Yalidine (api.yalidine.app), Yalitec (api.yalitec.me), ZR Express (procolis.com) or an Ecotrack-based courier (*.ecotrack.dz). Their terms and privacy policies are on each company's website.

* **Parcel tracking**: when parcel tracking is on, the tracking numbers of your parcels are sent to the same delivery company every 3 hours to read their status.
* **Meta Conversions API** (Meta Platforms): when you enter a dataset ID and access token, a Purchase event is sent to graph.facebook.com for each order (or each confirmed order). It contains the order value, currency, product IDs and quantities, the order number, the page address, the visitor's IP address and browser, the _fbp / _fbc cookies, and the phone number, first name and commune **hashed with SHA-256**. [Terms](https://www.facebook.com/legal/terms/businesstools), [Privacy](https://www.facebook.com/privacy/policy).
* **TikTok Events API** (TikTok): when you enter a pixel code and access token, a CompletePayment event with the same kind of data (phone hashed with SHA-256, IP address, browser, _ttp cookie, order value and products) is sent to business-api.tiktok.com. [Terms](https://ads.tiktok.com/i18n/official/policy/business-products-terms), [Privacy](https://www.tiktok.com/legal/page/row/privacy-policy/en).

* **Delivery price import**: when you click "Import", your delivery company's price list is read from its API, or your Google Sheet is downloaded from docs.google.com (or the CSV address you entered). With the daily sync option, the sheet is downloaded once a day. Nothing about your customers is sent.
* **GitHub** (feature requests): the "Request a feature" screen does not contact any server. Only when you click "Continue on GitHub" does your browser open github.com with the text you typed (and, if you leave the box ticked, the plugin, WordPress, WooCommerce and PHP version numbers). [Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service), [Privacy](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement).

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

= How do I import delivery prices from Google Sheets? =

On the Delivery prices tab, download the template, open it in Google Sheets (File → Import), edit the home and desk columns, then Share → "Anyone with the link can view" and paste the link. Click "Import from sheet now", or tick the daily sync. You can also export your current prices to start from them.

= Can I show the delivery companies' logos? =

The plugin shows a coloured badge with each company's initials, because company logos are trademarks we cannot ship. To show your own logo files, use the `dzcod_courier_logo` filter: `add_filter( 'dzcod_courier_logo', function ( $url, $key ) { return 'yalidine' === $key ? 'https://your-site/logos/yalidine.png' : $url; }, 10, 2 );`

= Does the abandoned-order feature store personal data? =

Yes: the name, phone, wilaya, commune and address a visitor typed, plus the product. It is stored only in your own WordPress database, is shown only to shop managers, is deleted automatically after the number of days you set (30 by default), and is removed completely when you delete the plugin. A short notice under the form tells visitors their number may be saved; you can edit it or switch the feature off in General settings. Check what your local data-protection law requires.

= How do quantity offers work? =

Edit a product and fill in the "COD form — quantity offers" box: quantity, then either a fixed pack price or a % discount. The form then shows packs instead of the quantity selector. The pack with a badge is selected by default.

= How should I set up the confirmation workflow? =

In General settings set "New order status" to Pending payment and "When an order is confirmed by phone" to Processing. Your team works through WooCommerce → Confirm orders. If automatic sending is set to "When an order becomes Processing", confirmed orders go straight to your delivery company, and with "Count a purchase when the order is confirmed" Meta and TikTok only learn from real buyers.

= What happens when a parcel is refused? =

Parcel tracking marks it Returned and moves the order to "Returned (refused)". The phone number's history is updated, and if the customer refuses again they can be put on hold or refused automatically (Anti-fraud tab).

= How do I give free delivery on a product? =

Edit the product and use "Free delivery for this product" in the "COD form" box: free home delivery, free stop desk, or both, optionally only for some wilayas (for example 16, 09, 35). You can also give free delivery on a specific pack in the quantity offers, or above an order amount in Delivery prices.

= How can I ask for a new feature? =

Go to WooCommerce → Request a feature. It opens a pre-filled request on GitHub that you can review before posting. You can also vote 👍 on existing requests there.

= Is Noest supported? =

Not yet. Noest's API is not publicly documented; it is on the roadmap. Contributions are welcome on GitHub.

= A parcel was refused because of the commune name =

Delivery companies sometimes spell communes differently. Open the order, correct the commune in the shipping address, save, and click Send again. The exact error from the company is shown on the order.

= Does it replace the WooCommerce checkout? =

No. It creates standard WooCommerce orders with the Cash on delivery payment method, so your existing order management, emails and delivery plugins keep working.

== Changelog ==

= 1.5.1 =
* WordPress.org review: all admin JavaScript moved to an enqueued file (no inline script tags); late escaping everywhere (the order form is filtered through an allow-list with wp_kses); array input sanitized with map_deep; bundled data read with wp_json_file_decode; scripts load deferred.
* Suggested privacy policy text added under Settings → Privacy.
* Uninstall also removes the call log and all scheduled jobs.
* Coding standards: passes WordPress-Extra with no errors or warnings.
* Tested up to WordPress 7.1, WooCommerce 10.8. Requires WordPress 6.3.

= 1.5.0 =
* New: free delivery per product — home, stop desk or both, everywhere or only in chosen wilayas — with a badge on the form, live price update and server-side pricing (also when recovering abandoned orders).
* New: "Request a feature" screen that opens a pre-filled GitHub request (features, delivery companies, bugs), plus a feature-request template.
* Translations: Arabic and French updated (411 strings).

= 1.4.0 =
* New: parcel status sync from Yalidine, ZR Express and Ecotrack every 3 hours, with a "Returned (refused)" order status and automatic Completed / Returned updates.
* New: customer delivery history on every order and in the orders list; optional hold or refusal for repeat refusers.
* New: confirmation desk (WooCommerce → Confirm orders) with call outcomes, WhatsApp template, auto-cancel and agent statistics.
* New: Meta Conversions API and TikTok Events API, deduplicated with the browser pixels; option to count purchases only after phone confirmation.
* New: one-click upsell after the order.
* New: COD dashboard.
* New: Arabic and French translations.
* Developers: GitHub Actions (lint, unit and WordPress integration tests, release zips), contribution guide.

= 1.3.1 =
* Security: Google Sheets sync now requires a secret of at least 12 characters (a random one is generated if missing), and the Apps Script refuses to run with the example secret.
* Security: the generic Ecotrack option only accepts *.ecotrack.dz addresses, so your API token and customer data cannot be sent to another server (filter `dzcod_ecotrack_hosts` to extend).
* Security: size limits on responses from delivery companies and price sheets.
* Security: site-wide hourly limit on new abandoned-order records (filter `dzcod_leads_per_hour`).
* Fix: "Create order" on an abandoned order can no longer create two orders (double click or two staff members).
* Fix: orders recovered from abandoned forms are now sent to Google Sheets like other orders.
* Fix: orders with no wilaya or commune are not sent to the delivery company; a clear message is shown instead.
* Performance: abandoned-order counts are cached instead of queried on every admin page.

= 1.3.0 =
* New: quantity offers per product (fixed pack price or % off, badge, free delivery per pack), shown as cards with the crossed-out normal price.
* New: abandoned orders — capture after a valid phone number is typed, admin list with call/WhatsApp buttons, "Create order" in one click, automatic deletion after N days, privacy notice under the form.
* Orders created by staff from an abandoned form do not record the staff member's IP or account.

= 1.2.0 =
* New: Form fields tab — show/hide, required, label and placeholder for each field; second phone, email and note fields; up to 6 custom fields saved on the order, shown on the order screen and sent to Google Sheets.
* New: import delivery prices from Yalidine, ZR Express or Ecotrack couriers, or from a Google Sheet / CSV (template included, optional daily sync), and export prices to CSV.
* New: card-style delivery company picker with brand badges; the generic Ecotrack option lists 50+ compatible couriers (HHD Express, Eco Rapide Express…).
* New: second phone is sent to the courier (ZR Express MobileB, Ecotrack telephone_2).
* Fix: wilaya list no longer stays on "Loading…" — wilayas are rendered by the server, settings travel with the form (safe with JS optimisers), and communes fall back to an AJAX endpoint when the data file is blocked.
* Fix: delivery prices for wilayas 10–69 could be read from the wrong row after saving (numeric keys renumbered). Re-check your prices table after updating.
* Polished form design: delivery choice cards, trust line, success state.

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
