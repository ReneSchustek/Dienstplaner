<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260315000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assembly: planName, lineColor, footerText; Department: color';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE assembly ADD plan_name VARCHAR(255) DEFAULT NULL, ADD line_color VARCHAR(7) NOT NULL DEFAULT '#1a56db', ADD footer_text TEXT DEFAULT NULL");
        $this->addSql("ALTER TABLE department ADD color VARCHAR(7) NOT NULL DEFAULT '#1a56db'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly DROP COLUMN plan_name, DROP COLUMN line_color, DROP COLUMN footer_text');
        $this->addSql('ALTER TABLE department DROP COLUMN color');
    }
}
