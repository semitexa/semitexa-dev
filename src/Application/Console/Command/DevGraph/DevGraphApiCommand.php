<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Api\ApiCatalog;
use Semitexa\Dev\Application\Service\Api\ApiClass;
use Semitexa\Dev\Application\Service\Api\ApiHit;
use Semitexa\Dev\Application\Service\Api\ApiParameter;
use Semitexa\Dev\Application\Service\Api\ApiSearchResult;
use Semitexa\Dev\Application\Service\Console\LookupRefusal;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The framework's API without opening vendor/.
 *
 * Agents asked "how do I make a Column nullable" and "is there a regex validator"
 * by grepping vendor/ and reading whole files, because no tool answered either
 * question. This one answers both in a few hundred bytes.
 */
#[AsCommand(name: 'dev:graph:api', description: 'Show the signatures of a framework class, or search the framework API by name and doc summary')]
final class DevGraphApiCommand extends BaseCommand
{
    private const string CLASS_ARTIFACT = 'semitexa-dev.api/v1';
    private const string SEARCH_ARTIFACT = 'semitexa-dev.api-search/v1';

    /** @var array<int, ApiCatalog> without (0) and with (1) the showcases, each built lazily; see DevGraphEventCommand */
    private array $catalogs = [];

    public function __construct()
    {
        parent::__construct('dev:graph:api');
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Short or full class name (e.g. Column)')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Words to find in class and member names and doc summaries (e.g. "max length")')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'With --search: how many hits to show', (string) ApiCatalog::SEARCH_LIMIT)
            ->addOption('with-demo', null, InputOption::VALUE_NONE, 'Also search the demo and showcase packages')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = trim((string) $input->getOption('name'));
        $term = trim((string) $input->getOption('search'));

        if ($name !== '') {
            return $this->describe($name, $input, $output);
        }
        if ($term !== '') {
            return $this->search($term, $input, $output);
        }

        return LookupRefusal::refuse($input, $output, self::CLASS_ARTIFACT, 'Give --name=<class> or --search=<words>.');
    }

    private function describe(string $name, InputInterface $input, OutputInterface $output): int
    {
        $matches = $this->catalog((bool) $input->getOption('with-demo'))->resolve($name);
        if ($matches === []) {
            return $this->refuseUnknown($name, $input, $output);
        }

        $chosen = $this->catalog((bool) $input->getOption('with-demo'))->lookup($name);
        if ($chosen === null) {
            return LookupRefusal::refuse(
                $input,
                $output,
                self::CLASS_ARTIFACT,
                $this->catalog((bool) $input->getOption('with-demo'))->refusalFor($name),
                'Classes',
                $matches,
            );
        }

        $also = array_values(array_diff($matches, [$chosen->name]));
        if ($input->getOption('json')) {
            $this->writeJson($output, ['artifact' => self::CLASS_ARTIFACT, 'status' => 'ok', 'class' => $chosen->toArray(), 'also' => $also]);

            return Command::SUCCESS;
        }

        $this->writeClass($output, $chosen, $also);

        return Command::SUCCESS;
    }

    private function refuseUnknown(string $name, InputInterface $input, OutputInterface $output): int
    {
        return LookupRefusal::refuse(
            $input,
            $output,
            self::CLASS_ARTIFACT,
            sprintf('No framework class is named "%s".', $name),
            'Closest classes',
            $this->catalog((bool) $input->getOption('with-demo'))->similar($name),
            [['cmd' => 'ai:ask', 'args' => ['api', '--search=' . $name], 'why' => 'Find it by a member or a word from its description']],
        );
    }

    private function search(string $term, InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $withDemo = (bool) $input->getOption('with-demo');
        $result = $this->catalog($withDemo)->search($term, $limit);
        // Cut hits are said, with the call that shows them (tk-ask-api-search-truncates-silently).
        $more = [];
        if ($result->total > count($result->hits)) {
            $more[] = [
                'cmd' => 'ai:ask',
                // The same search, all of it (review, dev#242: --with-demo was dropped).
                'args' => ['api', '--search=' . $term, '--limit=' . $result->total, ...($withDemo ? ['--with-demo'] : []), '--json'],
                'why' => sprintf('%d more not shown; or add a word to narrow', $result->total - count($result->hits)),
            ];
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, [
                'artifact' => self::SEARCH_ARTIFACT,
                'status' => 'ok',
                'query' => $term,
                'partial' => $result->partial,
                'total' => $result->total,
                'truncated' => $more !== [],
                'hits' => array_map(static fn (ApiHit $hit): array => $hit->toArray(), $result->hits),
                'unread_classes' => $result->unread,
                'next_command' => $more,
            ]);

            return Command::SUCCESS;
        }

        $this->writeHits($output, $term, $result);
        if ($more !== []) {
            $this->writeLines($output, ['', sprintf('%d more not shown: --limit=%d shows them all, or add a word to narrow.', $result->total - count($result->hits), $result->total)]);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $also
     */
    private function writeClass(OutputInterface $output, ApiClass $class, array $also): void
    {
        $lines = [$class->name . ' (' . $class->kind . ($class->stable ? ', @api' : '') . ')', $class->file];
        if ($class->summary !== '') {
            $lines[] = $class->summary;
        }
        if ($class->extends !== null) {
            $lines[] = 'extends ' . $class->extends;
        }
        if ($class->traits !== []) {
            $lines[] = 'uses ' . implode(', ', $class->traits);
        }

        if ($class->parameters !== []) {
            $lines[] = '';
            $lines[] = $class->kind === 'attribute' ? 'Parameters:' : 'Constructor:';
            foreach ($class->parameters as $parameter) {
                $lines[] = '  ' . $this->parameterLine($parameter);
            }
        }

        if ($class->members !== []) {
            $lines[] = '';
            $lines[] = 'Members:';
            foreach ($class->shownMembers() as $member) {
                $lines[] = '  ' . $member->signature
                    . ($member->from === null ? '' : '  [from ' . $member->from . ']')
                    . ($member->summary === '' ? '' : '  // ' . $member->summary);
            }
            if ($class->omittedMembers() > 0) {
                $lines[] = sprintf('  … %d more; read the file for those.', $class->omittedMembers());
            }
        }

        if ($class->implementers !== []) {
            $lines[] = '';
            $lines[] = 'Implemented by:';
            foreach ($class->shownImplementers() as $implementer) {
                $lines[] = '  ' . $implementer;
            }
            if ($class->omittedImplementers() > 0) {
                $lines[] = sprintf('  … %d more.', $class->omittedImplementers());
            }
        }

        if ($also !== []) {
            $lines[] = '';
            $lines[] = 'Also named this: ' . implode(', ', $also);
        }

        $this->writeLines($output, $lines);
    }

    private function parameterLine(ApiParameter $parameter): string
    {
        return $parameter->signature()
            . ($parameter->cases === [] ? '' : '  // ' . implode('|', $parameter->cases))
            . ($parameter->implementers === [] ? '' : '  // one of: ' . implode(', ', $parameter->implementers))
            . ($parameter->summary === '' ? '' : '  // ' . $parameter->summary);
    }

    private function writeHits(OutputInterface $output, string $term, ApiSearchResult $result): void
    {
        if ($result->hits === []) {
            $lines = [sprintf('Nothing in the framework API matches "%s".', $term)];
        } else {
            $lines = [sprintf(
                $result->partial ? 'Nothing matches every word of "%3$s"; closest %1$d of %2$d:' : '%d of %d matches for "%s":',
                count($result->hits),
                $result->total,
                $term,
            )];
        }

        $current = null;
        foreach ($result->hits as $hit) {
            if ($hit->class !== $current) {
                $current = $hit->class;
                $lines[] = '';
                $lines[] = $hit->class . ' (' . $hit->classKind . ($hit->stable ? ', @api' : '') . ') ' . $hit->file;
            }
            $lines[] = '  ' . $hit->signature . ($hit->summary === '' ? '' : '  // ' . $hit->summary);
        }

        if ($result->unread !== []) {
            $lines[] = '';
            $lines[] = sprintf('Not searched, could not be loaded: %s', implode(', ', $result->unread));
        }

        $this->writeLines($output, $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function writeLines(OutputInterface $output, array $lines): void
    {
        // Raw: a signature holds `<` and `>` that Symfony would read as style tags.
        $output->writeln($lines, OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        $output->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
    }

    /** One per setting: a command run twice must not answer the second from the first's (review, dev#242). */
    private function catalog(bool $withShowcase = false): ApiCatalog
    {
        return $this->catalogs[$withShowcase ? 1 : 0] ??= ApiCatalog::forFramework((new ClassDiscovery())->getClassMap(), ProjectRoot::get(), $withShowcase);
    }
}
