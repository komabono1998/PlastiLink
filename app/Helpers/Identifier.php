<?php
declare(strict_types=1);

namespace App\Helpers;

/** Parses the single "phone number or email" login field. */
final class Identifier
{
    /** @return array{type:'email'|'phone', value:string}|null */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (str_contains($input, '@')) {
            $email = mb_strtolower($input);
            return filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190
                ? ['type' => 'email', 'value' => $email]
                : null;
        }
        $phone = Phone::normalize($input);
        return $phone ? ['type' => 'phone', 'value' => $phone] : null;
    }
}
