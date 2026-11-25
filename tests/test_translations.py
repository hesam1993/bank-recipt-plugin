import gettext
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class TranslationTests(unittest.TestCase):
    def setUp(self):
        with (ROOT / 'languages/card-to-card-receipts-for-woocommerce-fa_IR.mo').open('rb') as source:
            self.translations = gettext.GNUTranslations(source)

    def test_account_field_labels_are_translated(self):
        for text in ('Card number', 'Bank name', 'Account holder name'):
            self.assertNotEqual(self.translations.gettext(text), text)

    def test_bundled_catalog_translations_match_editable_source(self):
        catalogue = json.loads((ROOT / 'languages/fa_IR.json').read_text())
        # The editable source also retains obsolete strings from earlier releases.
        for message, translation in self.translations._catalog.items():
            if not message:
                continue
            with self.subTest(message=message):
                self.assertEqual(translation, catalogue[message])

    def test_catalog_metadata_matches_directory_slug_and_release(self):
        version = json.loads((ROOT / 'package.json').read_text())['version']
        self.assertEqual(self.translations.info()['project-id-version'],
                         'card-to-card-receipts-for-woocommerce ' + version)

    def test_php_escaped_apostrophe_matches_runtime_gettext_key(self):
        text = "The browser tab temporarily stores an upload token and account/reference information to recover checkout. Upload protection uses WooCommerce session cookies and local hourly counters containing salted IP hashes. No analytics or receipt data is sent to the plugin author. Receipt notifications use the store's configured WordPress mail transport."
        self.assertNotEqual(self.translations.gettext(text), text)

    def test_compiler_handles_long_unterminated_escape_sequence(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for folder in ('scripts', 'includes', 'languages'):
                (root / folder).mkdir()
            for name in ('compile_translations.py', 'audit_release.py'):
                shutil.copyfile(ROOT / 'scripts' / name, root / 'scripts' / name)
            shutil.copyfile(ROOT / 'package.json', root / 'package.json')
            message = "Store's path \\x"
            (root / 'languages/fa_IR.json').write_text(json.dumps({message: 'ترجمه آزمایشی'}))
            domain = 'card-to-card-receipts-for-woocommerce'
            (root / 'c2c-receipts.php').write_text(
                "<?php\n__( '" + r'\&' * 100000 + "\n" +
                r"esc_html__( 'Store\'s path \\x', '" + domain + "' );\n")
            (root / 'uninstall.php').write_text('<?php\n')
            result = subprocess.run([sys.executable, str(root / 'scripts/compile_translations.py')],
                                    text=True, capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn('Compiled 1 Persian translations.', result.stdout)
            with (root / f'languages/{domain}-fa_IR.mo').open('rb') as source:
                self.assertEqual(gettext.GNUTranslations(source).gettext(message), 'ترجمه آزمایشی')


if __name__ == '__main__':
    unittest.main()
