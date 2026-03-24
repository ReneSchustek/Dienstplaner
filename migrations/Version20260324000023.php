<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324000023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Person und User: name-Feld in first_name / last_name aufteilen';
    }

    public function up(Schema $schema): void
    {
        // Person: neue Spalten
        $this->addSql('ALTER TABLE person ADD first_name VARCHAR(100) NOT NULL DEFAULT \'\', ADD last_name VARCHAR(100) NOT NULL DEFAULT \'\'');

        // Person: Daten splitten — Format "Nachname, Vorname"
        $this->addSql("UPDATE person SET last_name = TRIM(SUBSTRING_INDEX(name, ',', 1)), first_name = TRIM(SUBSTRING_INDEX(name, ',', -1)) WHERE name LIKE '%,%'");
        $this->addSql("UPDATE person SET last_name = TRIM(name), first_name = '' WHERE name NOT LIKE '%,%'");

        // Person: alte Spalte entfernen
        $this->addSql('ALTER TABLE person DROP COLUMN name');

        // User: neue Spalten
        $this->addSql('ALTER TABLE `user` ADD first_name VARCHAR(100) DEFAULT NULL, ADD last_name VARCHAR(100) DEFAULT NULL');

        // User: Daten splitten — Format "Nachname, Vorname"
        $this->addSql("UPDATE `user` SET last_name = TRIM(SUBSTRING_INDEX(name, ',', 1)), first_name = TRIM(SUBSTRING_INDEX(name, ',', -1)) WHERE name LIKE '%,%'");
        $this->addSql("UPDATE `user` SET last_name = TRIM(name), first_name = NULL WHERE name NOT LIKE '%,%' AND name IS NOT NULL");

        // User: alte Spalte entfernen
        $this->addSql('ALTER TABLE `user` DROP COLUMN name');
    }

    public function down(Schema $schema): void
    {
        // Person: name-Spalte zurück
        $this->addSql('ALTER TABLE person ADD name VARCHAR(255) NOT NULL DEFAULT \'\'');
        $this->addSql("UPDATE person SET name = CONCAT(last_name, ', ', first_name)");
        $this->addSql('ALTER TABLE person DROP COLUMN first_name, DROP COLUMN last_name');

        // User: name-Spalte zurück
        $this->addSql('ALTER TABLE `user` ADD name VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE `user` SET name = CASE WHEN last_name IS NOT NULL THEN CONCAT(last_name, ', ', first_name) ELSE NULL END");
        $this->addSql('ALTER TABLE `user` DROP COLUMN first_name, DROP COLUMN last_name');
    }
}
