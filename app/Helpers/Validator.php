<?php
declare(strict_types=1);

namespace App\Helpers;

/**
 * Tiny validator. Rules: [field => [Label, 'rule|rule:arg']].
 * Rules: required, max:N, min:N, email, phone, in:a,b,c, int, numeric, between:a,b, same:field, accepted.
 * Returns [field => plain-language message]; empty = valid.
 */
final class Validator
{
    /** @return array<string,string> */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => [$label, $spec]) {
            $value = $data[$field] ?? null;
            $str = is_string($value) ? trim($value) : (is_scalar($value) ? (string) $value : '');
            $parts = array_filter(explode('|', $spec));
            $required = in_array('required', $parts, true);

            if ($str === '' ) {
                if (in_array('accepted', $parts, true)) {
                    $errors[$field] = "You must agree to the $label to continue.";
                } elseif ($required) {
                    $errors[$field] = "$label is required.";
                }
                continue;
            }
            foreach ($parts as $part) {
                [$rule, $arg] = array_pad(explode(':', $part, 2), 2, null);
                $msg = match ($rule) {
                    'max'      => mb_strlen($str) > (int) $arg ? "$label must be $arg characters or fewer." : null,
                    'min'      => mb_strlen($str) < (int) $arg ? "$label must be at least $arg characters." : null,
                    'email'    => (!filter_var($str, FILTER_VALIDATE_EMAIL) || strlen($str) > 190) ? "Enter a valid email address for $label." : null,
                    'phone'    => Phone::normalize($str) === null ? "$label must be a valid Ugandan phone number, for example 0771234567." : null,
                    'in'       => !in_array($str, explode(',', (string) $arg), true) ? "Choose a valid option for $label." : null,
                    'int'      => !preg_match('/^\d+$/', $str) ? "$label must be a whole number." : null,
                    'numeric'  => !is_numeric($str) ? "$label must be a number." : null,
                    'between'  => self::between($str, (string) $arg, $label),
                    'same'     => $str !== (string) ($data[$arg] ?? '') ? "$label does not match." : null,
                    'accepted' => !in_array($str, ['1', 'on', 'yes', 'true'], true) ? "You must agree to the $label to continue." : null,
                    default    => null,
                };
                if ($msg) {
                    $errors[$field] = $msg;
                    break;
                }
            }
        }
        return $errors;
    }

    private static function between(string $v, string $arg, string $label): ?string
    {
        [$a, $b] = array_map('floatval', explode(',', $arg));
        return (!is_numeric($v) || (float) $v < $a || (float) $v > $b) ? "$label must be between $a and $b." : null;
    }
}
