<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\StockBalance;
use App\Models\StockTransfer;

/** Hub-to-hub transfers in two steps: the sender's stock leaves at once ("in transit"); the receiver confirms what arrived. */
final class TransferService
{
    /** Transfer visible to this user (sending or receiving hub in their scope), else null. */
    public static function findAccessible(int $id, array $actor): ?array
    {
        $t = StockTransfer::find($id);
        if (!$t) {
            return null;
        }
        $scope = HubScope::forUser($actor);
        return ($scope === null || in_array((int) $t['from_hub_id'], $scope, true) || in_array((int) $t['to_hub_id'], $scope, true)) ? $t : null;
    }

    /** @return array{errors:array<string,string>, id?:int, duplicate?:bool} */
    public static function send(array $in, array $actor): array
    {
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => ['_' => 'This form expired. Please reload the page and try again.']];
        }
        if ($dup = StockTransfer::idByToken($token)) {
            return ['errors' => [], 'id' => $dup, 'duplicate' => true];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['errors' => ['_' => 'You do not have permission to transfer stock.']];
        }

        $errors = [];
        $from = StockInput::hubIn($in, 'from_hub_id', $actor);
        $to = (int) ($in['to_hub_id'] ?? 0);
        $cat = StockInput::category($in);
        [$cs, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '');
        if ($from === null) {
            $errors['from_hub_id'] = 'Choose one of your hubs to send from.';
        }
        if (!array_key_exists($to, Hub::options())) {
            $errors['to_hub_id'] = 'Choose an active hub to send to.';
        } elseif ($to === $from) {
            $errors['to_hub_id'] = 'The receiving hub must be a different hub.';
        }
        if ($cat === null) {
            $errors['plastic_category_id'] = 'Choose the material.';
        }
        if ($qErr) {
            $errors['quantity_kg'] = $qErr;
        }
        if (mb_strlen(trim((string) ($in['notes'] ?? ''))) > 255) {
            $errors['notes'] = 'Notes must be 255 characters or fewer.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        try {
            $id = Database::transaction(function () use ($token, $from, $to, $cat, $cs, $in, $actor): int {
                $id = StockTransfer::insert(['token' => $token, 'from' => $from, 'to' => $to, 'cat' => $cat, 'qty' => Weight::toDecimal($cs),
                    'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'by' => (int) $actor['id']]);
                $ref = StockTransfer::find($id)['reference'];
                StockBalance::lock($to, $cat);   // make sure the receiving hub has a balance row, so "arriving" stock is visible there
                StockService::post('transfer_out', $from, $cat, -$cs, 0, 'transfer', $id, $ref, 'Sent to hub #' . $to, (int) $actor['id']);
                AuditLog::write('stock.transfer_sent', (int) $actor['id'], 'transfer', $id, ['from' => $from, 'to' => $to, 'kg' => Weight::toDecimal($cs)]);
                return $id;
            });
        } catch (StockException $e) {
            return ['errors' => ['quantity_kg' => $e->getMessage()]];
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = StockTransfer::idByToken($token))) {
                return ['errors' => [], 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
        return ['errors' => [], 'id' => $id];
    }

    /** The receiving hub confirms the weight that actually arrived (cannot exceed what was sent). */
    public static function receive(int $id, array $in, array $actor): array
    {
        $t = self::findAccessible($id, $actor);
        if (!$t) {
            return ['error' => 'Transfer not found.'];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['error' => 'You do not have permission to receive stock.'];
        }
        if (!HubScope::allows(HubScope::forUser($actor), (int) $t['to_hub_id'])) {
            return ['error' => 'Only the receiving hub can confirm this transfer.'];
        }
        [$cs, $err] = StockInput::quantity($in['received_kg'] ?? '', 'Received weight');
        if ($err) {
            return ['error' => $err];
        }
        $sent = Weight::fromDecimal((string) $t['qty_sent_kg']);
        if ($cs > $sent) {
            return ['error' => 'The received weight cannot be more than the ' . format_kg($t['qty_sent_kg']) . ' that was sent. If more arrived, record a stock adjustment.'];
        }
        $note = trim((string) ($in['note'] ?? ''));
        if ($cs < $sent && mb_strlen($note) < 3) {
            return ['error' => 'Less arrived than was sent. Say what happened to the difference.'];
        }

        try {
            return Database::transaction(function () use ($id, $cs, $sent, $note, $actor): array {
                $row = StockTransfer::lock($id);
                if (!$row || $row['status'] !== 'in_transit') {
                    return ['error' => 'Only transfers that are in transit can be received.'];
                }
                StockService::post('transfer_in', (int) $row['to_hub_id'], (int) $row['plastic_category_id'], $cs, 0, 'transfer', $id,
                    StockTransfer::find($id)['reference'], $note !== '' ? mb_substr($note, 0, 255) : null, (int) $actor['id']);
                StockTransfer::markReceived($id, Weight::toDecimal($cs), (int) $actor['id'], $note !== '' ? mb_substr($note, 0, 255) : null);
                AuditLog::write('stock.transfer_received', (int) $actor['id'], 'transfer', $id, ['received_kg' => Weight::toDecimal($cs), 'lost_kg' => Weight::toDecimal($sent - $cs)]);
                return ['error' => null, 'message' => $cs < $sent ? 'Transfer received with a difference of ' . format_kg(Weight::toDecimal($sent - $cs)) . '.' : 'Transfer received.'];
            });
        } catch (StockException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** The sending hub cancels a transfer that is still in transit; the stock returns to it. */
    public static function cancel(int $id, ?string $reason, array $actor): array
    {
        $t = self::findAccessible($id, $actor);
        if (!$t) {
            return ['error' => 'Transfer not found.'];
        }
        if (!StockInput::can($actor, 'inventory.manage')) {
            return ['error' => 'You do not have permission to cancel transfers.'];
        }
        if (!HubScope::allows(HubScope::forUser($actor), (int) $t['from_hub_id'])) {
            return ['error' => 'Only the sending hub can cancel this transfer.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for cancelling.'];
        }
        try {
            return Database::transaction(function () use ($id, $reason, $actor): array {
                $row = StockTransfer::lock($id);
                if (!$row || $row['status'] !== 'in_transit') {
                    return ['error' => 'Only transfers that are in transit can be cancelled.'];
                }
                StockService::post('transfer_return', (int) $row['from_hub_id'], (int) $row['plastic_category_id'], Weight::fromDecimal((string) $row['qty_sent_kg']),
                    0, 'transfer', $id, StockTransfer::find($id)['reference'], 'Transfer cancelled', (int) $actor['id']);
                StockTransfer::markCancelled($id, (int) $actor['id'], mb_substr($reason, 0, 255));
                AuditLog::write('stock.transfer_cancelled', (int) $actor['id'], 'transfer', $id, ['reason' => mb_substr($reason, 0, 200)]);
                return ['error' => null, 'message' => 'Transfer cancelled. The stock is back at the sending hub.'];
            });
        } catch (StockException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
