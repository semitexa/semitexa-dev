<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Where the pinned actionlint is, fetched the first time it is needed.
 *
 * As roave for the BC gate ({@see BcGateCheck}), the tool lives in var/tools of
 * the project and is installed on first use. It is one static binary, taken
 * from the project's GitHub release for this machine's architecture; the
 * archive's sha256 is pinned here and compared before anything is unpacked, so
 * a download that is not the released file is never run.
 *
 * Linux only, amd64 and arm64: the runtime Semitexa ships. Anywhere else, and
 * whenever the download, the hash or the unpacking fails, there is no tool and
 * the answer names why; the caller then reports the workflow as not read.
 */
final class ActionlintTool
{
    public const VERSION = '1.7.7';

    public const INSTALL_DIR = 'var/tools/actionlint/' . self::VERSION;

    /**
     * sha256 of each release archive, by Go architecture. From
     * https://github.com/rhysd/actionlint/releases/download/v1.7.7/actionlint_1.7.7_checksums.txt
     * (fetched 2026-11-20), which says:
     *
     *   023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757  actionlint_1.7.7_linux_amd64.tar.gz
     *   401942f9c24ed71e4fe71b76c7d638f66d8633575c4016efd2977ce7c28317d0  actionlint_1.7.7_linux_arm64.tar.gz
     */
    public const SHA256 = [
        'amd64' => '023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757',
        'arm64' => '401942f9c24ed71e4fe71b76c7d638f66d8633575c4016efd2977ce7c28317d0',
    ];

    private const MACHINES = ['x86_64' => 'amd64', 'amd64' => 'amd64', 'aarch64' => 'arm64', 'arm64' => 'arm64'];

    /**
     * @param string|null $binary  the actionlint to use in place of the pinned one: nothing is
     *                             fetched, and a path that is not executable is no tool (tests)
     * @param string|null $machine `uname -m` and the OS family of this runtime; null reads them
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner,
        private readonly ?string $binary = null,
        private readonly ?string $machine = null,
        private readonly ?string $osFamily = null,
    ) {}

    public static function assetName(string $arch): string
    {
        return 'actionlint_' . self::VERSION . '_linux_' . $arch . '.tar.gz';
    }

    /**
     * @return array{binary: string}|array{unavailable: string} the executable, or why there is none
     */
    public function obtain(): array
    {
        if ($this->binary !== null) {
            return is_file($this->binary) && is_executable($this->binary)
                ? ['binary' => $this->binary]
                : ['unavailable' => "the actionlint named, {$this->binary}, is not an executable file"];
        }

        $installed = $this->projectRoot . '/' . self::INSTALL_DIR . '/actionlint';
        if (is_file($installed) && is_executable($installed)) {
            return ['binary' => $installed];
        }

        $os = $this->osFamily ?? PHP_OS_FAMILY;
        $machine = $this->machine ?? php_uname('m');
        $arch = self::MACHINES[strtolower($machine)] ?? null;
        if ($os !== 'Linux' || $arch === null) {
            return ['unavailable' => "no pinned actionlint " . self::VERSION . " build for {$os} {$machine} (linux amd64 and arm64 only)"];
        }

        $dir = dirname($installed);
        $stage = $dir . '/.fetch-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!is_dir($stage) && !@mkdir($stage, 0775, true) && !is_dir($stage)) {
            return ['unavailable' => 'could not create ' . self::INSTALL_DIR . ' in the project'];
        }

        try {
            return $this->fetch($arch, $stage, $installed);
        } finally {
            foreach (glob($stage . '/*') ?: [] as $left) {
                @unlink($left);
            }
            @rmdir($stage);
        }
    }

    /**
     * @return array{binary: string}|array{unavailable: string}
     */
    private function fetch(string $arch, string $stage, string $installed): array
    {
        $asset = self::assetName($arch);
        $url = 'https://github.com/rhysd/actionlint/releases/download/v' . self::VERSION . '/' . $asset;
        $archive = $stage . '/' . $asset;

        $download = $this->processRunner->run(['curl', '--fail', '--silent', '--show-error', '--location', '--proto', '=https', '--max-time', '100', '--output', $archive, $url], $this->projectRoot);
        if ($download['exit'] !== 0 || !is_file($archive)) {
            return ['unavailable' => "could not download {$url}: " . SignalText::compress($download['output'])];
        }

        $hash = hash_file('sha256', $archive);
        if ($hash !== self::SHA256[$arch]) {
            return ['unavailable' => "the downloaded {$asset} has sha256 " . ($hash === false ? '(unreadable)' : $hash) . ', not the pinned ' . self::SHA256[$arch] . '; it was not unpacked'];
        }

        $unpack = $this->processRunner->run(['tar', '-xzf', $archive, '-C', $stage, 'actionlint'], $this->projectRoot);
        $unpacked = $stage . '/actionlint';
        if ($unpack['exit'] !== 0 || !is_file($unpacked)) {
            return ['unavailable' => "could not unpack {$asset}: " . SignalText::compress($unpack['output'])];
        }

        // Into place in one step: a second run at the same moment finds a whole binary or none.
        if (!@chmod($unpacked, 0755) || !@rename($unpacked, $installed)) {
            return ['unavailable' => 'could not install actionlint into ' . self::INSTALL_DIR];
        }

        return ['binary' => $installed];
    }
}
