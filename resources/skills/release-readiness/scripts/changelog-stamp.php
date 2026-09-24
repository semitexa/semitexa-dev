<?php

declare(strict_types=1);

/**
 * Turn a package's `## Unreleased` section into the section of the release
 * being cut.
 *
 * Authors write consumer-visible changes under `## Unreleased` on develop; the
 * tagger renames that heading to `## <version> — <date>` in the release commit
 * it already makes for floors. Until it did, nothing wrote the heading at all:
 * core's Content-Type entry shipped in 2026.09.23.1717 and 2026.09.24.1147
 * still labelled Unreleased, and PackageChangelogReader::notesBetween() skips
 * that label, so nobody upgrading was shown it.
 *
 * The heading is renamed, not copied under a fresh empty `## Unreleased`: the
 * reader turns every heading into a note, and an empty one would surface in
 * "What's new" as a release with nothing in it. A package with no Unreleased
 * section, or an empty one, gets no entry — the honest answer for a release
 * that changed nothing a consumer can see.
 *
 * Pure functions only; bump-packages.php does the git.
 */

const CHANGELOG_FILE = 'CHANGELOG.md';

/**
 * The `## Unreleased` heading line, matched the way PackageChangelogReader
 * reads headings (level two exactly, case-insensitive label).
 */
const CHANGELOG_UNRELEASED_HEADING = '/^##[ \t]+Unreleased[ \t]*$/mi';

/**
 * Whether the changelog has an Unreleased section with something in it.
 */
function changelogHasUnreleasedEntry(string $markdown): bool
{
    return unreleasedSectionBody($markdown) !== null;
}

/**
 * The changelog with its Unreleased heading renamed to the release, or null
 * when there is nothing to stamp.
 *
 * The date comes from the version (YYYY.MM.DD.hhmm), so the heading names the
 * day the release says it is, whatever clock the tagger runs on.
 */
function stampUnreleasedChangelog(string $markdown, string $releaseVersion): ?string
{
    if (unreleasedSectionBody($markdown) === null) {
        return null;
    }

    $heading = '## ' . $releaseVersion . ' — ' . changelogDateOf($releaseVersion);

    return (string) preg_replace(CHANGELOG_UNRELEASED_HEADING, $heading, $markdown, 1);
}

function changelogDateOf(string $releaseVersion): string
{
    if (preg_match('/^(\d{4})\.(\d{2})\.(\d{2})\.\d{4}$/', $releaseVersion, $m) !== 1) {
        throw new InvalidArgumentException("Not a release version: {$releaseVersion}");
    }

    return "{$m[1]}-{$m[2]}-{$m[3]}";
}

/**
 * The trimmed body of the Unreleased section, or null when there is no such
 * section or it holds nothing but whitespace.
 */
function unreleasedSectionBody(string $markdown): ?string
{
    if (preg_match(CHANGELOG_UNRELEASED_HEADING, $markdown, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $start = $match[0][1] + strlen($match[0][0]);
    $rest = substr($markdown, $start);
    // The section ends at the next level-two heading; `###` subsections belong to it.
    $end = preg_match('/^##(?!#)/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1 ? $next[0][1] : strlen($rest);
    $body = trim(substr($rest, 0, $end));

    return $body === '' ? null : $body;
}
