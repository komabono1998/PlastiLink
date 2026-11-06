<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Hub
{
    /** @param int[]|null $scope hub ids the viewer may see; null = all */
    public static function list(?array $scope): array
    {
        $where = '';
        if ($scope !== null) {
            $where = $scope ? 'WHERE h.id IN (' . implode(',', array_map('intval', $scope)) . ')' : 'WHERE 1 = 0';
        }
        return Database::connection()->query(
            "SELECT h.*, n.name AS neighbourhood,
                    (SELECT COUNT(*) FROM hub_users hu WHERE hu.hub_id = h.id) AS staff,
                    (SELECT COUNT(*) FROM pickers p WHERE p.preferred_hub_id = h.id) AS pickers
             FROM collection_hubs h JOIN neighbourhoods n ON n.id = h.neighbourhood_id $where ORDER BY h.name"
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare(
            'SELECT h.*, n.name AS neighbourhood FROM collection_hubs h JOIN neighbourhoods n ON n.id = h.neighbourhood_id WHERE h.id = ?'
        );
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return array<int,string> id => name of active hubs, optionally limited to $scope */
    public static function options(?array $scope = null): array
    {
        $rows = Database::connection()->query("SELECT id, name FROM collection_hubs WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $scope === null ? $rows : array_intersect_key($rows, array_flip($scope));
    }

    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM collection_hubs WHERE name = ? AND id <> ?');
        $s->execute([$name, $exceptId ?? 0]);
        return (int) $s->fetchColumn() > 0;
    }

    public static function create(array $d): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO collection_hubs (name, neighbourhood_id, address_note, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$d['name'], $d['neighbourhood_id'], $d['address_note'], $d['latitude'], $d['longitude'], $d['status']]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE collection_hubs SET code = ? WHERE id = ?')->execute(['HUB-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT), $id]);
        return $id;
    }

    public static function update(int $id, array $d): void
    {
        Database::connection()->prepare(
            'UPDATE collection_hubs SET name = ?, neighbourhood_id = ?, address_note = ?, latitude = ?, longitude = ?, status = ? WHERE id = ?'
        )->execute([$d['name'], $d['neighbourhood_id'], $d['address_note'], $d['latitude'], $d['longitude'], $d['status'], $id]);
    }

    public static function staff(int $hubId): array
    {
        $s = Database::connection()->prepare(
            'SELECT hu.id AS assignment_id, hu.hub_role, u.id AS user_id, u.full_name, u.email, u.phone_e164, u.status
             FROM hub_users hu JOIN users u ON u.id = hu.user_id WHERE hu.hub_id = ? ORDER BY hu.hub_role, u.full_name'
        );
        $s->execute([$hubId]);
        return $s->fetchAll();
    }

    public static function assign(int $hubId, int $userId, string $hubRole): bool
    {
        $s = Database::connection()->prepare('INSERT IGNORE INTO hub_users (hub_id, user_id, hub_role) VALUES (?, ?, ?)');
        $s->execute([$hubId, $userId, $hubRole]);
        return $s->rowCount() > 0;
    }

    public static function unassign(int $hubId, int $userId): bool
    {
        $s = Database::connection()->prepare('DELETE FROM hub_users WHERE hub_id = ? AND user_id = ?');
        $s->execute([$hubId, $userId]);
        return $s->rowCount() > 0;
    }

    /** @return int[] */
    public static function idsForUser(int $userId): array
    {
        $s = Database::connection()->prepare('SELECT hub_id FROM hub_users WHERE user_id = ?');
        $s->execute([$userId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function countByStatus(): array
    {
        return Database::connection()->query('SELECT status, COUNT(*) FROM collection_hubs GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
