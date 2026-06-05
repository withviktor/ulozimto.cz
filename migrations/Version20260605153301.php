<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605153301 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files ADD custom_alias VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE files ADD scan_status VARCHAR(10) NOT NULL DEFAULT 'clean'");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6354059226193FD ON files (custom_alias)');
        $this->addSql('CREATE INDEX idx_custom_alias ON files (custom_alias)');
        $this->addSql('CREATE INDEX idx_scan_status ON files (scan_status)');
        $this->addSql("ALTER TABLE users ADD plan VARCHAR(10) NOT NULL DEFAULT 'free'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_6354059226193FD');
        $this->addSql('DROP INDEX idx_custom_alias');
        $this->addSql('DROP INDEX idx_scan_status');
        $this->addSql('ALTER TABLE files DROP custom_alias');
        $this->addSql('ALTER TABLE files DROP scan_status');
        $this->addSql('ALTER TABLE users DROP plan');
    }
}
