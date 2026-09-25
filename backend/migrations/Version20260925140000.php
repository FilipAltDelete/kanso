<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API keys for integrations. Only the SHA-256 hash of a key is stored.
 */
final class Version20260925140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE api_key (
                id BINARY(16) NOT NULL,
                name VARCHAR(128) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                role VARCHAR(32) NOT NULL,
                created_by BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME DEFAULT NULL,
                last_used_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_api_key_hash (key_hash),
                KEY idx_api_key_created_by (created_by),
                CONSTRAINT fk_api_key_created_by FOREIGN KEY (created_by) REFERENCES app_user (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_key');
    }
}
