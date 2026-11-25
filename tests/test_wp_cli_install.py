import json
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]


class WpCliInstallTests(unittest.TestCase):
    def install(self, directory, failure=None):
        directory = Path(directory)
        tools = directory / 'tools'
        tools.mkdir()
        # Exercise the installer without network access or a PHP runtime.
        stub = textwrap.dedent('''\
            #!/usr/bin/env python3
            import json, os, sys
            from pathlib import Path
            name = Path(sys.argv[0]).name
            with open(os.environ['C2C_INSTALL_TEST_LOG'], 'a') as log:
                log.write(json.dumps([name, sys.argv[1:]]) + '\\n')
            if name == 'curl':
                target = Path(sys.argv[sys.argv.index('--output') + 1])
                target.write_text('downloaded test fixture')
            if name == 'sha256sum':
                line = sys.stdin.read()
                assert line.startswith('ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  ')
                assert Path(line.split('  ', 1)[1].strip()).is_file()
            sys.exit(1 if name == os.environ.get('C2C_INSTALL_TEST_FAILURE') else 0)
        ''')
        for name in ('curl', 'php', 'sha256sum'):
            path = tools / name
            path.write_text(stub)
            path.chmod(0o755)
        path_file = directory / 'github-path'
        path_file.touch()
        log = directory / 'calls.jsonl'
        env = dict(os.environ, PATH=str(tools) + os.pathsep + os.environ['PATH'],
                   RUNNER_TEMP=str(directory), GITHUB_PATH=str(path_file),
                   C2C_INSTALL_TEST_LOG=str(log), C2C_INSTALL_TEST_FAILURE=failure or '')
        result = subprocess.run(['bash', str(ROOT / 'scripts/install_wp_cli.sh')],
                                env=env, text=True, capture_output=True)
        calls = [json.loads(line) for line in log.read_text().splitlines()]
        return result, path_file, calls

    def test_verified_cli_is_executable_and_available_to_later_steps(self):
        with tempfile.TemporaryDirectory() as directory:
            result, path_file, calls = self.install(directory)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual([call[0] for call in calls], ['curl', 'sha256sum', 'php'])
            self.assertIn('--retry-all-errors', calls[0][1])
            self.assertEqual(calls[0][1][-1],
                             'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar')
            bin_dir = Path(path_file.read_text().strip())
            self.assertEqual((bin_dir / 'wp').read_text(), 'downloaded test fixture')
            self.assertTrue(os.access(bin_dir / 'wp', os.X_OK))
            self.assertEqual(list(bin_dir.glob('wp-cli.*')), [])

    def test_failed_download_checksum_or_php_check_cannot_publish_cli(self):
        for failure, expected_calls in (('curl', ['curl']),
                                        ('sha256sum', ['curl', 'sha256sum']),
                                        ('php', ['curl', 'sha256sum', 'php'])):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                result, path_file, calls = self.install(directory, failure)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual([call[0] for call in calls], expected_calls)
                self.assertEqual(path_file.read_text(), '')
                self.assertEqual(list((Path(directory) / 'c2c-tools').iterdir()), [])


if __name__ == '__main__':
    unittest.main()
