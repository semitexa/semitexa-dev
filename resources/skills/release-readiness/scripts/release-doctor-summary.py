#!/usr/bin/env python3
"""Turn a `system:doctor --json` report into lines the release gate can act on.

Its own file rather than a heredoc inside the shell script: quoting a Python
program through two levels of shell is how the reader acquired a syntax error
that made the gate pass silently. A non-zero exit here is the gate's signal that
nothing was checked.
"""
import json
import sys

report = json.load(sys.stdin)
checks = report.get("checks", [])
if not isinstance(checks, list):
    raise SystemExit("doctor report has no checks list")

counts = {"pass": 0, "warn": 0, "fail": 0, "skip": 0}
lines = []

for check in checks:
    status = str(check.get("status", "skip"))
    counts[status] = counts.get(status, 0) + 1
    if status in ("warn", "fail"):
        name = check.get("name", "?")
        package = check.get("package", "?")
        message = check.get("message", "")
        lines.append((status.upper(), "{} ({}): {}".format(name, package, message)))

print("COUNTS " + " ".join("{}={}".format(k, v) for k, v in counts.items()))
for status, line in sorted(lines):
    print(status + " " + line)
