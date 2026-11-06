<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class PlasticCategory
{
    /** @return array<int,string> id => name of active categories */
    public static function options(): array
    {
        return Database::connection()->query('SELECT id, name FROM plastic_categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function find(int $id): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM plastic_categories WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }
}
