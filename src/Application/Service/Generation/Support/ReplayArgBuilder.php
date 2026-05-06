<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Support;

use Symfony\Component\Console\Input\InputInterface;

final class ReplayArgBuilder
{
    /**
     * @param list<string> $requiredOptions
     * @param list<string> $booleanFlags
     * @return list<string>
     */
    public static function fromInput(InputInterface $input, array $requiredOptions, array $booleanFlags = []): array
    {
        $args = [];

        foreach ($requiredOptions as $optionName) {
            $value = $input->getOption($optionName);
            if ($value === null || $value === '') {
                continue;
            }

            $args[] = sprintf('--%s=%s', $optionName, self::stringify($value));
        }

        foreach ($booleanFlags as $flagName) {
            if ($input->getOption($flagName)) {
                $args[] = '--' . $flagName;
            }
        }

        return $args;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
