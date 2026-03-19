<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: person_id Verknüpfung für Kalender-Eigentümerprüfung';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649217BBB47 FOREIGN KEY (person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649217BBB47 ON `user` (person_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649217BBB47');
        $this->addSql('DROP INDEX IDX_8D93D649217BBB47 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP COLUMN person_id');
    }
}
