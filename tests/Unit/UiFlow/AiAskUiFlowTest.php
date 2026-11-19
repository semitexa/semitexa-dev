<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\UiFlow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\AiAskCommand;
use Semitexa\Dev\Tests\Support\JsonRead;
use Semitexa\Dev\Tests\Support\UiFlowShop;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphUiFlowCommand;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `ai:ask ui-flow [--screen|--module]`: the UI flow as JSON for an agent,
 * the model the Observatory's tab draws.
 *
 * The subject is routed to `ai:review-graph:ui-flow`. That command opens the
 * project's own graph, so here one with its options, answering from the test
 * graph through the real command's answer(), stands under its name: what is
 * proved is the routing, the options that reach it, and the document.
 */
final class AiAskUiFlowTest extends TestCase
{
    private UiFlowShop $shop;

    protected function setUp(): void
    {
        $this->shop = UiFlowShop::built();
    }

    protected function tearDown(): void
    {
        $this->shop->remove();
    }

    #[Test]
    public function without_an_option_it_lists_the_modules_that_have_screens(): void
    {
        [$exit, $body] = $this->ask([]);

        self::assertSame([0, 'ui_flow', 'overview', 'ok'], [$exit, $body['kind'], $body['view'], $body['status']]);
        self::assertSame(['Blog', 'Shop'], array_column(JsonRead::rows($body['modules']), 'module'));
        self::assertSame(['ui-flow', '--module=Blog'], JsonRead::rows($body['next_command'])[0]['args']);
    }

    #[Test]
    public function a_module_and_a_screen_are_asked_for_by_option(): void
    {
        [$exit, $module] = $this->ask(['--module' => 'Shop']);
        self::assertSame([0, 'module', 'Shop', [UiFlowShop::ORDERS]], [$exit, $module['view'], $module['module'], array_column(JsonRead::rows($module['screens']), 'id')]);
        self::assertSame(['ui-flow', '--screen=' . UiFlowShop::ORDERS], JsonRead::rows($module['next_command'])[0]['args']);

        // By path, as an agent that has only seen the page would name it.
        [$exit, $screen] = $this->ask(['--screen' => '/orders']);
        self::assertSame([0, 'screen', UiFlowShop::ORDERS], [$exit, $screen['view'], JsonRead::map($screen['screen'])['id']]);
        self::assertSame(['/nowhere', '/blog', '{{ row.href }}'], array_column(JsonRead::rows($screen['elements']), 'href'));
        self::assertSame([UiFlowShop::BLOG], array_map(static fn (array $hop): mixed => JsonRead::map($hop['node'])['id'], JsonRead::rows($screen['in'])));
    }

    #[Test]
    public function what_is_not_there_is_an_error_document_and_a_failure(): void
    {
        [$exit, $body] = $this->ask(['--module' => 'Billing']);

        self::assertSame([1, 'error', 'No module "Billing" with a screen. With screens: Blog, Shop.'], [$exit, $body['kind'], $body['error']]);
    }

    #[Test]
    public function the_subject_is_listed_and_bound_to_the_project_graph_command(): void
    {
        $map = JsonRead::map((new \ReflectionClass(AiAskCommand::class))->getConstant('SUBJECT_MAP'));

        self::assertSame('ai:review-graph:ui-flow', $map['ui-flow'] ?? null);
        // The real command declares what ai:ask forwards to it.
        $definition = (new ReviewGraphUiFlowCommand())->getDefinition();
        self::assertSame([true, true], [$definition->hasOption('screen'), $definition->hasOption('module')]);
        self::assertSame('ai:review-graph:ui-flow', (new \ReflectionClass(ReviewGraphUiFlowCommand::class))->getAttributes(\Semitexa\Core\Attribute\AsCommand::class)[0]->newInstance()->name);
    }

    /**
     * @param array<string, string> $options
     * @return array{int, array<string, mixed>}
     */
    private function ask(array $options): array
    {
        $app = new Application();
        $app->setAutoExit(false);
        $app->add(new AiAskCommand());
        $app->add(self::answeringFrom($this->shop->storage));

        $output = new BufferedOutput();
        $exit = $app->find('ai:ask')->run(new ArrayInput(['subject' => 'ui-flow'] + $options), $output);
        /** @var array<string, mixed> $body */
        $body = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        return [$exit, $body];
    }

    /** `ai:review-graph:ui-flow` with the real command's options and answer, over a given graph. */
    private static function answeringFrom(GraphStorage $storage): Command
    {
        return new class($storage) extends Command {
            public function __construct(private readonly GraphStorage $storage)
            {
                parent::__construct('ai:review-graph:ui-flow');
            }

            protected function configure(): void
            {
                $this->setDefinition(clone (new ReviewGraphUiFlowCommand())->getDefinition());
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $screen = $input->getOption('screen');
                $module = $input->getOption('module');
                [$exit, $body] = ReviewGraphUiFlowCommand::answer($this->storage, is_string($screen) ? $screen : null, is_string($module) ? $module : null);
                $output->writeln((string) json_encode($body, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

                return $exit;
            }
        };
    }
}
