<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Shipped today" counts shipments now (ADR-0009), not the ship transition,
 * and the order list filters by ship date: both read shipments by time.
 * Version20260927130000's ship-event index lost its only reader.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index shipments by ship time; drop the unused ship-event index.';
    }

    public function up(Schema $schema): void
    {
        // Covering for the dashboard's count; a range scan for the list filter.
        $this->addSql('ALTER TABLE shipment ADD KEY idx_shipment_shipped (shipped_at, order_id)');
        $this->addSql('ALTER TABLE order_event DROP KEY idx_order_event_transition');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE order_event ADD KEY idx_order_event_transition (transition, occurred_at, order_id)');
        $this->addSql('ALTER TABLE shipment DROP KEY idx_shipment_shipped');
    }
}
