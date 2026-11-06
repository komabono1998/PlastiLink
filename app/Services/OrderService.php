<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Dates;
use App\Helpers\Weight;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\PurchaseOrder;
use App\Models\Recycler;
use App\Models\StockDispatch;

/**
 * Marketplace: offers, negotiation, purchase orders, scheduling and fulfilment.
 *
 * Status machine (enforced here):
 *   negotiating -> confirmed | rejected | withdrawn | expired
 *   confirmed   -> in_delivery (first dispatch) | cancelled (before any dispatch)
 *   in_delivery -> completed (everything dispatched and confirmed, or closed short by the hub)
 * Accepting reserves the agreed quantity in the hub's stock, so the same plastic can never be promised twice.
 */
final class OrderService
{
    public const OFFER_HOURS = 72;
    public const MAX_ROUNDS = 6;
    public const MAX_OPEN_PER_RECYCLER = 10;
    public const MAX_PRICE_PER_KG = 1000000;

    private const NONE = ['errors' => [], 'error' => null];

    /** @return array{0:?string,1:?array} ['recycler'|'seller'|null, the recycler row for recyclers] */
    public static function sideOf(array $actor): array
    {
        if ($actor['role_slug'] === 'recycler') {
            $rec = Recycler::findByUserId((int) $actor['id']);
            return ($rec && $rec['status'] === 'approved') ? ['recycler', $rec] : [null, null];
        }
        return StockInput::can($actor, 'orders.review') ? ['seller', null] : [null, null];
    }

    /** The order if this user may see it (recyclers: their own; hub staff: their hubs; administrators: all), else null. */
    public static function findAccessible(int $id, array $actor): ?array
    {
        PurchaseOrder::expireStale();
        $o = PurchaseOrder::find($id);
        if (!$o) {
            return null;
        }
        [$side, $rec] = self::sideOf($actor);
        if ($side === 'recycler') {
            return (int) $rec['id'] === (int) $o['recycler_id'] ? $o : null;
        }
        if ($side === 'seller') {
            return HubScope::allows(HubScope::forUser($actor), (int) $o['hub_id']) ? $o : null;
        }
        return null;
    }

    /** Which buttons this user may use on this order right now. */
    public static function actions(array $o, array $actor): array
    {
        [$side] = self::sideOf($actor);
        $a = ['side' => $side, 'accept' => false, 'counter' => false, 'reject' => false, 'withdraw' => false, 'waiting' => false,
              'schedule' => false, 'cancel' => false, 'dispatch' => false, 'close' => false, 'receive' => false];
        $st = $o['status'];
        if ($st === 'negotiating') {
            $myTurn = $o['last_offer_by'] !== $side;
            $a['accept'] = $myTurn;
            $a['counter'] = $myTurn && (int) $o['round_count'] < self::MAX_ROUNDS;
            $a['waiting'] = !$myTurn;
            $a['reject'] = $side === 'seller';
            $a['withdraw'] = $side === 'recycler';
        }
        if (in_array($st, ['confirmed', 'in_delivery'], true)) {
            $remaining = Weight::fromDecimal((string) $o['agreed_qty_kg']) - Weight::fromDecimal((string) $o['dispatched_kg']);
            $a['cancel'] = $st === 'confirmed';
            if ($side === 'seller') {
                $a['schedule'] = true;
                $a['dispatch'] = $remaining > 0 && StockInput::can($actor, 'inventory.manage');
                $a['close'] = $st === 'in_delivery' && $remaining > 0;
            }
            $a['receive'] = $side === 'recycler';
        }
        if ($st === 'completed' && $side === 'recycler') {
            $a['receive'] = true;   // late confirmation of a load after the hub closed the order short
        }
        return $a;
    }

