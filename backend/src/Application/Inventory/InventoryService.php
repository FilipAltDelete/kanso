<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Application\Inventory;

use Kanso\Core\Internal\Application\Exception\Conflict;
use Kanso\Core\Internal\Application\Exception\ValidationFailed;
use Kanso\Core\Internal\Domain\Catalog\ProductStoreInterface;
use Kanso\Core\Internal\Domain\Common\Actor;
use Kanso\Core\Internal\Domain\Common\ConcurrentModification;
use Kanso\Core\Internal\Domain\Common\TransactionInterface;
use Kanso\Core\Internal\Domain\Inventory\AdjustmentReason;
use Kanso\Core\Internal\Domain\Inventory\InventoryLevel;
use Kanso\Core\Internal\Domain\Inventory\InventoryMovement;
use Kanso\Core\Internal\Domain\Inventory\InventoryStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\LocationStoreInterface;
use Kanso\Core\Internal\Domain\Inventory\StockRuleViolated;
use Psr\Clock\ClockInterface;

/**
 * Manual stock adjustments.
 *
 * One adjustment is one transaction: the level changes and its movement is
 * written together, or neither is. The caller sends the version of the level
 * it showed the operator (0 when there was no stock at that location yet); a
 * different version means someone else adjusted in between, and the operator
 * decides again with the current numbers rather than having theirs applied on
 * top of a count they never saw.
 */
final class InventoryService
{
    public const int MAX_NOTE_LENGTH = 500;

    public function __construct(
        private readonly ProductStoreInterface $products,
        private readonly LocationStoreInterface $locations,
        private readonly InventoryStoreInterface $inventory,
        private readonly TransactionInterface $transaction,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Exactly one of `$delta` (add or remove) and `$counted` (set on hand to
     * what was counted) is given.
     */
    public function adjust(
        string $productId,
        string $locationId,
        ?int $delta,
        ?int $counted,
        string $reason,
        ?string $note,
        int $expectedVersion,
        Actor $actor,
    ): InventoryMovement {
        $note = null === $note ? null : trim($note);
        $note = '' === $note ? null : $note;
        $reasonCode = AdjustmentReason::tryFrom($reason);
        $product = $this->products->findById($productId);
        $location = $this->locations->findById($locationId);

        $violations = [];
        if (null === $product) {
            $violations[] = ['path' => 'productId', 'message' => 'No such product.', 'code' => 'not_found'];
        }
        if (null === $location) {
            $violations[] = ['path' => 'locationId', 'message' => 'No such location.', 'code' => 'not_found'];
        }
        if ((null === $delta) === (null === $counted)) {
            $violations[] = ['path' => 'delta', 'message' => 'Send either a change (delta) or a counted quantity (onHand), not both.', 'code' => 'one_of'];
        }
        if (null === $reasonCode) {
            $violations[] = ['path' => 'reason', 'message' => \sprintf('Reason is one of: %s.', implode(', ', array_column(AdjustmentReason::cases(), 'value'))), 'code' => 'unknown_reason'];
        } elseif ($reasonCode->requiresNote() && null === $note) {
            $violations[] = ['path' => 'note', 'message' => 'Say what happened when the reason is "other".', 'code' => 'required'];
        }
        if (null !== $note && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $violations[] = ['path' => 'note', 'message' => \sprintf('A note is at most %d characters.', self::MAX_NOTE_LENGTH), 'code' => 'length'];
        }
        if ($expectedVersion < 0) {
            $violations[] = ['path' => 'expectedVersion', 'message' => 'A version is 0 or more.', 'code' => 'range'];
        }
        if ([] !== $violations || null === $product || null === $location || null === $reasonCode) {
            throw new ValidationFailed($violations);
        }

        try {
            return $this->transaction->run(function () use ($product, $location, $delta, $counted, $reasonCode, $note, $expectedVersion, $actor): InventoryMovement {
                $now = $this->clock->now();
                $level = $this->inventory->findLevel($product, $location);
                $current = $level?->version() ?? 0;

                if ($current !== $expectedVersion) {
                    throw self::stale($current, $expectedVersion);
                }

                if (null === $level) {
                    $level = new InventoryLevel($product, $location, $now);
                    $this->inventory->addLevel($level);
                }

                try {
                    $change = null !== $delta ? $level->adjustBy($delta, $now) : $level->countAs((int) $counted, $now);
                } catch (StockRuleViolated $violation) {
                    throw new ValidationFailed([['path' => null !== $delta ? 'delta' : 'onHand', 'message' => $violation->getMessage(), 'code' => $violation->rule]]);
                }

                $movement = InventoryMovement::adjustment($level, $change, $reasonCode, $note, $actor, $now);
                $this->inventory->addMovement($movement);

                return $movement;
            });
        } catch (ConcurrentModification) {
            // Lost the race between our read and our write.
            throw new Conflict('Someone else adjusted this stock at the same moment. Reload and try again.');
        }
    }

    private static function stale(int $current, int $expected): Conflict
    {
        return new Conflict(\sprintf(
            'The stock changed since you looked at it (version %d, you saw %d). Reload and try again.',
            $current,
            $expected,
        ));
    }
}
