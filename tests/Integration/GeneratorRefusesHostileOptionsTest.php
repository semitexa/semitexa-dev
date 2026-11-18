<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeCommandCommand;
use Semitexa\Dev\Application\Console\Command\MakeContractCommand;
use Semitexa\Dev\Application\Console\Command\MakeCrudCommand;
use Semitexa\Dev\Application\Console\Command\MakeEventListenerCommand;
use Semitexa\Dev\Application\Console\Command\MakeFormCommand;
use Semitexa\Dev\Application\Console\Command\MakeHandlerCommand;
use Semitexa\Dev\Application\Console\Command\MakeModuleCommand;
use Semitexa\Dev\Application\Console\Command\MakePageCommand;
use Semitexa\Dev\Application\Console\Command\MakePayloadCommand;
use Semitexa\Dev\Application\Console\Command\MakeResourceCommand;
use Semitexa\Dev\Application\Console\Command\MakeServiceCommand;
use Semitexa\Dev\Application\Console\Command\MakeTestCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tk-generator-option-values-reach-php-unescaped, at the door an MCP client
 * and a command line both come through: each make:* command, run with --write
 * and one option that is code rather than what the option means, answers with
 * a refusal naming that option and writes nothing.
 *
 * Before the check, `make:command --command-name="a')] final class Pwned {}
 * system('id'); __halt_compiler();//" --write` wrote a class file `php -l`
 * accepted, and the console loads every #[AsCommand] class at boot.
 */
final class GeneratorRefusesHostileOptionsTest extends TestCase
{
    private const BREAKS_OUT_OF_A_STRING = "a')] final class Pwned {} system('id'); __halt_compiler();//";
    private const BREAKS_OUT_OF_A_NAME = "Foo{}system('id');__halt_compiler();";

    private string $tmpRoot;
    private ?string $originalCwd = null;
    /** The root ProjectRoot held before this test, put back as it was (review, dev#280). */
    private ?string $savedProjectRoot = null;

    protected function setUp(): void
    {
        $saved = (new \ReflectionProperty(ProjectRoot::class, 'root'))->getValue();
        $this->savedProjectRoot = is_string($saved) ? $saved : null;
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-dev-hostile-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules/Shop', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->tmpRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->savedProjectRoot);
        $this->removeDir($this->tmpRoot);
    }

