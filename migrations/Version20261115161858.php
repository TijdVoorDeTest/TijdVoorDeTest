<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115161858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cache table for quiz statistics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cache_items (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cache_items');
    }
}
