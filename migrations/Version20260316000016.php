<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316000016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move task assignments from user_task to person_task';
    }

    public function up(Schema $schema): void
    {
        // Neue Pivot-Tabelle person_task
        $this->addSql('CREATE TABLE person_task (
            person_id INT NOT NULL,
            task_id INT NOT NULL,
            PRIMARY KEY (person_id, task_id),
            INDEX IDX_person_task_person (person_id),
            INDEX IDX_person_task_task (task_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('ALTER TABLE person_task
            ADD CONSTRAINT FK_person_task_person FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE person_task
            ADD CONSTRAINT FK_person_task_task FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');

        // Daten migrieren: user_task → person_task (über user.person_id)
        $this->addSql('INSERT INTO person_task (person_id, task_id)
            SELECT DISTINCT u.person_id, ut.task_id
            FROM user_task ut
            JOIN `user` u ON u.id = ut.user_id
            WHERE u.person_id IS NOT NULL');

        // Alte Tabelle entfernen
        $this->addSql('ALTER TABLE user_task DROP FOREIGN KEY FK_user_task_user');
        $this->addSql('ALTER TABLE user_task DROP FOREIGN KEY FK_user_task_task');
        $this->addSql('DROP TABLE user_task');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_task (
            user_id INT NOT NULL,
            task_id INT NOT NULL,
            PRIMARY KEY (user_id, task_id),
            INDEX IDX_FE2042A2A76ED395 (user_id),
            INDEX IDX_FE2042A28DB60186 (task_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');

        $this->addSql('ALTER TABLE user_task
            ADD CONSTRAINT FK_user_task_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_task
            ADD CONSTRAINT FK_user_task_task FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE person_task DROP FOREIGN KEY FK_person_task_person');
        $this->addSql('ALTER TABLE person_task DROP FOREIGN KEY FK_person_task_task');
        $this->addSql('DROP TABLE person_task');
    }
}
