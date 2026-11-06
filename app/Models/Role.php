<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Role
{
    /** @return string[] permission slugs granted to a role */
    public static function permissionSlugs(int $roleId): array
    {
        $s = Database::connection()->prepare(
            'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?'
        );
        $s->execute([$roleId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }
}
