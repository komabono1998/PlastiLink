<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\Payment;
use PDO;

/** Ticking successful payments off against the real mobile money / bank statements. */
final class ReconciliationService
{
    /**
     * Mark successful, not-yet-reconciled payments as matched to a statement line.
     * @param int[] $paymentIds
     * @return array{error:?string, count:int}
     */
    public static function mark(array $paymentIds, ?string $statementRef, array $actor): array
    {
        if (!StockInput::can($actor, 'payments.reconcile')) {
            return ['error' => 'You do not have permission to reconcile payments.', 'count' => 0];
        }
        $ref = trim((string) $statementRef);
        if (!preg_match('/^[A-Za-z0-9\-_.\/ ]{3,80}$/', $ref)) {
            return ['error' => 'Enter the statement reference (3 to 80 letters or numbers), for example the statement date or number.', 'count' => 0];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
        if (!$ids) {
            return ['error' => 'Tick at least one payment.', 'count' => 0];
        }
        return Database::transaction(function () use ($ids, $ref, $actor): array {
            $n = 0;
            foreach ($ids as $id) {
                $p = Payment::lock($id);
                if (!$p || $p['status'] !== 'successful' || $p['reconciled_at'] !== null) {
                    continue;
                }
                Payment::update($id, ['reconciled_by' => (int) $actor['id'], 'reconciled_at' => gmdate('Y-m-d H:i:s'), 'statement_reference' => $ref]);
                AuditLog::write('payment.reconciled', (int) $actor['id'], 'payment', $id, ['statement' => $ref]);
                $n++;
            }
            return $n === 0
                ? ['error' => 'None of the ticked payments could be reconciled (they must be successful and not already reconciled).', 'count' => 0]
                : ['error' => null, 'count' => $n];
        });
    }

    /** Totals of successful payments, split by direction and reconciliation state. */
    public static function summary(?string $fromUtc, ?string $toUtc): array
    {
        $w = "status = 'successful'";
        $a = [];
        if ($fromUtc) {
            $w .= ' AND created_at >= ?';
            $a[] = $fromUtc;
        }
        if ($toUtc) {
            $w .= ' AND created_at < ?';
            $a[] = $toUtc;
        }
        $s = Database::connection()->prepare(
            "SELECT direction, reconciled_at IS NOT NULL AS rec, COUNT(*) AS n, COALESCE(SUM(amount_ugx), 0) AS total FROM payments WHERE $w GROUP BY direction, rec"
        );
        $s->execute($a);
        $out = ['out' => ['reconciled' => [0, 0], 'unreconciled' => [0, 0]], 'in' => ['reconciled' => [0, 0], 'unreconciled' => [0, 0]]];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['direction']][$r['rec'] ? 'reconciled' : 'unreconciled'] = [(int) $r['n'], (int) $r['total']];
        }
        return $out;
    }
}
