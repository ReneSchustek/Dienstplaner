<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace assembly.address with street, zip, city';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly ADD street VARCHAR(255) DEFAULT NULL, ADD zip VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(255) DEFAULT NULL, DROP address');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly ADD address VARCHAR(500) DEFAULT NULL, DROP street, DROP zip, DROP city');
    }
}
