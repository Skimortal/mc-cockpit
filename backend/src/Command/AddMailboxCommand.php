<?php

namespace App\Command;

use App\Entity\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:add-mailbox', description: 'Legt ein Postfach an/aktualisiert es (Default: World4You). Passwort optional.')]
class AddMailboxCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Anzeigename, z. B. „Office"')
            ->addArgument('email', InputArgument::REQUIRED, 'z. B. office@hdv-stojakovic.at')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Postfach-Passwort (nur setzen, wenn angegeben)')
            ->addOption('imap-host', null, InputOption::VALUE_REQUIRED, '', 'mail.world4you.com')
            ->addOption('imap-port', null, InputOption::VALUE_REQUIRED, '', '993')
            ->addOption('imap-encryption', null, InputOption::VALUE_REQUIRED, '', 'ssl')
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, '', 'smtp.world4you.com')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, '', '587')
            ->addOption('smtp-encryption', null, InputOption::VALUE_REQUIRED, '', 'tls');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        $mailbox = $this->em->getRepository(Mailbox::class)->findOneBy(['email' => $email]) ?? new Mailbox();
        $mailbox->name = $input->getArgument('name');
        $mailbox->email = $email;
        $mailbox->username = $email;
        $mailbox->imapHost = $input->getOption('imap-host');
        $mailbox->imapPort = (int) $input->getOption('imap-port');
        $mailbox->imapEncryption = $input->getOption('imap-encryption');
        $mailbox->smtpHost = $input->getOption('smtp-host');
        $mailbox->smtpPort = (int) $input->getOption('smtp-port');
        $mailbox->smtpEncryption = $input->getOption('smtp-encryption');
        $mailbox->active = true;
        if (null !== $input->getOption('password')) {
            $mailbox->password = $input->getOption('password');
        }

        $this->em->persist($mailbox);
        $this->em->flush();

        $io->success(sprintf(
            'Postfach %s gespeichert (IMAP %s:%d/%s, SMTP %s:%d/%s, Passwort: %s).',
            $email,
            $mailbox->imapHost, $mailbox->imapPort, $mailbox->imapEncryption,
            $mailbox->smtpHost, $mailbox->smtpPort, $mailbox->smtpEncryption,
            '' === $mailbox->password ? 'NICHT gesetzt' : 'gesetzt'
        ));

        return Command::SUCCESS;
    }
}
