<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove short_links table (feature removed - file sharing only)
 */
final class Version20260613162105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop short_links table - feature removed';
    }

    public function up(Schema $schema): void
    {
        // Drop the short_links table if it exists
        $this->addSql('DROP TABLE IF EXISTS short_links');
    }

    public function down(Schema $schema): void
    {
        // Recreation not needed - feature is being removed
        $this->addSql('CREATE TABLE short_links (
            id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
            file_id CHAR(36) NOT NULL COMMENT \'(DC2Type:uuid)\',
            slug VARCHAR(12) NOT NULL UNIQUE,
            accessed_count INT DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            KEY IDX_8E2C37B493CB796C (file_id),
            CONSTRAINT FK_8E2C37B493CB796C FOREIGN KEY (file_id) REFERENCES file (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }
}
