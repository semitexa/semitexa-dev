<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Auth\PayloadAccessType;

/**
 * Finds a public page whose handler decides by itself who may see it.
 *
 * The Guard run (2026-11-08) left `/guard/profile` as `#[AsPublicPayload]`; its
 * handler asked `isGuest()` and rendered a "please sign in" card. A guest got
 * 200 and a page, nothing refused anything, and no check said so. What the
 * framework has for that is `#[AsProtectedPayload]`: a guest is refused before
 * the handler runs (and, with authentication on, before hydration).
 *
 * The shape, both halves required:
 *
 *  1. a payload handler (`#[AsPayloadHandler(payload: X::class)]`) whose payload
 *     is public and states no `reason`;
 *  2. a line in that handler reading who is signed in: `isGuest()`,
 *     `getUser()`, `isAuthenticated()` or the session's `AuthSessionSegment`.
 *
 * Public pages read identity for good reasons too — a sign-in page greets a
 * returning visitor, a landing page shows a menu. Those say so once, in the
 * attribute (`reason: '...'`), and stay quiet here. A slot handler is never
 * read: a user menu on a public page is the normal case, not this one.
 *
 * The payload's route is read by reflection, not from text, because the route
 * attribute lives in another file; a payload class that cannot be loaded gives
 * no finding (the quieter error, as everywhere in this lint).
 */
final class PublicPageReadsIdentityDetector implements MechanismDetectorInterface
{
    private const IDENTITY_READS = [
        '->isGuest(' => 'isGuest()',
        '->getUser(' => 'getUser()',
        '->isAuthenticated(' => 'isAuthenticated()',
        'AuthSessionSegment' => 'AuthSessionSegment',
    ];

    /** @var \Closure(class-string): ?AbstractPayloadRoute */
    private \Closure $routeOf;

    /**
     * @param (\Closure(class-string): ?AbstractPayloadRoute)|null $routeOf the route
     *        attribute of a payload class; reflection by default, a table in tests
     */
    public function __construct(?\Closure $routeOf = null)
    {
        $this->routeOf = $routeOf ?? self::reflectRoute(...);
    }

    /** @return non-empty-list<string> */
    public function extensions(): array
    {
        return ['php'];
    }

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array
    {
        $payload = self::handledPayload($lines);
        if ($payload === null) {
            return [];
        }

        $route = ($this->routeOf)($payload);
        if ($route === null
            || $route->getAccessType() !== PayloadAccessType::Public
            || trim((string) ($route->reason ?? '')) !== '') {
            return [];
        }

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, 'use ') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            foreach (self::IDENTITY_READS as $needle => $name) {
                if (!str_contains($line, $needle)) {
                    continue;
                }

                return [new MechanismFinding(
                    file: $file,
                    line: $index + 1,
                    capabilityId: 'authorization.protected-page',
                    evidence: sprintf(
                        'the handler of public %s (%s) reads %s at line %d; a page for signed-in visitors only is #[AsProtectedPayload], a public one that must read it says why with reason: \'...\'',
                        self::shortName($payload),
                        $route->path ?? 'no path',
                        $name,
                        $index + 1,
                    ),
                )];
            }
        }

        return [];
    }

    /**
     * The payload class a handler file declares it handles, resolved through
     * the file's namespace and `use` imports.
     *
     * @param list<string> $lines
     * @return class-string|null
     */
    private static function handledPayload(array $lines): ?string
    {
        $source = implode("\n", $lines);
        if (preg_match('/#\[\s*AsPayloadHandler\s*\([^\]]*?\bpayload\s*:\s*\\\\?([A-Za-z_][\w\\\\]*)::class/s', $source, $m) !== 1) {
            return null;
        }
        $name = $m[1];

        if (str_contains($name, '\\')) {
            $first = strstr($name, '\\', true);
            $imported = self::imports($source)[$first] ?? null;
            $class = $imported !== null ? $imported . strstr($name, '\\') : $name;
        } else {
            $class = self::imports($source)[$name]
                ?? (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $ns) === 1 ? $ns[1] . '\\' . $name : $name);
        }

        /** @var class-string $class */
        return ltrim($class, '\\');
    }

    /** @return array<string, string> alias => fully qualified name */
    private static function imports(string $source): array
    {
        preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $all, PREG_SET_ORDER);
        $imports = [];
        foreach ($all as $use) {
            $fqcn = ltrim($use[1], '\\');
            $alias = ($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\' . $fqcn, '\\'), 1);
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /** @param class-string $class */
    private static function reflectRoute(string $class): ?AbstractPayloadRoute
    {
        try {
            if (!class_exists($class)) {
                return null;
            }
            $attributes = (new \ReflectionClass($class))
                ->getAttributes(AbstractPayloadRoute::class, \ReflectionAttribute::IS_INSTANCEOF);

            return $attributes === [] ? null : $attributes[0]->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }
}
