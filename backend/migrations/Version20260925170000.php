<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Customers, their addresses, and the audit trail of changes to them.
 */
final class Version20260925170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers, customer addresses and customer events.';
    }

    public function up(Schema $schema): void
    {
        // `email_canonical` is the lower-cased email, binary-collated so the
        // unique key means exactly "same email, ignoring case" — the table's
        // default collation would also treat accented letters as equal.
        $this->addSql(<<<'SQL'
            CREATE TABLE customer (
                id BINARY(16) NOT NULL,
                email VARCHAR(180) NOT NULL,
                email_canonical VARCHAR(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                name VARCHAR(255) NOT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_customer_email (email_canonical),
                KEY idx_customer_name (name),
                KEY idx_customer_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE customer_address (
                id BINARY(16) NOT NULL,
                customer_id BINARY(16) NOT NULL,
                position INT NOT NULL,
                type VARCHAR(16) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                name VARCHAR(255) DEFAULT NULL,
                company VARCHAR(255) DEFAULT NULL,
                line1 VARCHAR(255) NOT NULL,
                line2 VARCHAR(255) DEFAULT NULL,
                postal_code VARCHAR(32) NOT NULL,
                city VARCHAR(128) NOT NULL,
                region VARCHAR(128) DEFAULT NULL,
                country_code CHAR(2) NOT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_customer_address_customer (customer_id, position),
                CONSTRAINT fk_customer_address_customer FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        // No foreign key to customer: the audit trail outlives what it describes.
        $this->addSql(<<<'SQL'
            CREATE TABLE customer_event (
                id BINARY(16) NOT NULL,
                customer_id BINARY(16) NOT NULL,
                type VARCHAR(16) NOT NULL,
                actor_id VARCHAR(64) DEFAULT NULL,
                actor_label VARCHAR(255) NOT NULL,
                changes JSON NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_customer_event_customer (customer_id, occurred_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE customer_event');
        $this->addSql('DROP TABLE customer_address');
        $this->addSql('DROP TABLE customer');
    }
}
