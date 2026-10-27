# Contributing

Thanks for helping Algerian online stores! Issues and pull requests are welcome in English, French or Arabic.

## Good first contributions

- **Add a delivery company.** Ecotrack couriers need only a name and their verified `*.ecotrack.dz` address in `DZCOD_Courier_API::providers()`. Open a "Add a delivery company" issue if you are not sure.
- **Courier status wording.** If a parcel shows "Unknown", add the courier's wording to `DZCOD_Courier_API::normalize_status()` and a case to `tests/unit/test-status-mapping.php`.
- **Translations.** Edit `languages/dz-cod-order-form-ar.po` or `-fr_FR.po` (Poedit works well), or translate on translate.wordpress.org once the plugin is listed.
- **Noest / Maystro.** If you have their official API documentation, an integration is very welcome.

## Development

```bash
composer install
php tests/unit/run.php                       # fast tests, no WordPress needed
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WC_DIR=/path/to/woocommerce vendor/bin/phpunit   # full order flow in real WordPress + WooCommerce
composer lint                                # WordPress coding standards
```

## Rules of the house

- Every price is calculated on the server. Never trust amounts, quantities or packs sent by the browser.
- Escape all output (`esc_html`, `esc_attr`, `esc_url`), sanitize all input, and check a nonce **and** a capability in every admin action.
- Never commit API tokens, real phone numbers or customer data (also not in screenshots or test fixtures).
- Wilaya codes are array keys like `"16"`, which PHP turns into integers: compare them as strings and never merge code-keyed arrays with `array_merge()` / `wp_parse_args()`.
- Delivery companies still use the 58 historical wilaya codes: use the `l` (legacy) field of the dataset when talking to them.

## Releasing

Bump `Version:` in `dz-cod-order-form.php`, `DZCOD_VERSION`, and `Stable tag:` in `readme.txt`, add a changelog entry, then push a tag `vX.Y.Z`. GitHub Actions runs the tests and attaches the zip to the release.
