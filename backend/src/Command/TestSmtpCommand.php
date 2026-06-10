<?php

namespace App\Command;

use App\Entity\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email as MimeEmail;

#[AsCommand(name: 'app:test-smtp', description: 'Sendet eine Test-Mail über das SMTP eines Postfachs (Diagnose).')]
class TestSmtpCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Postfach-E-Mail (z. B. support@hdv-stojakovic.at)')
            ->addArgument('to', InputArgument::REQUIRED, 'Empfänger')
            ->addArgument('subject', InputArgument::OPTIONAL, '', 'Cockpit SMTP-Test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy(['email' => $input->getArgument('from')]);
        if (!$mailbox) {
            $io->error('Postfach nicht gefunden.');

            return Command::FAILURE;
        }
        if ('' === $mailbox->password) {
            $io->error('Kein SMTP-Passwort hinterlegt.');

            return Command::FAILURE;
        }

        $transport = new EsmtpTransport($mailbox->smtpHost, $mailbox->smtpPort, 'ssl' === $mailbox->smtpEncryption);
        $transport->setUsername($mailbox->username);
        $transport->setPassword($mailbox->password);

        $mime = (new MimeEmail())
            ->from($mailbox->email)
            ->to($input->getArgument('to'))
            ->subject($input->getArgument('subject'))
            ->text("Test vom MOST Connect Cockpit.\nWenn diese Mail ankommt, funktioniert der SMTP-Versand.");

        try {
            (new SymfonyMailer($transport))->send($mime);
        } catch (\Throwable $e) {
            $io->error('Versand fehlgeschlagen: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Test-Mail über %s an %s gesendet.', $mailbox->smtpHost, $input->getArgument('to')));

        return Command::SUCCESS;
    }
}
