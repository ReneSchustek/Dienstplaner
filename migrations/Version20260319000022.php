<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319000022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mail template fields to assembly table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly
            ADD mail_invitation_subject VARCHAR(255) DEFAULT NULL,
            ADD mail_invitation_body LONGTEXT DEFAULT NULL,
            ADD mail_password_reset_subject VARCHAR(255) DEFAULT NULL,
            ADD mail_password_reset_body LONGTEXT DEFAULT NULL,
            ADD mail_calendar_link_subject VARCHAR(255) DEFAULT NULL,
            ADD mail_calendar_link_body LONGTEXT DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assembly
            DROP mail_invitation_subject,
            DROP mail_invitation_body,
            DROP mail_password_reset_subject,
            DROP mail_password_reset_body,
            DROP mail_calendar_link_subject,
            DROP mail_calendar_link_body
        ');
    }
}
