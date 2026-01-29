# Abandoned Cart Admin Email Notifier for PrestaShop

[![Support via PayPal](https://img.shields.io/badge/Support-PayPal-blue?logo=paypal)](https://www.paypal.com/paypalme/ettorestani)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7.x--8.x-blue.svg)](https://www.prestashop.com/)
[![Version](https://img.shields.io/badge/version-1.0.0-green.svg)](https://github.com/ettorestani/abandonedcartadminnotifier)
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
- **Abandoned cart detection** after configurable time period (default: 24 hours)
- **Registered customers only** - filters out guest carts
- **Order verification** - excludes carts that were converted to orders
- **Duplicate prevention** - each cart triggers only one notification

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

## Requirements

- **PrestaShop:** 1.7.0.0 - 8.x
- **PHP:** 7.1+
- **Cron job access** on your server
- **SMTP configured** in PrestaShop for email sending

## Installation

### Standard Installation

1. Download the latest version from [Releases](https://github.com/ettorestani/abandonedcartadminnotifier/releases)
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
| **Email Recipients** | Comma-separated list of email addresses |

### Email Recipients Format

Enter one or more email addresses separated by commas:

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
3. Ensure only one cron job is configured

### Cart not detected

A cart must meet ALL these criteria:
- Customer is registered (`id_customer > 0`)
- No order exists for this cart
- Cart is at least 24 hours old
- No notification was sent previously

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
| id_cart | INT | Cart ID (indexed) |
| id_customer | INT | Customer ID (indexed) |
| date_sent | DATETIME | When notification was sent |
| email_status | ENUM | 'success' or 'failed' |
| error_message | TEXT | Error details if failed |
| log_visible | TINYINT | Show in admin log (0/1) |
| date_add | DATETIME | Record creation date |

### Configuration Keys

| Key | Description |
|-----|-------------|
| ACN_EMAIL_RECIPIENTS | Comma-separated recipient emails |
| ACN_MODULE_ENABLED | Module enabled status (0/1) |
| ACN_SECURE_TOKEN | Cron security token |

### Cart Selection Criteria

A cart is considered "abandoned" when:
1. `id_customer > 0` (registered customer, not guest)
2. No corresponding order exists in `ps_orders`
3. `date_upd` is at least 24 hours old
4. No notification has been sent for this cart

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
