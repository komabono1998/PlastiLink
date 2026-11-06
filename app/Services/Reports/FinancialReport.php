<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\FinanceService;

final class FinancialReport extends Report
{
    public static function key(): string { return 'financial'; }
    public static function title(): string { return 'Financial'; }
    public static function description(): string { return 'Earned versus paid to pickers, invoiced versus received from recyclers, cash in and out, and receivables aging.'; }
    public static function filters(): array { return ['range']; }

    public function run(array $f): array
    {
        $r = [$f['from_utc'], $f['to_utc']];
        $off = self::offset();
        $m = $this->all(
            "SELECT DATE_FORMAT(DATE_ADD(created_at, INTERVAL $off SECOND), '%Y-%m') AS m,
                    COALESCE(SUM(CASE WHEN entry_type IN ('collection_earning','collection_reversal') THEN amount_ugx END), 0) AS earned,
                    -COALESCE(SUM(CASE WHEN entry_type IN ('payout','payout_reversal') THEN amount_ugx END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN entry_type IN ('invoice_issued','invoice_void') THEN amount_ugx END), 0) AS invoiced,
                    -COALESCE(SUM(CASE WHEN entry_type IN ('invoice_payment','invoice_payment_reversal') THEN amount_ugx END), 0) AS received
             FROM financial_ledger WHERE created_at >= ? AND created_at < ? GROUP BY m ORDER BY m", $r);
        foreach ($m as &$x) {
            $x['label'] = date('M Y', strtotime($x['m'] . '-01'));
            $x['net'] = (int) $x['received'] - (int) $x['paid'];
        }
        unset($x);
        $t = $this->sum($m, ['earned', 'paid', 'invoiced', 'received']);
        $cashOut = (int) $this->one("SELECT COALESCE(SUM(amount_ugx), 0) FROM payments WHERE direction = 'out' AND status = 'successful' AND processed_at >= ? AND processed_at < ?", $r);
        $cashIn = (int) $this->one("SELECT COALESCE(SUM(amount_ugx), 0) FROM payments WHERE direction = 'in' AND status = 'successful' AND confirmed_at >= ? AND confirmed_at < ?", $r);
        $s = FinanceService::summary();

        $owed = $this->all(
            "SELECT p.picker_code, u.full_name AS name, SUM(l.amount_ugx) AS bal FROM financial_ledger l JOIN pickers p ON p.id = l.account_id AND l.account_type = 'picker'
             JOIN users u ON u.id = p.user_id GROUP BY p.id, p.picker_code, u.full_name HAVING bal <> 0 ORDER BY bal DESC LIMIT 20");
        $aging = [['bucket' => 'Not yet due', 'amount' => $s['aging']['current']], ['bucket' => '1 to 30 days overdue', 'amount' => $s['aging']['d1_30']],
                  ['bucket' => '31 to 60 days overdue', 'amount' => $s['aging']['d31_60']], ['bucket' => 'Over 60 days overdue', 'amount' => $s['aging']['d61']]];

        return [
            'title'   => 'Financial report',
            'summary' => [
                self::stat('Earned by pickers (period)', (int) $t['earned'], 'ugx'), self::stat('Paid out to pickers (period, ledger)', (int) $t['paid'], 'ugx'),
                self::stat('Invoiced to recyclers (period)', (int) $t['invoiced'], 'ugx'), self::stat('Received from recyclers (period, ledger)', (int) $t['received'], 'ugx'),
                self::stat('Cash paid out (successful payments)', $cashOut, 'ugx'), self::stat('Cash received (confirmed payments)', $cashIn, 'ugx'),
                self::stat('Net cash movement', $cashIn - $cashOut, 'ugx'), self::stat('Invoiced minus picker earnings (indicative)', (int) $t['invoiced'] - (int) $t['earned'], 'ugx'),
                self::stat('Owed to pickers now', $s['owed'], 'ugx'), self::stat('Owed by recyclers now', $s['outstanding'], 'ugx'), self::stat('Overdue receivables now', $s['overdue'], 'ugx'),
            ],
            'tables'  => [
                ['title' => 'By month', 'columns' => [self::col('label', 'Month'), self::col('earned', 'Earned by pickers', 'ugx'), self::col('paid', 'Paid to pickers', 'ugx'),
                    self::col('invoiced', 'Invoiced', 'ugx'), self::col('received', 'Received', 'ugx'), self::col('net', 'Net cash (received - paid)', 'ugx')],
                 'rows' => $m, 'totals' => ['earned' => (int) $t['earned'], 'paid' => (int) $t['paid'], 'invoiced' => (int) $t['invoiced'], 'received' => (int) $t['received'], 'net' => (int) $t['received'] - (int) $t['paid']]],
                ['title' => 'Receivables aging (now)', 'columns' => [self::col('bucket', 'Bucket'), self::col('amount', 'Amount', 'ugx')], 'rows' => $aging, 'totals' => ['amount' => array_sum(array_column($aging, 'amount'))]],
                ['title' => 'Largest balances owed to pickers (top 20, now)', 'columns' => [self::col('picker_code', 'Picker ID'), self::col('name', 'Name'), self::col('bal', 'Balance', 'ugx')], 'rows' => $owed],
            ],
            'chart'   => ['type' => 'bar', 'title' => 'Earned, paid, invoiced and received (UGX)', 'labels' => array_column($m, 'label'),
                          'datasets' => [['label' => 'Earned by pickers', 'data' => array_map('intval', array_column($m, 'earned'))], ['label' => 'Paid to pickers', 'data' => array_map('intval', array_column($m, 'paid'))],
                                         ['label' => 'Invoiced', 'data' => array_map('intval', array_column($m, 'invoiced'))], ['label' => 'Received', 'data' => array_map('intval', array_column($m, 'received'))]]],
            'notes'   => ['Figures come from the financial ledger and the payments table. "Invoiced minus picker earnings" is indicative only: it does not match each invoice to the specific collections it came from.'],
        ];
    }
}
