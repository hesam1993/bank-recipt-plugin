#!/usr/bin/env python3
"""Compile a gettext PO/MO catalogue using only Python's standard library."""
import json
import re
import struct
from pathlib import Path
from audit_release import SLUG

root = Path(__file__).resolve().parents[1]
catalogue = json.loads((root / 'languages/fa_IR.json').read_text())
version = json.loads((root / 'package.json').read_text())['version']
sources = [root / 'c2c-receipts.php', root / 'uninstall.php', *sorted((root / 'includes').glob('*.php'))]
messages = set()
for path in sources:
    # Escaped pairs and ordinary characters must not both consume a backslash.
    raw_messages = re.findall(r"(?:__|esc_html__|esc_attr__)\(\s*'((?:\\.|[^'\\])*)',\s*'" + re.escape(SLUG) + "'", path.read_text())
    messages.update(re.sub(r"\\([\\'])", lambda match: match.group(1), text) for text in raw_messages)
missing = messages - catalogue.keys()
if missing:
    raise SystemExit('Missing Persian translations: ' + repr(sorted(missing)))
for message in messages:
    if sorted(re.findall(r'%\d*\$?[sdf]', message)) != sorted(re.findall(r'%\d*\$?[sdf]', catalogue[message])):
        raise SystemExit('Translation placeholders differ: ' + message)
header = (f'Project-Id-Version: {SLUG} {version}\nLanguage: fa_IR\n'
          'Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n'
          'Plural-Forms: nplurals=2; plural=(n > 1);\n')
entries = {'': header, **{key: catalogue[key] for key in sorted(messages)}}
po = ['# Persian translation for Card to Card Receipts.']
for key, value in entries.items():
    po.extend(['', 'msgid ' + json.dumps(key, ensure_ascii=False), 'msgstr ' + json.dumps(value, ensure_ascii=False)])
(root / f'languages/{SLUG}-fa_IR.po').write_text('\n'.join(po) + '\n')
entries = sorted(entries.items())
keys = [key.encode() + b'\0' for key, _ in entries]
values = [value.encode() + b'\0' for _, value in entries]
start = 28 + len(entries) * 16
offset = start
key_table = []
for value in keys:
    key_table.append(struct.pack('<II', len(value) - 1, offset))
    offset += len(value)
value_table = []
for value in values:
    value_table.append(struct.pack('<II', len(value) - 1, offset))
    offset += len(value)
binary = struct.pack('<7I', 0x950412DE, 0, len(entries), 28, 28 + len(entries) * 8, 0, 0)
binary += b''.join(key_table + value_table + keys + values)
(root / f'languages/{SLUG}-fa_IR.mo').write_bytes(binary)
print(f'Compiled {len(messages)} Persian translations.')
