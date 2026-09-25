<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Batch documents: one PDF of pick lists or packing slips for several
 * orders. Such a document has no single order; it lists its orders with
 * their versions, and a key of them finds it again for the same request.
 */
final class Version20260929090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents for several orders.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                MODIFY order_id BINARY(16) DEFAULT NULL,
                MODIFY order_number VARCHAR(32) DEFAULT NULL,
                MODIFY order_version INT DEFAULT NULL,
                ADD batch_orders JSON DEFAULT NULL AFTER order_version,
                ADD batch_key VARCHAR(64) DEFAULT NULL AFTER batch_orders,
                ADD KEY idx_document_batch (batch_key)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM document WHERE order_id IS NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE document
                DROP KEY idx_document_batch,
                DROP batch_key,
                DROP batch_orders,
                MODIFY order_id BINARY(16) NOT NULL,
                MODIFY order_number VARCHAR(32) NOT NULL,
                MODIFY order_version INT NOT NULL
            SQL);
    }
}
