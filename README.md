# Abandoned Cart Admin Email Notifier for PrestaShop

[![Support via PayPal](https://img.shields.io/badge/Support-PayPal-blue?logo=paypal)](https://www.paypal.com/paypalme/ettorestani)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7.x--8.x-blue.svg)](https://www.prestashop.com/)
[![Version](https://img.shields.io/badge/version-1.1.0-green.svg)](https://github.com/ettorestani/Abandoned-Cart-Admin-Email-Notifier)
[![License](https://img.shields.io/badge/license-AFL%203.0-orange.svg)](https://opensource.org/licenses/AFL-3.0)
[![PHP](https://img.shields.io/badge/php-7.2%2B-blue.svg)](https://www.php.net/)

A PrestaShop module that sends email notifications to store administrators when a registered customer abandons their shopping cart. Keep track of potential sales and follow up with customers who didn't complete their purchase.

## Disclaimer

This module is provided "as is", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and noninfringement.

**Important:**
- Always test in a **development/staging environment** before production
- Create a **complete backup** of your store before installation
- Verify compatibility with your PrestaShop version and other modules
- The author is **not responsible** for any damage, data loss, or issues caused by using this module

**Use at your own risk.** For production environments, consider using [professional installation services](#-professional-services).

---

## Key Features

### Automatic Cart Monitoring
- **Abandoned cart detection** after a configurable inactivity threshold (default: 24 hours)
- **Maximum cart age** - older carts are ignored (default: 7 days), so the first run doesn't flood you with historic carts
- **Customer carts only** - carts linked to a customer account (guest checkout accounts included), empty carts excluded
- **Order verification** - excludes carts converted to orders and customers who ordered afterwards
- **Duplicate prevention** - each cart triggers only one successful notification; failed ones are retried

### Email Notifications
- **Multiple recipients** - send to one or more email addresses
- **Plain text format** - maximum compatibility with all email clients
- **Complete cart details:**
  - Customer information (ID, name, email)
  - Cart details (ID, abandonment date, total value)
  - Full product list with quantities

### Administration Panel
- **Easy configuration** via PrestaShop back office
- **Notification log** - view last 50 sent notifications with status
- **Cron job management** - secure URL with token protection
- **Enable/disable switch** - quickly turn notifications on/off

### Security & Performance
- **Secure cron execution** with random token authentication
- **SQL injection protection** - all queries use PrestaShop's safe methods
- **Optimized queries** with proper indexing
- **Batch processing** - handles up to 100 carts per execution
- **Concurrency lock** - overlapping cron runs cannot send duplicate emails
- **Dry run mode** - preview which carts would be notified without sending anything

## Requirements

- **PrestaShop:** 1.7.0.0 - 8.x
- **PHP:** 7.1+
- **Cron job access** on your server
- **SMTP configured** in PrestaShop for email sending

## Installation

### Standard Installation

1. Download the latest version from [Releases](https://github.com/ettorestani/Abandoned-Cart-Admin-Email-Notifier/releases)
2. Go to your PrestaShop back office
3. Navigate to **Modules** > **Module Manager** > **Upload a module**
4. Select the downloaded ZIP file
5. Click **Configure** after installation
6. Set up email recipients and cron job

### Manual Installation

1. Extract the ZIP contents
2. Upload the `abandonedcartadminnotifier` folder to `/modules/` via FTP
3. In the back office, go to **Modules** > **Module Manager**
4. Search for "Abandoned Cart Admin Email Notifier"
5. Click **Install**

### Post-Installation

After installation, you must:
1. Configure at least one email recipient
2. Set up the cron job on your server (see [Cron Configuration](#cron-job-configuration))

## Configuration

### Module Settings

Access configuration via **Modules** > **Module Manager** > **Configure**

| Setting | Description |
|---------|-------------|
| **Enable Module** | Turn notifications on/off |
| **Email Recipients** | Email addresses separated by comma, semicolon or new line |
| **Inactivity threshold** | Hours without cart updates before it is considered abandoned (default 24) |
| **Maximum cart age** | Days after which a cart is no longer notified (default 7, max 90) |

### Email Recipients Format

Enter one or more email addresses separated by commas, semicolons or new lines:

```
admin@yourstore.com, manager@yourstore.com, sales@yourstore.com
```

### Notification Log

The configuration page displays the last 50 notifications with:
- Cart ID
- Customer name and email
- Date sent
- Status (Success/Failed)
- Error message (if any)

Use **Clear Log** to hide old entries (tracking data is preserved to prevent duplicate notifications).

## Cron Job Configuration

The module requires a cron job to scan for abandoned carts periodically.

### Cron URL

The secure cron URL is displayed on the configuration page:

```
https://yourstore.com/module/abandonedcartadminnotifier/cron?secure_token=YOUR_TOKEN
```

### Secure Token

A unique secure token is generated during installation. This token protects the cron URL from unauthorized access.

You can find the secure token:
1. On the module configuration page
2. In the database: `SELECT value FROM ps_configuration WHERE name = 'ACN_SECURE_TOKEN'`

### Setting Up Cron

#### Linux/Unix Crontab

```bash
# Edit crontab
crontab -e

# Add this line (runs daily at 2:00 AM)
0 2 * * * wget -q -O /dev/null "https://yourstore.com/module/abandonedcartadminnotifier/cron?secure_token=YOUR_TOKEN"

# Alternative using curl
0 2 * * * curl -s "https://yourstore.com/module/abandonedcartadminnotifier/cron?secure_token=YOUR_TOKEN" > /dev/null
```

#### cPanel

1. Go to **Cron Jobs** in cPanel
2. Set frequency to "Once Per Day"
3. Enter command:
   ```
   wget -q -O /dev/null "https://yourstore.com/module/abandonedcartadminnotifier/cron?secure_token=YOUR_TOKEN"
   ```
4. Click **Add New Cron Job**

#### Plesk

1. Go to **Scheduled Tasks**
2. Click **Add Task**
3. Set schedule to run daily
4. Enter the wget or curl command

The cron must run at least as often as the inactivity threshold: daily is fine with the default 24 hours, below 24 hours run it every hour. The configuration page shows a suggested crontab entry for the current setting.

### Dry Run

Append `&dry_run=1` to the cron URL to list the carts that would be notified on the next run. Nothing is sent or logged, and it works even when the module is disabled - useful right after installing or upgrading:

```
https://yourstore.com/module/abandonedcartadminnotifier/cron?secure_token=YOUR_TOKEN&dry_run=1
```

### Cron Response

The cron returns a JSON response:

```json
{
  "success": true,
  "execution_time": "1.23s",
  "statistics": {
    "carts_processed": 5,
    "emails_sent": 5,
    "errors": 0
  },
  "error_messages": []
}
```

## Email Format

Notification emails include:

```
A new abandoned cart has been detected.

CUSTOMER DETAILS:
- Customer ID: 123
- Name: John Doe
- Email: john@example.com

CART DETAILS:
- Cart ID: 456
- Abandoned Date: 2024-01-15 14:30:00
- Total Value: €99.99

PRODUCTS IN CART:
- Product Name 1 (Quantity: 2)
- Product Name 2 (Quantity: 1)

---
This is an automatic notification generated by the system.
```

## Troubleshooting

### Emails not being sent

1. Verify **Enable Module** is ON
2. Check email recipients are configured
3. Test PrestaShop email settings (**Advanced Parameters** > **Email**)
4. Check PrestaShop logs (**Advanced Parameters** > **Logs**)
5. Manually access cron URL and check JSON response

### Cron not executing

1. Verify secure token matches configuration
2. Test cron URL in browser
3. Check server cron logs (`/var/log/cron`)
4. Run wget/curl command manually

### Duplicate notifications

1. Check notification log for same cart ID
2. Verify database table is intact
3. Overlapping cron runs are blocked by a lock, so duplicates usually mean two different shops/databases share the same recipients

### Cart not detected

A cart must meet ALL these criteria:
- Cart is linked to a customer account (`id_customer > 0`, guest accounts included) that is not deleted
- Cart contains at least one product
- No order exists for this cart, and the customer has not placed any order since the cart was last updated
- Cart was last updated between the inactivity threshold (default 24 hours) and the maximum cart age (default 7 days) ago
- No notification was successfully sent previously (failed ones are retried on the next run)

## Compatibility

- PrestaShop 1.7.0 - 1.7.8
- PrestaShop 8.0 - 8.x
- PHP 7.2 - 8.2
- Multistore compatible
- All standard PrestaShop themes

## Security

- CSRF protection via secure token
- SQL injection prevention (pSQL, type casting)
- XSS protection in templates (Smarty escape)
- No direct file access (index.php protection)
- Server-side input validation

## Technical Details

### Database Table

Table: `ps_abandoned_cart_notifications`

| Column | Type | Description |
|--------|------|-------------|
| id_notification | INT | Primary key (auto-increment) |
| id_cart | INT | Cart ID (unique) |
| id_customer | INT | Customer ID (indexed) |
| date_sent | DATETIME | When notification was sent |
| email_status | ENUM | 'success' or 'failed' |
| error_message | TEXT | Error details if failed |
| log_visible | TINYINT | Show in admin log (0/1) |
| date_add | DATETIME | Record creation date |

### Configuration Keys

| Key | Description |
|-----|-------------|
| ACN_EMAIL_RECIPIENTS | Recipient emails (comma, semicolon or newline separated in the form) |
| ACN_MODULE_ENABLED | Module enabled status (0/1) |
| ACN_SECURE_TOKEN | Cron security token |
| ACN_MIN_CART_AGE_HOURS | Inactivity threshold in hours (default 24) |
| ACN_MAX_CART_AGE_DAYS | Maximum cart age in days (default 7) |

### Cart Selection Criteria

A cart is considered "abandoned" when:
1. `id_customer > 0` and the customer is not deleted (guest accounts included)
2. The cart contains at least one product
3. No corresponding order exists in `ps_orders`, and the customer has no order dated after the cart's `date_upd`
4. `date_upd` is older than the inactivity threshold (default 24 hours) and newer than the maximum cart age (default 7 days) (older carts are never notified, so installing the module does not flood recipients with historic carts)
5. No successful notification exists for this cart (failed notifications are retried while the cart is inside the window)

Concurrent cron runs are prevented with a MySQL named lock.

## Upgrading

Upload the new zip from **Modules** > **Module Manager** > **Upload a module**. PrestaShop runs the upgrade scripts in `upgrade/` automatically; notifications already sent are preserved.

Upgrading from 1.0.0 to 1.1.0 adds a unique index on `id_cart` and the two new settings with their defaults. Open the cron URL with `&dry_run=1` afterwards to check what the next run will send.

## Uninstallation

### Warning

Uninstalling this module will **permanently delete**:
- All notification log data
- All module configuration settings
- The `ps_abandoned_cart_notifications` database table

### Uninstall Procedure

1. Go to **Modules** > **Module Manager**
2. Search for "Abandoned Cart Admin Email Notifier"
3. Click the dropdown arrow and select **Uninstall**
4. Confirm the uninstallation

---

## Support This Project

This module is **completely free** and will always be.

If you're using it in your business and it's saving you development time, please consider supporting its development:

**[Support via PayPal](https://www.paypal.com/paypalme/ettorestani)**

Even a small contribution helps me:
- Keep the module updated with new PrestaShop versions
- Fix bugs faster
- Add new features based on community feedback

Thank you for your support!

---

*Business using this module? I also offer [professional services](#-professional-services).*

## Professional Services

Need help with your PrestaShop store? I offer:

- **Module Customization** - Tailored modifications to fit your specific needs
- **Complete PrestaShop E-commerce Development** - From setup to launch
- **Performance Optimization** - Speed up your store
- **Custom Module Development** - Build exactly what you need

**Contact:** info@ettorestani.it | **Website:** https://www.ettorestani.it

## License

This module is released under the [Academic Free License (AFL 3.0)](https://opensource.org/licenses/AFL-3.0).

## Author

**Ettore Stani**
- Email: info@ettorestani.it
- Website: https://www.ettorestani.it

## Changelog

### Version 1.1.0
- New: configurable inactivity threshold (default 24 hours) and maximum cart age (default 7 days)
- New: `dry_run=1` cron parameter lists the carts that would be notified, without sending or logging (works with the module disabled)
- Fix: empty carts are no longer notified
- Fix: carts older than the maximum age (default 7 days) are ignored (no flood of historic carts on first run)
- Fix: carts of customers who ordered afterwards, or deleted customers, are skipped
- Fix: cart total is formatted in the cart's own currency
- Fix: failed notifications are retried instead of being silently dropped
- Fix: concurrent cron runs can no longer send duplicate notifications (named lock + unique cart index)
- Fix: cron runs while the shop is in maintenance mode or geolocation-restricted
- Fix: recipient emails with apostrophes are no longer double-escaped; newline/semicolon separators accepted
- Fix: "Quantity" label in notification emails is now translated
- Security: cron no longer redirects from HTTPS to HTTP (the token was sent in clear text)
- Security: customer and product data are sanitized before being injected into email templates
- Security: JS-escaped confirmation message in the notification log

### Version 1.0.0
- Initial release
- Abandoned cart detection after 24 hours
- Email notifications to multiple recipients
- Notification logging with success/failure tracking
- Secure cron job execution with token
- PrestaShop 1.7.x and 8.x compatibility
- Italian and English translations

## Show Your Support

If this module has been helpful:
- Star this repository
- Share it with other developers
- Contribute translations or improvements
- [Support via PayPal](https://www.paypal.com/paypalme/ettorestani)

---

**Made with love by [Ettore Stani](https://www.ettorestani.it)**
