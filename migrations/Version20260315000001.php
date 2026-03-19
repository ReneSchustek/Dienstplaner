<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initiales Datenbankmodell: Assembly, Department, Person, Task, Day, Assignment, Absence, ExternalTask, User';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assembly (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(500) DEFAULT NULL,
            weekdays JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE department (
            id INT AUTO_INCREMENT NOT NULL,
            assembly_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_CD1DE18AB0F586 (assembly_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE person (
            id INT AUTO_INCREMENT NOT NULL,
            assembly_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_34DCD176B0F586 (assembly_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE task (
            id INT AUTO_INCREMENT NOT NULL,
            department_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_527EDB25AE80F5DF (department_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE day (
            id INT AUTO_INCREMENT NOT NULL,
            assembly_id INT NOT NULL,
            date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_E5A0290FB0F586 (assembly_id),
            UNIQUE INDEX uq_day_assembly_date (assembly_id, date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE assignment (
            id INT AUTO_INCREMENT NOT NULL,
            person_id INT NOT NULL,
            task_id INT NOT NULL,
            day_id INT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_30C544BA217BBB47 (person_id),
            INDEX IDX_30C544BA8DB60186 (task_id),
            INDEX IDX_30C544BA9C24126 (day_id),
            UNIQUE INDEX uq_assignment_task_day (task_id, day_id),
            UNIQUE INDEX uq_assignment_person_day (person_id, day_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE absence (
            id INT AUTO_INCREMENT NOT NULL,
            person_id INT NOT NULL,
            start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            note VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_461EC3B3217BBB47 (person_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE external_task (
            id INT AUTO_INCREMENT NOT NULL,
            person_id INT NOT NULL,
            day_id INT NOT NULL,
            description VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_B8685E1A217BBB47 (person_id),
            INDEX IDX_B8685E1A9C24126 (day_id),
            UNIQUE INDEX uq_external_task_person_day (person_id, day_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE `user` (
            id INT AUTO_INCREMENT NOT NULL,
            assembly_id INT DEFAULT NULL,
            department_id INT DEFAULT NULL,
            email VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            roles JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_8D93D649B0F586 (assembly_id),
            INDEX IDX_8D93D649AE80F5DF (department_id),
            UNIQUE INDEX uq_user_email (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE department ADD CONSTRAINT FK_CD1DE18AB0F586 FOREIGN KEY (assembly_id) REFERENCES assembly (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE person ADD CONSTRAINT FK_34DCD176B0F586 FOREIGN KEY (assembly_id) REFERENCES assembly (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25AE80F5DF FOREIGN KEY (department_id) REFERENCES department (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE day ADD CONSTRAINT FK_E5A0290FB0F586 FOREIGN KEY (assembly_id) REFERENCES assembly (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA9C24126 FOREIGN KEY (day_id) REFERENCES day (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_461EC3B3217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_task ADD CONSTRAINT FK_B8685E1A217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE external_task ADD CONSTRAINT FK_B8685E1A9C24126 FOREIGN KEY (day_id) REFERENCES day (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649B0F586 FOREIGN KEY (assembly_id) REFERENCES assembly (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649AE80F5DF FOREIGN KEY (department_id) REFERENCES department (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649B0F586');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649AE80F5DF');
        $this->addSql('ALTER TABLE external_task DROP FOREIGN KEY FK_B8685E1A217BBB47');
        $this->addSql('ALTER TABLE external_task DROP FOREIGN KEY FK_B8685E1A9C24126');
        $this->addSql('ALTER TABLE absence DROP FOREIGN KEY FK_461EC3B3217BBB47');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA217BBB47');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA8DB60186');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA9C24126');
        $this->addSql('ALTER TABLE day DROP FOREIGN KEY FK_E5A0290FB0F586');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25AE80F5DF');
        $this->addSql('ALTER TABLE person DROP FOREIGN KEY FK_34DCD176B0F586');
        $this->addSql('ALTER TABLE department DROP FOREIGN KEY FK_CD1DE18AB0F586');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE external_task');
        $this->addSql('DROP TABLE absence');
        $this->addSql('DROP TABLE assignment');
        $this->addSql('DROP TABLE day');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE person');
        $this->addSql('DROP TABLE department');
        $this->addSql('DROP TABLE assembly');
    }
}
