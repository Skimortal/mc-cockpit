<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestufte Retention: pro Postfach konfigurierbare Aufbewahrung + Markierung entfernter Anhänge.
 */
final class Version20260621190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mailbox: attachment_retention_months/mail_retention_months; Attachment: pruned_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox ADD attachment_retention_months INT NOT NULL DEFAULT 12');
        $this->addSql('ALTER TABLE mailbox ADD mail_retention_months INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE attachment ADD pruned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mailbox DROP attachment_retention_months');
        $this->addSql('ALTER TABLE mailbox DROP mail_retention_months');
        $this->addSql('ALTER TABLE attachment DROP pruned_at');
    }
}
