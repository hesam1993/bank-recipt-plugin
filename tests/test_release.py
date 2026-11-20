import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('audit_release', ROOT / 'scripts/audit_release.py')
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)


class ReleaseTests(unittest.TestCase):
    def records(self, directory):
        for branch in ('8.3', '8.4', '8.5', '8.6'):
            for hpos in ('yes', 'no'):
                self.record(directory, branch, hpos, 'latest')
        for hpos in ('yes', 'no'):
            self.record(directory, '8.3', hpos, 'minimum')

    def record(self, directory, branch, hpos, profile):
        path = Path(directory) / (branch + '-' + hpos + '-' + profile)
        path.mkdir()
        record = dict(plugin_version=audit.metadata()[1]['Version'], source_sha256=audit.fingerprint(), php_version=branch + '.0', hpos=hpos, profile=profile,
                      requested_php=branch, requested_hpos=hpos,
                      wordpress_version='7.1.5' if profile == 'latest' else '7.0', woocommerce_version='11.3.0' if profile == 'latest' else '11.0.0',
                      checks={key: True for key in ('javascript', 'php_lint', 'storage_crypto', 'woocommerce', 'http')})
        (path / 'validation.json').write_text(json.dumps(record))

    def test_metadata_and_license(self):
        self.assertEqual(audit.audit(), [])

    def test_complete_matrix_produces_measured_versions(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            self.assertEqual(audit.validated_versions(directory), ('7.1', '11.3.0'))

    def test_missing_runtime_records_block_release(self):
        with tempfile.TemporaryDirectory() as directory:
            self.record(directory, '8.3', 'yes', 'latest')
            with self.assertRaises(ValueError):
                audit.validated_versions(directory)

    def test_stale_source_evidence_blocks_release(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            path = next(Path(directory).rglob('validation.json'))
            record = json.loads(path.read_text())
            record['source_sha256'] = '0' * 64
            path.write_text(json.dumps(record))
            with self.assertRaisesRegex(ValueError, 'match this source'):
                audit.validated_versions(directory)

    def test_failed_checks_block_release(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            path = next(Path(directory).rglob('validation.json'))
            record = json.loads(path.read_text())
            record['checks']['woocommerce'] = False
            path.write_text(json.dumps(record))
            with self.assertRaisesRegex(ValueError, 'incomplete or failed'):
                audit.validated_versions(directory)

    def test_duplicate_matrix_records_block_release(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            extra = Path(directory) / 'duplicate'
            extra.mkdir()
            original = next(Path(directory).rglob('validation.json'))
            (extra / 'validation.json').write_bytes(original.read_bytes())
            with self.assertRaisesRegex(ValueError, 'duplicate'):
                audit.validated_versions(directory)

    def test_hpos_mismatch_blocks_release_before_duplicate_detection(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            path = Path(directory) / '8.5-no-latest' / 'validation.json'
            record = json.loads(path.read_text())
            record['hpos'] = 'yes'
            path.write_text(json.dumps(record))
            with self.assertRaisesRegex(ValueError, 'Requested and measured.*8.5-no-latest'):
                audit.validated_versions(directory)

    def test_php_mismatch_blocks_release(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            path = Path(directory) / '8.6-yes-latest' / 'validation.json'
            record = json.loads(path.read_text())
            record['php_version'] = '8.5.0'
            path.write_text(json.dumps(record))
            with self.assertRaisesRegex(ValueError, 'Requested and measured.*8.6-yes-latest'):
                audit.validated_versions(directory)

    def test_duplicate_error_identifies_both_record_paths(self):
        with tempfile.TemporaryDirectory() as directory:
            self.records(directory)
            source = Path(directory) / '8.5-yes-latest' / 'validation.json'
            duplicate = Path(directory) / 'duplicate' / 'validation.json'
            duplicate.parent.mkdir()
            duplicate.write_bytes(source.read_bytes())
            with self.assertRaises(ValueError) as error:
                audit.validated_versions(directory)
            self.assertIn(str(source), str(error.exception))
            self.assertIn(str(duplicate), str(error.exception))


if __name__ == '__main__':
    unittest.main()
