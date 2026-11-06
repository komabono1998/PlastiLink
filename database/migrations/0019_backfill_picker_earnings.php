<?php
declare(strict_types=1);

/**
 * Collections approved before Phase 7 earn money for their pickers. Replay them into the ledger.
 * Idempotent: the unique (source_type, source_id, entry_type) key means nothing is ever posted twice.
 */
return [
    'up' => function (PDO $pdo): void {
        $rows = $pdo->query(
            "SELECT id, picker_id, status, total_value_ugx, reference, decided_by, decided_at, reversed_by, reversed_at
             FROM collection_transactions WHERE status IN ('approved','reversed') ORDER BY COALESCE(decided_at, recorded_at), id"
        )->fetchAll();
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO financial_ledger (account_type, account_id, entry_type, amount_ugx, source_type, source_id, reference, note, created_by, created_at)
             VALUES ('picker', ?, ?, ?, 'collection', ?, ?, ?, ?, ?)"
        );
        foreach ($rows as $r) {
            $value = (int) $r['total_value_ugx'];
            if ($value <= 0) {
                continue;
            }
            $ins->execute([$r['picker_id'], 'collection_earning', $value, $r['id'], $r['reference'], 'Back-filled from approved collection', $r['decided_by'], $r['decided_at'] ?? gmdate('Y-m-d H:i:s')]);
            if ($r['status'] === 'reversed') {
                $ins->execute([$r['picker_id'], 'collection_reversal', -$value, $r['id'], $r['reference'], 'Back-filled from reversed collection', $r['reversed_by'], $r['reversed_at'] ?? gmdate('Y-m-d H:i:s')]);
            }
        }
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("DELETE FROM financial_ledger WHERE source_type = 'collection'");
    },
];
