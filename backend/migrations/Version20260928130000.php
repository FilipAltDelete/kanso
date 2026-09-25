<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit trail of products (ADR-0013): one row per create or change,
 * with who, when, through what, and the fields before and after.
 *
 * Products that exist already get a `created` event dated when they were
 * created and attributed to the system, since who created them was never
 * recorded; their history then starts where the product did.
 */
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product audit trail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_event (
                id BINARY(16) NOT NULL,
                product_id BINARY(16) NOT NULL,
                type VARCHAR(16) NOT NULL,
                source VARCHAR(16) NOT NULL,
                actor VARCHAR(64) NOT NULL,
                actor_name VARCHAR(255) NOT NULL,
                before_state JSON DEFAULT NULL,
                after_state JSON NOT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_product_event_product (product_id, occurred_at),
                CONSTRAINT fk_product_event_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO product_event (id, product_id, type, source, actor, actor_name, before_state, after_state, occurred_at)
            SELECT UUID_TO_BIN(UUID(), 1), id, 'created', 'api', 'system', 'System', NULL,
                   JSON_OBJECT('sku', sku, 'name', name, 'barcode', barcode, 'weightGrams', weight_grams),
                   created_at
            FROM product
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_event');
    }
}
