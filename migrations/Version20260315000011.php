<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '2FA: add totp_secret, backup_codes, two_factor_required to user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD COLUMN `totp_secret` VARCHAR(255) DEFAULT NULL");
        $this->addSql("ALTER TABLE `user` ADD COLUMN `backup_codes` JSON NOT NULL DEFAULT ('[]')");
        $this->addSql("ALTER TABLE `user` ADD COLUMN `two_factor_required` TINYINT(1) NOT NULL DEFAULT 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` DROP COLUMN `totp_secret`");
        $this->addSql("ALTER TABLE `user` DROP COLUMN `backup_codes`");
        $this->addSql("ALTER TABLE `user` DROP COLUMN `two_factor_required`");
    }
}
