<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Fälligkeits-Erinnerungen: merkt sich, an welchem Tag zuletzt erinnert wurde. */
final class Version20260629100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'task.due_reminder_sent_on für tägliche Fälligkeits-Erinnerung (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD due_reminder_sent_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP due_reminder_sent_on');
    }
}
