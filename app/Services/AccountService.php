<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\PasswordPolicy;
use App\Models\AuditLog;
use App\Models\User;

final class AccountService
{
    /** @return array<string,string> field errors; empty on success */
    public static function changePassword(array $user, string $current, string $new, string $confirm): array
    {
        $row = \App\Core\Database::connection()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $row->execute([(int) $user['id']]);
        $hash = (string) $row->fetchColumn();

        $errors = [];
        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'Your current password is not correct.';
        }
        $pe = PasswordPolicy::validate($new, $user['role_slug']);
        if ($pe) {
            $errors['password'] = $pe[0];
        } elseif (password_verify($new, $hash)) {
            $errors['password'] = 'Choose a password different from the current one.';
        }
        if ($new !== $confirm) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }
        if ($errors) {
            return $errors;
        }

        User::updatePassword((int) $user['id'], $new);   // also clears must_change_password
        AuditLog::write('auth.password_changed', (int) $user['id'], 'user', (int) $user['id']);
        return [];
    }
}
