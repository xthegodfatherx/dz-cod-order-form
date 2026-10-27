# DZ COD Order Form

Free, open-source **cash-on-delivery order form for Algerian WooCommerce stores**.
Name, phone, wilaya, commune, confirm — one step, right on the product page.

استمارة طلب بالدفع عند الاستلام للمتاجر الجزائرية على WooCommerce — 69 ولاية وجميع البلديات، سعر توصيل لكل ولاية، وحماية من الطلبات المكررة والوهمية.

Formulaire de commande paiement à la livraison pour les boutiques WooCommerce algériennes — 69 wilayas, toutes les communes, tarifs de livraison par wilaya et protection anti-fraude.

## Features

- **69 wilayas** (incl. the 11 created in 2025) and **1,541 communes**, Arabic or Latin names
- **Delivery price per wilaya** — home delivery and stop desk, enable/disable wilayas, free delivery threshold
- Live summary (product + delivery = total), simple & variable products, quantity
- **Anti-fraud**: Algerian phone validation, duplicate detection (block or flag On hold), phone blocklist, IP rate limit, honeypot
- **Delivery companies**: Yalidine, Yalitec, ZR Express + 22 Ecotrack couriers (DHD, Conexlog/UPS, Rex, Rocket, 48hr…) — one click, bulk, or automatic on a chosen status; tracking + label saved on the order
- **Google Sheets** sync via Apps Script (new orders + status updates), **CSV export**
- Meta Pixel / TikTok / GA4 purchase events (if already installed) + `dzcod:order` JS event
- Auto-insert on product pages, shortcode `[dzcod_form]`, **Elementor widget**
- RTL, HPOS-compatible, works behind page caches

## Install

1. Download the latest release zip (or clone into `wp-content/plugins/dz-cod-order-form`).
2. Activate (WooCommerce required).
3. **WooCommerce → DZ COD Form** → set delivery prices.

## Google Sheets

1. Google Sheet → Extensions → Apps Script → paste [`google-apps-script/Code.gs`](google-apps-script/Code.gs), set `SECRET`.
2. Deploy as Web app (execute as Me, access Anyone).
3. Paste the URL + secret in the plugin's *Google Sheets & export* tab → **Send a test row**.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `dzcod_locations` | filter | Change the wilaya/commune dataset |
| `dzcod_is_valid_phone` | filter | Custom phone rules |
| `dzcod_antifraud_result` | filter | Add your own fraud checks |
| `dzcod_auto_insert_for_product` | filter | Skip the form on some products |
| `dzcod_sheets_row` | filter | Add columns to the sheet/CSV |
| `dzcod_client_ip` | filter | Behind Cloudflare/proxy: return the real IP |
| `dzcod_before_order_save` | action | Modify the order before it is saved |
| `dzcod_order_created` | action | SMS, CRM, custom integrations… |
| `dzcod_courier_providers` | filter | Add another delivery company |
| `dzcod_courier_payload` | filter | Adjust the request sent to a courier |
| `dzcod_parcel_created` | action | React to a new tracking number |

```js
document.addEventListener('dzcod:order', e => console.log(e.detail)); // {id, total, currency, items}
```

## Roadmap — contributions welcome

- Maystro and Noest integrations
- Parcel status sync back from couriers (delivered / returned)
- Official stop-desk lists per courier
- Abandoned form capture (phone typed but not submitted)
- Arabic & French translation files
- Upsells / quantity offers

Good first issues are labelled [`good first issue`](../../issues?q=label%3A%22good+first+issue%22).

## Credits

Location data: [geoalgeria](https://www.npmjs.com/package/geoalgeria) (MIT, see `data/LICENSE-geoalgeria.txt`).

## License

GPL-2.0-or-later.
