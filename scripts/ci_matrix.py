#!/usr/bin/env python3
"""Print the selected GitHub Actions compatibility matrix."""
import argparse
import json
from audit_release import validation_matrix

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--scope', choices=('routine', 'release'), default='release')
args = parser.parse_args()
print(json.dumps({'include': validation_matrix(args.scope)}, separators=(',', ':')))
