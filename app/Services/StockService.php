<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Weight;
use App\Models\StockBalance;

/**
 * The ONLY writer of the stock ledger. Every change is an append-only movement; the balance row is updated in the
 * same transaction, under a row lock. Stock can never go negative and reserved stock can never exceed stock on hand.
 */
final class StockService
{
    public const TYPES = ['collection_in', 'collection_reversal', 'transfer_out', 'transfer_in', 'transfer_return',
                          'dispatch_out', 'dispatch_return', 'adjustment_in', 'adjustment_out', 'write_off', 'reserve', 'release'];

    /**
     * Post one movement. Quantities are signed hundredths of a kg. Must be called inside a transaction.
     *
     * @throws StockException when a stock rule would be broken
     */
    public static function post(
        string $type, int $hubId, int $catId, int $qtyCs, int $reservedDeltaCs,
        string $sourceType, int $sourceId, ?string $reference, ?string $reason, ?int $userId
    ): int {
        $pdo = Database::connection();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('StockService::post must run inside a transaction.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown movement type $type");
        }

        $bal = StockBalance::lock($hubId, $catId);
        $oldOn = Weight::fromDecimal((string) $bal['on_hand_kg']);
        $oldRes = Weight::fromDecimal((string) $bal['reserved_kg']);
        $on = $oldOn + $qtyCs;
        $res = $oldRes + $reservedDeltaCs;

        if ($on < 0) {
            throw new StockException('There is not enough stock at this hub for that. On hand: ' . format_kg(Weight::toDecimal($oldOn)) . '.');
        }
        if ($res < 0) {
            throw new StockException('That would release more stock than is reserved.');
        }
        if ($res > $on) {
            throw new StockException('Part of the stock is reserved for orders. Available: ' . format_kg(Weight::toDecimal(max(0, $oldOn - $oldRes))) . '.');
        }

        try {
            $id = StockBalance::insertMovement([
                'hub' => $hubId, 'cat' => $catId, 'type' => $type, 'qty' => Weight::toSignedDecimal($qtyCs),
                'res_delta' => Weight::toSignedDecimal($reservedDeltaCs), 'balance' => Weight::toDecimal($on), 'reserved' => Weight::toDecimal($res),
                'source_type' => $sourceType, 'source_id' => $sourceId, 'reference' => $reference, 'reason' => $reason, 'user' => $userId,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new StockException('This stock movement has already been recorded.');
            }
            throw $e;
        }
        StockBalance::update((int) $bal['id'], Weight::toDecimal($on), Weight::toDecimal($res));
        return $id;
    }

    /** Reserve stock for an order (Phase 6 will call this). Fails if not enough is available. */
    public static function reserve(int $hubId, int $catId, int $qtyCs, string $sourceType, int $sourceId, ?int $userId, ?string $reference = null): void
    {
        if ($qtyCs <= 0) {
            throw new StockException('The quantity to reserve must be more than zero.');
        }
        Database::atomic(fn() => self::post('reserve', $hubId, $catId, 0, $qtyCs, $sourceType, $sourceId, $reference, null, $userId));
    }

    public static function release(int $hubId, int $catId, int $qtyCs, string $sourceType, int $sourceId, ?int $userId, ?string $reference = null): void
    {
        if ($qtyCs <= 0) {
            throw new StockException('The quantity to release must be more than zero.');
        }
        Database::atomic(fn() => self::post('release', $hubId, $catId, 0, -$qtyCs, $sourceType, $sourceId, $reference, null, $userId));
    }

    /** Stock on hand / reserved / available in hundredths of a kg. */
    public static function levels(int $hubId, int $catId): array
    {
        $b = StockBalance::find($hubId, $catId);
        $on = $b ? Weight::fromDecimal((string) $b['on_hand_kg']) : 0;
        $res = $b ? Weight::fromDecimal((string) $b['reserved_kg']) : 0;
        return ['on_hand' => $on, 'reserved' => $res, 'available' => $on - $res];
    }
}
