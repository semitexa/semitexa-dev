<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Ai\Context\ContextPacker;
use Semitexa\Dev\Ai\Recipe\Recipe;
use Semitexa\Dev\Ai\Recipe\RecipeRegistry;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:context', description: 'Pack prior art + signals for a recipe (NDJSON, agent-facing)')]
final class AiContextCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

    public function __construct()
    {
        parent::__construct('ai:context');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('recipe', InputArgument::REQUIRED, 'Recipe id (see: ai:task)')
            ->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Restrict prior art to this module')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a context_summary event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recipeId = (string) $input->getArgument('recipe');
        $module = $input->getOption('module');

        $recipe = RecipeRegistry::find($recipeId);
        if ($recipe === null) {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => "unknown recipe: {$recipeId}",
                'hint'  => 'list known recipes with: ai:task --help, or pick one from the README.',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $packer = new ContextPacker($this->getProjectRoot());
        $items = $packer->pack($recipe, $module);

        $output->writeln(json_encode([
            'kind'        => 'summary',
            'recipe'      => $recipe->id,
            'module'      => $module,
            'prior_art'   => count($items),
            'risk_hint'   => $recipe->default_risk,
        ], JSON_UNESCAPED_SLASHES));

        $this->emitConventions($output, $recipe);

        foreach ($items as $item) {
            $output->writeln(json_encode([
                'kind'   => 'prior_art',
                'path'   => $item->path,
                'module' => $item->module,
                'type'   => $item->type,
                'score'  => $item->score,
                'why'    => $item->why,
                'action' => 'read',
            ], JSON_UNESCAPED_SLASHES));
        }

        if ($items === []) {
            $output->writeln(json_encode([
                'kind'   => 'note',
                'note'   => 'no prior art found in src/modules — codebase may be greenfield for this recipe',
                'action' => 'none',
            ], JSON_UNESCAPED_SLASHES));
        }

        $this->appendToTrace($input, $output, $recipe, $module, $items);

        return self::SUCCESS;
    }

    /**
     * @param list<\Semitexa\Dev\Ai\Context\PriorArtItem> $items
     */
    private function appendToTrace(InputInterface $input, OutputInterface $output, Recipe $recipe, ?string $module, array $items): void
    {
        $top = array_slice($items, 0, 3);
        $summary = sprintf(
            'context for %s%s — %d prior-art items',
            $recipe->id,
            $module !== null ? " in module={$module}" : '',
            count($items),
        );
        $payload = [
            'artifact'    => 'semitexa-dev.ai-context/v1',
            'recipe'      => $recipe->id,
            'module'      => $module,
            'prior_art'   => count($items),
            'risk_hint'   => $recipe->default_risk,
            'top'         => array_map(static fn($i) => [
                'path'  => $i->path,
                'type'  => $i->type,
                'score' => $i->score,
            ], $top),
        ];
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::CONTEXT_SUMMARY, $summary, $payload);
    }

    private function emitConventions(OutputInterface $output, Recipe $recipe): void
    {
        if ($recipe->context_signals === []) {
            return;
        }
        $output->writeln(json_encode([
            'kind'     => 'convention',
            'signals'  => $recipe->context_signals,
            'note'     => 'files matching these path fragments are the canonical home for this recipe',
        ], JSON_UNESCAPED_SLASHES));
    }
}
