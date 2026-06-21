<?php

namespace App\Command;

use App\Entity\Attachment;
use App\Entity\Conversation;
use App\Entity\Email;
use App\Entity\Mailbox;
use App\Entity\Task;
use App\Service\Search\SearchIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gestufte Aufbewahrung pro Postfach:
 *   Stufe A – Anhang-Dateien nach attachment_retention_months von der Platte entfernen
 *             (Mail + extrahierter Text bleiben durchsuchbar; Original liegt im mbox-Archiv).
 *   Stufe B – ganze Mails nach mail_retention_months löschen (Original bleibt im mbox-Archiv).
 *
 * Schutz: Konversationen mit OFFENER Aufgabe werden in Stufe A nie angefasst; in Stufe B
 * werden Konversationen mit IRGENDEINER Aufgabe übersprungen (Verlauf bleibt intakt).
 */
#[AsCommand(name: 'app:apply-retention', description: 'Gestufte Aufbewahrung: alte Anhänge/Mails gemäß Postfach-Einstellungen entfernen.')]
class ApplyRetentionCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchIndexer $search,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was passieren würde – nichts löschen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();
        $attBase = $this->projectDir.'/var/attachments/';

        // Konversationen mit offener bzw. irgendeiner Aufgabe vormerken
        $openTask = [];
        $anyTask = [];
        foreach ($this->em->getRepository(Task::class)->findAll() as $t) {
            $cid = $t->conversation?->id;
            if (null === $cid) {
                continue;
            }
            $anyTask[$cid] = true;
            if ('done' !== $t->status) {
                $openTask[$cid] = true;
            }
        }

        $prunedFiles = 0;
        $prunedBytes = 0;
        $deletedMails = 0;
        $deletedConvs = 0;
        $attRepo = $this->em->getRepository(Attachment::class);
        $emailRepo = $this->em->getRepository(Email::class);

        foreach ($this->em->getRepository(Mailbox::class)->findAll() as $mb) {
            // ----- Stufe A: Anhang-Dateien entfernen -----
            if ($mb->attachmentRetentionMonths > 0) {
                $cutoff = $now->modify("-{$mb->attachmentRetentionMonths} months");
                $atts = $this->em->createQueryBuilder()
                    ->select('a')->from(Attachment::class, 'a')
                    ->join('a.email', 'e')->join('e.conversation', 'c')
                    ->where('c.mailbox = :mb')->andWhere('a.prunedAt IS NULL')
                    ->andWhere("a.path <> ''")->andWhere('e.occurredAt < :cut')
                    ->setParameter('mb', $mb)->setParameter('cut', $cutoff)
                    ->getQuery()->getResult();
                foreach ($atts as $a) {
                    $cid = $a->email?->conversation?->id;
                    if (null !== $cid && isset($openTask[$cid])) {
                        continue; // offene Aufgabe -> Anhänge behalten
                    }
                    if (!$dry) {
                        $f = $attBase.$a->path;
                        if (is_file($f)) {
                            @unlink($f);
                        }
                        if (is_file($f.'.preview.pdf')) {
                            @unlink($f.'.preview.pdf');
                        }
                        $a->prunedAt = $now;
                    }
                    ++$prunedFiles;
                    $prunedBytes += $a->size;
                }
            }

            // ----- Stufe B: ganze Mails löschen -----
            if ($mb->mailRetentionMonths > 0) {
                $cutoff = $now->modify("-{$mb->mailRetentionMonths} months");
                $emails = $this->em->createQueryBuilder()
                    ->select('e')->from(Email::class, 'e')->join('e.conversation', 'c')
                    ->where('c.mailbox = :mb')->andWhere('e.occurredAt < :cut')
                    ->setParameter('mb', $mb)->setParameter('cut', $cutoff)
                    ->getQuery()->getResult();
                $touched = [];
                foreach ($emails as $e) {
                    $c = $e->conversation;
                    $cid = $c?->id;
                    if (null !== $cid && isset($anyTask[$cid])) {
                        continue; // an Aufgabe gebunden -> behalten
                    }
                    if (!$dry) {
                        foreach ($attRepo->findBy(['email' => $e]) as $a) {
                            if ('' !== $a->path && is_file($attBase.$a->path)) {
                                @unlink($attBase.$a->path);
                                @unlink($attBase.$a->path.'.preview.pdf');
                            }
                            $this->em->remove($a);
                        }
                        $this->em->remove($e);
                    }
                    ++$deletedMails;
                    if (null !== $cid) {
                        $touched[$cid] = $c;
                    }
                }
                if (!$dry) {
                    $this->em->flush();
                }
                // Leere, aufgabenfreie Konversationen entfernen
                foreach ($touched as $cid => $c) {
                    if (isset($anyTask[$cid])) {
                        continue;
                    }
                    if ($dry || 0 === $emailRepo->count(['conversation' => $c])) {
                        if (!$dry) {
                            $this->em->remove($c);
                            $this->search->removeDoc(SearchIndexer::CONVERSATIONS, $cid);
                        }
                        ++$deletedConvs;
                    }
                }
            }
        }

        if (!$dry) {
            $this->em->flush();
        }

        $mb2 = round($prunedBytes / 1024 / 1024, 1);
        $io->writeln(($dry ? '[DRY-RUN] ' : '').'Stufe A: '."$prunedFiles Anhang-Dateien entfernt (~{$mb2} MB)");
        $io->writeln(($dry ? '[DRY-RUN] ' : '').'Stufe B: '."$deletedMails Mails, $deletedConvs Konversationen gelöscht");
        $io->success($dry ? 'Dry-Run abgeschlossen – nichts verändert.' : 'Retention angewendet.');

        return Command::SUCCESS;
    }
}
