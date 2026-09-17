<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Semitexa\Core\Support\Str;
use Semitexa\Dev\Application\Service\Generation\Contract\NameInflectorInterface;

final class NameInflector implements NameInflectorInterface
{
    public function toStudly(string $input): string
    {
        return Str::toStudly($input);
    }

    /**
     * A kebab SLUG — letters, digits and hyphens, and nothing else.
     *
     * The filter is not tidiness. This value is substituted into generated
     * code, including a single-quoted JavaScript literal in page-js.js.tpl, so
     * a name carrying an apostrophe wrote a file that does not parse — or one
     * with a statement in it. A kebab name never legitimately contained
     * anything this drops.
     */
    public function toKebab(string $input): string
    {
        // Convert StudlyCase or snake_case to kebab-case
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);
        $result = preg_replace('/[_\s]+/', '-', $result);
        $result = strtolower((string) $result);
        $result = preg_replace('/[^a-z0-9-]+/', '-', $result);

        $kebab = trim(preg_replace('/-{2,}/', '-', (string) $result) ?? '', '-');

        // Everything dropped leaves NOTHING for some inputs — `'` or an emoji
        // sanitise to the empty string. The generator then planned files named
        // for nothing at all: `.html.twig`, and with --with-assets a `.js`, a
        // `.css` and a `.json` beside it. The command only checks that --name
        // was PASSED, so the refusal has to be here, where the emptiness is
        // first visible.
        if ($kebab === '') {
            throw new \InvalidArgumentException(sprintf(
                'The name "%s" has no characters usable in a file name. Use letters or digits.',
                $input,
            ));
        }

        return $kebab;
    }

    public function toPayloadClass(string $input): string
    {
        return $this->withSuffix($input, 'Payload');
    }

    public function toHandlerClass(string $input): string
    {
        return $this->withSuffix($input, 'Handler');
    }

    public function toResponseClass(string $input): string
    {
        return $this->withSuffix($input, 'Response');
    }

    public function toCommandClass(string $input): string
    {
        return $this->withSuffix($input, 'Command');
    }

    /**
     * Strip a trailing $suffix case-insensitively, normalize the base via
     * toStudly, and re-append the canonical $suffix. Always yields exactly
     * one trailing $suffix, regardless of input casing or word separators.
     *
     * Examples (for $suffix = 'Command'):
     *   sync, sync-command, sync_command, syncCommand, SyncCommand,
     *   SYNC_COMMAND, synccommand                   → SyncCommand
     *   UserImport, UserImportCommand, user-import  → UserImportCommand
     *
     * Edge case: when the input is the suffix itself (`Command` /
     * `command`), the base is empty and the result is just the canonical
     * suffix — never a doubled `CommandCommand`.
     */
    private function withSuffix(string $input, string $suffix): string
    {
        $base = (string) preg_replace('/' . preg_quote($suffix, '/') . '$/i', '', $input);
        if ($base === '') {
            return $suffix;
        }
        return $this->toStudly($base) . $suffix;
    }

    public function toTemplateName(string $input): string
    {
        return $this->toKebab($input) . '.html.twig';
    }
}
