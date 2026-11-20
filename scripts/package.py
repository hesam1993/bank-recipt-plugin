#!/usr/bin/env python3
"""Package only runtime files; no dependencies or secrets enter the ZIP."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import re
import argparse
from audit_release import audit, runtime_files, validated_versions

root = Path(__file__).resolve().parents[1]
version = re.search(r"^ \* Version: ([0-9.]+)$", (root / "c2c-receipts.php").read_text(), re.MULTILINE).group(1)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--wordpress-org', action='store_true', help='Require complete matching runtime evidence and add verified compatibility metadata.')
parser.add_argument('--validation-dir', type=Path)
args = parser.parse_args()
problems = audit(root)
if problems:
    raise SystemExit('\n'.join(problems))
overlays = {}
if args.wordpress_org:
    if not args.validation_dir:
        raise SystemExit('WordPress.org packaging requires --validation-dir with passing runtime records. No tested versions will be invented.')
    tested, woo = validated_versions(args.validation_dir, root)
    readme = (root / 'readme.txt').read_text()
    readme = re.sub(r'^Tested up to: .+\n', '', readme, flags=re.MULTILINE)
    readme = readme.replace('Requires at least: 7.0\n', f'Requires at least: 7.0\nTested up to: {tested}\n', 1)
    main = (root / 'c2c-receipts.php').read_text()
    main = main.replace(' * WC requires at least: 11.0\n', f' * WC requires at least: 11.0\n * WC tested up to: {woo}\n', 1)
    overlays = {'readme.txt': readme.encode(), 'c2c-receipts.php': main.encode()}
suffix = '' if args.wordpress_org else '-review-candidate'
output = root / 'dist' / f'c2c-receipts-{version}{suffix}.zip'
output.parent.mkdir(exist_ok=True)
files = runtime_files(root)
with ZipFile(output, "w", ZIP_DEFLATED) as archive:
    for path in sorted(files):
        relative = str(path.relative_to(root))
        archive.writestr('c2c-receipts/' + relative, overlays.get(relative, path.read_bytes()))
print(output)
