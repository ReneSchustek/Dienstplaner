<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316000017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add two_factor_policy to assembly';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE assembly ADD two_factor_policy VARCHAR(20) NOT NULL DEFAULT 'user_choice'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly DROP COLUMN two_factor_policy');
    }
}
