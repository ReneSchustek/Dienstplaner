<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316000018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public absence and calendar tokens to assembly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly ADD public_absence_token VARCHAR(64) DEFAULT NULL UNIQUE, ADD public_calendar_token VARCHAR(64) DEFAULT NULL UNIQUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly DROP COLUMN public_absence_token, DROP COLUMN public_calendar_token');
    }
}
