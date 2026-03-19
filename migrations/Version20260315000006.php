<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix column types, index names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE external_task CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE external_task RENAME INDEX idx_b8685e1a217bbb47 TO IDX_C761BB09217BBB47');
        $this->addSql('ALTER TABLE external_task RENAME INDEX idx_b8685e1a9c24126 TO IDX_C761BB099C24126');
        $this->addSql('ALTER TABLE task CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE assembly CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE line_color line_color VARCHAR(7) NOT NULL, CHANGE footer_text footer_text LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE department CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE color color VARCHAR(7) NOT NULL');
        $this->addSql('ALTER TABLE department RENAME INDEX idx_cd1de18ab0f586 TO IDX_CD1DE18ACA2E7D4C');
        $this->addSql('ALTER TABLE user CHANGE role role VARCHAR(50) NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE theme theme VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX idx_8d93d649b0f586 TO IDX_8D93D649CA2E7D4C');
        $this->addSql('ALTER TABLE person CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE person RENAME INDEX idx_34dcd176b0f586 TO IDX_34DCD176CA2E7D4C');
        $this->addSql('ALTER TABLE day CHANGE date date DATE NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE day RENAME INDEX idx_e5a0290fb0f586 TO IDX_E5A02990CA2E7D4C');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE external_task CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE external_task RENAME INDEX idx_c761bb09217bbb47 TO IDX_B8685E1A217BBB47');
        $this->addSql('ALTER TABLE external_task RENAME INDEX idx_c761bb099c24126 TO IDX_B8685E1A9C24126');
        $this->addSql('ALTER TABLE task CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE assembly CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE assignment CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE department CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE department RENAME INDEX idx_cd1de18aca2e7d4c TO IDX_CD1DE18AB0F586');
        $this->addSql('ALTER TABLE user CHANGE role role VARCHAR(50) DEFAULT \'ROLE_USER\' NOT NULL, CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE theme theme VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE user RENAME INDEX idx_8d93d649ca2e7d4c TO IDX_8D93D649B0F586');
        $this->addSql('ALTER TABLE person CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE person RENAME INDEX idx_34dcd176ca2e7d4c TO IDX_34DCD176B0F586');
        $this->addSql('ALTER TABLE day CHANGE date date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE day RENAME INDEX idx_e5a02990ca2e7d4c TO IDX_E5A0290FB0F586');
    }
}
