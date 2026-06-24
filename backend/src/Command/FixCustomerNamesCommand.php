<?php

namespace App\Command;

use App\Entity\Conversation;
use App\Mail\ImapPoller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Einmalig: dekodiert MIME-codierte Kundennamen (=?UTF-8?Q?…?=) in Konversationen,
 * die noch vor dem Decode-Fix angelegt wurden.
 */
#[AsCommand(name: 'app:fix-customer-names', description: 'Dekodiert MIME-codierte Kundennamen in Konversationen.')]
final class FixCustomerNamesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $convs = $this->em->getRepository(Conversation::class)->createQueryBuilder('c')
            ->where("c.customerName LIKE '%=?%'")
            ->getQuery()->getResult();

        $fixed = 0;
        foreach ($convs as $c) {
            $decoded = ImapPoller::mimeDecode((string) $c->customerName);
            if ('' !== $decoded && $decoded !== $c->customerName) {
                $output->writeln(sprintf('  #%d  %s  →  %s', $c->id, $c->customerName, $decoded));
                $c->customerName = $decoded;
                ++$fixed;
            }
        }
        $this->em->flush();
        $output->writeln(sprintf('<info>%d Namen dekodiert.</info>', $fixed));

        return Command::SUCCESS;
    }
}
