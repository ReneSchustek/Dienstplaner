<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add page_size to assembly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly ADD page_size INT NOT NULL DEFAULT 10');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly DROP COLUMN page_size');
    }
}
