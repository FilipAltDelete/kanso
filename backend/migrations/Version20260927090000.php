<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment status and tags on orders. Notes need no table: a note is an
 * order event, so it is in the timeline with who wrote it and when.
 */
final class Version20260927090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order payment status and order tags.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_order
                ADD payment_status VARCHAR(24) DEFAULT 'unpaid' NOT NULL AFTER held_from,
                ADD KEY idx_sales_order_payment_status (payment_status)
            SQL);

        // Case-insensitive but accent-sensitive, as OrderTag::same() compares:
        // "VIP" and "vip" are one tag on an order, "cafe" and "café" are two.
        $this->addSql(<<<'SQL'
            CREATE TABLE order_tag (
                order_id BINARY(16) NOT NULL,
                name VARCHAR(64) NOT NULL COLLATE utf8mb4_0900_as_ci,
                PRIMARY KEY (order_id, name),
                KEY idx_order_tag_name (name),
                CONSTRAINT fk_order_tag_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_tag');
        $this->addSql('ALTER TABLE sales_order DROP KEY idx_sales_order_payment_status, DROP payment_status');
    }
}
