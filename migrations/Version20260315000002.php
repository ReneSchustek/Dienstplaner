<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User-Tabelle: roles (JSON) wird zu role (VARCHAR)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user DROP COLUMN roles, ADD COLUMN role VARCHAR(50) NOT NULL DEFAULT 'ROLE_USER' AFTER email");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP COLUMN role, ADD COLUMN roles JSON NOT NULL');
    }
}
