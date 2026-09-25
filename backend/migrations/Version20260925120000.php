<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 0 schema: users, and the table Messenger's Doctrine transport keeps
 * its queues in. Migrations are hand-written.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users and the messenger queue table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_user (
                id BINARY(16) NOT NULL,
                email VARCHAR(180) NOT NULL,
                name VARCHAR(128) DEFAULT NULL,
                password_hash VARCHAR(255) DEFAULT NULL,
                roles JSON NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_app_user_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGINT AUTO_INCREMENT NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_messenger_queue (queue_name, available_at, delivered_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE app_user');
    }
}
