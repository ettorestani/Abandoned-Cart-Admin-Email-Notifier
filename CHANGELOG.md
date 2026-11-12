# Changelog

## 1.2.0 — 2026-11-12

Same code for PrestaShop 1.7.0 – 9.x and PHP 7.1 – 8.4. Tested on 1.7.3.4 / PHP 7.1, 8.2.9 / PHP 8.1 and 9.1.5 / PHP 8.4.

### Compatibility
- Prices are formatted through the CLDR locale (1.7.6+). `Tools::displayPrice()`, removed in PrestaShop 9 and deprecated in 8, is only the fallback for 1.7.0 – 1.7.5. The formatted price is byte for byte the same on 1.7.3, 8.2 and 9.1.

### Fixes
- A PHP error (`TypeError` and similar) on one cart is caught and logged; before, it stopped every run on the same cart and blocked all the carts after it.
- When the shop is set to never send emails, the cron stops with an error instead of marking carts as notified.
- A web run stops starting new carts after 240 s, below the 300 s time limit, so a run killed mid-cart can no longer send that cart's emails twice.
- Failed notifications are retried at most 5 times in all (they were retried on every run while the cart was in the age window).
- Carts holding only deleted products are skipped.
- Only the carts of the current shop are selected (uses the `(id_shop, date_upd)` index; correct on multistore).
- Uninstalling keeps the notification history, so a reinstall no longer notifies the carts of the last days again. Install reuses an existing table and adds any missing column.
- Clearing the log is a POST form instead of a GET link.

### New
- Email redesigned in the PrestaShop mail style (HTML table layout, matching text version), with:
  - reference, unit price, line total and link for each product (prices from the cart, as the customer sees them);
  - abandon date in the shop's date format and how long the cart has been idle;
  - "shipping included" next to the total value, which has always included shipping;
  - customer phone, from the delivery address or the latest address.
- Configuration page:
  - cron status: last run, source (URL or CLI) and counts, with a red warning when the cron has not run for 48 hours (configurable);
  - test email button: sends the notification with a sample cart to the saved recipients;
  - button to generate a new cron token;
  - notification log paginated (20 per page) with cart total, attempts and a link to the cart.
- `cli/cron.php`: run the job from the command line (`--dry-run` supported).
- Lighter `logo.png` (64×64, 6 KB instead of 227 KB).
- `build.sh` builds the installation zip with only the files the module needs.
- `tests/run.php`: CLI test suite for the DDEV test environments (not in the zip).

### Upgrade
`upgrade/upgrade-1.2.0.php` adds the `attempts`, `cart_total` and `id_currency` columns and the `ACN_CRON_ALERT_HOURS` setting (48). Existing rows keep their status, with `attempts = 1` and no total.

## 1.1.0

- One notification row per cart (unique index), failed notifications retried in place.
- Configurable cart age window (inactivity threshold and maximum age).
- Bug and security fixes.

## 1.0.0

- Initial release.
