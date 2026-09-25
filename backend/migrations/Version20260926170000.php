<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An order's reference in the system it came from (a webshop's order number,
 * a CSV file's order column). Unique per channel, so importing the same order
 * twice cannot create it twice; NULL for orders entered by hand, and MySQL
 * lets any number of NULLs share the index.
 */
final class Version20260926170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'External order reference, unique per channel.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_order
                ADD external_reference VARCHAR(64) DEFAULT NULL AFTER number,
                ADD UNIQUE KEY uq_sales_order_channel_reference (channel_id, external_reference)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP INDEX uq_sales_order_channel_reference, DROP COLUMN external_reference');
    }
}
