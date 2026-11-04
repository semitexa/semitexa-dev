<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Dev\Application\Service\Api\ApiCatalog;
use Semitexa\Dev\Application\Service\Api\ApiClass;
use Semitexa\Dev\Application\Service\Api\ApiHit;
use Semitexa\Dev\Application\Service\Api\ApiMember;
use Semitexa\Dev\Application\Service\Api\ApiParameter;
use Semitexa\Dev\Application\Service\Api\ApiReflector;
use Semitexa\Dev\Tests\Fixtures\Api\One\SampleTwin as TwinOne;
use Semitexa\Dev\Tests\Fixtures\Api\SampleAliasing;
use Semitexa\Dev\Tests\Fixtures\Api\SampleAttribute;
use Semitexa\Dev\Tests\Fixtures\Api\SampleBase;
use Semitexa\Dev\Tests\Fixtures\Api\SampleCollector;
use Semitexa\Dev\Tests\Fixtures\Api\SampleContract;
use Semitexa\Dev\Tests\Fixtures\Api\SampleContractAbstract;
use Semitexa\Dev\Tests\Fixtures\Api\SampleContractDeep;
use Semitexa\Dev\Tests\Fixtures\Api\SampleContractOne;
use Semitexa\Dev\Tests\Fixtures\Api\SampleContractTwo;
use Semitexa\Dev\Tests\Fixtures\Api\SampleHelperTrait;
use Semitexa\Dev\Tests\Fixtures\Api\SampleMode;
use Semitexa\Dev\Tests\Fixtures\Api\SamplePlace;
use Semitexa\Dev\Tests\Fixtures\Api\SampleService;
use Semitexa\Dev\Tests\Fixtures\Api\SampleTitled;
use Semitexa\Dev\Tests\Fixtures\Api\SampleTitledChild;
use Semitexa\Dev\Tests\Fixtures\Api\SampleTraitUser;
use Semitexa\Dev\Tests\Fixtures\Api\SampleVariadic;
use Semitexa\Dev\Tests\Fixtures\Api\Three\SampleTwin as TwinThree;
use Semitexa\Dev\Tests\Fixtures\Api\Two\SampleTwin as TwinTwo;

final class ApiCatalogTest extends TestCase
{
    public function test_an_attribute_shows_what_can_be_written_between_its_parentheses(): void
    {
        $class = $this->catalog(SampleAttribute::class, SampleMode::class)->lookup('SampleAttribute');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('attribute', $class->kind);
        $this->assertTrue($class->stable);
        $this->assertSame('Marks a sample.', $class->summary);

        $nullable = $this->parameter($class, 'nullable');
        $this->assertSame('bool', $nullable->type);
        $this->assertSame('false', $nullable->default);
        $this->assertSame('bool $nullable = false', $nullable->signature());

        $this->assertSame('null', $this->parameter($class, 'length')->default);
        $this->assertSame('Overrides the label shown to the visitor.', $this->parameter($class, 'label')->summary);
    }

    public function test_a_required_parameter_is_told_from_one_defaulting_to_null(): void
    {
        $class = $this->catalog(SampleAttribute::class)->lookup('SampleAttribute');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertTrue($this->parameter($class, 'mode')->toArray()['required']);
        $this->assertFalse($this->parameter($class, 'length')->toArray()['required']);
    }

    public function test_an_enum_typed_parameter_lists_its_cases(): void
    {
        $class = $this->catalog(SampleAttribute::class)->lookup('SampleAttribute');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(['Plain', 'Strict'], $this->parameter($class, 'mode')->cases);
    }

    public function test_an_interface_typed_parameter_names_what_can_be_passed(): void
    {
        $class = $this->catalog(SampleAttribute::class, SampleContractOne::class, SampleContractTwo::class)->lookup('SampleAttribute');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame([SampleContractOne::class, SampleContractTwo::class], $this->parameter($class, 'contract')->implementers);
    }

    public function test_implementers_are_found_through_subclasses_and_only_concrete_ones_are_listed(): void
    {
        $class = $this->catalog(SampleContract::class, SampleContractAbstract::class, SampleContractDeep::class)->lookup('SampleContract');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame([SampleContractDeep::class], $class->implementers);
    }

