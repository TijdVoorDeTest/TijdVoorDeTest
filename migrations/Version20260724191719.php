<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Auto-generated Migration: Please modify to your needs! */
final class Version20260724191719 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("ALTER TABLE season_settings ADD locale VARCHAR(5) DEFAULT 'nl' NOT NULL");
        $this->addSql('ALTER TABLE "user" ADD locale VARCHAR(5) DEFAULT \'nl\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE season_settings DROP locale');
        $this->addSql('ALTER TABLE "user" DROP locale');
    }
}
