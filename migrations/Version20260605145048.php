<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260605145048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE files (id UUID NOT NULL, share_token VARCHAR(12) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(127) DEFAULT NULL, size_bytes BIGINT NOT NULL, minio_key VARCHAR(512) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, download_count INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6354059D6594DD6 ON files (share_token)');
        $this->addSql('CREATE INDEX IDX_6354059A76ED395 ON files (user_id)');
        $this->addSql('CREATE INDEX idx_share_token ON files (share_token)');
        $this->addSql('CREATE INDEX idx_expires_at ON files (expires_at)');
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('ALTER TABLE files ADD CONSTRAINT FK_6354059A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE files DROP CONSTRAINT FK_6354059A76ED395');
        $this->addSql('DROP TABLE files');
        $this->addSql('DROP TABLE users');
    }
}
