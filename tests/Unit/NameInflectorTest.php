<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Support\NameInflector;

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
}
