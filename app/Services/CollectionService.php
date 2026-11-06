<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\Hub;
use App\Models\Picker;
use App\Models\PlasticCategory;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\Role;

/**
 * Collection transactions: recording, weighing, approval, rejection and reversal.
 *
 * State machine (enforced here, server-side):
 *   pending  -> approved | rejected
 *   approved -> reversed
 * Approved/rejected/reversed records never change their weights or price.
 */
final class CollectionService
{
    public const TRANSITIONS = ['pending' => ['approved', 'rejected'], 'approved' => ['reversed']];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return array{0:?array,1:?int} [hub ids (null = all), picker user id (null = not a picker)] */
    public static function scopeFor(array $actor): array
    {
        if ($actor['role_slug'] === 'waste_picker') {
            return [null, (int) $actor['id']];
        }
        return [HubScope::forUser($actor), null];
    }

    /** The collection if this user may see it (hub staff: own hubs; pickers: own collections), else null. */
    public static function findAccessible(int $id, array $actor): ?array
    {
        $c = Collection::find($id);
        if (!$c) {
            return null;
        }
        [$hubs, $pickerUser] = self::scopeFor($actor);
        if ($pickerUser !== null && (int) $c['picker_user_id'] !== $pickerUser) {
            return null;
        }
        if ($hubs !== null && !in_array((int) $c['hub_id'], $hubs, true)) {
            return null;
        }
        return $c;
    }

    private static function can(array $actor, string $permission): bool
    {
        return in_array($permission, Role::permissionSlugs((int) $actor['role_id']), true);
    }

    /**
     * Read and validate weights.
     * @return array{0:?array{gross:int,tare:int,net:int},1:array<string,string>}
     */
    public static function readWeights(array $in): array
    {
        $errors = [];
        $grossRaw = trim((string) ($in['gross_weight_kg'] ?? ''));
        $tareRaw = trim((string) ($in['tare_weight_kg'] ?? ''));
        $gross = Weight::parse($grossRaw);
        $tare = $tareRaw === '' ? 0 : Weight::parse($tareRaw);

        if ($grossRaw === '') {
            $errors['gross_weight_kg'] = 'Gross weight is required.';
        } elseif ($gross === null) {
            $errors['gross_weight_kg'] = 'Gross weight must be a number such as 12.5 (at most 2 decimals).';
        } elseif ($gross <= 0) {
            $errors['gross_weight_kg'] = 'Gross weight must be more than zero.';
        } elseif ($gross > Weight::MAX_HUNDREDTHS) {
            $errors['gross_weight_kg'] = 'Gross weight looks too high (maximum 5,000 kg). Check the scale reading.';
        }
        if ($tare === null) {
            $errors['tare_weight_kg'] = 'Tare weight must be a number such as 0.5 (at most 2 decimals), or left empty.';
        }
        if (!$errors && $tare >= $gross) {
            $errors['tare_weight_kg'] = 'Tare weight must be less than the gross weight.';
        }
        if ($errors) {
            return [null, $errors];
        }
        return [['gross' => $gross, 'tare' => $tare, 'net' => $gross - $tare], []];
    }

