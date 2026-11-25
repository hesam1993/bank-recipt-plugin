=== Card to Card Receipts for WooCommerce ===
Contributors: hesamst
Tags: woocommerce, bank transfer, receipt, persian, card to card
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Requires Plugins: woocommerce
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add card-to-card bank transfers, protected receipt uploads, manual payment review, and Persian support to WooCommerce checkout.

== Description ==

Adds a separate card-to-card payment method to WooCommerce. Customers select a destination account and upload a receipt during checkout. Orders remain on hold until a merchant verifies the actual bank deposit and approves payment.

Supports classic checkout, Checkout Blocks, WooCommerce order CRUD/HPOS, guest checkout, expiring private guest links, rejected receipt replacement, upload recovery, and retention cleanup. Includes Persian (fa_IR) translations and RTL-friendly controls.

Requires WooCommerce 11.0+, PHP 8.3+, PHP GD, OpenSSL with AES-256-GCM, and a working normal WordPress uploads directory.

No bank connection, OCR, SMS provider, recurring payments, or automatic refund processing is included. A screenshot never automatically proves payment.

== Installation ==

1. Install and activate the plugin ZIP. When updating, replace the existing plugin; do not uninstall it.
2. Open WooCommerce > Settings > Payments > Card to card.
3. Add accounts using the three separate fields: Card number, Bank name, Account holder name. Use Add bank account to add another account. Persian digits are supported.
4. Save and check the saved-account summary, enable the gateway, and resolve any displayed setup issues.
5. Test an order on staging, then review its receipt from the WooCommerce order screen.

New receipts automatically use the normal WordPress uploads folder and file permissions. No custom folder or wp-config.php setting is needed. The files are encrypted before they are stored, so a direct uploads URL does not expose the image. Receipt images are only decrypted after order authorization.

== Frequently Asked Questions ==

= Is a receipt treated as proof that money arrived? =
No. Staff must verify the deposit in the bank account before approving payment.

= Where are receipt images stored? =
In the c2c-receipts subfolder of the normal WordPress uploads base. Images are re-encoded as JPEG and encrypted as .c2c files using AES-256-GCM before WordPress writes them. Direct URLs expose only encrypted bytes. Authorized customers and staff view the decrypted image through its order. Back up receipt files and the WordPress database together: the encryption key is a non-autoloaded WordPress option. Losing that key makes encrypted receipts unrecoverable. Old plaintext receipts remain in their original private folder; keep the old folder/configuration until those files are no longer needed.

= What if checkout fails after a bank transfer? =
The uploaded receipt token is retained in the browser tab for up to 24 hours and verified against the original WooCommerce session. Retry submission; do not send the transfer again. Clearing cookies or closing the tab can prevent automatic recovery; contact the store in that case.

= How does a guest replace a rejected receipt? =
The customer order email contains a private order-specific link that expires after seven days. Authorized registered customers can also use My Account. Staff can resend the order email to issue a new link. The replacement is attached to the same order.

= Does it support toman? =
The gateway preserves the order currency and amount without conversion. A currency extension must register and configure toman correctly. Validate its labels and totals on staging; this plugin does not register a new currency or silently convert IRR.

= What is removed automatically? =
Unattached receipt uploads expire after 24 hours. Closed-order receipt images expire according to the configured age, 90 days by default. Active orders are retained. WP-Cron runs bounded cleanup batches; use a real cron on low-traffic stores. Erasure restrictions can be implemented with c2creceipts_allow_erasure. The deprecated c2c_receipts_allow_erasure filter is still honored; a denial from either filter prevents erasure.

= Does uninstall delete payments? =
Deactivation preserves data. Uninstall preserves data unless its deletion option was explicitly enabled. Opt-in uninstall removes plugin receipt records and files, never WooCommerce orders. Both uploads and any legacy private folder must remain available to finish deletion. The encryption key is removed only with opt-in receipt-data deletion. On multisite, activate and manage the plugin per site; network-wide lifecycle management is not implemented.

== Privacy ==

