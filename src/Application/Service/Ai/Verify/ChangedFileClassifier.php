<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Pure path → kind classifier. Never reads the file — the point is for the
 * planner to decide lint scope from just a git diff name list, without
 * autoloading anything.
 *
 * The fragment table mirrors {@see \Semitexa\Dev\Application\Service\Ai\Similarity\SimilarityIndexBuilder}
 * so the two stay aligned: if the similarity index recognises a path, the
 * verifier does too.
 */
final class ChangedFileClassifier
{
    private const PHP_FRAGMENT_RULES = [
        '/Application/Handler/PayloadHandler/' => ChangedFile::KIND_HANDLER,
        '/Application/Handler/DomainListener/' => ChangedFile::KIND_LISTENER,
        '/Application/Payload/Request/'        => ChangedFile::KIND_PAYLOAD,
        '/Application/Resource/Response/'      => ChangedFile::KIND_RESOURCE,
        '/Domain/Service/'                     => ChangedFile::KIND_SERVICE,
        '/Domain/Contract/'                    => ChangedFile::KIND_CONTRACT,
    ];

    /**
     * Path-segment names that mark a sub-tree of `tests/` as fixture /
     * stub / support code. A `.php` file under any of these — at any
     * depth below `/tests/` — is classified as
     * {@see ChangedFile::KIND_TEST_FIXTURE} regardless of its own
     * filename. The planner uses that to skip direct `phpunit`
     * invocation for files that are not real PHPUnit cases.
     */
    private const FIXTURE_DIR_SEGMENTS = [
        '/Fixtures/',
        '/Fixture/',
        '/Stubs/',
        '/Stub/',
        '/Support/',
        '/Helpers/',
        '/Helper/',
        '/Traits/',
        '/Builders/',
        '/Builder/',
        '/Doubles/',
        '/Mocks/',
    ];

    public function classify(string $path, string $status = ChangedFile::STATUS_MODIFIED): ChangedFile
    {
        $kind = $this->resolveKind($path);
        return new ChangedFile($path, $kind, $status);
    }

    private function resolveKind(string $path): string
    {
        $normalised = '/' . ltrim($path, '/');

        if (str_ends_with($path, '.twig')) {
            return ChangedFile::KIND_TEMPLATE;
        }
        if (!str_ends_with($path, '.php')) {
            return ChangedFile::KIND_NON_PHP;
        }
        if ($this->isTest($normalised)) {
            return $this->isFixtureLike($normalised)
                ? ChangedFile::KIND_TEST_FIXTURE
                : ChangedFile::KIND_TEST;
        }
        foreach (self::PHP_FRAGMENT_RULES as $fragment => $kind) {
            if (str_contains($normalised, $fragment)) {
                return $kind;
            }
        }
        return ChangedFile::KIND_PHP_OTHER;
    }

    /**
     * A path is "test-tree" if it lives under `/tests/` OR if its
     * basename ends in `Test.php`. Both branches still land in the
     * test family; the fixture sub-classification narrows it.
     */
    private function isTest(string $normalised): bool
    {
        if (str_contains($normalised, '/tests/')) {
            return true;
        }
        return str_ends_with($normalised, 'Test.php');
    }

    /**
     * Within a `/tests/` tree, files that do NOT end in `Test.php`
     * AND that live under a fixture-like sub-directory (Fixtures/,
     * Stubs/, Support/, Helpers/, Traits/, Builders/, Doubles/,
     * Mocks/, …) are fixtures. Files that DO end in `Test.php` are
     * always real test classes regardless of where they live.
     *
     * The pure-path basis is intentional: the classifier never reads
     * the file, so `class extends TestCase` is not consulted. The
     * filename + segment check is conservative — it would only
     * misclassify a `*Test.php` placed inside a `Fixtures/` directory,
     * which would be a project convention violation in any case.
     */
    private function isFixtureLike(string $normalised): bool
    {
        if (str_ends_with($normalised, 'Test.php')) {
            return false;
        }
        foreach (self::FIXTURE_DIR_SEGMENTS as $segment) {
            if (str_contains($normalised, $segment)) {
                return true;
            }
        }
        // Any other `.php` file under `/tests/` whose basename does
        // NOT end in `Test.php` is also support code (TestCase base
        // classes, abstract test scaffolding, helper traits placed
        // directly under tests/Unit/, …) — treat as fixture so the
        // planner doesn't try to phpunit-invoke it.
        return str_contains($normalised, '/tests/');
    }
}
