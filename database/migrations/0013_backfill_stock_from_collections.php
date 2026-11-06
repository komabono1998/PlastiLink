<?php
declare(strict_types=1);

/**
 * Phase 4 collections were approved before stock existed. Replay them into the ledger so stock matches history.
 * Idempotent: the unique (source_type, source_id, movement_type) key means a collection is never posted twice.
 */
return [
    'up' => function (PDO $pdo): void {
        $rows = $pdo->query(
            "SELECT id, hub_id, plastic_category_id, status, net_weight_kg, reference, decided_by, decided_at, reversed_by, reversed_at
             FROM collection_transactions WHERE status IN ('approved','reversed') ORDER BY COALESCE(decided_at, recorded_at), id"
        )->fetchAll();

        $bal = [];   // "hub:cat" => on-hand hundredths
        $toCs = static function (string $d): int {
            $neg = str_starts_with($d, '-');
            [$i, $f] = array_pad(explode('.', ltrim($d, '-'), 2), 2, '0');
            $v = (int) $i * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');
            return $neg ? -$v : $v;
        };
        $dec = static fn(int $cs): string => ($cs < 0 ? '-' : '') . intdiv(abs($cs), 100) . '.' . str_pad((string) (abs($cs) % 100), 2, '0', STR_PAD_LEFT);

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO stock_movements (hub_id, plastic_category_id, movement_type, qty_kg, reserved_delta_kg, balance_after_kg, reserved_after_kg,
                                                  source_type, source_id, reference, reason, created_by, created_at)
             VALUES (?, ?, ?, ?, 0, ?, 0, \'collection\', ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $key = $r['hub_id'] . ':' . $r['plastic_category_id'];
            $net = $toCs((string) $r['net_weight_kg']);
            $bal[$key] = ($bal[$key] ?? 0) + $net;
            $ins->execute([$r['hub_id'], $r['plastic_category_id'], 'collection_in', $dec($net), $dec($bal[$key]), $r['id'], $r['reference'],
                           'Back-filled from approved collection', $r['decided_by'], $r['decided_at'] ?? gmdate('Y-m-d H:i:s')]);
            if ($r['status'] === 'reversed') {
                $bal[$key] -= $net;
                $ins->execute([$r['hub_id'], $r['plastic_category_id'], 'collection_reversal', $dec(-$net), $dec($bal[$key]), $r['id'], $r['reference'],
                               'Back-filled from reversed collection', $r['reversed_by'], $r['reversed_at'] ?? gmdate('Y-m-d H:i:s')]);
            }
        }

        // Balances are recomputed from the WHOLE ledger (not just the replay), so re-running this after other movements
        // exist can never overwrite them.
        $mk =$pdo->prepare('INSERT IGNORE INTO inventory (hub_id, plastic_category_id) VALUES (?, ?)');
        foreach (array_keys($bal) as $key) {
            [$hub, $cat] = explode(':', $key);
            $mk->execute([(int) $hub, (int) $cat]);
        }
        $pdo->exec(
            'UPDATE inventory i SET
               i.on_hand_kg  = (SELECT COALESCE(SUM(m.qty_kg), 0) FROM stock_movements m WHERE m.hub_id = i.hub_id AND m.plastic_category_id = i.plastic_category_id),
               i.reserved_kg = (SELECT COALESCE(SUM(m.reserved_delta_kg), 0) FROM stock_movements m WHERE m.hub_id = i.hub_id AND m.plastic_category_id = i.plastic_category_id)'
        );
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE FROM stock_movements WHERE source_type = 'collection'");
        $pdo->exec('DELETE FROM inventory');
    },
];
