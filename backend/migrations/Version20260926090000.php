<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 1 orders: channels, orders with their lines and event history, and
 * the order-number sequence. Seeds the `manual` channel that orders created
 * in Kanso belong to.
 */
final class Version20260926090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Channels, orders, order lines, order events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE channel (
                id BINARY(16) NOT NULL,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(128) NOT NULL,
                type VARCHAR(32) NOT NULL,
                currency CHAR(3) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_channel_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sales_order (
                id BINARY(16) NOT NULL,
                number VARCHAR(32) NOT NULL,
                channel_id BINARY(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                held_from VARCHAR(16) DEFAULT NULL,
                currency CHAR(3) NOT NULL,
                customer_id BINARY(16) DEFAULT NULL,
                customer_name VARCHAR(255) NOT NULL,
                customer_email VARCHAR(255) DEFAULT NULL,
                shipping_address JSON NOT NULL,
                billing_address JSON DEFAULT NULL,
                total_amount BIGINT NOT NULL,
                placed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                version INT NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sales_order_number (number),
                KEY idx_sales_order_placed (placed_at),
                KEY idx_sales_order_status_placed (status, placed_at),
                KEY idx_sales_order_channel_placed (channel_id, placed_at),
                KEY idx_sales_order_customer_name (customer_name),
                KEY idx_sales_order_customer_email (customer_email),
                KEY idx_sales_order_customer (customer_id),
                CONSTRAINT fk_sales_order_channel FOREIGN KEY (channel_id) REFERENCES channel (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_line (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                position INT NOT NULL,
                sku_code VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                unit_price BIGINT NOT NULL,
                line_total BIGINT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_order_line_position (order_id, position),
                KEY idx_order_line_sku (sku_code),
                CONSTRAINT fk_order_line_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_event (
                id BINARY(16) NOT NULL,
                order_id BINARY(16) NOT NULL,
                type VARCHAR(32) NOT NULL,
                transition VARCHAR(32) DEFAULT NULL,
                actor VARCHAR(64) NOT NULL,
                actor_name VARCHAR(255) NOT NULL,
                before_state JSON DEFAULT NULL,
                after_state JSON DEFAULT NULL,
                occurred_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_order_event_order (order_id, id),
                CONSTRAINT fk_order_event_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE order_number_sequence (
                id TINYINT NOT NULL,
                next_value BIGINT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
            SQL);
        // The first order is 10001.
        $this->addSql('INSERT INTO order_number_sequence (id, next_value) VALUES (1, 10000)');

        // SEK as the default for manually created orders; an order can name any currency.
        $this->addSql(
            'INSERT INTO channel (id, code, name, type, currency, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [Uuid::v7()->toBinary(), 'manual', 'Manual', 'manual', 'SEK'],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_number_sequence');
        $this->addSql('DROP TABLE order_event');
        $this->addSql('DROP TABLE order_line');
        $this->addSql('DROP TABLE sales_order');
        $this->addSql('DROP TABLE channel');
    }
}
