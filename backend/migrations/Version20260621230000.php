<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Antwort-Mails: Signatur pro Postfach + CC-Empfänger an E-Mails. */
final class Version20260621230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mailbox.signature (HTML) + email.cc_address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD signature TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD cc_address TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP signature');
        $this->addSql('ALTER TABLE email DROP cc_address');
    }
}
