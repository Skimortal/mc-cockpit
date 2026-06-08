<?php

namespace App\Command;

use App\Entity\Email;
use App\Service\Triage\EmailTriageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:triage', description: 'Macht aus noch nicht verarbeiteten eingehenden Mails per Claude Aufgaben.')]
class TriageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailTriageService $triage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max. Mails pro Lauf', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $emails = $this->em->getRepository(Email::class)->createQueryBuilder('e')
            ->where('e.direction = :in')->andWhere('e.triagedAt IS NULL')
            ->setParameter('in', 'in')
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        if (!$emails) {
            $io->success('Keine offenen Mails zur Triage.');

            return Command::SUCCESS;
        }

        $created = 0;
        foreach ($emails as $email) {
            try {
                $task = $this->triage->triage($email);
                if ($task) {
                    ++$created;
                    $io->writeln(sprintf(
                        ' ✓ <info>%s</info>  [%s/%s%s]  ← %s',
                        $task->title,
                        $task->type,
                        $task->priority,
                        $task->suggestedAssignee ? ', '.$task->suggestedAssignee : '',
                        mb_substr((string) $email->subject, 0, 45)
                    ));
                } else {
                    $io->writeln(sprintf(' · (keine Aufgabe) ← %s', mb_substr((string) $email->subject, 0, 45)));
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Mail #%d: %s', $email->id, $e->getMessage()));
            }
        }

        $io->success(sprintf('%d Aufgabe(n) erstellt aus %d Mail(s).', $created, \count($emails)));

        return Command::SUCCESS;
    }
}
