<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\Phone;
use App\Helpers\Secret;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\User;

final class UserService
{
    public const STAFF_ROLES = ['super_admin', 'project_manager', 'hub_manager', 'hub_operator'];

    /** @return array{errors:array<string,string>, id?:int, temp?:string} */
    public static function createStaff(array $in, array $actor): array
    {
        $errors = self::validateProfile($in, null, true);
        if ($errors) {
            return ['errors' => $errors];
        }
        $email = self::emailOf($in);
        $phone = self::phoneOf($in);
        $temp = Secret::tempPassword(10);

        $id = Database::transaction(function () use ($in, $email, $phone, $temp, $actor): int {
            $id = User::create([
                'role' => $in['role'], 'full_name' => trim($in['full_name']), 'email' => $email,
                'phone_e164' => $phone, 'password' => $temp, 'status' => 'active',
            ]);
            User::setMustChange($id, true);
            User::decide($id, 'active', (int) $actor['id']);
            AuditLog::write('user.created', (int) $actor['id'], 'user', $id, ['role' => $in['role']]);
            return $id;
        });
        return ['errors' => [], 'id' => $id, 'temp' => $temp];
    }

    /** @return array{errors:array<string,string>} */
    public static function update(int $id, array $in, array $actor): array
    {
        $target = User::findById($id);
        if (!$target) {
            return ['errors' => ['_' => 'User not found.']];
        }
        $isStaff = in_array($target['role_slug'], self::STAFF_ROLES, true);
        $in['role'] = $isStaff ? ($in['role'] ?? $target['role_slug']) : $target['role_slug']; // pickers/recyclers keep their role
        $errors = self::validateProfile($in, $id, $isStaff);

        if ($isStaff && $in['role'] !== $target['role_slug']) {
            if ((int) $actor['id'] === $id) {
                $errors['role'] = 'You cannot change your own role.';
            } elseif ($target['role_slug'] === 'super_admin' && $target['status'] === 'active' && User::activeSuperAdmins() <= 1) {
                $errors['role'] = 'This is the last active Super Administrator. Create another one first.';
            }
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        User::updateProfile($id, trim($in['full_name']), self::emailOf($in), self::phoneOf($in), $in['role']);
        AuditLog::write('user.updated', (int) $actor['id'], 'user', $id, [
            'role_changed' => $in['role'] !== $target['role_slug'] ? [$target['role_slug'], $in['role']] : false,
        ]);
        return ['errors' => []];
    }

    /** @return string|null error message */
    public static function setStatus(int $id, string $status, array $actor): ?string
    {
        $target = User::findById($id);
        if (!$target) {
            return 'User not found.';
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            return 'Invalid status.';
        }
        if (in_array($target['role_slug'], ['waste_picker', 'recycler'], true)) {
            return 'Manage picker and recycler accounts from their own pages so their approval record stays in step.';
        }
        if ((int) $actor['id'] === $id) {
            return 'You cannot change the status of your own account.';
        }
        if ($status === 'suspended' && $target['role_slug'] === 'super_admin' && $target['status'] === 'active' && User::activeSuperAdmins() <= 1) {
            return 'This is the last active Super Administrator and cannot be suspended.';
        }
        if ($target['status'] === $status) {
            return null;
        }
        User::decide($id, $status, (int) $actor['id']);
        AuditLog::write('user.status_changed', (int) $actor['id'], 'user', $id, ['from' => $target['status'], 'to' => $status]);
        return null;
    }

    /** @return array{error:?string, temp?:string} */
    public static function resetPassword(int $id, array $actor): array
    {
        $target = User::findById($id);
        if (!$target) {
            return ['error' => 'User not found.'];
        }
        if ((int) $actor['id'] === $id) {
            return ['error' => 'Use "Change password" for your own account.'];
        }
        return self::issueTempPassword($target, $actor);
    }

    /** Shared by user and picker screens. */
    public static function issueTempPassword(array $target, array $actor): array
    {
        $temp = Secret::tempPassword(10);
        User::updatePassword((int) $target['id'], $temp);
        User::setMustChange((int) $target['id'], true);
        AuditLog::write('user.password_reset_by_staff', (int) $actor['id'], 'user', (int) $target['id']);
        return ['error' => null, 'temp' => $temp];
    }

    // ------------------------------------------------------------------ helpers
    private static function emailOf(array $in): ?string
    {
        $e = mb_strtolower(trim((string) ($in['email'] ?? '')));
        return $e === '' ? null : $e;
    }

    private static function phoneOf(array $in): ?string
    {
        $p = trim((string) ($in['phone'] ?? ''));
        return $p === '' ? null : Phone::normalize($p);
    }

    /** @return array<string,string> */
    private static function validateProfile(array $in, ?int $exceptId, bool $staff): array
    {
        $rules = [
            'full_name' => ['Full name', 'required|max:150'],
            'email'     => ['Email', 'email'],
            'phone'     => ['Phone number', 'phone'],
        ];
        if ($staff) {
            $rules['role'] = ['Role', 'required|in:' . implode(',', self::STAFF_ROLES)];
        }
        $errors = Validator::validate($in, $rules);

        $email = self::emailOf($in);
        $phone = self::phoneOf($in);
        if ($email === null && $phone === null && !isset($errors['email']) && !isset($errors['phone'])) {
            $errors['email'] = 'Enter an email address or a phone number (or both).';
        }
        if ($email && !isset($errors['email']) && User::emailTaken($email, $exceptId)) {
            $errors['email'] = 'Another account already uses this email address.';
        }
        if ($phone && !isset($errors['phone']) && User::phoneTaken($phone, $exceptId)) {
            $errors['phone'] = 'Another account already uses this phone number.';
        }
        return $errors;
    }
}
