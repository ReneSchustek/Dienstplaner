<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317000020 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user.department_id (ManyToOne) with user_departments join table (ManyToMany)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_departments (
            user_id INT NOT NULL,
            department_id INT NOT NULL,
            PRIMARY KEY(user_id, department_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE INDEX IDX_UD_user ON user_departments (user_id)');
        $this->addSql('CREATE INDEX IDX_UD_department ON user_departments (department_id)');

        $this->addSql('ALTER TABLE user_departments ADD CONSTRAINT FK_UD_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_departments ADD CONSTRAINT FK_UD_department FOREIGN KEY (department_id) REFERENCES department (id) ON DELETE CASCADE');

        $this->addSql('INSERT INTO user_departments (user_id, department_id) SELECT id, department_id FROM `user` WHERE department_id IS NOT NULL');

        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649AE80F5DF');
        $this->addSql('ALTER TABLE `user` DROP INDEX IDX_8D93D649AE80F5DF');
        $this->addSql('ALTER TABLE `user` DROP COLUMN department_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD department_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649AE80F5DF ON `user` (department_id)');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649AE80F5DF FOREIGN KEY (department_id) REFERENCES department (id) ON DELETE SET NULL');

        $this->addSql('UPDATE `user` u SET u.department_id = (SELECT department_id FROM user_departments ud WHERE ud.user_id = u.id LIMIT 1)');

        $this->addSql('ALTER TABLE user_departments DROP FOREIGN KEY FK_UD_user');
        $this->addSql('ALTER TABLE user_departments DROP FOREIGN KEY FK_UD_department');
        $this->addSql('DROP TABLE user_departments');
    }
}
