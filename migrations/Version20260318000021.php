<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add planning_lock table for concurrent department editing prevention';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE planning_lock (
            id INT AUTO_INCREMENT NOT NULL,
            department_id INT NOT NULL,
            user_id INT NOT NULL,
            locked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uq_planning_lock_department (department_id),
            INDEX IDX_PL_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE planning_lock ADD CONSTRAINT FK_PL_department FOREIGN KEY (department_id) REFERENCES department (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE planning_lock ADD CONSTRAINT FK_PL_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planning_lock DROP FOREIGN KEY FK_PL_department');
        $this->addSql('ALTER TABLE planning_lock DROP FOREIGN KEY FK_PL_user');
        $this->addSql('DROP TABLE planning_lock');
    }
}
