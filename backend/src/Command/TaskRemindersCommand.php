<?php

namespace App\Command;

use App\Entity\Task;
use App\Mail\Mailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Schickt jedem Zuständigen einmal täglich (morgens, Wiener Zeit) EINE Sammel-Mail mit
 * seinen heute fälligen + überfälligen, noch offenen Aufgaben. Idempotent über
 * task.due_reminder_sent_on – darf gefahrlos öfter pro Tag aufgerufen werden.
 */
#[AsCommand(name: 'app:task-reminders', description: 'Erinnert Zuständige per Mail an heute fällige/überfällige Aufgaben.')]
final class TaskRemindersCommand extends Command
{
    private const BASE_URL = 'https://crm.most-connect.com';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Auch außerhalb des Morgenfensters (07–20 Uhr) senden.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was verschickt würde – nichts senden, nichts markieren.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zone = new \DateTimeZone('Europe/Vienna');
        $now = new \DateTimeImmutable('now', $zone);
        $hour = (int) $now->format('H');
        if (!$input->getOption('force') && ($hour < 7 || $hour >= 20)) {
            return Command::SUCCESS; // nachts/abends nicht erinnern
        }

        $today = new \DateTimeImmutable('today', $zone);
        $tomorrow = $today->modify('+1 day');

        /** @var list<Task> $tasks */
        $tasks = $this->em->getRepository(Task::class)->createQueryBuilder('t')
            ->where('t.status != :done')->setParameter('done', 'done')
            ->andWhere('t.assignee IS NOT NULL')
            ->andWhere('t.dueDate IS NOT NULL')
            ->andWhere('t.dueDate < :tomorrow')->setParameter('tomorrow', $tomorrow)
            ->andWhere('(t.dueReminderSentOn IS NULL OR t.dueReminderSentOn < :today)')->setParameter('today', $today)
            ->getQuery()->getResult();

        if (!$tasks) {
            return Command::SUCCESS;
        }

        // Nach Zuständigem (per E-Mail, eindeutig) gruppieren -> EINE Sammel-Mail pro Person.
        /** @var array<string, array{name: string, email: string, tasks: list<Task>}> $byUser */
        $byUser = [];
        foreach ($tasks as $t) {
            $u = $t->assignee;
            $email = $u ? trim($u->getEmail()) : '';
            if ('' === $email) {
                continue;
            }
            $key = mb_strtolower($email);
            if (!isset($byUser[$key])) {
                $byUser[$key] = [
                    'name' => trim($u->getFirstName().' '.$u->getLastName()) ?: $email,
                    'email' => $email,
                    'tasks' => [],
                ];
            }
            $byUser[$key]['tasks'][] = $t;
        }

        $dry = (bool) $input->getOption('dry-run');
        $sent = 0;
        foreach ($byUser as $grp) {
            if ($dry) {
                $output->writeln(sprintf('[dry-run] → %s (%s): %d Aufgabe(n): %s', $grp['name'], $grp['email'], \count($grp['tasks']), implode('; ', array_map(static fn (Task $t) => $t->title, $grp['tasks']))));
                ++$sent;
                continue;
            }
            try {
                $this->mailer->sendDueReminder($grp['email'], $grp['name'], $grp['tasks'], self::BASE_URL);
                foreach ($grp['tasks'] as $t) {
                    $t->dueReminderSentOn = $today;
                }
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Fälligkeits-Erinnerung fehlgeschlagen', ['user' => $grp['email'], 'error' => $e->getMessage()]);
            }
        }
        if (!$dry) {
            $this->em->flush();
        }

        $output->writeln(sprintf('%s%d Empfänger (%d Aufgaben).', $dry ? '[dry-run] ' : 'Verschickt: ', $sent, \count($tasks)));

        return Command::SUCCESS;
    }
}
