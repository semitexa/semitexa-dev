<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Classifier;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiTaskCommand;
use Semitexa\Dev\Application\Console\Command\MakeCommand;
use Semitexa\Dev\Application\Service\Ai\Classifier\ClassificationResult;
use Semitexa\Dev\Application\Service\Ai\Recipe\RecipeRegistry;
use Symfony\Component\Console\Input\ArgvInput;

final class NextCommandContractTest extends TestCase
{
    public function testEveryGeneratorRecipeCarriesSharedInputsIntoAPreview(): void
    {
        $builder = new \ReflectionMethod(AiTaskCommand::class, 'buildNextCommands');
        foreach (RecipeRegistry::all() as $recipe) {
            if ($recipe->generator_chain === []) {
                continue;
            }
            $result = new ClassificationResult($recipe, 10, 'contract test', 'Billing', [], ClassificationResult::CONFIDENCE_HIGH);
            $hints = $builder->invoke(new AiTaskCommand(), $result);
            self::assertSame('make', $hints[0]['cmd']);
            self::assertNotContains('--write', $hints[0]['args']);
            $input = new ArgvInput(array_merge(['make'], $hints[0]['args']), (new MakeCommand())->getDefinition());
            $input->validate();
            self::assertSame($recipe->id, $input->getOption('recipe'));
            $shared = [];
            foreach ($input->getOption('arg') as $arg) {
                [$key, $value] = explode('=', $arg, 2);
                $shared[$key] = $value;
            }
            foreach ($recipe->generator_chain as $step) {
                $suffix = str_replace(' ', '', ucwords(str_replace('-', ' ', substr($step, 5))));
                $class = 'Semitexa\\Dev\\Application\\Console\\Command\\Make' . $suffix . 'Command';
                $definition = (new $class())->getDefinition();
                // Required option presence is currently enforced in execute(),
                // not Symfony's VALUE_REQUIRED (which only requires a value).
                $source = file_get_contents((new \ReflectionClass($class))->getFileName());
                if (preg_match('/foreach \(\[([^\]]+)\] as \$required\)/', $source, $match)) {
                    preg_match_all("/'([^']+)'/", $match[1], $required);
                    foreach ($required[1] as $key) {
                        self::assertArrayHasKey($key, $shared, $recipe->id . ': ' . $step . ' needs ' . $key);
                    }
                }
                foreach (array_keys($shared) as $key) {
                    if ($definition->hasOption($key)) {
                        self::assertTrue($definition->getOption($key)->acceptValue());
                    }
                }
            }
            // No recipe hint may be silently discarded by every chain step.
            foreach (array_keys($shared) as $key) {
                $accepted = false;
                foreach ($recipe->generator_chain as $step) {
                    $suffix = str_replace(' ', '', ucwords(str_replace('-', ' ', substr($step, 5))));
                    $class = 'Semitexa\\Dev\\Application\\Console\\Command\\Make' . $suffix . 'Command';
                    $accepted = $accepted || (new $class())->getDefinition()->hasOption($key);
                }
                self::assertTrue($accepted, $recipe->id . ': unknown option ' . $key);
            }
        }
    }

    public function testEveryVerificationHintSuppliesAFileSource(): void
    {
        $builder = new \ReflectionMethod(AiTaskCommand::class, 'buildNextCommands');
        foreach (RecipeRegistry::all() as $recipe) {
            $result = new ClassificationResult($recipe, 10, 'contract test', null, [], ClassificationResult::CONFIDENCE_HIGH);
            foreach ($builder->invoke(new AiTaskCommand(), $result) as $hint) {
                if ($hint['cmd'] === 'ai:verify') {
                    self::assertContains('--files=<paths>', $hint['args'], $recipe->id);
                }
                if ($hint['cmd'] === 'orm:diff') {
                    self::assertNotContains('--json', $hint['args']);
                }
            }
        }
    }
}
