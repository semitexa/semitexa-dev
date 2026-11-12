<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\DevGraph\DevGraphApiCommand;
use Semitexa\Dev\Application\Service\Api\ApiCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two questions the bench agents grepped vendor/ for, asked of the real
 * installed framework. If these stop being answered, agents go back to reading
 * whole source files.
 */
final class DevGraphApiCommandTest extends TestCase
{
    public function test_it_answers_how_to_make_a_column_nullable(): void
    {
        $tester = $this->ask(['--name' => 'Column']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Semitexa\\Orm\\Attribute\\Column (attribute, @api)', $display);
        $this->assertStringContainsString('bool $nullable = false', $display);
        $this->assertStringContainsString('?int $length = null', $display);
        $this->assertStringContainsString('Semitexa\\Orm\\Adapter\\MySqlType', $display, 'What goes in type: must not need another search');
    }

    public function test_it_finds_the_regex_and_max_length_validators(): void
    {
        $regex = $this->ask(['--search' => 'regex', '--json' => true]);
        $decoded = json_decode(trim($regex->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('semitexa-dev.api-search/v1', $decoded['artifact']);
        $this->assertSame('validateRegex', $decoded['hits'][0]['member']);
        $this->assertStringContainsString('StringValidationTrait', $decoded['hits'][0]['class']);

        $length = $this->ask(['--search' => 'max length', '--json' => true]);
        $decoded = json_decode(trim($length->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertContains('validateMaxLength', array_slice(array_column($decoded['hits'], 'member'), 0, 3));
    }

    public function test_the_answer_stays_a_fraction_of_the_file_it_replaces(): void
    {
        $this->assertLessThan(2048, strlen($this->ask(['--name' => 'Column'])->getDisplay()));
        $this->assertLessThan(2048, strlen($this->ask(['--search' => 'regex'])->getDisplay()));
    }

    public function test_a_broad_search_is_bounded_and_says_how_much_there_was(): void
    {
        $decoded = json_decode(trim($this->ask(['--search' => 'validate', '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(ApiCatalog::SEARCH_LIMIT, $decoded['hits']);
        $this->assertGreaterThan(ApiCatalog::SEARCH_LIMIT, $decoded['total']);
        // Said, with the call that shows the rest (tk-ask-api-search-truncates-silently).
        $this->assertTrue($decoded['truncated']);
        $this->assertContains('--limit=' . $decoded['total'], $decoded['next_command'][0]['args']);
    }

    public function test_the_rest_of_a_cut_search_is_one_limit_away_and_a_whole_one_is_not_cut(): void
    {
        $all = json_decode(trim($this->ask(['--search' => 'validate', '--limit' => '500', '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount($all['total'], $all['hits']);
        $this->assertFalse($all['truncated']);
        $this->assertSame([], $all['next_command']);

        $text = $this->ask(['--search' => 'validate'])->getDisplay();
        $this->assertStringContainsString('more not shown: --limit=' . $all['total'] . ' shows them all', $text);
    }

    /** tk-ask-api-search-truncates-silently: the redirect a handler uses came tenth, behind a demo OAuth client's. */
    public function test_core_ranks_first_and_the_showcases_are_left_out_unless_asked(): void
    {
        $decoded = json_decode(trim($this->ask(['--search' => 'redirect', '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $top = array_map(static fn (array $hit): string => $hit['class'] . '::' . $hit['member'], array_slice($decoded['hits'], 0, 3));
        $this->assertContains('Semitexa\\Core\\Http\\Response\\ResourceResponse::setRedirect', $top);
        $this->assertSame([], array_filter($decoded['hits'], static fn (array $hit): bool => str_starts_with($hit['class'], 'Semitexa\\Demo\\')));

        $withDemo = json_decode(trim($this->ask(['--search' => 'redirect uri', '--with-demo' => true, '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame([], array_filter($withDemo['hits'], static fn (array $hit): bool => str_starts_with($hit['class'], 'Semitexa\\Demo\\')), 'asked for, the demo is searched');
    }

    public function test_an_unknown_class_refuses_under_json_and_offers_similar_names(): void
    {
        $tester = $this->ask(['--name' => 'StringValidation', '--json' => true]);
        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertSame('error', $decoded['status']);
        $this->assertContains('Semitexa\\Core\\Validation\\Trait\\StringValidationTrait', $decoded['candidates']);
    }

    public function test_it_refuses_when_asked_nothing(): void
    {
        $tester = $this->ask(['--json' => true]);
        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('--name', $decoded['error']);
    }

    public function test_text_that_looks_like_a_console_tag_is_printed_as_it_is(): void
    {
        $display = $this->ask(['--search' => 'zzqq <info>zzqq</info>'])->getDisplay();

        $this->assertStringContainsString('<info>', $display, 'Symfony would swallow it as a style tag');
    }

    public function test_bytes_that_are_not_utf8_still_get_a_json_answer(): void
    {
        $decoded = json_decode(trim($this->ask(['--search' => "regex\xff", '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $decoded['status']);
    }

    public function test_a_class_sharing_a_file_with_its_trait_keeps_its_own_methods(): void
    {
        $decoded = json_decode(trim($this->ask(['--name' => 'IncrementalEngine', '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $own = array_column(array_filter($decoded['class']['members'], static fn (array $member): bool => $member['from'] === null), 'name');
        $this->assertContains('fullBuild', $own);
    }

    public function test_a_phrase_with_no_exact_match_still_finds_the_validator(): void
    {
        $decoded = json_decode(trim($this->ask(['--search' => 'max length validator', '--json' => true])->getDisplay()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['partial']);
        $this->assertContains('validateMaxLength', array_column($decoded['hits'], 'member'));
    }

    public function test_a_pasted_method_and_attribute_are_found(): void
    {
        $this->assertStringContainsString('validateRegex', $this->ask(['--search' => 'validateRegex()'])->getDisplay());
        $this->assertStringContainsString('Semitexa\\Orm\\Attribute\\Column', $this->ask(['--search' => '#[Column]'])->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function ask(array $input): CommandTester
    {
        $tester = new CommandTester(new DevGraphApiCommand());
        $tester->execute($input);

        return $tester;
    }
}
