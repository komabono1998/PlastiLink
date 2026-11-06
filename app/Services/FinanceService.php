<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Dates;
use App\Models\Payment;
use PDO;

/** Finance summary figures and the "ledger check". Everything is computed from recorded entries. */
final class FinanceService
{
    private static function sum(string $sql, array $args = []): int
    {
        $s = Database::connection()->prepare($sql);
        $s->execute($args);
        return (int) $s->fetchColumn();
    }

    public static function summary(): array
    {
        $today = Dates::todayYmd();
        $earned = self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE entry_type IN ('collection_earning','collection_reversal')");
        $paid = -self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE entry_type IN ('payout','payout_reversal')");
        $owed = self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE account_type = 'picker'");
        $pendingPayouts = self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM payment_requests WHERE status IN ('pending_approval','approved')");
        $negative = self::sum("SELECT COUNT(*) FROM (SELECT account_id FROM financial_ledger WHERE account_type = 'picker' GROUP BY account_id HAVING SUM(amount_ugx) < 0) t");

        $invoiced = self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE entry_type IN ('invoice_issued','invoice_void')");
        $received = -self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE entry_type IN ('invoice_payment','invoice_payment_reversal')");
        $outstanding = self::sum("SELECT COALESCE(SUM(amount_ugx),0) FROM financial_ledger WHERE account_type = 'recycler'");

        // Receivables aging: what is overdue, by how long (computed from invoices and their successful payments)
        $aging = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61' => 0];
        $rows = Database::connection()->query(
            "SELECT i.amount_ugx, i.due_date,
                    i.amount_ugx - COALESCE((SELECT SUM(p.amount_ugx) FROM payments p WHERE p.invoice_id = i.id AND p.direction = 'in' AND p.status = 'successful'), 0) AS open_ugx
             FROM invoices i WHERE i.status IN ('issued','partially_paid')"
        )->fetchAll();
        $overdue = 0;
        foreach ($rows as $r) {
            $open = (int) $r['open_ugx'];
            $late = (int) ((strtotime($today) - strtotime($r['due_date'])) / 86400);
            if ($late <= 0) {
                $aging['current'] += $open;
                continue;
            }
            $overdue += $open;
            $aging[$late <= 30 ? 'd1_30' : ($late <= 60 ? 'd31_60' : 'd61')] += $open;
        }
        return [
            'earned' => $earned, 'paid' => $paid, 'owed' => $owed, 'pending_payouts' => $pendingPayouts, 'negative_accounts' => $negative,
            'invoiced' => $invoiced, 'received' => $received, 'outstanding' => $outstanding, 'overdue' => $overdue, 'aging' => $aging,
            'payments_by_status' => Payment::countByStatus(),
            'pending_approvals' => \App\Models\PaymentRequest::pendingApprovalCount(),
        ];
    }

    /**
     * Compare the ledger with the records it was built from. Returns a list of plain-language problems (empty = all agree).
     * @return string[]
     */
    public static function verify(): array
    {
        $problems = [];
        $pdo = Database::connection();

        // 1. Picker earnings in the ledger = value of approved collections
        $rows = $pdo->query(
            "SELECT p.id,
                    COALESCE((SELECT SUM(amount_ugx) FROM financial_ledger l WHERE l.account_type = 'picker' AND l.account_id = p.id AND l.entry_type IN ('collection_earning','collection_reversal')), 0) AS ledger,
                    COALESCE((SELECT SUM(c.total_value_ugx) FROM collection_transactions c WHERE c.picker_id = p.id AND c.status = 'approved'), 0) AS source
             FROM pickers p HAVING ledger <> source"
        )->fetchAll();
        foreach ($rows as $r) {
            $problems[] = "Picker #{$r['id']}: earnings in the ledger ({$r['ledger']}) differ from approved collections ({$r['source']}).";
        }

        // 2. Picker payouts in the ledger = successful outgoing payments
        $rows = $pdo->query(
            "SELECT p.id,
                    -COALESCE((SELECT SUM(amount_ugx) FROM financial_ledger l WHERE l.account_type = 'picker' AND l.account_id = p.id AND l.entry_type IN ('payout','payout_reversal')), 0) AS ledger,
                    COALESCE((SELECT SUM(y.amount_ugx) FROM payments y WHERE y.picker_id = p.id AND y.direction = 'out' AND y.status = 'successful'), 0) AS source
             FROM pickers p HAVING ledger <> source"
        )->fetchAll();
        foreach ($rows as $r) {
            $problems[] = "Picker #{$r['id']}: payouts in the ledger ({$r['ledger']}) differ from successful payments ({$r['source']}).";
        }

        // 3. Recycler invoices in the ledger = invoices that are not void
        $rows = $pdo->query(
            "SELECT r.id,
                    COALESCE((SELECT SUM(amount_ugx) FROM financial_ledger l WHERE l.account_type = 'recycler' AND l.account_id = r.id AND l.entry_type IN ('invoice_issued','invoice_void')), 0) AS ledger,
                    COALESCE((SELECT SUM(i.amount_ugx) FROM invoices i WHERE i.recycler_id = r.id AND i.status <> 'void'), 0) AS source
             FROM recyclers r HAVING ledger <> source"
        )->fetchAll();
        foreach ($rows as $r) {
            $problems[] = "Recycler #{$r['id']}: invoiced amount in the ledger ({$r['ledger']}) differs from invoices ({$r['source']}).";
        }

        // 4. Recycler receipts in the ledger = successful incoming payments
        $rows = $pdo->query(
            "SELECT r.id,
                    -COALESCE((SELECT SUM(amount_ugx) FROM financial_ledger l WHERE l.account_type = 'recycler' AND l.account_id = r.id AND l.entry_type IN ('invoice_payment','invoice_payment_reversal')), 0) AS ledger,
                    COALESCE((SELECT SUM(y.amount_ugx) FROM payments y WHERE y.recycler_id = r.id AND y.direction = 'in' AND y.status = 'successful'), 0) AS source
             FROM recyclers r HAVING ledger <> source"
        )->fetchAll();
        foreach ($rows as $r) {
            $problems[] = "Recycler #{$r['id']}: receipts in the ledger ({$r['ledger']}) differ from successful payments ({$r['source']}).";
        }

        // 5. Invoice status agrees with the money received
        $rows = $pdo->query(
            "SELECT i.id, i.status, i.amount_ugx,
                    COALESCE((SELECT SUM(y.amount_ugx) FROM payments y WHERE y.invoice_id = i.id AND y.direction = 'in' AND y.status = 'successful'), 0) AS paid
             FROM invoices i WHERE i.status <> 'void'"
        )->fetchAll();
        foreach ($rows as $r) {
            $expected = (int) $r['paid'] >= (int) $r['amount_ugx'] ? 'paid' : ((int) $r['paid'] > 0 ? 'partially_paid' : 'issued');
            if ($expected !== $r['status']) {
                $problems[] = "Invoice #{$r['id']} is '{$r['status']}' but its payments say '$expected'.";
            }
        }
        return $problems;
    }
}
