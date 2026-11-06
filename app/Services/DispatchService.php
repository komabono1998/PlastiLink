<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\Recycler;
use App\Models\StockDispatch;

/** Stock leaving a hub for an approved recycler. (Purchase orders and pricing link in during Phase 6.) */
final class DispatchService
{
    public static function findAccessible(int $id, array $actor): ?array
    {
        $d = StockDispatch::find($id);
        return $d && HubScope::allows(HubScope::forUser($actor), (int) $d['hub_id']) ? $d : null;
    }

    /** @return array{errors:array<string,string>, id?:int, duplicate?:bool} */
    public static function create(array $in, array $actor): array
    {
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => ['_' => 'This form expired. Please reload the page and try again.']];
        }
        if ($dup = StockDispatch::idByToken($token)) {
            return ['errors' => [], 'id' => $dup, 'duplicate' => true];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['errors' => ['_' => 'You do not have permission to dispatch stock.']];
        }

        $errors = [];
        $hub = StockInput::hubIn($in, 'hub_id', $actor);
        $cat = StockInput::category($in);
        $recycler = (int) ($in['recycler_id'] ?? 0);
        [$cs, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '');
        if ($hub === null) {
            $errors['hub_id'] = 'Choose one of your hubs.';
        }
        if ($cat === null) {
            $errors['plastic_category_id'] = 'Choose the material.';
        }
        if (!array_key_exists($recycler, Recycler::approvedOptions())) {
            $errors['recycler_id'] = 'Choose an approved recycler.';
        }
        if ($qErr) {
            $errors['quantity_kg'] = $qErr;
        }
        foreach (['vehicle' => 60, 'driver' => 100, 'notes' => 255] as $f => $max) {
            if (mb_strlen(trim((string) ($in[$f] ?? ''))) > $max) {
                $errors[$f] = "Must be $max characters or fewer.";
            }
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        try {
            $id = Database::transaction(function () use ($token, $hub, $cat, $recycler, $cs, $in, $actor): int {
                $id = StockDispatch::insert(['token' => $token, 'hub' => $hub, 'recycler' => $recycler, 'cat' => $cat, 'qty' => Weight::toDecimal($cs),
                    'vehicle' => trim((string) ($in['vehicle'] ?? '')) ?: null, 'driver' => trim((string) ($in['driver'] ?? '')) ?: null,
                    'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'by' => (int) $actor['id']]);
                StockService::post('dispatch_out', $hub, $cat, -$cs, 0, 'dispatch', $id, StockDispatch::find($id)['reference'], 'Dispatched to recycler #' . $recycler, (int) $actor['id']);
                AuditLog::write('stock.dispatch_created', (int) $actor['id'], 'dispatch', $id, ['hub' => $hub, 'recycler' => $recycler, 'kg' => Weight::toDecimal($cs)]);
                return $id;
            });
        } catch (StockException $e) {
            return ['errors' => ['quantity_kg' => $e->getMessage()]];
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = StockDispatch::idByToken($token))) {
                return ['errors' => [], 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
        return ['errors' => [], 'id' => $id];
    }

    public static function markDelivered(int $id, ?string $note, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Dispatch not found.'];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['error' => 'You do not have permission to update dispatches.'];
        }
        $note = trim((string) $note);
        return Database::transaction(function () use ($id, $note, $actor): array {
            $row = StockDispatch::lock($id);
            if (!$row || $row['status'] !== 'dispatched') {
                return ['error' => 'Only dispatched loads can be marked delivered.'];
            }
            StockDispatch::markDelivered($id, (int) $actor['id'], $note !== '' ? mb_substr($note, 0, 255) : null);
            AuditLog::write('stock.dispatch_delivered', (int) $actor['id'], 'dispatch', $id);
            return ['error' => null, 'message' => 'Marked as delivered.'];
        });
    }

    public static function cancel(int $id, ?string $reason, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Dispatch not found.'];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['error' => 'You do not have permission to cancel dispatches.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for cancelling.'];
        }
        try {
            return Database::transaction(function () use ($id, $reason, $actor): array {
                $row = StockDispatch::lock($id);
                if (!$row || $row['status'] !== 'dispatched') {
                    return ['error' => 'Only loads that are still dispatched (not yet delivered) can be cancelled.'];
                }
                StockService::post('dispatch_return', (int) $row['hub_id'], (int) $row['plastic_category_id'], Weight::fromDecimal((string) $row['qty_kg']),
                    0, 'dispatch', $id, StockDispatch::find($id)['reference'], 'Dispatch cancelled', (int) $actor['id']);
                StockDispatch::markCancelled($id, (int) $actor['id'], mb_substr($reason, 0, 255));
                AuditLog::write('stock.dispatch_cancelled', (int) $actor['id'], 'dispatch', $id, ['reason' => mb_substr($reason, 0, 200)]);
                return ['error' => null, 'message' => 'Dispatch cancelled. The stock is back at the hub.'];
            });
        } catch (StockException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
