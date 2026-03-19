<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assembly: add teamup_calendar_url column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly ADD teamup_calendar_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly DROP COLUMN teamup_calendar_url');
    }
}