    public function test_an_interface_lists_its_implementers(): void
    {
        $class = $this->catalog(SampleContract::class, SampleContractOne::class, SampleContractTwo::class, SampleAttribute::class)
            ->lookup('SampleContract');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('interface', $class->kind);
        $this->assertSame([SampleContractOne::class, SampleContractTwo::class], $class->implementers);
    }

    public function test_a_trait_shows_its_protected_methods_because_that_is_its_api(): void
    {
        $class = $this->catalog(SampleHelperTrait::class)->lookup('SampleHelperTrait');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('trait', $class->kind);
        $this->assertSame(['checkPattern', 'helpPublicly'], $this->memberNames($class));
        $this->assertSame('protected checkPattern(array &$errors, string $pattern): void', $class->members[0]->signature);
        $this->assertSame('Checks the value against a regex.', $class->members[0]->summary);
    }

    public function test_an_abstract_class_shows_its_extension_points_without_a_foreign_parents_methods(): void
    {
        $class = $this->catalog(SampleBase::class)->lookup('SampleBase');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('abstract class', $class->kind);
        $this->assertSame(['extensionPoint', 'inheritedByAll', 'mustBeWritten'], $this->memberNames($class));
        $this->assertSame('abstract protected mustBeWritten(): void', $this->member($class, 'mustBeWritten')->signature);
    }

    public function test_a_concrete_class_shows_public_members_and_names_where_inherited_ones_come_from(): void
    {
        $class = $this->catalog(SampleService::class)->lookup('SampleService');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(['LIMIT', 'pattern', 'owner', 'perform', 'make', 'byReference', 'old', '__invoke', 'inheritedByAll'], $this->memberNames($class));
        $this->assertSame(SampleBase::class, $class->extends);
        $this->assertNull($this->member($class, 'perform')->from);
    }

    public function test_an_inherited_member_names_the_class_it_comes_from(): void
    {
        $class = $this->catalog(SampleService::class)->lookup('SampleService');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(SampleBase::class, $this->member($class, 'inheritedByAll')->from);
    }

    public function test_a_trait_member_names_the_trait_and_search_finds_it_once(): void
    {
        $catalog = $this->catalog(SampleTraitUser::class, SampleHelperTrait::class);

        $class = $catalog->lookup('SampleTraitUser');
        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(SampleHelperTrait::class, $this->member($class, 'helpPublicly')->from);

        $result = $catalog->search('helpPublicly');
        $this->assertSame(1, $result->total);
        $this->assertSame(SampleHelperTrait::class, $result->hits[0]->class);
    }

    public function test_an_aliased_trait_method_still_names_the_trait(): void
    {
        $class = $this->catalog(SampleAliasing::class)->lookup('SampleAliasing');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(SampleHelperTrait::class, $this->member($class, 'helpUnderAnotherName')->from);
    }

    public function test_a_promoted_property_shown_as_a_parameter_is_not_listed_again(): void
    {
        $class = $this->catalog(SampleAttribute::class)->lookup('SampleAttribute');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame([], $this->memberNames($class));
    }

    public function test_a_variadic_constructor_parameter_is_optional_and_says_so(): void
    {
        $class = $this->catalog(SampleVariadic::class)->lookup('SampleVariadic');

        $this->assertInstanceOf(ApiClass::class, $class);
        $scopes = $this->parameter($class, 'scopes');
        $this->assertSame('string ...$scopes', $scopes->signature());
        $this->assertFalse($scopes->toArray()['required']);
    }

    public function test_a_by_reference_constructor_parameter_shows_it(): void
    {
        $class = $this->catalog(SampleCollector::class)->lookup('SampleCollector');

        $this->assertInstanceOf(ApiClass::class, $class);
        $errors = $this->parameter($class, 'errors');
        $this->assertSame('array &$errors', $errors->signature());
        $this->assertTrue($errors->toArray()['by_reference']);
    }

    public function test_a_parents_promoted_property_stays_when_a_child_constructor_reuses_the_name(): void
    {
        $class = $this->catalog(SampleTitledChild::class)->lookup('SampleTitledChild');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('string $title', $this->parameter($class, 'title')->signature());
        $this->assertSame(SampleTitled::class, $this->member($class, 'title')->from);
    }

