<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605161445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files ALTER scan_status DROP DEFAULT');
        $this->addSql('ALTER TABLE users ADD name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD avatar_key VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD pending_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_change_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_change_token_expiry TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ALTER plan DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files ALTER scan_status SET DEFAULT \'clean\'');
        $this->addSql('ALTER TABLE users DROP name');
        $this->addSql('ALTER TABLE users DROP avatar_key');
        $this->addSql('ALTER TABLE users DROP pending_email');
        $this->addSql('ALTER TABLE users DROP email_change_token');
        $this->addSql('ALTER TABLE users DROP email_change_token_expiry');
        $this->addSql('ALTER TABLE users ALTER plan SET DEFAULT \'free\'');
    }
}
