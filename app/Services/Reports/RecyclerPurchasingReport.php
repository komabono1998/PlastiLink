<?php
declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\Dates;

final class RecyclerPurchasingReport extends Report
{
    public static function key(): string { return 'recyclers'; }
    public static function title(): string { return 'Recycler purchasing'; }
    public static function description(): string { return 'Per recycler: orders, agreed versus received kilograms, prices, invoices, payments and what is owed.'; }
    public static function filters(): array { return ['range', 'hub']; }

    public function run(array $f): array
    {
        $range = [$f['from_utc'], $f['to_utc']];
        $hubO = $this->hubIn($f, 'o.hub_id');
        $recyclers = $this->all('SELECT r.id, r.business_name FROM recyclers r ORDER BY r.business_name');

        $ord = $this->keyed($this->all(
            "SELECT o.recycler_id AS id, COUNT(*) AS total, SUM(o.status = 'negotiating') AS neg, SUM(o.status IN ('confirmed','in_delivery')) AS active, SUM(o.status = 'completed') AS done,
                    SUM(o.status IN ('rejected','withdrawn','cancelled','expired')) AS lost, AVG(o.round_count) AS rounds
             FROM purchase_orders o WHERE o.created_at >= ? AND o.created_at < ? $hubO GROUP BY o.recycler_id", $range));
        $items = $this->keyed($this->all(
            "SELECT o.recycler_id AS id, SUM(it.agreed_qty_kg) AS agreed, SUM(it.received_kg) AS recd, SUM(it.line_total_ugx) AS total
             FROM purchase_orders o JOIN purchase_order_items it ON it.order_id = o.id WHERE o.created_at >= ? AND o.created_at < ? $hubO GROUP BY o.recycler_id", $range));
        $inv = $this->keyed($this->all(
            "SELECT i.recycler_id AS id, COALESCE(SUM(i.amount_ugx), 0) AS invoiced FROM invoices i WHERE i.status <> 'void' AND i.issued_at >= ? AND i.issued_at < ?" . $this->hubIn($f, 'i.hub_id') . ' GROUP BY i.recycler_id', $range));
        $rec = $this->keyed($this->all(
            "SELECT y.recycler_id AS id, COALESCE(SUM(y.amount_ugx), 0) AS received FROM payments y JOIN invoices i ON i.id = y.invoice_id
             WHERE y.direction = 'in' AND y.status = 'successful' AND y.confirmed_at >= ? AND y.confirmed_at < ?" . $this->hubIn($f, 'i.hub_id') . ' GROUP BY y.recycler_id', $range));
        $owing = $this->keyed($this->all("SELECT account_id AS id, SUM(amount_ugx) AS bal FROM financial_ledger WHERE account_type = 'recycler' GROUP BY account_id"));
        $over = $this->keyed($this->all(
            "SELECT i.recycler_id AS id, SUM(i.amount_ugx - COALESCE((SELECT SUM(p.amount_ugx) FROM payments p WHERE p.invoice_id = i.id AND p.direction = 'in' AND p.status = 'successful'), 0)) AS open_ugx
             FROM invoices i WHERE i.status IN ('issued','partially_paid') AND i.due_date < ? GROUP BY i.recycler_id", [Dates::todayYmd()]));

        $rows = [];
        foreach ($recyclers as $r) {
            $id = (int) $r['id'];
            $o = $ord[$id] ?? null;
            $it = $items[$id] ?? null;
            if (!$o && !isset($inv[$id]) && !isset($rec[$id])) {
                continue;
            }
            $agreed = (float) ($it['agreed'] ?? 0);
            $rows[] = [
                'recycler' => $r['business_name'], 'orders' => (int) ($o['total'] ?? 0), 'open' => (int) ($o['neg'] ?? 0) + (int) ($o['active'] ?? 0), 'done' => (int) ($o['done'] ?? 0),
                'lost' => (int) ($o['lost'] ?? 0), 'rounds' => isset($o['rounds']) ? (float) $o['rounds'] : null, 'agreed' => $agreed, 'recd' => (float) ($it['recd'] ?? 0),
                'fulfil' => $agreed > 0 ? (float) $it['recd'] / $agreed * 100 : null, 'price' => $agreed > 0 ? (float) $it['total'] / $agreed : null,
                'invoiced' => (int) ($inv[$id]['invoiced'] ?? 0), 'received' => (int) ($rec[$id]['received'] ?? 0),
                'owing' => (int) ($owing[$id]['bal'] ?? 0), 'overdue' => (int) ($over[$id]['open_ugx'] ?? 0),
            ];
        }
        $t = $this->sum($rows, ['orders', 'open', 'done', 'lost', 'agreed', 'recd', 'invoiced', 'received', 'owing', 'overdue']);

        $off = self::offset();
        $trend = $this->all(
            "SELECT DATE_FORMAT(DATE_ADD(o.agreed_at, INTERVAL $off SECOND), '%Y-%m') AS m, SUM(it.agreed_qty_kg) AS kg, SUM(it.line_total_ugx) / SUM(it.agreed_qty_kg) AS price
             FROM purchase_orders o JOIN purchase_order_items it ON it.order_id = o.id WHERE o.agreed_at >= ? AND o.agreed_at < ? $hubO GROUP BY m ORDER BY m", $range);
        foreach ($trend as &$x) {
            $x['label'] = date('M Y', strtotime($x['m'] . '-01'));
        }
        unset($x);

        return [
            'title'   => 'Recycler purchasing report',
            'summary' => [
                self::stat('Orders started in the period', (int) $t['orders'], 'int'), self::stat('Agreed quantity', $t['agreed'], 'kg'), self::stat('Confirmed received', $t['recd'], 'kg'),
                self::stat('Fulfilment (received / agreed)', $t['agreed'] > 0 ? $t['recd'] / $t['agreed'] * 100 : null, 'pct'),
                self::stat('Invoiced in the period', (int) $t['invoiced'], 'ugx'), self::stat('Received in the period', (int) $t['received'], 'ugx'),
                self::stat('Owed by recyclers now', (int) $t['owing'], 'ugx'), self::stat('Of which overdue', (int) $t['overdue'], 'ugx'),
            ],
            'tables'  => [
                ['title' => 'Recyclers', 'columns' => [self::col('recycler', 'Recycler'), self::col('orders', 'Orders', 'int'), self::col('open', 'Open', 'int'), self::col('done', 'Completed', 'int'),
                    self::col('lost', 'Not concluded', 'int'), self::col('rounds', 'Avg rounds', 'num'), self::col('agreed', 'Agreed', 'kg'), self::col('recd', 'Received', 'kg'),
                    self::col('fulfil', 'Fulfilment', 'pct'), self::col('price', 'Avg agreed price/kg', 'ugx'), self::col('invoiced', 'Invoiced', 'ugx'), self::col('received', 'Paid', 'ugx'),
                    self::col('owing', 'Owed now', 'ugx'), self::col('overdue', 'Overdue now', 'ugx')],
                 'rows' => $rows, 'totals' => ['orders' => (int) $t['orders'], 'open' => (int) $t['open'], 'done' => (int) $t['done'], 'lost' => (int) $t['lost'], 'agreed' => $t['agreed'],
                     'recd' => $t['recd'], 'invoiced' => (int) $t['invoiced'], 'received' => (int) $t['received'], 'owing' => (int) $t['owing'], 'overdue' => (int) $t['overdue']]],
                ['title' => 'Agreed price per kg by month', 'columns' => [self::col('label', 'Month'), self::col('kg', 'Agreed quantity', 'kg'), self::col('price', 'Weighted avg price/kg', 'ugx')], 'rows' => $trend],
            ],
            'chart'   => ['type' => 'line', 'title' => 'Average agreed price per kg (UGX)', 'labels' => array_column($trend, 'label'),
                          'datasets' => [['label' => 'UGX per kg', 'data' => array_map(static fn($r) => round((float) $r['price']), $trend)]]],
            'notes'   => ['"Avg rounds" is the average number of offers per negotiation. Orders are counted by the date the offer was made, invoices by issue date, payments by confirmation date. "Owed now" and "Overdue now" are as of today.'],
        ];
    }

    private function keyed(array $rows): array
    {
        $o = [];
        foreach ($rows as $r) {
            $o[(int) $r['id']] = $r;
        }
        return $o;
    }
}
