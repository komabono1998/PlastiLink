<?php
declare(strict_types=1);

namespace App\Helpers;

final class Secret
{
    /** Random temporary password without look-alike characters (0/O, 1/l/I). */
    public static function tempPassword(int $length = 10): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
