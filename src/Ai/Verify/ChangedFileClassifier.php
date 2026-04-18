<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

/**
 * Pure path → kind classifier. Never reads the file — the point is for the
 * planner to decide lint scope from just a git diff name list, without
 * autoloading anything.
 *
 * The fragment table mirrors {@see \Semitexa\Dev\Ai\Similarity\SimilarityIndexBuilder}
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
            return ChangedFile::KIND_TEST;
        }
        foreach (self::PHP_FRAGMENT_RULES as $fragment => $kind) {
            if (str_contains($normalised, $fragment)) {
                return $kind;
            }
        }
        return ChangedFile::KIND_PHP_OTHER;
    }

    private function isTest(string $normalised): bool
    {
        if (str_contains($normalised, '/tests/')) {
            return true;
        }
        return str_ends_with($normalised, 'Test.php');
    }
}
