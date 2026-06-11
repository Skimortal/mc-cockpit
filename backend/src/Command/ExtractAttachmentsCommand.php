<?php

namespace App\Command;

use App\Entity\Attachment;
use App\Service\Attachment\AttachmentExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:extract-attachments', description: 'Liest Anhang-Texte per Tika aus und indexiert sie für die Suche.')]
class ExtractAttachmentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentExtractor $extractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximale Anzahl pro Lauf', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        /** @var Attachment[] $pending */
        $pending = $this->em->getRepository(Attachment::class)->findBy(['extractedText' => null], ['id' => 'ASC'], $limit);

        $done = 0;
        $skipped = 0;
        foreach ($pending as $a) {
            if (!$this->extractor->isExtractable($a)) {
                $a->extractedText = '[übersprungen]';
                $this->em->flush();
                ++$skipped;
                continue;
            }
            if ($this->extractor->extract($a)) {
                ++$done;
            }
        }

        $io->success(sprintf('%d extrahiert, %d übersprungen (offen waren: %d).', $done, $skipped, \count($pending)));

        return Command::SUCCESS;
    }
}
