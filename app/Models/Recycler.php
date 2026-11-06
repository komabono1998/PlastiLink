<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Recycler
{
    private const SELECT = 'SELECT r.*, u.full_name, u.email, u.phone_e164, u.status AS account_status, db.full_name AS decided_by_name
                            FROM recyclers r JOIN users u ON u.id = r.user_id LEFT JOIN users db ON db.id = r.decided_by';

    private static function where(array $f): array
    {
        $w = [];
        $a = [];
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(r.business_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
            array_push($a, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            $w[] = 'r.status = ?';
            $a[] = $f['status'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY r.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f): int
    {
        [$where, $a] = self::where($f);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM recyclers r JOIN users u ON u.id = r.user_id $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE r.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO recyclers (user_id, business_name, registration_number, contact_person, address, latitude, longitude, materials, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $d['user_id'], $d['business_name'], $d['registration_number'], $d['contact_person'], $d['address'],
            $d['latitude'] ?? null, $d['longitude'] ?? null, $d['materials'], $d['status'] ?? 'pending',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function decide(int $id, string $status, int $byUserId, ?string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE recyclers SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_reason = ? WHERE id = ?'
        )->execute([$status, $byUserId, $reason, $id]);
    }

    /** @return array<int,string> id => business name of approved recyclers whose login is active */
    public static function approvedOptions(): array
    {
        return Database::connection()->query(
            "SELECT r.id, r.business_name FROM recyclers r JOIN users u ON u.id = r.user_id
             WHERE r.status = 'approved' AND u.status = 'active' ORDER BY r.business_name"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function countByStatus(): array
    {
        return Database::connection()->query('SELECT status, COUNT(*) FROM recyclers GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