    public function test_a_promoted_property_stays_when_its_constructor_is_not_on_show(): void
    {
        $class = $this->catalog(SamplePlace::class)->lookup('SamplePlace');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame([], $class->parameters);
        $this->assertSame('readonly string $title', $this->member($class, 'title')->signature);
    }

    public function test_what_a_signature_alone_would_hide_is_said(): void
    {
        $class = $this->catalog(SampleService::class)->lookup('SampleService');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('private(set) string $owner', $this->member($class, 'owner')->signature);
        $this->assertSame("const LIMIT = 'limit'", $this->member($class, 'LIMIT')->signature);
        $this->assertSame('Deprecated.', $this->member($class, 'old')->summary);
        $this->assertSame('&byReference(): array', $this->member($class, 'byReference')->signature);
    }

    public function test_a_default_expression_is_printed_and_never_evaluated(): void
    {
        $class = $this->catalog(SampleService::class)->lookup('SampleService');

        $this->assertInstanceOf(ApiClass::class, $class);
        $signature = $this->member($class, 'perform')->signature;
        $this->assertStringContainsString('= new ', $signature);
        $this->assertStringContainsString('int ...$rest', $signature);
    }

    public function test_an_enum_shows_its_cases_and_methods_but_not_the_engine_ones(): void
    {
        $class = $this->catalog(SampleMode::class)->lookup('SampleMode');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame('enum', $class->kind);
        $this->assertSame(['Plain', 'Strict', 'label'], $this->memberNames($class));
        $this->assertSame("case Plain = 'plain'", $class->members[0]->signature);
        $this->assertSame('The everyday one.', $class->members[0]->summary);
    }

    public function test_a_full_name_and_a_leading_backslash_both_find_the_class(): void
    {
        $catalog = $this->catalog(SampleMode::class);

        $this->assertSame([SampleMode::class], $catalog->resolve(SampleMode::class));
        $this->assertSame([SampleMode::class], $catalog->resolve('\\' . SampleMode::class));
        $this->assertSame([SampleMode::class], $catalog->resolve('samplemode'));
    }

    public function test_a_shared_short_name_goes_to_the_only_stable_class(): void
    {
        $class = $this->catalog(TwinOne::class, TwinTwo::class)->lookup('SampleTwin');

        $this->assertInstanceOf(ApiClass::class, $class);
        $this->assertSame(TwinTwo::class, $class->name);
    }

    public function test_two_equal_classes_are_refused_rather_than_guessed(): void
    {
        $catalog = $this->catalog(TwinTwo::class, TwinThree::class);

        $this->assertNull($catalog->lookup('SampleTwin'));
        $this->assertSame([TwinThree::class, TwinTwo::class], $catalog->resolve('SampleTwin'));
    }

    public function test_a_class_that_cannot_load_is_not_called_ambiguous(): void
    {
        $file = (string) (new ReflectionClass(SampleMode::class))->getFileName();
        $catalog = new ApiCatalog([SampleMode::class . 'Gone' => $file], new ApiReflector('/nowhere'));

        $this->assertNull($catalog->lookup('SampleModeGone'));
        $this->assertStringContainsString('cannot be loaded', $catalog->refusalFor('SampleModeGone'));
    }

    public function test_equal_classes_are_refused_as_ambiguous(): void
    {
        $this->assertStringContainsString('names 2 classes', $this->catalog(TwinTwo::class, TwinThree::class)->refusalFor('SampleTwin'));
    }

    public function test_an_unknown_name_is_not_answered(): void
    {
        $this->assertNull($this->catalog(SampleMode::class)->lookup('NothingLikeThis'));
    }

    public function test_similar_offers_classes_by_name_shortest_first(): void
    {
        $catalog = $this->catalog(SampleContractOne::class, SampleContract::class, SampleMode::class);

        $this->assertSame([SampleContract::class, SampleContractOne::class], $catalog->similar('contract'));
    }