    // ------------------------------------------------------------------ helpers
    /** @return array{0:?int,1:?string} price in UGX or an error */
    private static function price(mixed $raw): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return [null, 'Price per kg is required.'];
        }
        if (!preg_match('/^\d{1,9}$/', $s)) {
            return [null, 'Price per kg must be a whole number of shillings.'];
        }
        $p = (int) $s;
        return ($p < 1 || $p > self::MAX_PRICE_PER_KG)
            ? [null, 'Price per kg must be between 1 and ' . number_format(self::MAX_PRICE_PER_KG) . ' UGX.']
            : [$p, null];
    }

    /** @return array{0:?string,1:?string} Y-m-d or null, and an error */
    private static function date(mixed $raw, bool $required = false, string $label = 'Delivery date'): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return [null, $required ? "$label is required." : null];
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) {
            return [null, "$label must be a valid date."];
        }
        $today = Dates::todayYmd();
        if ($s < $today) {
            return [null, "$label cannot be in the past."];
        }
        if ($s > (new \DateTimeImmutable($today))->modify('+180 days')->format('Y-m-d')) {
            return [null, "$label must be within the next 180 days."];
        }
        return [$s, null];
    }

    private static function note(array $in, string $key = 'note'): ?string
    {
        $n = trim((string) ($in[$key] ?? ''));
        return $n === '' ? null : mb_substr($n, 0, 255);
    }

    // ------------------------------------------------------------------ open a negotiation
    /** @return array{errors:array<string,string>, error:?string, id?:int, duplicate?:bool} */
    public static function create(array $in, array $actor): array
    {
        [$side, $rec] = self::sideOf($actor);
        if ($side !== 'recycler') {
            return ['errors' => [], 'error' => 'Only approved recycling companies can place offers.'];
        }
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => [], 'error' => 'This form expired. Please reload the page and try again.'];
        }
        PurchaseOrder::expireStale();
        if ($dup = PurchaseOrder::idByToken($token)) {
            return ['errors' => [], 'error' => null, 'id' => $dup, 'duplicate' => true];
        }
        if (PurchaseOrder::openNegotiations((int) $rec['id']) >= self::MAX_OPEN_PER_RECYCLER) {
            return ['errors' => [], 'error' => 'You already have ' . self::MAX_OPEN_PER_RECYCLER . ' open negotiations. Finish or withdraw one first.'];
        }

        $errors = [];
        $hub = (int) ($in['hub_id'] ?? 0);
        $cat = (int) ($in['plastic_category_id'] ?? 0);
        if (!array_key_exists($hub, Hub::options())) {
            $errors['hub_id'] = 'Choose a hub.';
        }
        if (!array_key_exists($cat, PlasticCategory::options())) {
            $errors['plastic_category_id'] = 'Choose the material.';
        }
        [$qty, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '');
        [$price, $pErr] = self::price($in['price_per_kg_ugx'] ?? '');
        [$date, $dErr] = self::date($in['delivery_date'] ?? '');
        foreach (['quantity_kg' => $qErr, 'price_per_kg_ugx' => $pErr, 'delivery_date' => $dErr] as $k => $e) {
            if ($e) {
                $errors[$k] = $e;
            }
        }
        if (mb_strlen(trim((string) ($in['note'] ?? ''))) > 255) {
            $errors['note'] = 'The note must be 255 characters or fewer.';
        }
        if (!$errors) {
            $available = StockService::levels($hub, $cat)['available'];
            if ($qty > $available) {
                $errors['quantity_kg'] = 'Only ' . format_kg(Weight::toDecimal(max(0, $available))) . ' is available at this hub right now.';
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'error' => null];
        }

        try {
            $id = Database::transaction(function () use ($token, $rec, $hub, $cat, $qty, $price, $date, $in, $actor): int {
                $id = PurchaseOrder::insert(['token' => $token, 'recycler' => (int) $rec['id'], 'hub' => $hub, 'cat' => $cat, 'hours' => self::OFFER_HOURS, 'by' => (int) $actor['id']]);
                PurchaseOrder::insertOffer($id, 1, 'recycler', (int) $actor['id'], Weight::toDecimal($qty), $price, Weight::value($qty, $price), $date, self::note($in));
                AuditLog::write('order.requested', (int) $actor['id'], 'order', $id, ['hub' => $hub, 'kg' => Weight::toDecimal($qty), 'price' => $price]);
                return $id;
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = PurchaseOrder::idByToken($token))) {
                return ['errors' => [], 'error' => null, 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
        return ['errors' => [], 'error' => null, 'id' => $id];
    }

    // ------------------------------------------------------------------ negotiate
    /** Counter the other side's latest offer. */
    public static function counter(int $id, array $in, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o) {
            return ['errors' => [], 'error' => 'Order not found.'];
        }
        [$side] = self::sideOf($actor);
        if ($o['status'] !== 'negotiating') {
            return ['errors' => [], 'error' => 'This negotiation is no longer open.'];   // say so before complaining about the form fields
        }

        $errors = [];
        [$qty, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '');
        [$price, $pErr] = self::price($in['price_per_kg_ugx'] ?? '');
        [$date, $dErr] = self::date($in['delivery_date'] ?? '');
        foreach (['quantity_kg' => $qErr, 'price_per_kg_ugx' => $pErr, 'delivery_date' => $dErr] as $k => $e) {
            if ($e) {
                $errors[$k] = $e;
            }
        }
        if (mb_strlen(trim((string) ($in['note'] ?? ''))) > 255) {
            $errors['note'] = 'The note must be 255 characters or fewer.';
        }
        if (!$errors) {
            $available = StockService::levels((int) $o['hub_id'], (int) $o['plastic_category_id'])['available'];
            if ($qty > $available) {
                $errors['quantity_kg'] = 'Only ' . format_kg(Weight::toDecimal(max(0, $available))) . ' is available at this hub right now.';
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'error' => null];
        }

        return Database::transaction(function () use ($id, $side, $qty, $price, $date, $in, $actor): array {
            $row = PurchaseOrder::lock($id);
            if ($err = self::negotiationProblem($row, $side)) {
                return ['errors' => [], 'error' => $err];
            }
            if ((int) $row['round_count'] >= self::MAX_ROUNDS) {
                return ['errors' => [], 'error' => 'This is the final round. Accept the latest offer or end the negotiation.'];
            }
            $round = (int) $row['round_count'] + 1;
            PurchaseOrder::closeOpenOffer($id, 'superseded');
            PurchaseOrder::insertOffer($id, $round, $side, (int) $actor['id'], Weight::toDecimal($qty), $price, Weight::value($qty, $price), $date, self::note($in));
            PurchaseOrder::setCounter($id, $round, $side, self::OFFER_HOURS);
            AuditLog::write('order.countered', (int) $actor['id'], 'order', $id, ['round' => $round, 'by' => $side, 'kg' => Weight::toDecimal($qty), 'price' => $price]);
            return self::NONE + ['message' => 'Counter-offer sent.'];
        });
    }

    /** Accept the other side's latest offer: creates the order terms and reserves the stock. */
    public static function accept(int $id, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o) {
            return ['error' => 'Order not found.'];
        }
        [$side] = self::sideOf($actor);
        try {
            return Database::transaction(function () use ($id, $side, $actor): array {
                $row = PurchaseOrder::lock($id);
                if ($err = self::negotiationProblem($row, $side)) {
                    return ['error' => $err];
                }
                $s = Database::connection()->prepare("SELECT * FROM order_offers WHERE order_id = ? AND status = 'open' ORDER BY round_no DESC LIMIT 1 FOR UPDATE");
                $s->execute([$id]);
                $offer = $s->fetch();
                if (!$offer) {
                    return ['error' => 'There is no open offer to accept.'];
                }
                $qty = Weight::fromDecimal((string) $offer['qty_kg']);
                $ref = PurchaseOrder::find($id)['reference'];

                // Reserve first: if the plastic is no longer available the whole acceptance is rolled back.
                StockService::reserve((int) $row['hub_id'], (int) $row['plastic_category_id'], $qty, 'order', $id, (int) $actor['id'], $ref);
                PurchaseOrder::insertItem($id, (int) $row['plastic_category_id'], (string) $offer['qty_kg'], (int) $offer['price_per_kg_ugx'], (int) $offer['total_ugx']);
                PurchaseOrder::closeOpenOffer($id, 'accepted');
                PurchaseOrder::confirm($id, (int) $actor['id'], $offer['delivery_date']);
                AuditLog::write('order.accepted', (int) $actor['id'], 'order', $id, ['kg' => $offer['qty_kg'], 'price' => (int) $offer['price_per_kg_ugx'], 'total' => (int) $offer['total_ugx']]);
                return ['error' => null, 'message' => 'Offer accepted. The plastic is now reserved for this order.'];
            });
        } catch (StockException $e) {
            return ['error' => 'The offer cannot be accepted because the plastic is no longer available. ' . $e->getMessage()];
        }
    }

    /** Seller rejects, or the recycler withdraws, while still negotiating. */
    public static function endNegotiation(int $id, ?string $reason, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o) {
            return ['error' => 'Order not found.'];
        }
        [$side] = self::sideOf($actor);
        $reason = trim((string) $reason);
        if ($side === 'seller' && mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for rejecting this request.'];
        }
        return Database::transaction(function () use ($id, $side, $reason, $actor): array {
            $row = PurchaseOrder::lock($id);
            if (!$row || $row['status'] !== 'negotiating') {
                return ['error' => 'Only a negotiation in progress can be ended.'];
            }
            $status = $side === 'seller' ? 'rejected' : 'withdrawn';
            PurchaseOrder::closeOpenOffer($id, $status);
            PurchaseOrder::end($id, $status, (int) $actor['id'], $reason !== '' ? mb_substr($reason, 0, 500) : null);
            AuditLog::write('order.' . $status, (int) $actor['id'], 'order', $id, $reason !== '' ? ['reason' => mb_substr($reason, 0, 200)] : []);
            return ['error' => null, 'message' => $side === 'seller' ? 'Request rejected.' : 'Offer withdrawn.'];
        });
    }

    /** null when this side may act on the order's negotiation now, otherwise a plain-language problem. */
    private static function negotiationProblem(?array $row, ?string $side): ?string
    {
        if (!$row || $row['status'] !== 'negotiating') {
            return 'This negotiation is no longer open.';
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            PurchaseOrder::expireStale();
            return 'This offer has expired.';
        }
        if ($row['last_offer_by'] === $side) {
            return 'You made the latest offer. Wait for the other side to respond.';
        }
        return null;
    }

    // ------------------------------------------------------------------ confirmed orders
    public static function schedule(int $id, array $in, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o || self::sideOf($actor)[0] !== 'seller') {
            return ['errors' => [], 'error' => 'Order not found.'];
        }
        [$date, $err] = self::date($in['delivery_date'] ?? '', true);
        if ($err) {
            return ['errors' => ['delivery_date' => $err], 'error' => null];
        }
        if (mb_strlen(trim((string) ($in['note'] ?? ''))) > 255) {
            return ['errors' => ['note' => 'The note must be 255 characters or fewer.'], 'error' => null];
        }
        return Database::transaction(function () use ($id, $date, $in, $actor): array {
            $row = PurchaseOrder::lock($id);
            if (!$row || !in_array($row['status'], ['confirmed', 'in_delivery'], true)) {
                return ['errors' => [], 'error' => 'Only confirmed orders can be scheduled.'];
            }
            PurchaseOrder::setSchedule($id, $date, self::note($in));
            AuditLog::write('order.scheduled', (int) $actor['id'], 'order', $id, ['date' => $date]);
            return self::NONE + ['message' => 'Delivery date saved.'];
        });
    }

    /** Cancel a confirmed order before anything is dispatched; the reservation is released. */
    public static function cancel(int $id, ?string $reason, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o) {
            return ['error' => 'Order not found.'];
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            return ['error' => 'Give a short reason for cancelling.'];
        }
        try {
            return Database::transaction(function () use ($id, $reason, $actor): array {
                $row = PurchaseOrder::lock($id);
                $item = PurchaseOrder::lockItem($id);
                if (!$row || $row['status'] !== 'confirmed' || !$item || Weight::fromDecimal((string) $item['dispatched_kg']) > 0) {
                    return ['error' => 'Only a confirmed order with nothing dispatched can be cancelled. Once loads have left, the hub closes the order instead.'];
                }
                StockService::release((int) $row['hub_id'], (int) $row['plastic_category_id'], Weight::fromDecimal((string) $item['agreed_qty_kg']), 'order', $id, (int) $actor['id'],
                    PurchaseOrder::find($id)['reference']);
                PurchaseOrder::end($id, 'cancelled', (int) $actor['id'], mb_substr($reason, 0, 500));
                AuditLog::write('order.cancelled', (int) $actor['id'], 'order', $id, ['reason' => mb_substr($reason, 0, 200)]);
                return ['error' => null, 'message' => 'Order cancelled. The reserved plastic is available again.'];
            });
        } catch (StockException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** The hub closes an order that was only partly delivered; the remaining reservation is released. */
    public static function closeShort(int $id, ?string $note, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o || self::sideOf($actor)[0] !== 'seller') {
            return ['error' => 'Order not found.'];
        }
        $note = trim((string) $note);
        if (mb_strlen($note) < 3) {
            return ['error' => 'Say why the order is being closed short.'];
        }
        try {
            return Database::transaction(function () use ($id, $note, $actor): array {
                $row = PurchaseOrder::lock($id);
                $item = PurchaseOrder::lockItem($id);
                if (!$row || $row['status'] !== 'in_delivery' || !$item) {
                    return ['error' => 'Only an order that is being delivered can be closed short.'];
                }
                $remaining = Weight::fromDecimal((string) $item['agreed_qty_kg']) - Weight::fromDecimal((string) $item['dispatched_kg']);
                if ($remaining <= 0) {
                    return ['error' => 'Everything agreed has been dispatched. The order completes when the recycler confirms receipt.'];
                }
                StockService::release((int) $row['hub_id'], (int) $row['plastic_category_id'], $remaining, 'order', $id, (int) $actor['id'], PurchaseOrder::find($id)['reference']);
                PurchaseOrder::complete($id, Weight::toDecimal($remaining), (int) $actor['id'], mb_substr($note, 0, 500));
                AuditLog::write('order.closed_short', (int) $actor['id'], 'order', $id, ['short_kg' => Weight::toDecimal($remaining), 'note' => mb_substr($note, 0, 200)]);
                return ['error' => null, 'message' => 'Order closed. The undelivered ' . format_kg(Weight::toDecimal($remaining)) . ' is available again.'];
            });
        } catch (StockException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------ fulfilment
    /** The hub dispatches (part of) the order. Consumes the reservation and takes stock out in one movement. */
    public static function dispatch(int $id, array $in, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o || self::sideOf($actor)[0] !== 'seller' || !StockInput::can($actor, 'inventory.manage')) {
            return ['errors' => [], 'error' => 'You do not have permission to dispatch for this order.'];
        }
        $token = StockInput::token($in);
        if (!$token) {
            return ['errors' => [], 'error' => 'This form expired. Please reload the page and try again.'];
        }
        if ($dup = StockDispatch::idByToken($token)) {
            return ['errors' => [], 'error' => null, 'id' => $dup, 'duplicate' => true];
        }
        [$qty, $qErr] = StockInput::quantity($in['quantity_kg'] ?? '', 'Weight loaded');
        $errors = $qErr ? ['quantity_kg' => $qErr] : [];
        foreach (['vehicle' => 60, 'driver' => 100, 'notes' => 255] as $f => $max) {
            if (mb_strlen(trim((string) ($in[$f] ?? ''))) > $max) {
                $errors[$f] = "Must be $max characters or fewer.";
            }
        }
        if ($errors) {
            return ['errors' => $errors, 'error' => null];
        }

        try {
            $did = Database::transaction(function () use ($id, $token, $qty, $in, $actor, $o): int|array {
                $row = PurchaseOrder::lock($id);
                $item = PurchaseOrder::lockItem($id);
                if (!$row || !$item || !in_array($row['status'], ['confirmed', 'in_delivery'], true)) {
                    return ['errors' => [], 'error' => 'This order is not open for dispatch.'];
                }
                $agreed = Weight::fromDecimal((string) $item['agreed_qty_kg']);
                $sent = Weight::fromDecimal((string) $item['dispatched_kg']);
                if ($qty > $agreed - $sent) {
                    return ['errors' => ['quantity_kg' => 'Only ' . format_kg(Weight::toDecimal($agreed - $sent)) . ' of this order is still to be dispatched.'], 'error' => null];
                }
                $did = StockDispatch::insert(['token' => $token, 'hub' => (int) $row['hub_id'], 'recycler' => (int) $row['recycler_id'], 'order' => $id,
                    'cat' => (int) $row['plastic_category_id'], 'qty' => Weight::toDecimal($qty),
                    'vehicle' => trim((string) ($in['vehicle'] ?? '')) ?: null, 'driver' => trim((string) ($in['driver'] ?? '')) ?: null,
                    'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'by' => (int) $actor['id']]);
                // One movement: stock on hand goes down AND the reservation shrinks by the same amount.
                StockService::post('dispatch_out', (int) $row['hub_id'], (int) $row['plastic_category_id'], -$qty, -$qty, 'dispatch', $did,
                    StockDispatch::find($did)['reference'], 'Order ' . $o['reference'], (int) $actor['id']);
                PurchaseOrder::setItemQuantities($id, Weight::toDecimal($sent + $qty), (string) $item['received_kg']);
                PurchaseOrder::setStatus($id, 'in_delivery');
                AuditLog::write('order.dispatched', (int) $actor['id'], 'order', $id, ['dispatch' => $did, 'kg' => Weight::toDecimal($qty)]);
                return $did;
            });
        } catch (StockException $e) {
            return ['errors' => ['quantity_kg' => $e->getMessage()], 'error' => null];
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' && ($d = StockDispatch::idByToken($token))) {
                return ['errors' => [], 'error' => null, 'id' => $d, 'duplicate' => true];
            }
            throw $e;
        }
        return is_array($did) ? $did : ['errors' => [], 'error' => null, 'id' => $did];
    }

    /** The recycler confirms the weight that arrived for one dispatch. Completes the order when everything is in. */
    public static function receive(int $id, int $dispatchId, array $in, array $actor): array
    {
        $o = self::findAccessible($id, $actor);
        if (!$o || self::sideOf($actor)[0] !== 'recycler') {
            return ['error' => 'Order not found.'];
        }
        [$qty, $err] = StockInput::quantity($in['received_kg'] ?? '', 'Received weight');
        if ($err) {
            return ['error' => $err];
        }
        $note = trim((string) ($in['note'] ?? ''));

        return Database::transaction(function () use ($id, $dispatchId, $qty, $note, $actor): array {
            $row = PurchaseOrder::lock($id);
            $item = PurchaseOrder::lockItem($id);
            $d = StockDispatch::lock($dispatchId);
            if (!$row || !$item || !$d || (int) $d['purchase_order_id'] !== $id || $d['status'] !== 'dispatched') {
                return ['error' => 'That load is not waiting for confirmation.'];
            }
            $sent = Weight::fromDecimal((string) $d['qty_kg']);
            if ($qty > $sent) {
                return ['error' => 'The received weight cannot be more than the ' . format_kg($d['qty_kg']) . ' that was dispatched.'];
            }
            if ($qty < $sent && mb_strlen($note) < 3) {
                return ['error' => 'Less arrived than was dispatched. Say what happened to the difference.'];
            }
            StockDispatch::markReceived($dispatchId, (int) $actor['id'], Weight::toDecimal($qty), $note !== '' ? mb_substr($note, 0, 255) : null);
            $received = Weight::fromDecimal((string) $item['received_kg']) + $qty;
            PurchaseOrder::setItemQuantities($id, (string) $item['dispatched_kg'], Weight::toDecimal($received));
            AuditLog::write('order.received', (int) $actor['id'], 'order', $id, ['dispatch' => $dispatchId, 'received_kg' => Weight::toDecimal($qty), 'dispatched_kg' => $d['qty_kg']]);

            $pending = (int) Database::connection()->query("SELECT COUNT(*) FROM stock_dispatches WHERE purchase_order_id = $id AND status = 'dispatched'")->fetchColumn();
            if ($row['status'] === 'in_delivery' && Weight::fromDecimal((string) $item['dispatched_kg']) === Weight::fromDecimal((string) $item['agreed_qty_kg']) && $pending === 0) {
                PurchaseOrder::complete($id, '0.00', null, null);
                AuditLog::write('order.completed', (int) $actor['id'], 'order', $id);
                return ['error' => null, 'message' => 'Receipt confirmed. This order is now complete.'];
            }
            return ['error' => null, 'message' => 'Receipt confirmed.'];
        });
    }

    /**
     * Called by DispatchService when a dispatch that belongs to an order is cancelled before delivery.
     * Returns the quantity (hundredths) that must go back into the reservation.
     * @throws StockException if the order is already closed
     */
    public static function onDispatchCancelled(array $dispatch): int
    {
        $id = (int) $dispatch['purchase_order_id'];
        $o = PurchaseOrder::lock($id);
        $item = PurchaseOrder::lockItem($id);
        if (!$o || !$item || !in_array($o['status'], ['confirmed', 'in_delivery'], true)) {
            throw new StockException('This load belongs to an order that is already closed, so it cannot be cancelled.');
        }
        $q = Weight::fromDecimal((string) $dispatch['qty_kg']);
        $left = Weight::fromDecimal((string) $item['dispatched_kg']) - $q;
        PurchaseOrder::setItemQuantities($id, Weight::toDecimal($left), (string) $item['received_kg']);
        PurchaseOrder::setStatus($id, $left === 0 ? 'confirmed' : 'in_delivery');
        return $q;
    }
}
