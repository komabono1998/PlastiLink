<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\User;

final class HubService
{
    private const ROLE_FOR = ['manager' => 'hub_manager', 'operator' => 'hub_operator'];

    /** @return array{errors:array<string,string>, id?:int} */
    public static function save(?int $id, array $in, array $actor): array
    {
        $errors = Validator::validate($in, [
            'name'             => ['Hub name', 'required|max:120'],
            'neighbourhood_id' => ['Neighbourhood', 'required|int'],
            'address_note'     => ['Address note', 'max:255'],
            'status'         => ['Status', 'required|in:active,inactive'],
        ]);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name !== '' && !isset($errors['name']) && Hub::nameTaken($name, $id)) {
            $errors['name'] = 'A hub with this name already exists.';
        }
        if (!isset($errors['neighbourhood_id']) && !array_key_exists((int) $in['neighbourhood_id'], Neighbourhood::options())) {
            $errors['neighbourhood_id'] = 'Choose a neighbourhood from the list.';
        }
        [$lat, $lng, $geoError] = \App\Helpers\Geo::parse((string) ($in['latitude'] ?? ''), (string) ($in['longitude'] ?? ''));
        if ($geoError !== null) {
            $errors['latitude'] = $geoError;
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $data = [
            'name' => $name, 'neighbourhood_id' => (int) $in['neighbourhood_id'],
            'address_note' => trim((string) ($in['address_note'] ?? '')) ?: null,
            'latitude' => $lat, 'longitude' => $lng,
            'status' => $in['status'],
        ];
        if ($id === null) {
            $id = Hub::create($data);
            AuditLog::write('hub.created', (int) $actor['id'], 'hub', $id);
        } else {
            if (!Hub::find($id)) {
                return ['errors' => ['_' => 'Hub not found.']];
            }
            Hub::update($id, $data);
            AuditLog::write('hub.updated', (int) $actor['id'], 'hub', $id);
        }
        return ['errors' => [], 'id' => $id];
    }

    /** @return string|null error message */
    public static function assign(int $hubId, int $userId, string $hubRole, array $actor): ?string
    {
        if (!Hub::find($hubId)) {
            return 'Hub not found.';
        }
        $user = User::findById($userId);
        if (!isset(self::ROLE_FOR[$hubRole]) || !$user || $user['role_slug'] !== self::ROLE_FOR[$hubRole] || $user['status'] !== 'active') {
            return 'Choose an active user whose role matches the assignment.';
        }
        if (!Hub::assign($hubId, $userId, $hubRole)) {
            return 'This person is already assigned to this hub.';
        }
        AuditLog::write('hub.user_assigned', (int) $actor['id'], 'hub', $hubId, ['user_id' => $userId, 'hub_role' => $hubRole]);
        return null;
    }

    public static function unassign(int $hubId, int $userId, array $actor): ?string
    {
        if (!Hub::unassign($hubId, $userId)) {
            return 'That assignment does not exist.';
        }
        AuditLog::write('hub.user_removed', (int) $actor['id'], 'hub', $hubId, ['user_id' => $userId]);
        return null;
    }
}
