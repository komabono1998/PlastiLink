<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/** Reads of the money ledger. Writes go through App\Services\LedgerService only. */
final class LedgerEntry
{
    public static function insert(string $accountType, int $accountId, string $type, int $amount, string $sourceType, int $sourceId, ?string $reference, ?string $note, ?int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO financial_ledger (account_type, account_id, entry_type, amount_ugx, source_type, source_id, reference, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        )->execute([$accountType, $accountId, $type, $amount, $sourceType, $sourceId, $reference, $note, $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** Lock the account owner's row so concurrent requests/payments on the same account run one at a time. */
    public static function lockAccount(string $accountType, int $accountId): void
    {
        $table = $accountType === 'picker' ? 'pickers' : 'recyclers';
        Database::connection()->prepare("SELECT id FROM $table WHERE id = ? FOR UPDATE")->execute([$accountId]);
    }

    public static function balance(string $accountType, int $accountId): int
    {
        $s = Database::connection()->prepare('SELECT COALESCE(SUM(amount_ugx), 0) FROM financial_ledger WHERE account_type = ? AND account_id = ?');
        $s->execute([$accountType, $accountId]);
        return (int) $s->fetchColumn();
    }

    /** @return array<string,int> entry_type => net amount, optionally within [from, to) UTC */
    public static function totalsByType(string $accountType, int $accountId, ?string $from = null, ?string $to = null): array
    {
        $w = 'account_type = ? AND account_id = ?';
        $a = [$accountType, $accountId];
        if ($from) {
            $w .= ' AND created_at >= ?';
            $a[] = $from;
        }
        if ($to) {
            $w .= ' AND created_at < ?';
            $a[] = $to;
        }
        $s = Database::connection()->prepare("SELECT entry_type, COALESCE(SUM(amount_ugx), 0) FROM financial_ledger WHERE $w GROUP BY entry_type");
        $s->execute($a);
        return array_map('intval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public static function entries(string $accountType, int $accountId, int $offset, int $limit, ?string $from = null, ?string $to = null): array
    {
        $w = 'account_type = ? AND account_id = ?';
        $a = [$accountType, $accountId];
        if ($from) {
            $w .= ' AND created_at >= ?';
            $a[] = $from;
        }
        if ($to) {
            $w .= ' AND created_at < ?';
            $a[] = $to;
        }
        $s = Database::connection()->prepare("SELECT * FROM financial_ledger WHERE $w ORDER BY id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function countEntries(string $accountType, int $accountId): int
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM financial_ledger WHERE account_type = ? AND account_id = ?');
        $s->execute([$accountType, $accountId]);
        return (int) $s->fetchColumn();
    }
}
