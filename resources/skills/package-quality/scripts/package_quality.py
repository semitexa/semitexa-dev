#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any


CURRENT_YEAR = datetime.now().year
DEFAULT_EXCLUDED_PACKAGES = {
    "semitexa-installer",
}
LICENSE_TEMPLATE = """MIT License

Copyright (c) {year} Semitexa Framework

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
"""


@dataclass
class Finding:
    status: str
    message: str


@dataclass
class PackageReport:
    name: str
    path: Path
    findings: list[Finding] = field(default_factory=list)

    def add(self, status: str, message: str) -> None:
        self.findings.append(Finding(status=status, message=message))

    def has_blockers(self) -> bool:
        return any(item.status in {"blocked", "manual-review"} for item in self.findings)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Audit and fix quality issues in Semitexa packages.")
    parser.add_argument("--root", default="packages", help="Packages root directory.")
    parser.add_argument(
        "--mode",
        choices=["audit", "fix"],
        default="audit",
        help="Whether to only report or to apply deterministic file fixes.",
    )
    parser.add_argument(
        "--check",
        choices=["all", "license", "metadata"],
        default="all",
        help="Subset of checks to run.",
    )
    parser.add_argument(
        "--package",
        action="append",
        default=[],
        help="Specific package directory name to inspect. Can be passed multiple times.",
    )
    parser.add_argument(
        "--exclude-package",
        action="append",
        default=[],
        help="Package directory name to exclude. Can be passed multiple times.",
    )
    parser.add_argument("--json", action="store_true", help="Emit machine-readable JSON.")
    return parser.parse_args()


def iter_packages(root: Path, selected: list[str], excluded: set[str]) -> list[Path]:
    if selected:
        packages = [root / name for name in selected]
    else:
        packages = sorted(path for path in root.iterdir() if path.is_dir())
    return [path for path in packages if path.exists() and path.name not in excluded]


def load_json(path: Path) -> dict[str, Any] | None:
    try:
        return json.loads(path.read_text())
    except FileNotFoundError:
        return None
    except json.JSONDecodeError as exc:
        raise ValueError(f"Invalid JSON in {path}: {exc}") from exc


def detect_domain(package_name: str) -> str:
    if package_name.startswith("semitexa-"):
        return package_name[len("semitexa-") :]
    return package_name


def suggested_keywords(package_name: str, composer: dict[str, Any]) -> list[str]:
    domain = detect_domain(package_name)
    tokens = re.split(r"[-_]+", domain)
    keywords = ["semitexa", *[token for token in tokens if token]]
    description = str(composer.get("description", "")).lower()

    capability_map = {
        "cache": ["redis", "tags", "tenancy"],
        "scheduler": ["cron", "jobs", "queue"],
        "mail": ["smtp", "twig", "attachments"],
        "storage": ["filesystem", "s3", "minio"],
        "auth": ["authentication", "sessions"],
        "authorization": ["policies", "permissions"],
        "rbac": ["roles", "permissions"],
        "orm": ["mysql", "schema", "attributes"],
        "ssr": ["twig", "rendering", "frontend"],
        "media": ["images", "uploads", "cdn"],
        "llm": ["ai", "assistant", "skills"],
        "docs": ["documentation", "guides", "architecture"],
    }

    for probe, words in capability_map.items():
        if probe in domain or probe in description:
            keywords.extend(words)

    deduped: list[str] = []
    seen: set[str] = set()
    for keyword in keywords:
        normalized = keyword.strip().lower()
        if not normalized or normalized in seen:
            continue
        deduped.append(normalized)
        seen.add(normalized)
    return deduped[:8]


def check_license(package: Path, mode: str, report: PackageReport) -> None:
    license_path = package / "LICENSE"
    if not license_path.exists():
        if mode == "fix":
            license_path.write_text(LICENSE_TEMPLATE.format(year=CURRENT_YEAR))
            report.add("fixed", "LICENSE was missing and was created.")
        else:
            report.add("blocked", "LICENSE is missing.")
        return

    content = license_path.read_text()
    match = re.search(r"Copyright \(c\) (\d{4}) Semitexa Framework", content)
    if not match:
        report.add("manual-review", "LICENSE exists but does not match the expected Semitexa MIT header.")
        return

    year = int(match.group(1))
    if year == CURRENT_YEAR:
        report.add("ok", f"LICENSE year is current ({CURRENT_YEAR}).")
        return

    if mode == "fix":
        updated = re.sub(
            r"Copyright \(c\) \d{4} Semitexa Framework",
            f"Copyright (c) {CURRENT_YEAR} Semitexa Framework",
            content,
            count=1,
        )
        license_path.write_text(updated)
        report.add("fixed", f"LICENSE year updated from {year} to {CURRENT_YEAR}.")
    else:
        report.add("blocked", f"LICENSE year is stale ({year}); expected {CURRENT_YEAR}.")


def check_metadata(package: Path, mode: str, report: PackageReport) -> None:
    composer_path = package / "composer.json"
    composer = load_json(composer_path)
    if composer is None:
        report.add("blocked", "composer.json is missing.")
        return

    changed = False
    description = str(composer.get("description", "")).strip()
    if not description:
        report.add("blocked", "composer.json is missing a non-empty description.")
    else:
        report.add("ok", "composer.json contains a description.")

    keywords = composer.get("keywords")
    package_name = package.name
    suggested = suggested_keywords(package_name, composer)
    if isinstance(keywords, list) and all(isinstance(item, str) and item.strip() for item in keywords):
        normalized = [item.strip().lower() for item in keywords]
        report.add("ok", f"composer.json contains keywords: {', '.join(normalized)}")
    else:
        if mode == "fix" and description:
            composer["keywords"] = suggested
            changed = True
            report.add("fixed", f"composer.json keywords were added: {', '.join(suggested)}")
        else:
            report.add("manual-review", f"composer.json is missing keywords. Suggested: {', '.join(suggested)}")

    if changed:
        composer_path.write_text(json.dumps(composer, indent=4, ensure_ascii=True) + "\n")


def format_text_report(reports: list[PackageReport]) -> str:
    lines: list[str] = []
    for report in reports:
        lines.append(f"[{report.name}]")
        if not report.findings:
            lines.append("  - ok: no findings")
            continue
        for finding in report.findings:
            lines.append(f"  - {finding.status}: {finding.message}")
    return "\n".join(lines)


def format_json_report(reports: list[PackageReport]) -> str:
    payload = []
    for report in reports:
        payload.append(
            {
                "package": report.name,
                "path": str(report.path),
                "findings": [{"status": item.status, "message": item.message} for item in report.findings],
            }
        )
    return json.dumps(payload, indent=2, ensure_ascii=True)


def main() -> int:
    args = parse_args()
    root = Path(args.root)
    if not root.exists():
        print(f"Packages root does not exist: {root}", file=sys.stderr)
        return 1

    excluded = set(DEFAULT_EXCLUDED_PACKAGES)
    excluded.update(args.exclude_package)

    reports: list[PackageReport] = []
    for package in iter_packages(root, args.package, excluded):
        report = PackageReport(name=package.name, path=package)
        if args.check in {"all", "license"}:
            check_license(package, args.mode, report)
        if args.check in {"all", "metadata"}:
            check_metadata(package, args.mode, report)
        reports.append(report)

    output = format_json_report(reports) if args.json else format_text_report(reports)
    print(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