    /** @return array{errors:array<string,string>, id?:int, duplicate?:bool} */
    public static function record(array $in, array $actor): array
    {
        $token = (string) ($in['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return ['errors' => ['_' => 'This form expired. Please reload the page and try again.']];
        }
        if ($existing = Collection::idByToken($token)) {
            return ['errors' => [], 'id' => $existing, 'duplicate' => true];   // double submit
        }
        if (!self::can($actor, 'collections.record')) {
            return ['errors' => ['_' => 'You do not have permission to record collections.']];
        }

        $errors = [];

        // Picker (limited to pickers this user may see)
        $picker = null;
        $pid = (int) ($in['picker_id'] ?? 0);
        if ($pid > 0) {
            $picker = PickerService::findAccessible($pid, $actor);
        } elseif (trim((string) ($in['picker_code'] ?? '')) !== '') {
            $found = Picker::findByCode((string) $in['picker_code']);
            $picker = $found ? PickerService::findAccessible((int) $found['id'], $actor) : null;
        }
        if (!$picker) {
            $errors['picker'] = 'Choose a registered picker from the list (search by name, picker ID or phone).';
        } elseif ($picker['account_status'] !== 'active') {
            $errors['picker'] = "This picker's account is not active, so a collection cannot be recorded.";
        }

        // Hub and material
        $hubId = (int) ($in['hub_id'] ?? 0);
        if (!array_key_exists($hubId, Hub::options(HubScope::forUser($actor)))) {
            $errors['hub_id'] = 'Choose one of your active hubs.';
        }
        $catId = (int) ($in['plastic_category_id'] ?? 0);
        $cats = PlasticCategory::options();
        if (!array_key_exists($catId, $cats)) {
            $errors['plastic_category_id'] = 'Choose the material.';
        }
        if (mb_strlen(trim((string) ($in['notes'] ?? ''))) > 255) {
            $errors['notes'] = 'Notes must be 255 characters or fewer.';
        }

        [$w, $werr] = self::readWeights($in);
        $errors += $werr;

        $price = null;
        if (!isset($errors['hub_id']) && !isset($errors['plastic_category_id'])) {
            $price = Price::current($catId, $hubId);
            if (!$price) {
                $errors['plastic_category_id'] = "No price has been set for {$cats[$catId]} at this hub yet. Ask an administrator to set one on the Prices page.";
            }
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $priceUgx = (int) $price['price_per_kg_ugx'];
        $row = [
            'gross' => Weight::toDecimal($w['gross']), 'tare' => Weight::toDecimal($w['tare']), 'net' => Weight::toDecimal($w['net']),
            'price' => $priceUgx, 'total' => Weight::value($w['net'], $priceUgx),
        ];

        try {
            $id = Database::transaction(function () use ($row, $token, $picker, $hubId, $catId, $price, $in, $actor): int {
                $id = Collection::insert($row + [
                    'token' => $token, 'picker_id' => (int) $picker['id'], 'hub_id' => $hubId, 'neighbourhood_id' => (int) $picker['neighbourhood_id'],
                    'plastic_category_id' => $catId, 'price_list_id' => (int) $price['id'],
                    'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'recorded_by' => (int) $actor['id'],
                ]);
                Collection::insertWeighing($id, 1, $row, (int) $actor['id'], null);
                AuditLog::write('collection.recorded', (int) $actor['id'], 'collection', $id, ['net_kg' => $row['net'], 'price' => $row['price'], 'total' => $row['total']]);
                return $id;
            });
        } catch (\PDOException $e) {
            // A parallel double-click inserted the same token first.
            if ($e->getCode() === '23000' && ($dup = Collection::idByToken($token))) {
                return ['errors' => [], 'id' => $dup, 'duplicate' => true];
            }
            throw $e;
        }
        return ['errors' => [], 'id' => $id];
    }

    /** New weighing of a PENDING collection (history is kept; the current price is applied). */
    public static function reweigh(int $id, array $in, array $actor): array
    {
        $c = self::findAccessible($id, $actor);
        if (!$c) {
            return ['errors' => ['_' => 'Collection not found.']];
        }
        if (!self::can($actor, 'collections.record')) {
            return ['errors' => ['_' => 'You do not have permission to weigh collections.']];
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        $errors = [];
        if (mb_strlen($reason) < 3) {
            $errors['reason'] = 'Say why the collection is being re-weighed.';
        } elseif (mb_strlen($reason) > 255) {
            $errors['reason'] = 'The reason must be 255 characters or fewer.';
        }
        [$w, $werr] = self::readWeights($in);
        $errors += $werr;
        $price = Price::current((int) $c['plastic_category_id'], (int) $c['hub_id']);
        if (!$price) {
            $errors['_'] = 'No price is currently set for this material at this hub.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $priceUgx = (int) $price['price_per_kg_ugx'];
        $row = [
            'gross' => Weight::toDecimal($w['gross']), 'tare' => Weight::toDecimal($w['tare']), 'net' => Weight::toDecimal($w['net']),
            'price' => $priceUgx, 'total' => Weight::value($w['net'], $priceUgx),
        ];

        return Database::transaction(function () use ($id, $row, $price, $reason, $actor): array {
            $locked = Collection::lock($id);
            if (!$locked || $locked['status'] !== 'pending') {
                return ['errors' => ['_' => 'Only pending collections can be re-weighed. This one has already been decided.']];
            }
            $seq = count(Collection::weighings($id)) + 1;
            Collection::updateWeights($id, $row, (int) $price['id']);
            Collection::insertWeighing($id, $seq, $row, (int) $actor['id'], $reason);
            AuditLog::write('collection.reweighed', (int) $actor['id'], 'collection', $id, ['weighing' => $seq, 'net_kg' => $row['net'], 'total' => $row['total']]);
            return ['errors' => []];
        });
    }

    /** @return array{error:?string, message?:string} */
    public static function approve(int $id, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Collection not found.'];
        }
        if (!self::can($actor, 'collections.approve')) {
            return ['error' => 'You do not have permission to approve collections.'];
        }
        return Database::transaction(function () use ($id, $actor): array {
            $row = Collection::lock($id);
            if (!$row || !self::canMove($row['status'], 'approved')) {
                return ['error' => 'Only pending collections can be approved.'];
            }
            if ((int) $row['recorded_by'] === (int) $actor['id']) {
                return ['error' => 'You recorded this collection, so someone else must approve it.'];
            }
            Collection::setDecision($id, 'approved', (int) $actor['id'], null);
            $full = Collection::find($id);
            ReceiptService::issue($full, $actor);
            // Approved plastic enters the hub's stock (same transaction: no receipt without stock, no stock without a receipt).
            StockService::post('collection_in', (int) $row['hub_id'], (int) $row['plastic_category_id'], Weight::fromDecimal((string) $row['net_weight_kg']),
                0, 'collection', $id, $full['reference'], null, (int) $actor['id']);
            AuditLog::write('collection.approved', (int) $actor['id'], 'collection', $id, ['total' => (int) $row['total_value_ugx']]);
            return ['error' => null, 'message' => 'Collection approved and receipt issued.'];
        });
    }

    /** @return array{error:?string, message?:string} */
    public static function reject(int $id, ?string $reason, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Collection not found.'];
        }
        if (!self::can($actor, 'collections.approve')) {
            return ['error' => 'You do not have permission to reject collections.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for rejecting this collection.'];
        }
        return Database::transaction(function () use ($id, $reason, $actor): array {
            $row = Collection::lock($id);
            if (!$row || !self::canMove($row['status'], 'rejected')) {
                return ['error' => 'Only pending collections can be rejected.'];
            }
            Collection::setDecision($id, 'rejected', (int) $actor['id'], mb_substr($reason, 0, 500));
            AuditLog::write('collection.rejected', (int) $actor['id'], 'collection', $id, ['reason' => mb_substr($reason, 0, 200)]);
            return ['error' => null, 'message' => 'Collection rejected.'];
        });
    }

    /** Reverse an approved collection (authorised roles only; never deletes). */
    public static function reverse(int $id, ?string $reason, array $actor): array
    {
        if (!self::findAccessible($id, $actor)) {
            return ['error' => 'Collection not found.'];
        }
        if (!self::can($actor, 'collections.reverse')) {
            return ['error' => 'You do not have permission to reverse collections.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 5) {
            return ['error' => 'Give a clear reason (at least 5 characters) for reversing this collection.'];
        }
        try {
            return Database::transaction(function () use ($id, $reason, $actor): array {
                $row = Collection::lock($id);
                if (!$row || !self::canMove($row['status'], 'reversed')) {
                    return ['error' => 'Only approved collections can be reversed.'];
                }
                Collection::setReversal($id, (int) $actor['id'], mb_substr($reason, 0, 500));
                Receipt::void($id, mb_substr($reason, 0, 500));
                // Take the plastic back out of stock. Refused (and everything rolled back) if it has already left the hub.
                StockService::post('collection_reversal', (int) $row['hub_id'], (int) $row['plastic_category_id'], -Weight::fromDecimal((string) $row['net_weight_kg']),
                    0, 'collection', $id, $row['reference'], mb_substr($reason, 0, 255), (int) $actor['id']);
                AuditLog::write('collection.reversed', (int) $actor['id'], 'collection', $id, ['reason' => mb_substr($reason, 0, 200), 'total' => (int) $row['total_value_ugx']]);
                return ['error' => null, 'message' => 'Collection reversed and its receipt voided. The plastic was taken out of stock.'];
            });
        } catch (StockException $e) {
            return ['error' => 'This collection cannot be reversed because its plastic is no longer available in the hub\'s stock (it was dispatched, transferred, written off or reserved). '
                . 'Return or adjust that stock first, then reverse. ' . $e->getMessage()];
        }
    }
}
