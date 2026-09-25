<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial cancel (ADR-0011): an order line counts the units cancelled from
 * it. Every unit of a line is then reserved, shipped, cancelled, or (before
 * confirmation) none of those, so the three together never exceed the line.
 * The earlier checks (reserved ≤ quantity, reserved + shipped ≤ quantity)
 * follow from the new one and stay as they are.
 */
final class Version20260928090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cancelled quantities on order lines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE order_line
                ADD cancelled_quantity INT DEFAULT 0 NOT NULL AFTER shipped_quantity,
                ADD CONSTRAINT chk_order_line_cancelled CHECK (cancelled_quantity >= 0 AND reserved_quantity + shipped_quantity + cancelled_quantity <= quantity)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_line DROP CHECK chk_order_line_cancelled, DROP cancelled_quantity');
    }
}
