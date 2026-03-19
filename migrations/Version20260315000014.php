<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fügt den persönlichen Kalender-Token zur User-Tabelle hinzu.
 */
final class Version20260315000014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persönlicher Kalender-Token für tokenbasierten Kalenderzugriff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD calendar_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_user_calendar_token ON user (calendar_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_user_calendar_token ON user');
        $this->addSql('ALTER TABLE user DROP COLUMN calendar_token');
    }
}
