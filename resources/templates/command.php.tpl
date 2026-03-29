<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsCommand(name: '{{commandName}}', description: '{{description}}')]
final class {{className}} extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('{{commandName}}')
            ->setDescription('{{description}}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // TODO: implement command logic

        $io->success('Done.');

        return self::SUCCESS;
    }
}
