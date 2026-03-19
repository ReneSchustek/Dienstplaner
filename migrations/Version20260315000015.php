<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fügt ein optionales Namensfeld zur User-Tabelle hinzu.
 */
final class Version20260315000015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: optionales Namensfeld für Profilseite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN name');
    }
}
