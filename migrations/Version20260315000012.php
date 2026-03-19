<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fügt password_reset_token und force_password_change zur User-Tabelle hinzu.
 */
final class Version20260315000012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passwort-Reset-Token und Passwort-Änderungspflicht für Benutzer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD password_reset_token VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD force_password_change TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN password_reset_token');
        $this->addSql('ALTER TABLE user DROP COLUMN force_password_change');
    }
}