The plugin sends no analytics, telemetry, receipt images, or account information to the author or a third-party service. PHP GD and OpenSSL process images locally. JavaScript, styles and translations are bundled; WordPress and WooCommerce provide their registered libraries.

The store retains receipt images, transaction references, destination account details, order amounts, session-owner hashes and payment review history. Customer emails can include a private order receipt link, and receipt updates can notify the store administrator using WordPress mail and the store's configured mail transport. The link expires after seven days; anyone possessing it can access that order's receipt until expiry.

Session storage in the customer's browser tab retains an upload token and account/reference information to recover failed checkout attempts. The WooCommerce session binds that token to its uploader. Local hourly abuse counters store salted IP hashes, not raw IP addresses. Safety upload rates and storage budgets protect the store's resources; they are not paid feature restrictions or trial limits. The administrator can adjust the storage budget using C2C_RECEIPTS_QUOTA_MB.

Unattached receipts expire after 24 hours. Closed-order image retention defaults to 90 days. Active orders and records under a configured retention restriction can be kept longer. WordPress personal-data exporter and eraser callbacks handle receipt metadata and eligible files; image copies can be requested privately from the store. No legal-compliance guarantee is made.

The encryption key is stored in the non-autoloaded c2c_receipts_encryption_key option. Back up the database and encrypted files together. Deactivation preserves data. Uninstall removes files, records and the key only if the merchant explicitly enabled deletion.

== Source code ==

All runtime PHP, JavaScript and CSS is included in readable, unminified form. The plugin uses WordPress's registered React/jQuery and WooCommerce Blocks scripts; it does not bundle copies. No JavaScript build step is required. Editable gettext PO sources are included beside MO files; rebuild with GNU gettext, for example:

msgfmt languages/card-to-card-receipts-for-woocommerce-fa_IR.po -o languages/card-to-card-receipts-for-woocommerce-fa_IR.mo

See source.txt for the source layout and license.txt for the complete GPL version 2 license. No external service account, license key, or purchase is required.

== Changelog ==

= 0.1.5 =
* Match the WordPress.org slug in translation domains, Persian catalogs and installation packages.
* Use the distinctive C2CRECEIPTS prefix for internal bootstrap constants.
* Check the assigned directory slug in CI; preserve saved settings, receipt storage and gateway identifiers.

= 0.1.4 =
* Address the supplied Plugin Check report: escape exception messages, sanitize request inputs, and use WordPress filesystem helpers.
* Verify file deletion before clearing receipt data; preserve nonce and order authorization checks.
* Document narrowly scoped custom-table queries and retention-filter compatibility.
* Rename the packaged source inventory to source.txt and prefer automatic directory translations with a bundled Persian fallback.
* Add request, retention and deletion regression coverage; include WordPress 7.1 tested metadata based on merchant-reported staging use; retain the full runtime release gate.

= 0.1.3 =
* Prepare WordPress.org directory metadata for contributor hesamst and author Hesam MM.
* Include full license text and source/privacy documentation.
* Use a distinctive implementation namespace without changing saved gateway or receipt data.
* Improve privacy erasure error handling, scope dependency notices, and preserve private URLs in plain-text order emails.
* Add release metadata validation and official Plugin Check automation.


= 0.1.2 =
* Set plugin author to Hesam MM.
* Replace text account entry with separate card, bank, and holder fields plus add/remove controls.
* Use standard WordPress uploads and filesystem permissions without manual folder setup.
* Encrypt new receipts before storage; decrypt only for authorized order access.
* Preserve existing accounts and support previously stored private receipts.

= 0.1.1 =
* Accept Card | Holder with Persian digits, while preserving the original extended account format.
* Show validation errors, retain rejected input for correction, and list actually saved accounts.
* Add private-folder configuration for shared hosting and specific storage/GD diagnostics.
* Report incomplete setup when the gateway is enabled; retain private storage protections.

= 0.1.0 =
* Initial development release: private receipt uploads, classic and block checkout, order review and replacement, retention, privacy callbacks, Persian translations, and validation harnesses.
