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
 *  1. a payload handler (`#[AsPayloadHandler(payload: X::class)]`, or `X::class`
 *     as its first argument) whose payload is public and states no `reason`;
 *  2. a line in that handler reading who is signed in: `isGuest()`,
 *     `getUser()`, `isAuthenticated()` or the session's `AuthSessionSegment`.
 *
 * Public pages read identity for good reasons too — a sign-in page greets a
 * returning visitor, a landing page shows a menu. Those say so once, in the
 * attribute (`reason: '...'`), and stay quiet here. A slot handler is never
 * read: a user menu on a public page is the normal case, not this one.
 *
 * The payload's route is read by reflection, not from text, because the route
 * attribute lives in another file. A handler that reads identity and whose
 * payload cannot be examined (not resolvable from the file, not autoloadable,
 * its attribute throwing) is NOT cleared: it is reported as such, because a
 * lint that cannot read what it checks must say so rather than print [OK].
 * Only a payload that loads and carries no route attribute is passed quietly.
 */
final class PublicPageReadsIdentityDetector implements MechanismDetectorInterface
{
    private const IDENTITY_READS = [
        '->isGuest(' => 'isGuest()',
        '->getUser(' => 'getUser()',
        '->isAuthenticated(' => 'isAuthenticated()',
        'AuthSessionSegment' => 'AuthSessionSegment',
    ];

    private const CAPABILITY = 'authorization.protected-page';

    /** @var \Closure(string): ?AbstractPayloadRoute */
    private \Closure $routeOf;

    /**
     * @param (\Closure(string): ?AbstractPayloadRoute)|null $routeOf the route
     *        attribute of a payload class, null when the class has none; throws
     *        when the class cannot be examined. Reflection by default.
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
        $source = implode("\n", $lines);
        $arguments = self::handlerArguments($source);
        if ($arguments === null) {
            return [];
        }

        $read = self::firstIdentityRead($lines);
        if ($read === null) {
            return [];
        }
        [$line, $name] = $read;

        $payload = self::handledPayload($arguments, $source);
        if ($payload === null) {
            return [self::notCleared($file, $line, $name, 'its payload', 'the payload class is not named as X::class in #[AsPayloadHandler]')];
        }

        try {
            $route = ($this->routeOf)($payload);
        } catch (\Throwable $e) {
            return [self::notCleared($file, $line, $name, self::shortName($payload), $e->getMessage())];
        }

        if ($route === null
            || $route->getAccessType() !== PayloadAccessType::Public
            || trim((string) ($route->reason ?? '')) !== '') {
            return [];
        }

        return [new MechanismFinding(
            file: $file,
            line: $line,
            capabilityId: self::CAPABILITY,
            evidence: sprintf(
                'the handler of public %s (%s) reads %s at line %d; a page for signed-in visitors only is #[AsProtectedPayload], a public one that must read it says why with reason: \'...\'',
                self::shortName($payload),
                $route->path ?? 'no path',
                $name,
                $line,
            ),
        )];
    }

    /**
     * The argument list of the file's #[AsPayloadHandler(...)], or null when it
     * declares none.
     */
    private static function handlerArguments(string $source): ?string
    {
        if (preg_match('/#\[\s*\\\\?(?:[\w\\\\]*\\\\)?AsPayloadHandler\s*\((.*?)\)\s*\]/s', $source, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * The first line that reads who is signed in, skipping imports and comments.
     *
     * @param list<string> $lines
     * @return array{int, string}|null line number (1-based) and what it reads
     */
    private static function firstIdentityRead(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, 'use ') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '#') && !str_starts_with($trimmed, '#[')
                || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            foreach (self::IDENTITY_READS as $needle => $name) {
                if (str_contains($line, $needle)) {
                    return [$index + 1, $name];
                }
            }
        }

        return null;
    }

    /**
     * The payload class the handler declares, named (`payload: X::class`) or
     * first positional, resolved through the file's namespace and imports.
     */
    private static function handledPayload(string $arguments, string $source): ?string
    {
        $class = '(\\\\?[A-Za-z_][\w\\\\]*)::class';
        if (preg_match('/\bpayload\s*:\s*' . $class . '/', $arguments, $m) !== 1
            && preg_match('/^\s*' . $class . '\s*(?:,|$)/', $arguments, $m) !== 1) {
            return null;
        }
        $name = $m[1];

        // PHP's own rules: a leading \ is fully qualified; otherwise the first
        // segment is looked up in the imports, and failing that the name is
        // relative to the file's namespace (Sub\P in App\X is App\X\Sub\P).
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = str_contains($name, '\\') ? (string) strstr($name, '\\', true) : $name;
        $rest = str_contains($name, '\\') ? (string) strstr($name, '\\') : '';
        $imports = self::imports($source);
        if (isset($imports[$first])) {
            return $imports[$first] . $rest;
        }

        return preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $ns) === 1 ? $ns[1] . '\\' . $name : $name;
    }

    /**
     * Class imports of the file, plain and grouped (`use A\{B, C as D};`);
     * `use function` and `use const` are not classes and are left out.
     *
     * @return array<string, string> alias => fully qualified name
     */
    private static function imports(string $source): array
    {
        $imports = [];
        preg_match_all('/^\s*use\s+(?!function\b|const\b)([^;{]+?)(?:\{([^}]*)\})?\s*;/m', $source, $all, PREG_SET_ORDER);
        foreach ($all as $use) {
            $prefix = trim($use[1]);
            $members = isset($use[2]) && $use[2] !== ''
                ? array_map(static fn (string $member): string => rtrim($prefix, '\\') . '\\' . trim($member), explode(',', $use[2]))
                : explode(',', $prefix);
            foreach ($members as $member) {
                if (preg_match('/^\\\\?([\w\\\\]+?)(?:\s+as\s+(\w+))?$/', trim($member), $parts) !== 1) {
                    continue;
                }
                $fqcn = $parts[1];
                $alias = ($parts[2] ?? '') !== '' ? $parts[2] : self::shortName($fqcn);
                $imports[$alias] = $fqcn;
            }
        }

        return $imports;
    }

    private static function notCleared(string $file, int $line, string $read, string $payload, string $why): MechanismFinding
    {
        return new MechanismFinding(
            file: $file,
            line: $line,
            capabilityId: self::CAPABILITY,
            evidence: sprintf(
                'the handler reads %s at line %d and %s could not be examined (%s), so this page is not cleared: if it is public, make it #[AsProtectedPayload] or state reason: \'...\'',
                $read,
                $line,
                $payload,
                $why,
            ),
        );
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /**
     * The route attribute of a payload class by reflection.
     *
     * Null only when the class loads and carries no route attribute. A class
     * that does not autoload, or whose attribute throws on instantiation,
     * throws here: the caller reports the page as not cleared.
     */
    private static function reflectRoute(string $class): ?AbstractPayloadRoute
    {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('class %s does not autoload', $class));
        }
        $attributes = (new \ReflectionClass($class))
            ->getAttributes(AbstractPayloadRoute::class, \ReflectionAttribute::IS_INSTANCEOF);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
