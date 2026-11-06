<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Neighbourhood
{
    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT n.*, (SELECT COUNT(*) FROM pickers p WHERE p.neighbourhood_id = n.id) AS pickers,
                    (SELECT COUNT(*) FROM collection_hubs h WHERE h.neighbourhood_id = n.id) AS hubs
             FROM neighbourhoods n ORDER BY n.name'
        )->fetchAll();
    }

    /** @return array<int,string> id => name of active neighbourhoods */
    public static function options(): array
    {
        return Database::connection()->query('SELECT id, name FROM neighbourhoods WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM neighbourhoods WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM neighbourhoods WHERE name = ? AND id <> ?');
        $s->execute([$name, $exceptId ?? 0]);
        return (int) $s->fetchColumn() > 0;
    }

    public static function create(string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO neighbourhoods (name) VALUES (?)')->execute([$name]);
        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE neighbourhoods SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('UPDATE neighbourhoods SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }
}
