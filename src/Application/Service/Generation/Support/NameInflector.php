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

    public function toKebab(string $input): string
    {
        // Convert StudlyCase or snake_case to kebab-case
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);
        $result = preg_replace('/[_\s]+/', '-', $result);
        return strtolower($result);
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
