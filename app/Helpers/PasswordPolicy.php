<?php
declare(strict_types=1);

namespace App\Helpers;

/** Password rules. Lighter minimum for waste pickers (hub-assisted, often basic phones). */
final class PasswordPolicy
{
    private const COMMON = [
        'password', 'password1', 'password123', '12345678', '123456789', '1234567890', '123456', '1234567',
        'qwerty', 'qwerty123', 'qwertyuiop', 'abc12345', 'abcd1234', 'letmein', 'welcome', 'welcome1',
        'admin123', 'administrator', 'iloveyou', 'monkey', 'dragon', 'football', 'plastilink', 'plastilink1',
        'uganda', 'uganda123', 'kampala', 'gulu', 'gulu1234', '11111111', '00000000', '111111', '000000',
    ];

    public static function minLength(?string $roleSlug): int
    {
        return $roleSlug === 'waste_picker' ? 6 : 8;
    }

    /** @return string[] Plain-language error messages (empty = valid). */
    public static function validate(string $password, ?string $roleSlug = null): array
    {
        $errors = [];
        $min = self::minLength($roleSlug);
        if (mb_strlen($password) < $min) {
            $errors[] = "The password must be at least $min characters.";
        }
        if (mb_strlen($password) > 128) {
            $errors[] = 'The password must be 128 characters or fewer.';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            $errors[] = 'That password is too common. Choose a less obvious one.';
        }
        if ($password !== '' && count(array_unique(mb_str_split($password))) === 1) {
            $errors[] = 'The password cannot be one repeated character.';
        }
        return $errors;
    }
}
