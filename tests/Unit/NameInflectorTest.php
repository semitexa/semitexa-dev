<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;

class NameInflectorTest extends TestCase
{
    private NameInflector $inflector;

    protected function setUp(): void
    {
        $this->inflector = new NameInflector();
    }

    public function test_to_studly_from_kebab(): void
    {
        $this->assertSame('UserProfile', $this->inflector->toStudly('user-profile'));
    }

    public function test_to_studly_from_snake(): void
    {
        $this->assertSame('UserProfile', $this->inflector->toStudly('user_profile'));
    }

    public function test_to_studly_already_studly(): void
    {
        $this->assertSame('Pricing', $this->inflector->toStudly('Pricing'));
    }

    public function test_to_kebab_from_studly(): void
    {
        $this->assertSame('user-profile', $this->inflector->toKebab('UserProfile'));
    }

    public function test_to_kebab_from_snake(): void
    {
        $this->assertSame('user-profile', $this->inflector->toKebab('user_profile'));
    }

    public function test_to_payload_class(): void
    {
        $this->assertSame('PricingPayload', $this->inflector->toPayloadClass('Pricing'));
    }

    public function test_to_payload_class_no_double_suffix(): void
    {
        $this->assertSame('PricingPayload', $this->inflector->toPayloadClass('PricingPayload'));
    }

    public function test_to_handler_class(): void
    {
        $this->assertSame('PricingHandler', $this->inflector->toHandlerClass('Pricing'));
    }

    public function test_to_response_class(): void
    {
        $this->assertSame('PricingResponse', $this->inflector->toResponseClass('Pricing'));
    }

    public function test_to_template_name(): void
    {
        $this->assertSame('pricing.html.twig', $this->inflector->toTemplateName('Pricing'));
    }

    public function test_to_template_name_multi_word(): void
    {
        $this->assertSame('user-profile.html.twig', $this->inflector->toTemplateName('UserProfile'));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function studlyMatrix(): array
    {
        return [
            // separator-driven: split + lower-then-ucfirst per chunk
            ['user-profile', 'UserProfile'],
            ['user_profile', 'UserProfile'],
            ['USER_PROFILE', 'UserProfile'],
            ['user profile', 'UserProfile'],
            ['user--profile', 'UserProfile'],

            // already-PascalCase / camelCase preserved (regression: was mangled)
            ['Pricing', 'Pricing'],
            ['SyncCommand', 'SyncCommand'],
            ['UserImportCommand', 'UserImportCommand'],
            ['ClearCacheCommand', 'ClearCacheCommand'],
            ['GenerateOpenApiCommand', 'GenerateOpenApiCommand'],
            ['XMLExportCommand', 'XMLExportCommand'],
            ['OAuthTokenRefreshCommand', 'OAuthTokenRefreshCommand'],
            ['userProfile', 'UserProfile'],

            // single-token edge cases
            ['', ''],
            ['x', 'X'],
            ['XML', 'XML'],
        ];
    }

    /**
     * @dataProvider studlyMatrix
     */
    public function test_to_studly_matrix(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->inflector->toStudly($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function commandClassMatrix(): array
    {
        return [
            // separator-driven
            ['sync', 'SyncCommand'],
            ['sync-command', 'SyncCommand'],
            ['sync_command', 'SyncCommand'],
            ['user-import', 'UserImportCommand'],
            ['user-import-command', 'UserImportCommand'],
            ['SYNC_COMMAND', 'SyncCommand'],

            // already-PascalCase / camelCase preserved
            ['syncCommand', 'SyncCommand'],
            ['SyncCommand', 'SyncCommand'],
            ['UserImport', 'UserImportCommand'],
            ['UserImportCommand', 'UserImportCommand'],
            ['ClearCacheCommand', 'ClearCacheCommand'],
            ['GenerateOpenApiCommand', 'GenerateOpenApiCommand'],
            ['XMLExportCommand', 'XMLExportCommand'],

            // case-insensitive suffix dedup (no CommandCommand)
            ['synccommand', 'SyncCommand'],
            // All-caps acronyms are preserved (same rule that keeps
            // XMLExportCommand intact), so SYNCCOMMAND → SYNCCommand.
            // Either SYNCCommand or SyncCommand satisfies the "no
            // CommandCommand" guarantee — see task §3.
            ['SYNCCOMMAND', 'SYNCCommand'],
            ['SyncCOMMAND', 'SyncCommand'],

            // degenerate: input is the suffix itself — never CommandCommand
            ['Command', 'Command'],
            ['command', 'Command'],
            ['COMMAND', 'Command'],
        ];
    }

    /**
     * @dataProvider commandClassMatrix
     */
    public function test_to_command_class_matrix(string $input, string $expected): void
    {
        $actual = $this->inflector->toCommandClass($input);
        $this->assertSame($expected, $actual);
        // Hard guard: no double suffix can ever leak through.
        $this->assertStringNotContainsString('CommandCommand', $actual);
    }

    public function test_payload_class_no_double_suffix_when_input_is_lowercase(): void
    {
        // Regression: case-sensitive str_ends_with used to miss `pricingpayload`
        // and produce `PricingpayloadPayload`.
        $this->assertSame('PricingPayload', $this->inflector->toPayloadClass('pricingpayload'));
        $this->assertSame('PricingPayload', $this->inflector->toPayloadClass('PRICING_PAYLOAD'));
        $this->assertSame('Payload', $this->inflector->toPayloadClass('Payload'));
        $this->assertSame('Payload', $this->inflector->toPayloadClass('payload'));
    }

    public function test_handler_class_no_double_suffix(): void
    {
        $this->assertSame('PricingHandler', $this->inflector->toHandlerClass('Pricing'));
        $this->assertSame('PricingHandler', $this->inflector->toHandlerClass('PricingHandler'));
        $this->assertSame('PricingHandler', $this->inflector->toHandlerClass('pricinghandler'));
    }

    public function test_response_class_no_double_suffix(): void
    {
        $this->assertSame('PricingResponse', $this->inflector->toResponseClass('Pricing'));
        $this->assertSame('PricingResponse', $this->inflector->toResponseClass('PricingResponse'));
    }
}
