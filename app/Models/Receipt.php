<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Receipt
{
    public static function create(int $collectionId, int $pickerId, int $issuedBy, array $snapshot): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO receipts (collection_id, picker_id, issued_by, issued_at, snapshot) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)'
        )->execute([$collectionId, $pickerId, $issuedBy, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        $id = (int) $pdo->lastInsertId();
        $number = 'RCT-' . str_replace('-', '', \App\Helpers\Dates::todayYmd()) . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE receipts SET receipt_number = ? WHERE id = ?')->execute([$number, $id]);
        return $id;
    }

    /** Receipt row with its collection's hub and picker owner (for access checks). */
    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(
            'SELECT r.*, c.hub_id, c.status AS collection_status, c.reference, pu.id AS picker_user_id
             FROM receipts r JOIN collection_transactions c ON c.id = r.collection_id
             JOIN pickers p ON p.id = r.picker_id JOIN users pu ON pu.id = p.user_id WHERE r.id = ?'
        );
        $s->execute([$id]);
        $row = $s->fetch();
        if ($row) {
            $row['data'] = json_decode((string) $row['snapshot'], true) ?: [];
        }
        return $row ?: null;
    }

    public static function void(int $collectionId, string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE receipts SET voided_at = UTC_TIMESTAMP(), void_reason = ? WHERE collection_id = ? AND voided_at IS NULL'
        )->execute([$reason, $collectionId]);
    }
}
