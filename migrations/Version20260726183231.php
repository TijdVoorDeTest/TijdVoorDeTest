<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Auto-generated Migration: Please modify to your needs! */
final class Version20260726183231 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add created (registration date) and last_activity to user';
    }

    public function up(Schema $schema): void
    {
        // Backfill existing rows to now() since their real registration date isn't known, then drop
        // the default so the DB matches the ORM mapping (Gedmo\Timestampable sets it in PHP, no DB default).
        $this->addSql('ALTER TABLE "user" ADD created TIMESTAMP(0) WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE "user" ALTER created DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ADD last_activity TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP created');
        $this->addSql('ALTER TABLE "user" DROP last_activity');
    }
}
