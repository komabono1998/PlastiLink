<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Picker
{
    private const SELECT = 'SELECT p.*, u.full_name, u.phone_e164, u.status AS account_status, u.created_at AS account_created,
                                   n.name AS neighbourhood, h.name AS hub_name, rb.full_name AS registered_by_name
                            FROM pickers p
                            JOIN users u ON u.id = p.user_id
                            JOIN neighbourhoods n ON n.id = p.neighbourhood_id
                            LEFT JOIN collection_hubs h ON h.id = p.preferred_hub_id
                            LEFT JOIN users rb ON rb.id = p.registered_by';

    /** @param int[]|null $scope */
    private static function where(array $f, ?array $scope): array
    {
        $w = [];
        $a = [];
        if ($scope !== null) {
            $w[] = $scope ? 'p.preferred_hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '1 = 0';
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $w[] = '(u.full_name LIKE ? OR p.picker_code LIKE ? OR u.phone_e164 LIKE ?)';
            array_push($a, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            $w[] = 'u.status = ?';
            $a[] = $f['status'];
        }
        if (!empty($f['neighbourhood_id'])) {
            $w[] = 'p.neighbourhood_id = ?';
            $a[] = (int) $f['neighbourhood_id'];
        }
        if (!empty($f['hub_id'])) {
            $w[] = 'p.preferred_hub_id = ?';
            $a[] = (int) $f['hub_id'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function paginate(array $f, ?array $scope, int $offset, int $limit): array
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare(self::SELECT . " $where ORDER BY p.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
        $s->execute($a);
        return $s->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $a] = self::where($f, $scope);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id $where");
        $s->execute($a);
        return (int) $s->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE p.id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function create(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO pickers (user_id, neighbourhood_id, preferred_hub_id, national_id, consent_version, consented_at, registered_by)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
        )->execute([$d['user_id'], $d['neighbourhood_id'], $d['preferred_hub_id'], $d['national_id'], $d['consent_version'], $d['registered_by']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE pickers SET picker_code = ? WHERE id = ?')->execute(['PLP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT), $id]);
        return $id;
    }

    public static function setVerification(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE pickers SET verification_status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public static function setReviewNote(int $id, ?string $note): void
    {
        Database::connection()->prepare('UPDATE pickers SET review_note = ? WHERE id = ?')->execute([$note, $id]);
    }

    /** Same name (case-insensitive) in the same neighbourhood, other than $exceptId. */
    public static function similarExists(string $fullName, int $neighbourhoodId, int $exceptId): bool
    {
        $s = Database::connection()->prepare(
            'SELECT COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id
             WHERE LOWER(u.full_name) = LOWER(?) AND p.neighbourhood_id = ? AND p.id <> ?'
        );
        $s->execute([$fullName, $neighbourhoodId, $exceptId]);
        return (int) $s->fetchColumn() > 0;
    }

    /** @param int[]|null $scope @return array<string,int> account status => count */
    public static function countByStatus(?array $scope = null): array
    {
        $where = $scope === null ? '' : ($scope ? 'WHERE p.preferred_hub_id IN (' . implode(',', array_map('intval', $scope)) . ')' : 'WHERE 1 = 0');
        return Database::connection()->query(
            "SELECT u.status, COUNT(*) FROM pickers p JOIN users u ON u.id = p.user_id $where GROUP BY u.status"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
