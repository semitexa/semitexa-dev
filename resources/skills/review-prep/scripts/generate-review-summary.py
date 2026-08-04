#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path


AREA_LABELS = {
    "src": "application",
    "tests": "tests",
    "docs": "documentation",
    "config": "configuration",
    "resources": "resources",
    "templates": "templates",
}

DOC_EXTENSIONS = {".md", ".mdx", ".rst", ".txt"}


def git(repo: str, *args: str) -> str:
    return subprocess.check_output(["git", "-C", repo, *args], text=True).strip()


def parse_name_status(raw: str) -> list[tuple[str, str]]:
    entries: list[tuple[str, str]] = []
    for line in raw.splitlines():
        if not line:
            continue
        parts = line.split("\t")
        if len(parts) < 2:
            continue
        status = parts[0].strip()
        path = parts[-1].strip()
        if path:
            entries.append((status, path))
    return entries


def classify(paths: list[str]) -> list[str]:
    areas: list[str] = []
    for name in paths:
        parts = Path(name).parts
        if not parts:
            continue
        first = parts[0]
        if first == "src" and len(parts) > 1:
            area = parts[1].replace("-", " ")
        else:
            area = AREA_LABELS.get(first, first.replace("-", " "))
        if area not in areas:
            areas.append(area)
    return areas


def all_docs(paths: list[str]) -> bool:
    if not paths:
        return False
    for name in paths:
        path = Path(name)
        if path.parts and path.parts[0] == "docs":
            continue
        if path.suffix.lower() in DOC_EXTENSIONS:
            continue
        if path.name.upper().startswith("README"):
            continue
        return False
    return True


def choose_verb(entries: list[tuple[str, str]]) -> str:
    paths = [path for _, path in entries]
    statuses = [status for status, _ in entries]

    if all_docs(paths):
        return "Document"

    if statuses and all(status.startswith("A") for status in statuses):
        return "Add"

    if statuses and all(status.startswith("D") for status in statuses):
        return "Remove"

    if any(status.startswith("R") for status in statuses):
        return "Refactor"

    if any(path.startswith("tests/") for path in paths) and not any(path.startswith("src/") for path in paths):
        return "Update"

    diff_text = ""
    try:
        diff_text = git(sys.argv[1], "diff", "--cached", "--unified=0", "--no-color")
    except subprocess.CalledProcessError:
        diff_text = ""

    lowered = diff_text.lower()
    fix_markers = ("fix", "bug", "error", "fail", "regression", "correct", "handle null", "prevent")
    add_markers = ("new ", "create", "introduce", "support ", "allow ", "enable ")
    refactor_markers = ("rename", "extract", "cleanup", "rework", "simplify", "restructure")

    if any(marker in lowered for marker in fix_markers):
        return "Fix"
    if any(marker in lowered for marker in add_markers) and any(status.startswith("A") for status in statuses):
        return "Add"
    if any(marker in lowered for marker in refactor_markers):
        return "Refactor"

    return "Update"


def summarize_subject(areas: list[str]) -> str:
    if not areas:
        return "review-ready changes"
    if len(areas) == 1:
        return areas[0]
    if len(areas) == 2:
        return f"{areas[0]} and {areas[1]}"
    return f"{areas[0]}, {areas[1]}, and related areas"


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: generate-review-summary.py /absolute/path/to/repo", file=sys.stderr)
        return 1

    repo = sys.argv[1]
    entries = parse_name_status(git(repo, "diff", "--cached", "--name-status"))
    paths = [path for _, path in entries]
    areas = classify(paths)
    verb = choose_verb(entries)
    subject = summarize_subject(areas)

    payload = {
        "areas": areas,
        "commit_message": f"{verb} {subject}",
        "pr_title": f"{verb} {subject}",
    }
    print(json.dumps(payload))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
