<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create special_date table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE special_date (id INT AUTO_INCREMENT NOT NULL, assembly_id INT NOT NULL, type VARCHAR(50) NOT NULL, start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', note VARCHAR(500) DEFAULT NULL, INDEX IDX_special_date_assembly (assembly_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE special_date ADD CONSTRAINT FK_special_date_assembly FOREIGN KEY (assembly_id) REFERENCES assembly (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE special_date DROP FOREIGN KEY FK_special_date_assembly');
        $this->addSql('DROP TABLE special_date');
    }
}
