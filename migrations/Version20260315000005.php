<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: theme-Feld für benutzerabhängige Darstellung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD theme VARCHAR(50) NOT NULL DEFAULT 'modern-classic'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN theme');
    }
}
