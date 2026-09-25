<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catalog and inventory: products, locations, stock per product and location,
 * and the append-only history of every change to that stock.
 */
final class Version20260925160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products, locations, inventory levels and inventory movements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product (
                id BINARY(16) NOT NULL,
                sku VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                barcode VARCHAR(64) DEFAULT NULL,
                weight_grams INT DEFAULT NULL,
                version INT DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_product_sku (sku),
                KEY idx_product_barcode (barcode),
                KEY idx_product_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE location (
                id BINARY(16) NOT NULL,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(128) NOT NULL,
                address_line1 VARCHAR(128) DEFAULT NULL,
                address_line2 VARCHAR(128) DEFAULT NULL,
                address_postal_code VARCHAR(16) DEFAULT NULL,
                address_city VARCHAR(64) DEFAULT NULL,
                address_country_code VARCHAR(2) DEFAULT NULL,
                version INT DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_location_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        // The CHECK is the database's copy of InventoryLevel's invariant, so no
        // code path that bypasses the entity can make available negative.
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_level (
                id BINARY(16) NOT NULL,
                product_id BINARY(16) NOT NULL,
                location_id BINARY(16) NOT NULL,
                on_hand INT NOT NULL,
                reserved INT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_inventory_level_product_location (product_id, location_id),
                KEY idx_inventory_level_location (location_id),
                CONSTRAINT fk_inventory_level_product FOREIGN KEY (product_id) REFERENCES product (id),
                CONSTRAINT fk_inventory_level_location FOREIGN KEY (location_id) REFERENCES location (id),
                CONSTRAINT chk_inventory_level_quantities CHECK (reserved >= 0 AND on_hand >= reserved)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_movement (
                id BINARY(16) NOT NULL,
                product_id BINARY(16) NOT NULL,
                location_id BINARY(16) NOT NULL,
                type VARCHAR(32) NOT NULL,
                reason VARCHAR(32) DEFAULT NULL,
                note VARCHAR(500) DEFAULT NULL,
                on_hand_before INT NOT NULL,
                on_hand_after INT NOT NULL,
                reserved_before INT NOT NULL,
                reserved_after INT NOT NULL,
                actor_id VARCHAR(64) NOT NULL,
                actor_name VARCHAR(180) NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_inventory_movement_product (product_id, occurred_at),
                KEY idx_inventory_movement_location (location_id, occurred_at),
                CONSTRAINT fk_inventory_movement_product FOREIGN KEY (product_id) REFERENCES product (id),
                CONSTRAINT fk_inventory_movement_location FOREIGN KEY (location_id) REFERENCES location (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE inventory_movement');
        $this->addSql('DROP TABLE inventory_level');
        $this->addSql('DROP TABLE location');
        $this->addSql('DROP TABLE product');
    }
}
