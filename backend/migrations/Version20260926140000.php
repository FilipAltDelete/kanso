<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generated documents (pick lists, packing slips): the request, the job's
 * state, and where the PDF is in object storage.
 */
final class Version20260926140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generated documents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE document (
                id BINARY(16) NOT NULL,
                type VARCHAR(32) NOT NULL,
                order_id BINARY(16) NOT NULL,
                order_number VARCHAR(32) NOT NULL,
                order_version INT NOT NULL,
                locale VARCHAR(8) NOT NULL,
                status VARCHAR(16) NOT NULL,
                storage_key VARCHAR(255) DEFAULT NULL,
                byte_size INT DEFAULT NULL,
                error VARCHAR(500) DEFAULT NULL,
                requested_by_id VARCHAR(64) NOT NULL,
                requested_by_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_document_order (order_id, type, locale, order_version),
                CONSTRAINT fk_document_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document');
    }
}
