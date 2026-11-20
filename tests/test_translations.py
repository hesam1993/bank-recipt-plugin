import gettext
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class TranslationTests(unittest.TestCase):
    def setUp(self):
        with (ROOT / 'languages/c2c-receipts-fa_IR.mo').open('rb') as source:
            self.translations = gettext.GNUTranslations(source)

    def test_account_field_labels_are_translated(self):
        for text in ('Card number', 'Bank name', 'Account holder name'):
            self.assertNotEqual(self.translations.gettext(text), text)

    def test_php_escaped_apostrophe_matches_runtime_gettext_key(self):
        text = "The browser tab temporarily stores an upload token and account/reference information to recover checkout. Upload protection uses WooCommerce session cookies and local hourly counters containing salted IP hashes. No analytics or receipt data is sent to the plugin author. Receipt notifications use the store's configured WordPress mail transport."
        self.assertNotEqual(self.translations.gettext(text), text)


if __name__ == '__main__':
    unittest.main()
