#!/usr/bin/env python3
"""Refuse text about to be published that carries private review evidence.

Usage: check-public-text.py [NAME=]FILE [[NAME=]FILE...]

NAME is what a finding calls the file ("title", "body") when FILE is a temp file.

Exit 0: nothing found. Exit 3: findings, one per line on stderr as
"<file>:<line>: <id> — <why>". Exit 2: the check itself could not run —
never treated as clean (a gate that cannot check must not pass).
"""
import json
import os
import re
import sys

PATTERNS = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'private-evidence-patterns.json')


def main(paths):
    if not paths:
        print('usage: check-public-text.py [NAME=]FILE [[NAME=]FILE...]', file=sys.stderr)
        return 2
    try:
        with open(PATTERNS, encoding='utf-8') as handle:
            # MULTILINE: '^' still means "start of a line", but a match may now
            # span lines — Markdown renders `![a<newline>b](x.png)` as an image.
            patterns = [(p['id'], p['why'], re.compile(p['regex'], re.ASCII | re.MULTILINE)) for p in json.load(handle)['patterns']]
    except (OSError, ValueError, KeyError, re.error) as error:
        print(f'cannot read {PATTERNS}: {error}', file=sys.stderr)
        return 2

    found = 0
    for argument in paths:
        name, _, path = argument.partition('=') if '=' in argument and not argument.startswith('/') else (argument, '', argument)
        try:
            with open(path, encoding='utf-8', errors='replace') as handle:
                text = handle.read()
        except OSError as error:
            print(f'cannot read {path}: {error}', file=sys.stderr)
            return 2
        # The whole text, not line by line: an image split over two lines
        # matched neither line and was published. A finding names the line
        # its match starts on.
        hits = sorted(
            ((text.count('\n', 0, match.start()) + 1, order, pid, why)
             for order, (pid, why, regex) in enumerate(patterns)
             for match in regex.finditer(text)),
        )
        for number, _, pid, why in hits:
            print(f'{name}:{number}: {pid} — {why}', file=sys.stderr)
        found += len(hits)
    return 3 if found else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
