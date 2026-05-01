<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

use Semitexa\Dev\Application\Service\Ai\Verify\ProcessRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;

/**
 * Runs PHPStan against a list of changed files using the focused
 * `packages/semitexa-dev/config/phpstan-ai-verify.neon` configuration that
 * loads only the Semitexa-owned DI rules.
 *
 * `ai:verify` does not implement DI rules itself — they live as PHPStan rules
 * in `packages/semitexa-core/src/PHPStan/Rules/` and are the project's single
 * source of truth (the same rules run via `composer phpstan`). This runner is
 * a thin orchestration shim: it invokes PHPStan and decodes its JSON output
 * so the wider `ai:verify` machinery can re-emit each diagnostic as an NDJSON
 * `violation` event carrying the original PHPStan identifier verbatim
 * (`semitexa.injectionViaConstructor`, `semitexa.staticContainerAccess`, …).
 *
 * Determinism: the runner shells out via {@see ProcessRunner}; given the same
 * inputs and PHPStan vendor version the output is reproducible.
 */
final class PhpstanRunner
{
    public const CONFIG_REL_PATH = 'packages/semitexa-dev/config/phpstan-ai-verify.neon';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner = new ShellProcessRunner(),
        private readonly ?string $phpstanBinary = null,
        private readonly ?string $configPath = null,
    ) {}

    /**
     * @param list<string> $relativePaths repo-relative paths to analyse; non-PHP entries are ignored by PHPStan itself
     * @return PhpstanRunResult
     */
    public function run(array $relativePaths): PhpstanRunResult
    {
        if ($relativePaths === []) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_PASS,
                diagnostics: [],
                rawSignal: 'no files to analyse',
            );
        }

        $binary = $this->phpstanBinary ?? $this->discoverPhpstanBinary();
        if ($binary === null) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_SKIPPED,
                diagnostics: [],
                rawSignal: 'phpstan binary not found (looked for vendor/bin/phpstan)',
            );
        }

        $config = $this->configPath ?? ($this->projectRoot . '/' . self::CONFIG_REL_PATH);
        if (!is_file($config)) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_SKIPPED,
                diagnostics: [],
                rawSignal: "phpstan-ai-verify config missing: {$config}",
            );
        }

        $command = [
            $binary,
            'analyse',
            '--no-progress',
            '--memory-limit=512M',
            '--error-format=json',
            '-c',
            $config,
            ...$relativePaths,
        ];

        $result = $this->processRunner->run($command, $this->projectRoot);

        $payload = $this->decode($result['output']);
        if ($payload === null) {
            return new PhpstanRunResult(
                status: PhpstanRunResult::STATUS_SKIPPED,
                diagnostics: [],
                rawSignal: 'phpstan output was not valid JSON: ' . $this->compress($result['output']),
            );
        }

        $diagnostics = $this->extractDiagnostics($payload);

        $status = $diagnostics === []
            ? PhpstanRunResult::STATUS_PASS
            : PhpstanRunResult::STATUS_FAIL;

        $signal = $diagnostics === []
            ? 'phpstan_di → 0 violations'
            : sprintf(
                'phpstan_di → %d violation(s); first: %s %s',
                count($diagnostics),
                $diagnostics[0]['identifier'] ?? 'phpstan.error',
                $diagnostics[0]['path'] ?? '?',
            );

        return new PhpstanRunResult(
            status: $status,
            diagnostics: $diagnostics,
            rawSignal: $signal,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function extractDiagnostics(array $payload): array
    {
        $rows = [];
        $files = $payload['files'] ?? [];
        if (!is_array($files)) {
            return [];
        }
        foreach ($files as $absPath => $info) {
            if (!is_array($info)) {
                continue;
            }
            $messages = $info['messages'] ?? [];
            if (!is_array($messages)) {
                continue;
            }
            $rel = $this->relativisePath((string) $absPath);
            foreach ($messages as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $identifier = isset($msg['identifier']) && is_string($msg['identifier']) && $msg['identifier'] !== ''
                    ? $msg['identifier']
                    : 'phpstan.error';
                $rows[] = [
                    'check'         => 'phpstan_di',
                    'severity'      => 'error',
                    'rule'          => $identifier,
                    'identifier'    => $identifier,
                    'path'          => $rel,
                    'line'          => (int) ($msg['line'] ?? 0),
                    'message'       => isset($msg['message']) && is_string($msg['message']) ? $msg['message'] : '',
                    'tip'           => isset($msg['tip']) && is_string($msg['tip']) ? $msg['tip'] : '',
                    'doc_ref'       => 'packages/semitexa-docs/docs/AI_BEST_PRACTICES.md',
                    'suggested_fix' => $this->suggestionFor($identifier),
                ];
            }
        }
        // Top-level (path-less) errors — usually configuration / discovery issues.
        $errors = $payload['errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (!is_string($err) || $err === '') {
                    continue;
                }
                $rows[] = [
                    'check'         => 'phpstan_di',
                    'severity'      => 'error',
                    'rule'          => 'phpstan.error',
                    'identifier'    => 'phpstan.error',
                    'path'          => '',
                    'line'          => 0,
                    'message'       => $err,
                    'tip'           => '',
                    'doc_ref'       => 'packages/semitexa-docs/docs/AI_BEST_PRACTICES.md',
                    'suggested_fix' => 'Resolve the PHPStan configuration issue and re-run ai:verify.',
                ];
            }
        }
        return $rows;
    }

    private function suggestionFor(string $identifier): string
    {
        return match ($identifier) {
            'semitexa.injectionViaConstructor'   => 'Replace the constructor parameter with a #[InjectAsReadonly] (or #[InjectAsMutable] / #[InjectAsFactory]) protected property and reduce the constructor to either parameterless or a parent::__construct() call.',
            'semitexa.staticContainerAccess'     => 'Drop the ContainerFactory:: call. Declare each required collaborator as a protected #[InjectAsReadonly] property typed against the concrete service contract.',
            'semitexa.injectedPropertyVisibility' => 'Make the injected property `protected`. Public/private visibility is forbidden on #[InjectAs*] / #[Config] properties.',
            'semitexa.injectOnScalarType'        => 'Use #[Config] for scalar configuration values. #[InjectAs*] applies only to class types.',
            'semitexa.configOnArrayType',
            'semitexa.configOnClassType'         => 'Adjust the property type. #[Config] supports scalar (int, float, string, bool) and backed-enum types only.',
            'semitexa.unannotatedServiceProperty' => 'Add the appropriate #[InjectAsReadonly] / #[InjectAsMutable] / #[InjectAsFactory] attribute, or remove the property if it is not a service.',
            'semitexa.traitInjection'            => 'Move the injected property out of the trait. Container-managed classes must declare their injection points directly.',
            default                              => 'Fix the issue reported by PHPStan.',
        };
    }

    private function discoverPhpstanBinary(): ?string
    {
        foreach (['/vendor/bin/phpstan', '/bin/phpstan'] as $candidate) {
            $abs = $this->projectRoot . $candidate;
            if (is_file($abs) && is_executable($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function decode(string $output): ?array
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }
        $candidate = substr($output, $start);
        try {
            $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private function relativisePath(string $absOrRel): string
    {
        $root = rtrim($this->projectRoot, '/') . '/';
        if (str_starts_with($absOrRel, $root)) {
            return substr($absOrRel, strlen($root));
        }
        return $absOrRel;
    }

    private function compress(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($value) > 240 ? substr($value, 0, 237) . '...' : $value;
    }
}
