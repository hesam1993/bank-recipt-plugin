#!/usr/bin/env python3
"""Offline directory metadata checks; this is not the official WordPress Plugin Check."""
from pathlib import Path
import argparse
import hashlib
import json
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'card-to-card-receipts-for-woocommerce'


def runtime_files(root=ROOT):
    paths = [root / name for name in ('c2c-receipts.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'license.txt', 'source.txt')]
    for folder in ('includes', 'assets', 'languages'):
        paths.extend(path for path in (root / folder).rglob('*') if path.is_file() and path.suffix in {'.php', '.js', '.css', '.mo', '.po'})
    return sorted(paths)


def fingerprint(root=ROOT):
    digest = hashlib.sha256()
    for path in runtime_files(root):
        digest.update(str(path.relative_to(root)).encode() + b'\0' + path.read_bytes() + b'\0')
    return digest.hexdigest()


def metadata(root=ROOT):
    source = (root / 'c2c-receipts.php').read_text()
    header = dict(re.findall(r'^ \* ([\w ]+): (.+)$', source, re.MULTILINE))
    readme = (root / 'readme.txt').read_text()
    top, description = readme.split('\n\n', 1)
    fields = dict(re.findall(r'^([\w ]+): (.+)$', top, re.MULTILINE))
    return source, header, readme, fields, description.splitlines()[0]


def audit(root=ROOT):
    errors = []
    source, header, readme, fields, description = metadata(root)
    for key in ('Plugin Name', 'Version', 'Author', 'Author URI', 'Requires at least', 'Requires PHP', 'Requires Plugins', 'License', 'License URI', 'Text Domain'):
        if not header.get(key):
            errors.append('Missing plugin header: ' + key)
    for key in ('Contributors', 'Tags', 'Stable tag', 'Requires at least', 'Tested up to', 'Requires PHP', 'License', 'License URI'):
        if not fields.get(key):
            errors.append('Missing readme header: ' + key)
    if not re.fullmatch(r'\d+\.\d+(?:\.\d+)?', fields.get('Tested up to', '')):
        errors.append('Readme Tested up to must contain a numeric WordPress version.')
    version = header.get('Version', '')
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        errors.append('Use a numeric three-part plugin version.')
    if version != fields.get('Stable tag') or version != json.loads((root / 'package.json').read_text())['version']:
        errors.append('Plugin, package and stable tag versions must match.')
    if f"define( 'C2CRECEIPTS_VERSION', '{version}' );" not in source:
        errors.append('Runtime version constant differs from the plugin header.')
    if header.get('Text Domain') != SLUG:
        errors.append('Text domain must match the intended directory slug.')
    if header.get('Author') != 'Hesam MM' or 'hesamst' not in fields.get('Contributors', '').split(','):
        errors.append('Keep author Hesam MM and contributor hesamst.')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+(?:,\s*[A-Za-z0-9_.-]+)*', fields.get('Contributors', '')):
        errors.append('Contributors must contain WordPress.org usernames, not display names.')
    if not 0 < len(description) <= 150:
        errors.append('Readme short description must be 1–150 characters.')
    if not 1 <= len(fields.get('Tags', '').split(',')) <= 5:
        errors.append('Readme must have at most five tags.')
    if header.get('Requires PHP') != fields.get('Requires PHP') or header.get('Requires at least') != fields.get('Requires at least'):
        errors.append('Readme and plugin minimum requirements differ.')
    license_text = (root / 'license.txt').read_text()
    if len(license_text) < 17000 or 'Version 2, June 1991' not in license_text or 'END OF TERMS AND CONDITIONS' not in license_text:
        errors.append('Include the full GPLv2 license, not only a short notice.')
    for section in ('Description', 'Installation', 'Privacy', 'Source code', 'Changelog'):
        if f'== {section} ==' not in readme:
            errors.append('Missing readme section: ' + section)
    if not readme.startswith('=== ' + header.get('Plugin Name', '') + ' ==='):
        errors.append('Readme and plugin display names differ.')
    for path in runtime_files(root):
        if not path.is_file():
            errors.append('Missing runtime/source file: ' + str(path))
        elif path.suffix == '.php' and "defined( 'ABSPATH' )" not in path.read_text() and path.name != 'uninstall.php':
            errors.append('Missing direct-access guard: ' + str(path))
    return errors


def validation_matrix(scope='release', root=ROOT):
    if scope not in ('routine', 'release'):
        raise ValueError('Validation scope must be routine or release.')
    config = json.loads((root / 'scripts/ci-matrix.json').read_text())
    branches = config['php'] if scope == 'release' else config['php'][-1:]
    latest = [dict(php=branch, hpos=hpos, profile='latest', wordpress='latest', woocommerce='latest')
              for branch in branches for hpos in ('yes', 'no')]
    minimum = [dict(config['minimum'], hpos=hpos, profile='minimum') for hpos in ('yes', 'no')]
    return latest + minimum


def validated_versions(directory, root=ROOT, scope='release'):
    records = [(path, json.loads(path.read_text())) for path in sorted(Path(directory).rglob('validation.json'))]
    matrix = validation_matrix(scope, root)
    expected = {(entry['php'], entry['hpos'], entry['profile']): entry for entry in matrix}
    seen = {}
    versions = set()
    woo = set()
    _, header, _, _, _ = metadata(root)
    digest = fingerprint(root)
    for path, record in records:
        if record.get('source_sha256') != digest or record.get('plugin_version') != header['Version']:
            raise ValueError('Validation evidence does not match this source/version.')
        if not all(record.get('checks', {}).get(name) is True for name in ('javascript', 'php_lint', 'storage_crypto', 'woocommerce', 'http')):
            raise ValueError('Runtime checks are incomplete or failed.')
        key = ('.'.join(record['php_version'].split('.')[:2]), record['hpos'], record['profile'])
        if record.get('requested_php', key[0]) != key[0] or record.get('requested_hpos', key[1]) != key[1]:
            raise ValueError(f'Requested and measured validation environments differ in {path}: {key!r}')
        if key not in expected:
            raise ValueError(f'Unexpected validation matrix record in {path}: {key!r}')
        if key in seen:
            raise ValueError(f'Unexpected or duplicate validation matrix record {key!r}: {seen[key]} and {path}')
        seen[key] = path
        if record['profile'] == 'latest':
            versions.add('.'.join(record['wordpress_version'].split('.')[:2]))
            woo.add(record['woocommerce_version'])
        elif record['wordpress_version'] != expected[key]['wordpress'] or record['woocommerce_version'] != expected[key]['woocommerce']:
            raise ValueError('Minimum-support validation ran against the wrong versions.')
    if set(seen) != set(expected) or len(versions) != 1:
        missing = sorted(set(expected) - set(seen))
        raise ValueError(f'All {len(expected)} matching {scope} matrix records are required; latest WordPress must agree. Missing: {missing!r}')
    tested = versions.pop()
    if not re.fullmatch(r'\d+\.\d+', tested):
        raise ValueError('A stable numeric WordPress version is required.')
    return tested, max(woo, key=lambda value: tuple(int(piece) for piece in value.split('.')))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--fingerprint', action='store_true')
    args = parser.parse_args()
    if args.fingerprint:
        print(fingerprint())
        sys.exit(0)
    problems = audit()
    if problems:
        print('\n'.join(problems), file=sys.stderr)
        sys.exit(1)
    print('Local directory metadata/source checks passed. Official Plugin Check and runtime release gates are separate.')
