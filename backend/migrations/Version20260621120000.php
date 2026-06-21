<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dokument-Upload: Datei-Metadaten + extrahierter Text am Firmen-Dokument.
 */
final class Version20260621120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Document: content_type, size, extracted_text für echten Datei-Upload + Volltextsuche';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD content_type VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD size INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE document ADD extracted_text TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP content_type');
        $this->addSql('ALTER TABLE document DROP size');
        $this->addSql('ALTER TABLE document DROP extracted_text');
    }
}
