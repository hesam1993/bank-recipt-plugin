# Project guidance

## Project

Card to Card Receipts for WooCommerce provides receipt uploads and manual bank-transfer review. The plugin slug and text domain are `c2c-receipts`, the PHP namespace is `C2CReceipts`, and the gateway ID is `c2c_receipts`. Runtime requirements are WordPress 7.0+, WooCommerce 11.0+, and PHP 8.3+ with GD and OpenSSL AES-256-GCM support.

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
- Use the `c2c-receipts` text domain and preserve Persian, RTL, and digit handling.
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

Use the GitHub Actions workflow for the PHP/WordPress/WooCommerce matrix, HPOS modes, and HTTP flows. Integration fixtures require disposable installations and databases. Report any checks that could not run.

CI installs `tests/ci-store.php` as a must-use plugin in its disposable store and sets the `C2C_TEST_HPOS` configuration constant. This fixture selects the order datastore for CLI and HTTP requests and disables synchronization. Keep it out of production installations and runtime ZIPs. `scripts/assert_test_environment.php` checks measured PHP, HPOS mode, and order datastore against the job's `C2C_TEST_PHP` and `C2C_TEST_HPOS` environment variables before integration tests and evidence recording.

## Packaging and documentation

```sh
python3 scripts/package.py
```

- The review archive is `dist/c2c-receipts-<version>-review-candidate.zip`, containing one `c2c-receipts/` directory.
- Use `--wordpress-org --validation-dir <directory>` only with matching successful runtime evidence for submission packaging.
- Exclude development dependencies, local configuration, tests, development notes, and directory artwork from installation ZIPs.
- Keep user instructions in `README.md`, directory metadata in `readme.txt`, and runtime source details in `source.txt`.
- Keep this file focused on current project structure, conventions, and commands.
- Record compatibility claims only from verified environments.
- Make changes on `develop`. Commit, rewrite history, merge, or push only when requested.
