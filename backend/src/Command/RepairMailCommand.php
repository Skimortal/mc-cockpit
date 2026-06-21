<?php

namespace App\Command;

use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Mailbox;
use App\Mail\ImapPoller;
use App\Service\Search\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repariert bestehende Daten:
 *  1) dekodiert MIME-encoded-word-Betreffe (=?utf-8?…) in Konversationen + Mails
 *  2) ersetzt fälschlich als „Kunde" gespeicherte eigene Postfach-Adressen durch die externe Gegenpartei
 */
#[AsCommand(name: 'app:repair-mail', description: 'Betreff-Encoding dekodieren + falsche Kunden-Zuordnung (eigenes Postfach) korrigieren.')]
class RepairMailCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $own = [];
        foreach ($this->em->getRepository(Mailbox::class)->findAll() as $mb) {
            if ($mb->email) {
                $own[mb_strtolower($mb->email)] = true;
            }
        }

        // 1) Betreffe dekodieren
        $convFixed = 0;
        foreach ($this->em->getRepository(Conversation::class)->findAll() as $c) {
            $dec = ImapPoller::mimeDecode($c->subject);
            if ($dec !== $c->subject) {
                $c->subject = $dec;
                ++$convFixed;
            }
        }
        $mailFixed = 0;
        foreach ($this->em->getRepository(Email::class)->findAll() as $e) {
            if (null !== $e->subject) {
                $dec = ImapPoller::mimeDecode($e->subject);
                if ($dec !== $e->subject) {
                    $e->subject = $dec;
                    ++$mailFixed;
                }
            }
        }
        $io->writeln("Betreffe dekodiert: $convFixed Konversationen, $mailFixed Mails");

        // 2) Falsche Kunden (eigenes Postfach) neu bestimmen
        $custFixed = 0;
        $emailRepo = $this->em->getRepository(Email::class);
        foreach ($this->em->getRepository(Conversation::class)->findAll() as $c) {
            $cur = $c->customerEmail ? mb_strtolower($c->customerEmail) : null;
            if (null !== $cur && !isset($own[$cur])) {
                continue; // bereits externe Adresse – ok
            }
            $emails = $emailRepo->findBy(['conversation' => $c], ['occurredAt' => 'ASC']);
            $newEmail = null;
            // bevorzugt: externer Absender einer eingehenden Mail
            foreach ($emails as $e) {
                if ($e->fromAddress && !isset($own[mb_strtolower($e->fromAddress)])) {
                    $newEmail = $e->fromAddress;
                    break;
                }
            }
            // sonst: externer Empfänger einer ausgehenden Mail
            if (null === $newEmail) {
                foreach ($emails as $e) {
                    if ($e->toAddress && !isset($own[mb_strtolower($e->toAddress)])) {
                        $newEmail = $e->toAddress;
                        break;
                    }
                }
            }
            if (null !== $newEmail && mb_strtolower($newEmail) !== $cur) {
                $c->customerEmail = $newEmail;
                $c->customerName = null; // Name nicht aus Mail rekonstruierbar; UI fällt auf Adresse zurück
                ++$custFixed;
            }
        }
        $io->writeln("Kunden-Zuordnung korrigiert: $custFixed Konversationen");

        $this->em->flush();

        // 3) Suchindex neu aufbauen (Betreffe + Kunden flossen in den Index)
        $this->indexer->reindexAll();
        $io->success('Reparatur abgeschlossen + Suchindex aktualisiert.');

        return Command::SUCCESS;
    }
}
