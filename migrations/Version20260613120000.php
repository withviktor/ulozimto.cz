<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create short_links table for URL shortening feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE short_links (id UUID NOT NULL, file_id UUID NOT NULL, slug VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accessed_count INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1B9F0F42989D9B62 ON short_links (slug)');
        $this->addSql('CREATE INDEX IDX_1B9F0F428A8E06E0 ON short_links (file_id)');
        $this->addSql('ALTER TABLE short_links ADD CONSTRAINT FK_1B9F0F428A8E06E0 FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE short_links');
    }
}
