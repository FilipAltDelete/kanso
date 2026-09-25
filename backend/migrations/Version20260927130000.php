<?php

declare(strict_types=1);

namespace Kanso\Core\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the dashboard's counts (Infrastructure/Doctrine/DashboardQuery):
 * orders shipped in a time range, and stock levels with nothing available.
 * Orders by placed time and by status are covered by indexes that exist.
 */
final class Version20260927130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dashboard indexes: ship events by time, stock levels by available quantity.';
    }

    public function up(Schema $schema): void
    {
        // Covering: the count reads the index alone.
        $this->addSql('ALTER TABLE order_event ADD KEY idx_order_event_transition (transition, occurred_at, order_id)');
        // A functional index (MySQL 8.0.13+); a query must use the same expression to hit it.
        $this->addSql('ALTER TABLE inventory_level ADD KEY idx_inventory_level_available ((on_hand - reserved))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_level DROP KEY idx_inventory_level_available');
        $this->addSql('ALTER TABLE order_event DROP KEY idx_order_event_transition');
    }
}