    public function test_search_finds_a_method_by_its_name_and_by_its_summary(): void
    {
        $catalog = $this->catalog(SampleHelperTrait::class);

        $this->assertSame('checkPattern', $this->firstHit($catalog, 'pattern')->member);
        $this->assertSame('checkPattern', $this->firstHit($catalog, 'regex')->member);
    }

    public function test_search_matches_words_across_the_class_and_its_parameter(): void
    {
        $hit = $this->firstHit($this->catalog(SampleAttribute::class), 'nullable sample');

        $this->assertSame('nullable', $hit->member);
        $this->assertSame(SampleAttribute::class, $hit->class);
        $this->assertSame('bool $nullable = false', $hit->signature);
    }

    public function test_search_splits_a_phrase_so_the_words_need_not_be_adjacent(): void
    {
        $catalog = $this->catalog(SampleHelperTrait::class);

        $this->assertSame('checkPattern', $this->firstHit($catalog, 'check pattern')->member);
        $this->assertSame('checkPattern', $this->firstHit($catalog, 'check_pattern')->member);
    }

    public function test_a_name_pasted_with_its_punctuation_is_still_found(): void
    {
        $catalog = $this->catalog(SampleHelperTrait::class, SampleAttribute::class);

        $this->assertSame('checkPattern', $this->firstHit($catalog, 'checkPattern()')->member);
        $this->assertNull($this->firstHit($catalog, '#[SampleAttribute]')->member);
        $this->assertSame(SampleAttribute::class, $this->firstHit($catalog, SampleAttribute::class)->class);
    }

    public function test_the_kind_and_the_namespace_count_as_words(): void
    {
        $hit = $this->firstHit($this->catalog(SampleAttribute::class, SampleMode::class), 'attribute sample');

        $this->assertSame(SampleAttribute::class, $hit->class);
        $this->assertNull($hit->member);
    }

    public function test_when_no_member_has_every_word_the_closest_are_offered_as_partial(): void
    {
        $catalog = $this->catalog(SampleHelperTrait::class);

        $this->assertFalse($catalog->search('check pattern')->partial);

        $result = $catalog->search('check pattern validator');
        $this->assertTrue($result->partial);
        $this->assertSame('checkPattern', $result->hits[0]->member);
    }

    public function test_nothing_at_all_is_not_reported_as_partial(): void
    {
        $result = $this->catalog(SampleMode::class)->search('zzqq yyww');

        $this->assertSame([], $result->hits);
        $this->assertFalse($result->partial);
    }

    public function test_a_class_only_the_wider_pass_read_is_still_reported_as_unread(): void
    {
        $file = (string) (new ReflectionClass(SampleService::class))->getFileName();
        $catalog = new ApiCatalog([SampleService::class . 'Gone' => $file], new ApiReflector('/nowhere'));

        $result = $catalog->search('perform zzqq');

        $this->assertSame([], $result->hits);
        $this->assertFalse($result->partial);
        $this->assertSame([SampleService::class . 'Gone'], $result->unread);
    }

    public function test_the_class_of_exactly_that_name_comes_first(): void
    {
        $hits = $this->catalog(SampleMode::class, SampleAttribute::class)->search('SampleMode')->hits;

        $this->assertSame(SampleMode::class, $hits[0]->class);
        $this->assertNull($hits[0]->member);
    }

    public function test_a_word_in_a_doc_comment_earns_no_bonus_over_a_name(): void
    {
        $hits = $this->catalog(SampleHelperTrait::class, SampleService::class)->search('regex pattern')->hits;

        $this->assertSame('checkPattern', $hits[0]->member, 'name and summary both match');
        $this->assertSame([], $this->catalog(SampleService::class)->search('regex')->hits);

        $docOnly = $this->catalog(SampleHelperTrait::class)->search('regex')->hits[0];
        $byName = $this->catalog(SampleService::class)->search('pattern')->hits[0];
        $this->assertLessThan($byName->score, $docOnly->score);
    }

    public function test_the_class_answers_for_its_own_name_and_its_members_do_not(): void
    {
        $result = $this->catalog(SampleAttribute::class)->search('SampleAttribute');

        $this->assertSame(1, $result->total);
        $this->assertNull($result->hits[0]->member);
    }

