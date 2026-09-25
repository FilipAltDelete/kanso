<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shipment-aware fulfillment: shipments can be voided (kept, marked), an
 * order remembers where it shipped from so a void can reopen it, and a
 * packing slip can be for one shipment.
 */
final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voided shipments, sales_order.shipped_from, per-shipment documents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE shipment
                ADD voided_at DATETIME DEFAULT NULL,
                ADD voided_by_id VARCHAR(64) DEFAULT NULL,
                ADD voided_by_name VARCHAR(255) DEFAULT NULL,
                ADD void_reason VARCHAR(500) DEFAULT NULL
            SQL);

        $this->addSql('ALTER TABLE sales_order ADD shipped_from VARCHAR(16) DEFAULT NULL AFTER held_from');

        // Shipped orders remember where from: what their `ship` event says,
        // or packed, which is where the old all-at-once transition came from.
        $this->addSql(<<<'SQL'
            UPDATE sales_order o
            SET o.shipped_from = COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(e.before_state, '$.status'))
                 FROM order_event e
                 WHERE e.order_id = o.id AND e.type = 'transition' AND e.transition = 'ship'
                 ORDER BY e.id DESC LIMIT 1),
                'packed')
            WHERE o.status IN ('shipped', 'delivered')
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE document
                ADD shipment_id BINARY(16) DEFAULT NULL AFTER locale,
                ADD shipment_number INT DEFAULT NULL AFTER shipment_id,
                ADD KEY idx_document_shipment (shipment_id),
                ADD CONSTRAINT fk_document_shipment FOREIGN KEY (shipment_id) REFERENCES shipment (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY fk_document_shipment, DROP KEY idx_document_shipment, DROP shipment_number, DROP shipment_id');
        $this->addSql('ALTER TABLE sales_order DROP shipped_from');
        $this->addSql('ALTER TABLE shipment DROP void_reason, DROP voided_by_name, DROP voided_by_id, DROP voided_at');
    }
}
