<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

/**
 * What a value given to a generator may be, and how it is written into the
 * file it lands in.
 *
 * A generator option is text from outside: a command line, or an MCP client
 * (semitexa_generate passes option values through as they came). The templates
 * used to take it by plain substitution, so
 * `--command-name="a')] final class X {} system('…'); __halt_compiler();//"`
 * rendered a file with the caller's PHP at the top level, `php -l` accepted
 * it, and the console reflects every #[AsCommand] class at boot: the next
 * `bin/semitexa` call ran it.
 *
 * Two answers, both needed:
 *
 * - {@see problem()} / {@see valid()}: a value is checked by what it MEANS
 *   before anything renders. A class name is a PHP identifier, a route path is
 *   a URL path, a command name is a command name. Placeholders that sit where
 *   PHP reads code (a class name, a namespace, a `use`, a docblock) cannot be
 *   encoded, so for them this check is the whole defence.
 * - {@see phpString()} / {@see twigString()}: a value that lands inside a
 *   string literal is written AS the literal, so one that passed its check, or
 *   free text that has none (a description), is still only ever data.
 *
 * {@see GenerationPreflight} applies the rules to the options by name, so a
 * refusal reaches the caller in its own format; the plan builders apply them
 * again to what they render, because they are callable without a command.
 */
final class GenerationInput
{
    /** A name a class is made from: `ImportUsers`, `import-users`, `import_users`, `import users`. */
    public const NAME = 'name';
    /** A PHP identifier: what a derived class or module name must be. */
    public const IDENTIFIER = 'identifier';
    /** A class name, namespaced or not. */
    public const CLASS_NAME = 'class';
    /** {@see self::CLASS_NAME} or {@see self::NAME}: an option that takes a class or the short name of one. */
    public const CLASS_OR_NAME = 'class_or_name';
    public const COMMAND_NAME = 'command_name';
    public const ROUTE_PATH = 'route_path';
    public const HTTP_METHOD = 'http_method';
    public const PERMISSION = 'permission';
    public const HANDLE = 'handle';
    public const TEMPLATE = 'template';
    public const GRAPHQL_FIELD = 'graphql_field';
    /** A form field name, as make:form's --fields takes it. */
    public const FIELD_NAME = 'field_name';

    private const IDENT = '[A-Za-z_][A-Za-z0-9_]*';
    private const WORDS = '[A-Za-z][A-Za-z0-9_ -]*';

    /** rule => [pattern, what a valid value is] */
    private const RULES = [
        self::NAME => [
            '/\A' . self::WORDS . '\z/',
            'a name of letters and digits that starts with a letter (words may be joined by "-", "_" or a space)',
        ],
        self::IDENTIFIER => [
            '/\A' . self::IDENT . '\z/',
            'a PHP identifier: letters, digits and "_", not starting with a digit',
        ],
        self::CLASS_NAME => [
            '/\A\\\\?' . self::IDENT . '(?:\\\\' . self::IDENT . ')*\z/',
            'a class name: PHP identifiers joined by "\\"',
        ],
        self::CLASS_OR_NAME => [
            '/\A(?:\\\\?' . self::IDENT . '(?:\\\\' . self::IDENT . ')*|' . self::WORDS . ')\z/',
            'a class name (PHP identifiers joined by "\\") or the short name of one',
        ],
        self::COMMAND_NAME => [
            '/\A[A-Za-z0-9][A-Za-z0-9_.-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)*\z/',
            'a command name such as users:import: letters, digits, "-", "_" and ".", in parts joined by ":"',
        ],
        // `env::NAME::/default` is the route attribute's own form for a path read from the environment.
        self::ROUTE_PATH => [
            '/\A(?:env::[A-Za-z_][A-Za-z0-9_]*::)?\/[A-Za-z0-9_.~\/{}-]*\z/',
            'a URL path that starts with "/": letters, digits, "-", "_", ".", "~", "/" and {parameters}',
        ],
        self::HTTP_METHOD => [
            '/\A(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\z/i',
            'one HTTP method: GET, POST, PUT, PATCH, DELETE, HEAD or OPTIONS',
        ],
        self::PERMISSION => [
            '/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/',
            'a permission name: letters, digits, "-", "_", "." and ":"',
        ],
        self::HANDLE => [
            '/\A[A-Za-z0-9_][A-Za-z0-9_.:-]*\z/',
            'a handle: letters, digits, "-", "_", "." and ":"',
        ],
        self::TEMPLATE => [
            '/\A(?!.*\.\.)@?[A-Za-z0-9_][A-Za-z0-9_.\/-]*\z/',
            'a template name such as @project-layouts-Shop/pages/cart.html.twig: letters, digits, "-", "_", "." and "/", without ".."',
        ],
        // Blank is "not given": the generator leaves the field to derive.
        self::GRAPHQL_FIELD => [
            '/\A\s*(?:[_A-Za-z][_0-9A-Za-z]*)?\s*\z/',
            'a GraphQL name: letters, digits and "_", not starting with a digit',
        ],
        self::FIELD_NAME => [
            '/\A[a-z][A-Za-z0-9]*\z/',
            'a field name: letters and digits, starting with a lowercase letter',
        ],
    ];

    /** option => rule, for every make:* option whose value reaches generated code. Free text (description, nav, submit-text) has no rule: it is encoded. */
    private const OPTION_RULES = [
        'module' => self::NAME,
        'name' => self::NAME,
        'implementation' => self::NAME,
        'payload' => self::NAME,
        'resource' => self::NAME,
        'response' => self::NAME,
        'event' => self::CLASS_OR_NAME,
        'model' => self::CLASS_OR_NAME,
        'command-name' => self::COMMAND_NAME,
        'path' => self::ROUTE_PATH,
        'method' => self::HTTP_METHOD,
        'permission' => self::PERMISSION,
        'handle' => self::HANDLE,
        'template' => self::TEMPLATE,
        'layout' => self::TEMPLATE,
        'graphql-field' => self::GRAPHQL_FIELD,
    ];

    /** The rule a make:* option's value is held to, null when it has none. */
    public static function ruleForOption(string $option): ?string
    {
        return self::OPTION_RULES[$option] ?? null;
    }

    /** What $value would have to be, when it is not that; null when it is valid. */
    public static function problem(string $rule, string $value): ?string
    {
        $definition = self::RULES[$rule] ?? null;
        if ($definition === null) {
            throw new \LogicException(sprintf('No generation input rule "%s".', $rule));
        }

        return preg_match($definition[0], $value) === 1 ? null : $definition[1];
    }

    /**
     * $value, or a refusal naming $what it was given as.
     *
     * @throws \InvalidArgumentException
     */
    public static function valid(string $rule, string $value, string $what): string
    {
        $problem = self::problem($rule, $value);
        if ($problem !== null) {
            throw new \InvalidArgumentException(sprintf('%s %s is refused: it must be %s.', $what, self::shown($value), $problem));
        }

        return $value;
    }

    /** A refused value as it is quoted back: short, one line, valid UTF-8. */
    public static function shown(string $value): string
    {
        $short = mb_scrub(mb_strcut($value, 0, 60, 'UTF-8'), 'UTF-8');
        $short = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $short);

        return '"' . $short . (strlen($value) > 60 ? '…' : '') . '"';
    }

    /** $value as a PHP string literal, quotes included. A plain value reads as it would hand-written: 'users:import'. */
    public static function phpString(string $value): string
    {
        return var_export($value, true);
    }

    /** $value as a Twig string literal, quotes included. Single-quoted: Twig interpolates `#{…}` in double quotes only. */
    public static function twigString(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }
}
