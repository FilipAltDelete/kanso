<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stock reservation for orders: order lines link to products and record what
 * they hold, orders have the location they reserve from, and inventory
 * movements name the order that caused them.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order lines linked to products, reserved quantities, order location, movement order reference.';
    }

    public function up(Schema $schema): void
    {
        // Nullable only for lines placed before the link existed; every new
        // line has a product (OrderService refuses an unknown SKU).
        $this->addSql(<<<'SQL'
            ALTER TABLE order_line
                ADD product_id BINARY(16) DEFAULT NULL AFTER order_id,
                ADD reserved_quantity INT DEFAULT 0 NOT NULL,
                ADD KEY idx_order_line_product (product_id),
                ADD CONSTRAINT fk_order_line_product FOREIGN KEY (product_id) REFERENCES product (id),
                ADD CONSTRAINT chk_order_line_reserved CHECK (reserved_quantity >= 0 AND reserved_quantity <= quantity)
            SQL);

        // Link what can be linked: a line whose SKU is in the catalogue.
        $this->addSql('UPDATE order_line ol JOIN product p ON p.sku = ol.sku_code SET ol.product_id = p.id');

        $this->addSql(<<<'SQL'
            ALTER TABLE sales_order
                ADD location_id BINARY(16) DEFAULT NULL AFTER currency,
                ADD KEY idx_sales_order_location (location_id),
                ADD CONSTRAINT fk_sales_order_location FOREIGN KEY (location_id) REFERENCES location (id)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movement
                ADD order_id BINARY(16) DEFAULT NULL AFTER note,
                ADD order_number VARCHAR(32) DEFAULT NULL AFTER order_id,
                ADD KEY idx_inventory_movement_order (order_id)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_movement DROP KEY idx_inventory_movement_order, DROP order_number, DROP order_id');
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY fk_sales_order_location, DROP KEY idx_sales_order_location, DROP location_id');
        $this->addSql('ALTER TABLE order_line DROP CHECK chk_order_line_reserved, DROP FOREIGN KEY fk_order_line_product, DROP KEY idx_order_line_product, DROP reserved_quantity, DROP product_id');
    }
}
