# Card to Card Receipts for WooCommerce

Accept manual card-to-card bank transfers with receipt uploads and payment review in WooCommerce.

Customers select a destination account and upload a receipt at checkout. Orders remain **On hold** until authorized staff verify the deposit and approve payment.

**Version:** 0.1.4 · **Author:** [Hesam MM](https://profiles.wordpress.org/hesamst/) · **License:** GPL-2.0-or-later

## Features

- Card-to-card payment method for classic checkout and Checkout Blocks.
- Up to 20 destination accounts, with card number, bank name, and account holder fields.
- JPEG, PNG, and WebP receipt uploads, with a default maximum of 5 MB.
- Protected receipt viewing, manual approval, rejection, and replacement uploads.
- Encrypted receipt storage and configurable file retention.
- WooCommerce HPOS support.
- Persian translation, Persian/Arabic digit input, and RTL-friendly controls.

## Requirements

- WordPress 7.0 or newer.
- WooCommerce 11.0 or newer, installed and active.
- PHP 8.3 or newer with GD and OpenSSL supporting AES-256-GCM.
- A writable WordPress uploads directory.
- HTTPS for production and JavaScript enabled for uploads and receipt review.
- Working WP-Cron or a server cron runner for scheduled cleanup.

## Installation

1. Back up your store and test the plugin on a staging site.
2. Install and activate WooCommerce.
3. Open **Plugins → Add New → Upload Plugin** and upload the plugin release ZIP.
4. Activate **Card to Card Receipts for WooCommerce**.
5. Open **WooCommerce → Settings → Payments → Card to card**.

For manual installation, place the runtime files in `wp-content/plugins/c2c-receipts/`, with `c2c-receipts.php` directly inside that folder.

### Building an installation ZIP from source

From the repository root, with Python 3 installed:

```sh
python3 scripts/package.py
```

Upload the generated `dist/c2c-receipts-0.1.4-review-candidate.zip`. The archive contains one `c2c-receipts/` plugin folder and excludes development tooling and directory artwork.

## Configuration

1. Open the **Card to card** payment settings.
2. Add each destination account with its 16-digit card number and account holder name. Bank name is optional.
3. Set the payment title and instructions customers will see at checkout.
4. Set the maximum upload size and receipt retention period. Defaults are 5 MB and 90 days.
5. Save settings, check the saved-account summary, and resolve any setup warnings shown on the page.
6. Verify the destination details, enable the gateway, and complete a test checkout.

New receipts use the configured WordPress uploads directory automatically. A custom private folder or `wp-config.php` change is not required.

To enable Persian, set the site language under **Settings → General** to **فارسی**.

## Customer payment workflow

1. At checkout, select **Card to card** and a destination account.
2. Transfer the exact order total through your bank.
3. Upload a JPEG, PNG, or WebP receipt, and enter a tracking reference if needed.
4. Place the order. It remains **On hold** while the store reviews the payment.
5. If the receipt is rejected, use the replacement flow available through the order page or customer email link.

## Reviewing payments

1. Open the order in WooCommerce and save any pending order edits.
2. Find the **Bank transfer receipt** panel and view the receipt.
3. Independently check that the full deposit reached the destination bank account.
4. Tick the deposit verification checkbox and choose **Approve payment**, or enter a reason and choose **Reject receipt**.

Approval uses WooCommerce's normal payment completion flow. The plugin does not connect to banks, verify transfers automatically, or initiate refunds. Arrange refunds through the bank and record them in WooCommerce.

If the order total or currency changed after upload, approval is blocked. Reconcile the amount or reject the receipt and request a replacement. Rejection keeps the order under review; cancel abandoned orders explicitly so WooCommerce can handle stock restoration.

## Receipt privacy and retention

Uploaded images are re-encoded as JPEG and encrypted before storage. They are not public Media Library attachments. Receipt viewing requires order authorization or a valid expiring customer access link. Guest links expire after seven days; anyone holding one can access that order's receipt during its validity, so keep them private.

Back up receipt files and the WordPress database together. The encryption key is stored in the database; losing it makes encrypted files unrecoverable.

Unattached uploads expire after 24 hours. Files for closed or deleted orders are removed after the configured retention period, while active orders are retained. Review metadata survives normal file cleanup.

Deactivation preserves data. Uninstall removes receipt files and plugin records only when the deletion setting is enabled; WooCommerce orders are preserved.

## Frequently asked questions

### Why is the payment method missing at checkout?

Check the gateway settings for setup errors. Confirm that the gateway is enabled, valid destination accounts are saved, GD and OpenSSL are available, and WordPress can write to uploads. This gateway supports new checkout orders; the separate WooCommerce `order-pay` endpoint is not supported.

### Does this support toman conversion?

The plugin uses the order's amount and currency. It does not register currencies or convert IRR and toman. Test any currency extension with the checkout and approval flows.

### What should I exclude from caching?

Exclude checkout and account pages, `wc-ajax=c2c_*` requests, `admin-post.php?action=c2c_file`, and URLs containing `c2c_access`. Respect private/no-store response headers and avoid logging signed access URLs.

### Can I update from an older version?

Replace the installed plugin without uninstalling it. If upgrading from 0.1.0 or 0.1.1, keep the existing private receipt folder and configuration for legacy plaintext files until those receipts have expired or been migrated separately. Do not move those files into public uploads.

### Does it support multisite?

Activate and configure it separately for each site. Network-wide activation, migration, and uninstall are not implemented.

## Support and contributing

Report reproducible issues through the repository's issue tracker with WordPress, WooCommerce, PHP, and plugin versions, checkout type, and steps to reproduce. Remove receipt images, bank details, access links, credentials, and customer information from reports.

See [codex.md](codex.md) for development commands and implementation context, [source.txt](source.txt) for the source inventory, and [readme.txt](readme.txt) for the changelog and directory documentation. This is a review candidate; complete CI and staging validation before production deployment.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for attribution and [license.txt](license.txt) for the full license.
