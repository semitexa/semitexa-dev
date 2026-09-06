#!/usr/bin/env python3
"""Turn `composer audit --format=json` into lines the release gate can act on.

Its own file, like the doctor reader beside it: a Python program quoted through
two levels of shell acquires syntax errors, and a reader that dies silently
turns its gate into a green light. A non-zero exit here means the gate must
treat the audit as NOT RUN.

MEASURED 2026-09-06 against the real command:
  network up, nothing found -> exit 0, {"advisories": [], "abandoned": [], ...}
  network down              -> exit 100, EMPTY stdout, error on stderr
The empty-stdout case is why parsing failure has to be loud: composer said
nothing, and nothing is not the same answer as none.
"""
import json
import sys

raw = sys.stdin.read().strip()
if raw == "":
    raise SystemExit("composer audit produced no output at all — it did not run")

report = json.loads(raw)

advisories = report.get("advisories", [])
# Composer keys advisories by package when there are any and emits a bare list
# when there are none; both shapes have been seen from the same binary.
if isinstance(advisories, dict):
    pairs = [(pkg, item) for pkg, items in advisories.items() for item in items]
else:
    pairs = [(item.get("packageName", "?"), item) for item in advisories]

counts = {}
lines = []
for package, item in pairs:
    severity = str(item.get("severity", "unknown")).lower()
    counts[severity] = counts.get(severity, 0) + 1
    lines.append((
        severity,
        "{} {} ({}): {}".format(
            package,
            item.get("cve") or item.get("advisoryId", "no id"),
            severity,
            str(item.get("title", ""))[:120],
        ),
    ))

abandoned = report.get("abandoned") or {}
print("COUNTS advisories={} abandoned={}".format(len(pairs), len(abandoned)))
for severity in sorted(counts):
    print("SEVERITY {}={}".format(severity, counts[severity]))

# low and unknown are reported, never blocking; the gate decides on the rest.
for severity, line in sorted(lines):
    print(("LOW " if severity in ("low", "unknown") else "BLOCK ") + line)