    public function test_a_helper_made_for_application_code_outranks_an_exact_name_on_a_plain_class(): void
    {
        $hits = $this->catalog(SampleService::class, SampleHelperTrait::class)->search('pattern')->hits;

        $this->assertSame(SampleHelperTrait::class, $hits[0]->class);
        $this->assertSame(SampleService::class, $hits[1]->class);
    }

    public function test_a_class_that_cannot_load_is_reported_and_not_silently_dropped(): void
    {
        $file = (string) (new ReflectionClass(SampleService::class))->getFileName();

        $result = (new ApiCatalog([SampleService::class . 'Gone' => $file], new ApiReflector('/nowhere')))->search('perform');

        $this->assertSame([], $result->hits);
        $this->assertSame([SampleService::class . 'Gone'], $result->unread);
    }

    public function test_a_search_with_no_words_finds_nothing_instead_of_everything(): void
    {
        $result = $this->catalog(SampleMode::class)->search(' - _ ');

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->hits);
    }

    public function test_the_framework_scope_drops_tests_fixtures_application_code_and_third_parties(): void
    {
        $file = (string) (new ReflectionClass(ApiCatalog::class))->getFileName();
        $catalog = ApiCatalog::forFramework([
            ApiCatalog::class => $file,
            'Symfony\\Component\\Console\\Command\\Command' => $file,
            'Semitexa\\Dev\\Tests\\Unit\\Api\\ApiCatalogTest' => $file,
            'Semitexa\\Modules\\Fixture\\Page' => '/app/packages/semitexa-x/tests/Fixture/Page.php',
            'Semitexa\\Modules\\Shop\\Cart' => '/app/src/modules/Shop/src/Cart.php',
        ], '/app');

        $this->assertSame([ApiCatalog::class], $catalog->resolve(ApiCatalog::class));
        $this->assertSame([], $catalog->resolve('Command'));
        $this->assertSame([], $catalog->resolve('ApiCatalogTest'));
        $this->assertSame([], $catalog->resolve('Page'));
        $this->assertSame([], $catalog->resolve('Cart'));
    }

    public function test_a_file_inside_the_project_is_shown_relative_to_it(): void
    {
        $file = (string) (new ReflectionClass(SampleMode::class))->getFileName();
        $root = dirname($file, 2);

        $class = (new ApiReflector($root))->describe(SampleMode::class);

        $this->assertSame('Api/SampleMode.php', $class->file);
    }

    public function test_a_long_class_says_how_much_was_left_out(): void
    {
        $members = [];
        for ($i = 0; $i < ApiClass::MAX_MEMBERS + 3; $i++) {
            $members[] = new ApiMember('method', 'm' . $i, 'm' . $i . '()', '');
        }

        $class = new ApiClass('X', 'class', 'x.php', '', false, null, [], [], $members);

        $this->assertCount(ApiClass::MAX_MEMBERS, $class->toArray()['members']);
        $this->assertSame(3, $class->toArray()['omitted_members']);
    }

    /**
     * @param class-string ...$classes
     */
    private function catalog(string ...$classes): ApiCatalog
    {
        $map = [];
        foreach ($classes as $class) {
            $map[$class] = (string) (new ReflectionClass($class))->getFileName();
        }

        return new ApiCatalog($map, new ApiReflector('/nowhere'));
    }

    private function parameter(ApiClass $class, string $name): ApiParameter
    {
        foreach ($class->parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        $this->fail('No parameter ' . $name);
    }

    private function member(ApiClass $class, string $name): ApiMember
    {
        foreach ($class->members as $member) {
            if ($member->name === $name) {
                return $member;
            }
        }

        $this->fail('No member ' . $name);
    }

    /**
     * @return list<string>
     */
    private function memberNames(ApiClass $class): array
    {
        return array_map(static fn (ApiMember $member): string => $member->name, $class->members);
    }

    private function firstHit(ApiCatalog $catalog, string $term): ApiHit
    {
        $hits = $catalog->search($term)->hits;
        $this->assertNotSame([], $hits, 'Nothing found for ' . $term);

        return $hits[0];
    }
}
