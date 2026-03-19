<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fügt 2FA-Methode und E-Mail-Auth-Code zur User-Tabelle hinzu.
 */
final class Version20260315000013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '2FA-Methode (totp/email) und E-Mail-Auth-Code für Benutzer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD two_factor_method VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD email_auth_code VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN two_factor_method');
        $this->addSql('ALTER TABLE user DROP COLUMN email_auth_code');
    }
}
