<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The import history (ADR-0014): one row per CSV import run for real, with
 * who, when, which file, the counts and the rows that failed.
 */
final class Version20260928130100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE import_run (
                id BINARY(16) NOT NULL,
                type VARCHAR(16) NOT NULL,
                filename VARCHAR(255) DEFAULT NULL,
                actor VARCHAR(64) NOT NULL,
                actor_name VARCHAR(255) NOT NULL,
                counts JSON NOT NULL,
                error_count INT NOT NULL,
                errors JSON NOT NULL,
                started_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_import_run_type (type, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_run');
    }
}
