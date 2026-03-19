<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_task pivot table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_task (user_id INT NOT NULL, task_id INT NOT NULL, PRIMARY KEY (user_id, task_id), INDEX IDX_FE2042A2A76ED395 (user_id), INDEX IDX_FE2042A28DB60186 (task_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE user_task ADD CONSTRAINT FK_user_task_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_task ADD CONSTRAINT FK_user_task_task FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_task DROP FOREIGN KEY FK_user_task_user');
        $this->addSql('ALTER TABLE user_task DROP FOREIGN KEY FK_user_task_task');
        $this->addSql('DROP TABLE user_task');
    }
}
