<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260610115016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox ADD scope VARCHAR(10) DEFAULT \'global\' NOT NULL');
        $this->addSql('ALTER TABLE mailbox ADD owner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE mailbox ADD CONSTRAINT FK_A69FE20B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id)');
        $this->addSql('CREATE INDEX IDX_A69FE20B7E3C61F9 ON mailbox (owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mailbox DROP CONSTRAINT FK_A69FE20B7E3C61F9');
        $this->addSql('DROP INDEX IDX_A69FE20B7E3C61F9');
        $this->addSql('ALTER TABLE mailbox DROP scope');
        $this->addSql('ALTER TABLE mailbox DROP owner_id');
    }
}
