<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Weight;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\Role;

/** Shared validation helpers for the inventory services. */
final class StockInput
{
    public static function can(array $actor, string $permission): bool
    {
        return in_array($permission, Role::permissionSlugs((int) $actor['role_id']), true);
    }

    /** Staff who handle money screens (not pickers, recyclers or hub operators). */
    public static function isFinanceStaff(array $actor): bool
    {
        return in_array($actor['role_slug'], ['super_admin', 'project_manager', 'hub_manager'], true);
    }

    public static function token(array $in): ?string
    {
        $t = (string) ($in['token'] ?? '');
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : null;
    }

    /** Positive quantity in hundredths, or an error message. @return array{0:?int,1:?string} */
    public static function quantity(mixed $raw, string $label = 'Quantity', bool $allowZero = false): array
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return [null, "$label is required."];
        }
        $cs = Weight::parse($s);
        if ($cs === null) {
            return [null, "$label must be a number such as 12.5 (at most 2 decimals)."];
        }
        if ($cs === 0 && !$allowZero) {
            return [null, "$label must be more than zero."];
        }
        return [$cs, null];
    }

    /** Hub id the actor may use (their own hubs; administrators any active hub). */
    public static function hubIn(array $in, string $key, array $actor): ?int
    {
        $id = (int) ($in[$key] ?? 0);
        return array_key_exists($id, Hub::options(HubScope::forUser($actor))) ? $id : null;
    }

    public static function category(array $in): ?int
    {
        $id = (int) ($in['plastic_category_id'] ?? 0);
        return array_key_exists($id, PlasticCategory::options()) ? $id : null;
    }
}
