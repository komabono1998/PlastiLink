<?php
declare(strict_types=1);

namespace App\Services\Reports;

final class PaymentsReport extends Report
{
    public static function key(): string { return 'payments'; }
    public static function title(): string { return 'Payments'; }
    public static function description(): string { return 'Payments by method and status, success rate, failure reasons, speed and reconciliation.'; }
    public static function filters(): array { return ['range']; }

    public function run(array $f): array
    {
        $r = [$f['from_utc'], $f['to_utc']];
        $by = $this->all(
            "SELECT direction, method, status, COUNT(*) AS n, COALESCE(SUM(amount_ugx), 0) AS amount FROM payments WHERE created_at >= ? AND created_at < ?
             GROUP BY direction, method, status ORDER BY direction DESC, method, status", $r);
        foreach ($by as &$x) {
            $x['dir'] = $x['direction'] === 'out' ? 'Payout' : 'Received';
            $x['method'] = ucfirst(str_replace('_', ' ', $x['method']));
            $x['status'] = ucfirst($x['status']);
        }
        unset($x);
        $o = $this->all("SELECT status, COUNT(*) AS n FROM payments WHERE direction = 'out' AND created_at >= ? AND created_at < ? GROUP BY status", $r);
        $cnt = array_column($o, 'n', 'status');
        $ok = (int) ($cnt['successful'] ?? 0);
        $bad = (int) ($cnt['failed'] ?? 0);
        $success = $ok + $bad > 0 ? $ok / ($ok + $bad) * 100 : null;
        $hours = $this->one("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, processed_at)) FROM payments WHERE direction = 'out' AND status = 'successful' AND created_at >= ? AND created_at < ?", $r);
        $reasons = $this->all(
            "SELECT COALESCE(NULLIF(failure_reason, ''), '(no reason recorded)') AS reason, COUNT(*) AS n, COALESCE(SUM(amount_ugx), 0) AS amount
             FROM payments WHERE status = 'failed' AND created_at >= ? AND created_at < ? GROUP BY reason ORDER BY n DESC LIMIT 25", $r);
        $rec = $this->all(
            "SELECT direction, reconciled_at IS NOT NULL AS rec, COUNT(*) AS n, COALESCE(SUM(amount_ugx), 0) AS amount FROM payments
             WHERE status = 'successful' AND created_at >= ? AND created_at < ? GROUP BY direction, rec", $r);
        $recRows = [];
        foreach ($rec as $x) {
            $recRows[] = ['dir' => $x['direction'] === 'out' ? 'Payout' : 'Received', 'state' => $x['rec'] ? 'Reconciled' : 'Not yet reconciled', 'n' => (int) $x['n'], 'amount' => (int) $x['amount']];
        }
        $status = $this->all("SELECT status, COALESCE(SUM(amount_ugx), 0) AS amount FROM payments WHERE created_at >= ? AND created_at < ? GROUP BY status", $r);
        $t = $this->sum($by, ['n', 'amount']);
        $unrec = array_sum(array_map(static fn($x) => $x['state'] === 'Not yet reconciled' ? $x['n'] : 0, $recRows));

        return [
            'title'   => 'Payments report',
            'summary' => [
                self::stat('Payments created', (int) $t['n'], 'int'), self::stat('Total amount', (int) $t['amount'], 'ugx'), self::stat('Payout success rate', $success, 'pct'),
                self::stat('Average time to pay (created to paid)', $hours !== null && $hours !== false ? (float) $hours / 60 : null, 'hours'), self::stat('Successful payments not yet reconciled', $unrec, 'int'),
            ],
            'tables'  => [
                ['title' => 'By method and status', 'columns' => [self::col('dir', 'Type'), self::col('method', 'Method'), self::col('status', 'Status'), self::col('n', 'Payments', 'int'), self::col('amount', 'Amount', 'ugx')],
                 'rows' => $by, 'totals' => ['n' => (int) $t['n'], 'amount' => (int) $t['amount']]],
                ['title' => 'Failure reasons', 'columns' => [self::col('reason', 'Reason'), self::col('n', 'Payments', 'int'), self::col('amount', 'Amount', 'ugx')], 'rows' => $reasons],
                ['title' => 'Reconciliation of successful payments', 'columns' => [self::col('dir', 'Type'), self::col('state', 'State'), self::col('n', 'Payments', 'int'), self::col('amount', 'Amount', 'ugx')], 'rows' => $recRows],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Amount by status (UGX)', 'labels' => array_map(static fn($r) => ucfirst($r['status']), $status),
                          'datasets' => [['label' => 'UGX', 'data' => array_map(static fn($r) => (int) $r['amount'], $status)]]],
            'notes'   => ['Success rate = successful / (successful + failed) payouts created in the period. Transaction IDs and phone numbers are not included.'],
        ];
    }
}
