<?php

namespace App\Command;

use App\Entity\Mailbox;
use App\Mail\ConversationThreader;
use App\Mail\ImapPoller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:fetch-mail', description: 'Ruft alle aktiven Postfächer per IMAP ab und threadet neue Mails.')]
class FetchMailCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImapPoller $poller,
        private readonly ConversationThreader $threader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mailboxes = $this->em->getRepository(Mailbox::class)->findBy(['active' => true]);

        if (!$mailboxes) {
            $io->warning('Keine aktiven Postfächer konfiguriert.');

            return Command::SUCCESS;
        }

        $totalNew = 0;
        foreach ($mailboxes as $mailbox) {
            if ('' === $mailbox->password) {
                $io->note(sprintf('Postfach %s übersprungen (kein Passwort gesetzt).', $mailbox->email));
                continue;
            }
            try {
                $messages = $this->poller->poll($mailbox);
                $new = 0;
                foreach ($messages as $msg) {
                    if ($this->threader->ingest($mailbox, $msg)) {
                        ++$new;
                    }
                }
                $totalNew += $new;
                $io->writeln(sprintf(' %s: %d abgerufen, %d neu', $mailbox->email, \count($messages), $new));
            } catch (\Throwable $e) {
                $io->error(sprintf('%s: %s', $mailbox->email, $e->getMessage()));
            }
        }

        $io->success(sprintf('Fertig. %d neue Nachricht(en).', $totalNew));

        return Command::SUCCESS;
    }
}
