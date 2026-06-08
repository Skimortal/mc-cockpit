<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608072104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email ADD triaged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD suggested_assignee VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD ai_summary TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD source_email_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25EB12F71B FOREIGN KEY (source_email_id) REFERENCES email (id)');
        $this->addSql('CREATE INDEX IDX_527EDB25EB12F71B ON task (source_email_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email DROP triaged_at');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25EB12F71B');
        $this->addSql('DROP INDEX IDX_527EDB25EB12F71B');
        $this->addSql('ALTER TABLE task DROP suggested_assignee');
        $this->addSql('ALTER TABLE task DROP ai_summary');
        $this->addSql('ALTER TABLE task DROP source_email_id');
    }
}
