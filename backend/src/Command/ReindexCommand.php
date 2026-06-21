<?php

namespace App\Command;

use App\Service\Search\SearchIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:search-reindex', description: 'Baut den Meilisearch-Index für Aufgaben, Konversationen, Kunden und Dokumente neu auf.')]
class ReindexCommand extends Command
{
    public function __construct(private readonly SearchIndexer $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->indexer->reindexAll();
        $io->success('Meilisearch-Index neu aufgebaut.');

        return Command::SUCCESS;
    }
}
