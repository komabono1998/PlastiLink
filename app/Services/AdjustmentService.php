<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\StockAdjustment;

/**
 * Manual stock entry, write-offs of rejected material and physical-count variances.
 * Nothing changes stock until a DIFFERENT person approves it.
 */
final class AdjustmentService
{
    public const KINDS = ['manual_entry' => 'Manual stock entry', 'write_off' => 'Write-off (rejected / lost)', 'count' => 'Physical count'];

    public static function findAccessible(int $id, array $actor): ?array
    {
        $a = StockAdjustment::find($id);
        return $a && HubScope::allows(HubScope::forUser($actor), (int) $a['hub_id']) ? $a : null;
    }

    /** @return array{errors:array<string,string>, id?:int, duplicate?:bool} */
    public static function request(array $in, array $actor): array
    {
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => ['_' => 'This form expired. Please reload the page and try again.']];
        }
        if ($dup = StockAdjustment::idByToken($token)) {
            return ['errors' => [], 'id' => $dup, 'duplicate' => true];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['errors' => ['_' => 'You do not have permission to request stock adjustments.']];
        }

        $errors = [];
        $kind = (string) ($in['kind'] ?? '');
        $hub = StockInput::hubIn($in, 'hub_id', $actor);
        $cat = StockInput::category($in);
        $reason = trim((string) ($in['reason'] ?? ''));
        if (!isset(self::KINDS[$kind])) {
            $errors['kind'] = 'Choose what kind of adjustment this is.';
        }
        if ($hub === null) {
            $errors['hub_id'] = 'Choose one of your hubs.';
        }
        if ($cat === null) {
            $errors['plastic_category_id'] = 'Choose the material.';
        }
        if (mb_strlen($reason) < 5) {
            $errors['reason'] = 'Give a clear reason (at least 5 characters).';
        } elseif (mb_strlen($reason) > 255) {
            $errors['reason'] = 'The reason must be 255 characters or fewer.';
        }
        [$cs, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '', $kind === 'count' ? 'Counted weight' : 'Quantity', $kind === 'count');
        if ($qErr) {
            $errors['quantity_kg'] = $qErr;
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $system = StockService::levels($hub, $cat)['on_hand'];
        $row = ['token' => $token, 'hub' => $hub, 'cat' => $cat, 'kind' => $kind, 'reason' => mb_substr($reason, 0, 255), 'by' => (int) $actor['id'],
                'system' => null, 'counted' => null];
        if ($kind === 'count') {
            $change = $cs - $system;
            if ($change === 0) {
                return ['errors' => ['quantity_kg' => 'The count matches the system balance (' . format_kg(Weight::toDecimal($system)) . '). No adjustment is needed.']];
            }
            $row['system'] = Weight::toDecimal($system);
            $row['counted'] = Weight::toDecimal($cs);
        } elseif ($kind === 'write_off') {
            $available = StockService::levels($hub, $cat)['available'];
            if ($cs > $available) {
                return ['errors' => ['quantity_kg' => 'You cannot write off more than the available stock (' . format_kg(Weight::toDecimal(max(0, $available))) . ').']];
            }
            $change = -$cs;
        } else {
            $change = $cs;
        }
        $row['change'] = Weight::toSignedDecimal($change);

        try {
            $id = StockAdjustment::insert($row);
            AuditLog::write('stock.adjustment_requested', (int) $actor['id'], 'adjustment', $id, ['kind' => $kind, 'change_kg' => $row['change']]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = StockAdjustment::idByToken($token))) {
                return ['errors' => [], 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
        return ['errors' => [], 'id' => $id];
    }

    /** approve | reject. Approval posts the movement. @return array{error:?string, message?:string} */
    public static function decide(int $id, string $decision, ?string $note, array $actor): array
    {
        $a = self::findAccessible($id, $actor);
        if (!$a) {
            return ['error' => 'Adjustment not found.'];
        }
        if (!StockInput::can($actor, 'inventory.approve')) {
            return ['error' => 'You do not have permission to approve stock adjustments.'];
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return ['error' => 'Unknown action.'];
        }
        $note = trim((string) $note);
        if ($decision === 'reject' && mb_strlen($note) < 3) {
            return ['error' => 'Give a short reason for rejecting this adjustment.'];
        }

        try {
            return Database::transaction(function () use ($id, $decision, $note, $actor): array {
                $row = StockAdjustment::lock($id);
                if (!$row || $row['status'] !== 'pending') {
                    return ['error' => 'Only pending adjustments can be decided.'];
                }
                if ((int) $row['requested_by'] === (int) $actor['id']) {
                    return ['error' => 'You requested this adjustment, so someone else must approve it.'];
                }
                if ($decision === 'reject') {
                    StockAdjustment::decide($id, 'rejected', (int) $actor['id'], mb_substr($note, 0, 255));
                    AuditLog::write('stock.adjustment_rejected', (int) $actor['id'], 'adjustment', $id, ['reason' => mb_substr($note, 0, 200)]);
                    return ['error' => null, 'message' => 'Adjustment rejected. Stock was not changed.'];
                }

                $change = Weight::fromDecimal((string) $row['change_kg']);
                $type = $row['kind'] === 'write_off' ? 'write_off' : ($change > 0 ? 'adjustment_in' : 'adjustment_out');
                $ref = StockAdjustment::find($id)['reference'];
                StockService::post($type, (int) $row['hub_id'], (int) $row['plastic_category_id'], $change, 0, 'adjustment', $id, $ref,
                    mb_substr(self::KINDS[$row['kind']] . ': ' . $row['reason'], 0, 255), (int) $actor['id']);
                StockAdjustment::decide($id, 'approved', (int) $actor['id'], $note !== '' ? mb_substr($note, 0, 255) : null);
                AuditLog::write('stock.adjustment_approved', (int) $actor['id'], 'adjustment', $id, ['change_kg' => $row['change_kg']]);
                return ['error' => null, 'message' => 'Adjustment approved and posted to stock.'];
            });
        } catch (StockException $e) {
            return ['error' => 'Stock has changed and this adjustment can no longer be applied: ' . $e->getMessage()];
        }
    }
}
