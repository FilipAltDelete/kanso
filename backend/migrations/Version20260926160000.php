<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shipments: what left, from where, with which carrier and tracking number,
 * and how many units of each order line — which may be part of a line.
 */
final class Version20260926160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipments, shipment lines, and shipped quantities on order lines.';
    }

    public function up(Schema $schema): void
    {
        // While an order holds stock, every unit of a line is either still
        // reserved or has shipped: reserved + shipped never exceeds the line.
        $this->addSql(<<<'SQL'
            ALTER TABLE order_line
                ADD shipped_quantity INT DEFAULT 0 NOT NULL AFTER reserved_quantity,
                ADD CONSTRAINT chk_order_line_shipped CHECK (shipped_quantity >= 0 AND reserved_quantity + shipped_quantity <= quantity)
            SQL);

        // Orders shipped by the old all-at-once transition shipped everything.
        $this->addSql("UPDATE order_line ol JOIN sales_order o ON o.id = ol.order_id SET ol.shipped_quantity = ol.quantity WHERE o.status IN ('shipped', 'delivered')");

        $this->addSql(<<<'SQL'
            CREATE TABLE shipment (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                location_id BINARY(16) NOT NULL,
                carrier VARCHAR(64) DEFAULT NULL,
                tracking_number VARCHAR(128) DEFAULT NULL,
                shipped_at DATETIME NOT NULL,
                actor_id VARCHAR(64) NOT NULL,
                actor_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_shipment_order (order_id),
                KEY idx_shipment_location (location_id),
                KEY idx_shipment_tracking (tracking_number),
                CONSTRAINT fk_shipment_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE,
                CONSTRAINT fk_shipment_location FOREIGN KEY (location_id) REFERENCES location (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE shipment_line (
                id BINARY(16) NOT NULL,
                shipment_id BINARY(16) NOT NULL,
                order_line_id BINARY(16) NOT NULL,
                quantity INT NOT NULL,
                PRIMARY KEY (id),
                KEY idx_shipment_line_shipment (shipment_id),
                KEY idx_shipment_line_order_line (order_line_id),
                CONSTRAINT fk_shipment_line_shipment FOREIGN KEY (shipment_id) REFERENCES shipment (id) ON DELETE CASCADE,
                CONSTRAINT fk_shipment_line_order_line FOREIGN KEY (order_line_id) REFERENCES order_line (id) ON DELETE CASCADE,
                CONSTRAINT chk_shipment_line_quantity CHECK (quantity > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipment_line');
        $this->addSql('DROP TABLE shipment');
        $this->addSql('ALTER TABLE order_line DROP CHECK chk_order_line_shipped, DROP shipped_quantity');
    }
}
