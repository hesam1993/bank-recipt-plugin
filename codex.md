# Project guidance

## Project

Card to Card Receipts for WooCommerce provides receipt uploads and manual bank-transfer review. The plugin slug and text domain are `card-to-card-receipts-for-woocommerce`, the PHP namespace is `C2CReceipts`, and the gateway ID is `c2c_receipts`. Runtime requirements are WordPress 7.0+, WooCommerce 11.0+, and PHP 8.3+ with GD and OpenSSL AES-256-GCM support.

## Repository map

- `c2c-receipts.php`: bootstrap, lifecycle hooks, and WooCommerce feature registration.
- `includes/`: gateway, Checkout Blocks integration, storage, encryption, receipt lifecycle, HTTP handlers, review UI, and privacy callbacks.
- `assets/`: checkout widgets, payment settings interactions, and CSS.
- `languages/`: Persian translation catalog and generated gettext files.
- `uninstall.php`: opt-in cleanup.
- `tests/`: JavaScript, Python, PHP, WooCommerce, and HTTP checks.
- `scripts/`: translation compilation, release audits, evidence recording, and packaging.
- `.github/workflows/test.yml`: automated compatibility and release checks.
- `.wordpress-org/`: directory artwork, excluded from runtime archives.
- `README.md` and `readme.txt`: user documentation and directory metadata.
- `source.txt`: packaged source inventory.
- `.codex/`: ignored local workspace.

## Implementation rules

- Keep the bootstrap and `readme.txt` at the repository root. Follow WordPress coding conventions and the configured PHP compatibility standard.
- Use WooCommerce CRUD for orders and preserve HPOS, classic checkout, and Checkout Blocks support.
- Validate request data, verify nonces where applicable, check authorization, escape output, and prepare SQL.
- Check authorization for every receipt response. Preserve session ownership, order ownership, reviewer permissions, and signed-link expiry checks.
- Re-encode uploads and encrypt new receipt files before storage. Do not expose plaintext receipts, encryption keys, receipt tokens, or signed URLs.
- Keep storage paths compatible with WordPress uploads and retain access to configured legacy private receipt storage.
- Preserve single-use token claims, review locks, order amount/currency checks, and retry behavior.
- Keep orders on hold until manual approval. Complete payment through WooCommerce; preserve its stock, fulfillment, and refund behavior.
- Retain active-order receipts during cleanup. Honor privacy restrictions and the opt-in uninstall setting.
- Use the `card-to-card-receipts-for-woocommerce` text domain and preserve Persian, RTL, and digit handling.
- Keep plugin headers, runtime constants, `package.json`, and the `readme.txt` stable tag consistent.

## Checks

Run commands from the repository root with Node.js 20+ and Python 3:

```sh
npm test
npm run check
python3 -m unittest discover -s tests -p 'test_*.py'
npm run audit
```

For translation changes:

```sh
python3 scripts/compile_translations.py
```

With PHP, the required extensions, and Composer installed:

```sh
composer install
composer lint
composer standards
php tests/storage.php
```

The GitHub Actions workflow runs shared JavaScript, translation, Python and coding-standard checks once. Routine pushes and pull requests test four configurations: minimum supported versions and newest supported PHP with current WordPress/WooCommerce, each with HPOS enabled and disabled. Nightly runs, tag pushes and manual runs with `full_matrix` enabled test all supported PHP branches in `scripts/ci-matrix.json`. `scripts/ci_matrix.py` generates the selected matrix. Integration fixtures require disposable installations and databases. Report any checks that could not run.

CI installs `tests/ci-store.php` as a must-use plugin in its disposable store and sets the `C2C_TEST_HPOS` configuration constant. This fixture selects the order datastore for CLI and HTTP requests and disables synchronization. Keep it out of production installations and runtime ZIPs. Run `scripts/setup_test_store.php` through WP-CLI before integration tests: it initializes WooCommerce's HPOS schema and creates, reloads and deletes a smoke-test order. `scripts/assert_test_environment.php` checks measured PHP, HPOS mode, datastore and required HPOS tables before integration tests and evidence recording.

## Packaging and documentation

```sh
python3 scripts/package.py
```

- The review archive is `dist/card-to-card-receipts-for-woocommerce-<version>-review-candidate.zip`, containing one `card-to-card-receipts-for-woocommerce/` directory.
- Use `--wordpress-org --validation-dir <directory>` only with matching successful runtime evidence for submission packaging.
- Routine CI validates its four records with `--validation-scope routine` and produces a review candidate. Submission packaging requires release scope and the full matrix. Matrix generation and evidence validation share `scripts/ci-matrix.json`.
- Exclude development dependencies, local configuration, tests, development notes, and directory artwork from installation ZIPs.
- Keep user instructions in `README.md`, directory metadata in `readme.txt`, and runtime source details in `source.txt`.
- Keep this file focused on current project structure, conventions, and commands.
- Record compatibility claims only from verified environments.
- Make changes on `develop`. Commit, rewrite history, merge, or push only when requested.
