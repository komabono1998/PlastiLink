<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Hub;

/** Which hubs may the signed-in user see data for? Hub staff are limited to their own hubs. */
final class HubScope
{
    /** @return int[]|null null = unrestricted (administration roles) */
    public static function forUser(array $user): ?array
    {
        return in_array($user['role_slug'], ['hub_manager', 'hub_operator'], true)
            ? Hub::idsForUser((int) $user['id'])
            : null;
    }

    public static function allows(?array $scope, ?int $hubId): bool
    {
        return $scope === null || ($hubId !== null && in_array($hubId, $scope, true));
    }
}