    /** @return iterable<string, array{class-string<Command>, array<string, string|bool>, string, string}> */
    public static function hostileOptions(): iterable
    {
        $cases = [
            [MakeCommandCommand::class, ['--module' => 'Shop', '--name' => 'ImportUsers', '--command-name' => 'users:import', '--description' => 'Import users.'], [
                'command-name' => self::BREAKS_OUT_OF_A_STRING, 'name' => self::BREAKS_OUT_OF_A_NAME, 'module' => '../../../etc',
            ]],
            [MakeContractCommand::class, ['--module' => 'Shop', '--name' => 'PaymentGateway', '--implementation' => 'StripeGateway'], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'implementation' => self::BREAKS_OUT_OF_A_NAME,
            ]],
            [MakeCrudCommand::class, ['--module' => 'Shop', '--model' => 'ProductResource'], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'path' => self::BREAKS_OUT_OF_A_STRING, 'permission' => "x */ system('id'); /*", 'model' => "Shop\\Product; system('id');",
            ]],
            [MakeEventListenerCommand::class, ['--module' => 'Shop', '--name' => 'SendReceipt', '--event' => 'OrderPlaced', '--execution' => 'Async'], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'event' => "Shop\\Event\\OrderPlaced; system('id'); use Shop\\X",
            ]],
            [MakeFormCommand::class, ['--module' => 'Shop', '--name' => 'ContactUs', '--fields' => 'email:email!'], [
                'name' => self::BREAKS_OUT_OF_A_NAME,
            ]],
            [MakeHandlerCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--payload' => 'Cart', '--resource' => 'Cart', '--override-duplicate' => true], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'payload' => self::BREAKS_OUT_OF_A_NAME, 'resource' => self::BREAKS_OUT_OF_A_NAME,
            ]],
            [MakeModuleCommand::class, [], [
                'name' => self::BREAKS_OUT_OF_A_NAME,
            ]],
            [MakePageCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--path' => '/cart'], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'path' => self::BREAKS_OUT_OF_A_STRING, 'method' => "GET'], responseWith: Pwned::class)] final class Pwned {} //", 'layout' => self::BREAKS_OUT_OF_A_STRING,
            ]],
            [MakePayloadCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--path' => '/cart', '--method' => 'GET', '--response' => 'Cart', '--override-duplicate' => true], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'path' => self::BREAKS_OUT_OF_A_STRING, 'method' => "GET'], responseWith: Pwned::class)] final class Pwned {} //",
                'response' => self::BREAKS_OUT_OF_A_NAME, 'graphql-field' => self::BREAKS_OUT_OF_A_STRING,
            ]],
            [MakeResourceCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--handle' => 'cart'], [
                'name' => self::BREAKS_OUT_OF_A_NAME, 'handle' => self::BREAKS_OUT_OF_A_STRING, 'template' => self::BREAKS_OUT_OF_A_STRING,
            ]],
            [MakeServiceCommand::class, ['--module' => 'Shop'], [
                'name' => self::BREAKS_OUT_OF_A_NAME,
            ]],
            [MakeTestCommand::class, ['--module' => 'Shop'], [
                'name' => self::BREAKS_OUT_OF_A_NAME,
            ]],
        ];
        foreach ($cases as [$command, $plain, $hostile]) {
            foreach ($hostile as $option => $value) {
                $short = substr((string) strrchr($command, '\\'), 1);
                yield "{$short} --{$option}" => [$command, $plain, $option, $value];
            }
        }
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, string|bool> $plain
     */
    #[DataProvider('hostileOptions')]
    public function test_an_option_that_is_code_is_refused_by_name_and_nothing_is_written(string $command, array $plain, string $option, string $value): void
    {
        $before = $this->files();
        $tester = new CommandTester(new $command());

        $exit = $tester->execute(['--' . $option => $value] + $plain + ['--write' => true, '--json' => true]);

        $this->assertSame(1, $exit, $tester->getDisplay());
        $result = self::generationResult($tester->getDisplay());
        $this->assertSame('rejected', $result['status']);
        $this->assertSame([], $result['created']);
        $this->assertSame(
            [['invalid_option', $option]],
            array_map(static fn (array $e): array => [$e['reason'], $e['option'] ?? null], $result['errors']),
            'the one hostile option is the one problem named',
        );
        $this->assertStringContainsString("--{$option} ", $result['errors'][0]['detail']);
        $this->assertStringContainsString('is refused: it must be', $result['errors'][0]['detail']);
        $this->assertSame($before, $this->files(), 'nothing was written');
    }

    /** @return iterable<string, array{class-string<Command>, array<string, string|bool>, string}> */
    public static function emptyOptions(): iterable
    {
        yield 'make:resource --template' => [MakeResourceCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--handle' => 'cart'], 'template'];
        yield 'make:crud --permission' => [MakeCrudCommand::class, ['--module' => 'Shop', '--model' => 'ProductResource'], 'permission'];
        yield 'make:crud --path' => [MakeCrudCommand::class, ['--module' => 'Shop', '--model' => 'ProductResource'], 'path'];
        yield 'make:crud --name' => [MakeCrudCommand::class, ['--module' => 'Shop', '--model' => 'ProductResource'], 'name'];
        yield 'make:page --layout' => [MakePageCommand::class, ['--module' => 'Shop', '--name' => 'Cart', '--path' => '/cart'], 'layout'];
    }

    /**
     * Review, dev#280: an option given empty passed the check, which skipped
     * every empty value, and the builder then threw outside the caller's format.
     *
     * @param class-string<Command> $command
     * @param array<string, string|bool> $plain
     */
    #[DataProvider('emptyOptions')]
    public function test_an_optional_option_given_empty_is_refused_by_name(string $command, array $plain, string $option): void
    {
        $before = $this->files();
        $tester = new CommandTester(new $command());

        $exit = $tester->execute(['--' . $option => ''] + $plain + ['--write' => true, '--json' => true]);

        $this->assertSame(1, $exit, $tester->getDisplay());
        $result = self::generationResult($tester->getDisplay());
        $this->assertSame('rejected', $result['status']);
        $this->assertSame(
            [['invalid_option', $option]],
            array_map(static fn (array $e): array => [$e['reason'], $e['option'] ?? null], $result['errors']),
        );
        $this->assertStringContainsString("--{$option} \"\" is refused: it must be", $result['errors'][0]['detail']);
        $this->assertStringContainsString('Leave the option out for its default.', $result['errors'][0]['detail']);
        $this->assertSame($before, $this->files(), 'nothing was written');
    }

    public function test_an_empty_graphql_field_is_still_a_field_left_to_derive(): void
    {
        $tester = new CommandTester(new MakePayloadCommand());
        $exit = $tester->execute([
            '--module' => 'Shop', '--name' => 'Cart', '--path' => '/cart', '--method' => 'GET', '--response' => 'Cart',
            '--graphql' => true, '--graphql-field' => '', '--override-duplicate' => true, '--json' => true,
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertSame('dry_run', self::generationResult($tester->getDisplay())['status']);
    }

    public function test_a_required_option_given_empty_is_named_once_as_missing(): void
    {
        $tester = new CommandTester(new MakeResourceCommand());
        $exit = $tester->execute(['--module' => 'Shop', '--name' => 'Cart', '--handle' => '', '--json' => true]);

        $this->assertSame(1, $exit, $tester->getDisplay());
        $this->assertSame(
            [['missing_option', 'handle']],
            array_map(static fn (array $e): array => [$e['reason'], $e['option'] ?? null], self::generationResult($tester->getDisplay())['errors']),
        );
    }

    /** @return iterable<string, array{class-string<Command>, array<string, string|bool>, string}> */
    public static function reservedNames(): iterable
    {
        yield 'make:service --name=List' => [MakeServiceCommand::class, ['--module' => 'Shop', '--name' => 'List'], 'The service class "List"'];
        yield 'make:service --name=class' => [MakeServiceCommand::class, ['--module' => 'Shop', '--name' => 'class'], 'The service class "Class"'];
        yield 'make:contract --implementation=Default' => [MakeContractCommand::class, ['--module' => 'Shop', '--name' => 'PaymentGateway', '--implementation' => 'Default'], 'The implementation class "Default"'];
        yield 'make:contract --name=Interface' => [MakeContractCommand::class, ['--module' => 'Shop', '--name' => 'Interface', '--implementation' => 'StripeGateway'], 'The contract interface "Interface"'];
        yield 'make:event-listener --name=Match' => [MakeEventListenerCommand::class, ['--module' => 'Shop', '--name' => 'Match', '--event' => 'OrderPlaced', '--execution' => 'Async'], 'The listener class "Match"'];
    }

    /**
     * Review, dev#280: `make:service --name=List --write` wrote `final class List`,
     * a file PHP does not compile.
     *
     * @param class-string<Command> $command
     * @param array<string, string|bool> $options
     */
    #[DataProvider('reservedNames')]
    public function test_a_class_named_with_a_word_php_reserves_is_refused_and_nothing_is_written(string $command, array $options, string $named): void
    {
        $before = $this->files();
        $tester = new CommandTester(new $command());

        $exit = $tester->execute($options + ['--write' => true, '--json' => true]);

        $this->assertSame(1, $exit, $tester->getDisplay());
        $result = self::generationResult($tester->getDisplay());
        $this->assertSame('rejected', $result['status']);
        $this->assertSame(['invalid_option'], array_column($result['errors'], 'reason'));
        $this->assertStringContainsString($named . ' is refused: it must be', $result['errors'][0]['detail']);
        $this->assertStringContainsString('not a word PHP reserves', $result['errors'][0]['detail']);
        $this->assertSame($before, $this->files(), 'nothing was written');
    }

    public function test_a_reserved_word_with_a_suffix_is_a_class_name_like_any_other(): void
    {
        // ListHandler, ListPayload and ListInterface are names PHP takes: only the bare word is refused.
        $tester = new CommandTester(new MakeContractCommand());
        $exit = $tester->execute(['--module' => 'Shop', '--name' => 'List', '--implementation' => 'ArrayList', '--write' => true, '--json' => true]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertFileExists($this->tmpRoot . '/src/modules/Shop/src/Domain/Contract/ListInterface.php');
    }

    public function test_the_project_root_held_before_a_test_is_the_one_held_after(): void
    {
        // Review, dev#280: tearDown reset the root, so a root an earlier test
        // left set was recomputed from the working directory for the next one.
        $root = new \ReflectionProperty(ProjectRoot::class, 'root');
        $mine = $root->getValue();
        $other = new self('test_the_project_root_held_before_a_test_is_the_one_held_after');
        try {
            $root->setValue(null, '/a/root/an/earlier/test/left');
            $other->setUp();
            $this->assertSame(realpath($other->tmpRoot), realpath(ProjectRoot::get()), 'inside the test the root is the fixture');
            $other->tearDown();

            $this->assertSame('/a/root/an/earlier/test/left', $root->getValue());
            $this->assertDirectoryDoesNotExist($other->tmpRoot);
        } finally {
            $root->setValue(null, $mine);
        }
    }

    public function test_a_description_that_is_code_is_written_as_the_text_it_is(): void
    {
        $tester = new CommandTester(new MakeCommandCommand());
        $exit = $tester->execute([
            '--module' => 'Shop', '--name' => 'ImportUsers', '--command-name' => 'users:import',
            '--description' => self::BREAKS_OUT_OF_A_STRING, '--write' => true, '--json' => true,
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $file = $this->tmpRoot . '/src/modules/Shop/src/Application/Console/Command/ImportUsersCommand.php';
        $this->assertFileExists($file);
        $tokens = \PhpToken::tokenize((string) file_get_contents($file), TOKEN_PARSE);
        $code = array_values(array_filter($tokens, static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $this->assertSame(1, count(array_filter($code, static fn (\PhpToken $t): bool => $t->id === T_CLASS)), 'one class, the command');
        $this->assertSame([], array_filter($code, static fn (\PhpToken $t): bool => $t->id === T_HALT_COMPILER));
        $names = array_values(array_map(static fn (\PhpToken $t): string => $t->text, array_filter($code, static fn (\PhpToken $t): bool => $t->id === T_STRING)));
        $this->assertContains('SymfonyStyle', $names, 'the template\'s own names are code');
        $this->assertNotContains('system', $names, 'the call in the description is not');
        $this->assertNotContains('Pwned', $names, 'nor is its class');
        $literals = array_values(array_map(
            static fn (\PhpToken $t): string => strtr(substr($t->text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]),
            array_filter($code, static fn (\PhpToken $t): bool => $t->id === T_CONSTANT_ENCAPSED_STRING),
        ));
        $this->assertSame(['users:import', self::BREAKS_OUT_OF_A_STRING, 'users:import', self::BREAKS_OUT_OF_A_STRING, 'Done.'], $literals);
    }

    /**
     * The generation result of a --json answer.
     *
     * @return array{status: string, created: list<string>, errors: list<array{reason: string, detail: string, option?: string}>}
     */
    private static function generationResult(string $display): array
    {
        $decoded = json_decode(trim($display), true);
        self::assertIsArray($decoded, 'the refusal is the JSON the caller asked for: ' . $display);
        self::assertIsArray($decoded['result'] ?? null);
        /** @var array{status: string, created: list<string>, errors: list<array{reason: string, detail: string, option?: string}>} $result */
        $result = $decoded['result'];

        return $result;
    }

    /** @return list<string> every file under the project, relative */
    private function files(): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $files[] = substr($item->getPathname(), strlen($this->tmpRoot) + 1);
            }
        }
        sort($files);

        return $files;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
