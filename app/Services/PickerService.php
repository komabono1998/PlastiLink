<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Helpers\PasswordPolicy;
use App\Helpers\Phone;
use App\Helpers\Secret;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\Neighbourhood;
use App\Models\Picker;
use App\Models\User;

final class PickerService
{
    public const CONSENT_VERSION = '2026-11-v1';
    public const CONSENT_TEXT = 'I agree that PlastiLink may store my name, phone number and plastic sales records to run the programme, '
        . 'and share my sales totals with the recyclers I sell to. I understand I can ask to see or delete my data.';

    private const MAX_PUBLIC_REGISTRATIONS_PER_HOUR = 5;

    /** Picker the user may see, or null (out of hub scope counts as "not found"). */
    public static function findAccessible(int $id, array $user): ?array
    {
        $p = Picker::find($id);
        if (!$p) {
            return null;
        }
        return HubScope::allows(HubScope::forUser($user), $p['preferred_hub_id'] !== null ? (int) $p['preferred_hub_id'] : null) ? $p : null;
    }

    /** @return array{errors:array<string,string>, id?:int, temp?:string, warning?:bool} */
    public static function registerByStaff(array $in, array $actor): array
    {
        $scope = HubScope::forUser($actor);
        $errors = self::validate($in, false);

        $hubId = (int) ($in['preferred_hub_id'] ?? 0) ?: null;
        if ($scope !== null) {
            if ($hubId === null) {
                $errors['preferred_hub_id'] ??= 'Choose the hub this picker will sell at.';
            } elseif (!in_array($hubId, $scope, true)) {
                $errors['preferred_hub_id'] = 'You can only register pickers for your own hub.';
            }
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $temp = Secret::tempPassword(8);
        $phone = (string) Phone::normalize($in['phone']);
        $id = Database::transaction(function () use ($in, $actor, $temp, $phone, $hubId): int {
            $uid = User::create([
                'role' => 'waste_picker', 'full_name' => trim($in['full_name']), 'phone_e164' => $phone,
                'password' => $temp, 'status' => 'active',
            ]);
            User::setMustChange($uid, true);
            User::decide($uid, 'active', (int) $actor['id']);
            $pid = Picker::create(self::row($in, $uid, $hubId, (int) $actor['id']));
            AuditLog::write('picker.registered', (int) $actor['id'], 'picker', $pid, ['by' => 'staff', 'consent' => self::CONSENT_VERSION]);
            return $pid;
        });

        return ['errors' => [], 'id' => $id, 'temp' => $temp,
                'warning' => Picker::similarExists(trim($in['full_name']), (int) $in['neighbourhood_id'], $id)];
    }

    /** @return array{errors:array<string,string>, id?:int} */
    public static function registerPublic(array $in): array
    {
        if (self::recentPublicRegistrations() >= self::MAX_PUBLIC_REGISTRATIONS_PER_HOUR) {
            return ['errors' => ['_' => 'Too many registrations from this connection. Please try again later or register at a hub.']];
        }
        $errors = self::validate($in, true);
        $pw = (string) ($in['password'] ?? '');
        if (!isset($errors['password'])) {
            $pe = PasswordPolicy::validate($pw, 'waste_picker');
            if ($pe) {
                $errors['password'] = $pe[0];
            }
        }
        if (!isset($errors['password_confirmation']) && $pw !== (string) ($in['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }
        $hubId = (int) ($in['preferred_hub_id'] ?? 0) ?: null;
        if ($hubId !== null && !array_key_exists($hubId, Hub::options())) {
            $errors['preferred_hub_id'] = 'Choose a valid hub.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $phone = (string) Phone::normalize($in['phone']);
        $id = Database::transaction(function () use ($in, $pw, $phone, $hubId): int {
            $uid = User::create([
                'role' => 'waste_picker', 'full_name' => trim($in['full_name']), 'phone_e164' => $phone,
                'password' => $pw, 'status' => 'pending',
            ]);
            $pid = Picker::create(self::row($in, $uid, $hubId, null));
            AuditLog::write('picker.self_registered', $uid, 'picker', $pid, ['consent' => self::CONSENT_VERSION]);
            return $pid;
        });
        return ['errors' => [], 'id' => $id];
    }

    /**
     * approve | reject | suspend | reactivate | verify | unverify | reset_password
     * @return array{error:?string, temp?:string, message?:string}
     */
    public static function decide(int $pickerId, string $decision, ?string $reason, array $actor): array
    {
        $p = self::findAccessible($pickerId, $actor);
        if (!$p) {
            return ['error' => 'Picker not found.'];
        }
        $uid = (int) $p['user_id'];
        $status = $p['account_status'];
        $reason = $reason !== null ? trim($reason) : null;
        $by = (int) $actor['id'];

        switch ($decision) {
            case 'approve':
                if ($status !== 'pending') {
                    return ['error' => 'Only pending registrations can be approved.'];
                }
                User::decide($uid, 'active', $by);
                Picker::setReviewNote($pickerId, null);
                SmsService::notifyRegistration('approved', User::findById($uid)['phone_e164'] ?? null, $uid);
                $msg = 'Picker approved. They can now sign in.';
                break;
            case 'reject':
                if ($status !== 'pending') {
                    return ['error' => 'Only pending registrations can be rejected.'];
                }
                if ($reason === null || mb_strlen($reason) < 3) {
                    return ['error' => 'Give a short reason for rejecting this registration.'];
                }
                User::decide($uid, 'suspended', $by);
                Picker::setReviewNote($pickerId, mb_substr($reason, 0, 500));
                SmsService::notifyRegistration('rejected', User::findById($uid)['phone_e164'] ?? null, $uid);
                $msg = 'Registration rejected.';
                break;
            case 'suspend':
                if ($status !== 'active') {
                    return ['error' => 'Only active pickers can be suspended.'];
                }
                User::decide($uid, 'suspended', $by);
                Picker::setReviewNote($pickerId, $reason ? mb_substr($reason, 0, 500) : null);
                $msg = 'Picker suspended.';
                break;
            case 'reactivate':
                if ($status !== 'suspended') {
                    return ['error' => 'Only suspended pickers can be reactivated.'];
                }
                User::decide($uid, 'active', $by);
                Picker::setReviewNote($pickerId, null);
                $msg = 'Picker reactivated.';
                break;
            case 'verify':
            case 'unverify':
                Picker::setVerification($pickerId, $decision === 'verify' ? 'verified' : 'unverified');
                $msg = $decision === 'verify' ? 'Picker marked as verified.' : 'Verification removed.';
                break;
            case 'reset_password':
                $r = UserService::issueTempPassword(['id' => $uid], $actor);
                return ['error' => null, 'temp' => $r['temp'], 'message' => 'A new temporary password was created. Give it to the picker; they must change it at first sign-in.'];
            default:
                return ['error' => 'Unknown action.'];
        }
        AuditLog::write('picker.' . $decision, $by, 'picker', $pickerId, $reason ? ['reason' => mb_substr($reason, 0, 200)] : []);
        return ['error' => null, 'message' => $msg];
    }

    // ------------------------------------------------------------------ helpers
    private static function row(array $in, int $userId, ?int $hubId, ?int $registeredBy): array
    {
        $nid = trim((string) ($in['national_id'] ?? ''));
        return [
            'user_id' => $userId, 'neighbourhood_id' => (int) $in['neighbourhood_id'], 'preferred_hub_id' => $hubId,
            'national_id' => $nid === '' ? null : strtoupper($nid), 'consent_version' => self::CONSENT_VERSION,
            'registered_by' => $registeredBy,
        ];
    }

    /** @return array<string,string> */
    private static function validate(array $in, bool $public): array
    {
        $errors = Validator::validate($in, [
            'full_name'        => ['Full name', 'required|max:150'],
            'phone'            => ['Phone number', 'required|phone'],
            'neighbourhood_id' => ['Neighbourhood', 'required|int'],
            'national_id'      => ['National ID', 'max:30'],
            'consent'          => ['data-use agreement', 'accepted'],
        ] + ($public ? [
            'password'              => ['Password', 'required'],
            'password_confirmation' => ['Password confirmation', 'required'],
        ] : []));

        if (!isset($errors['neighbourhood_id']) && !array_key_exists((int) ($in['neighbourhood_id'] ?? 0), Neighbourhood::options())) {
            $errors['neighbourhood_id'] = 'Choose a neighbourhood from the list.';
        }
        $nid = trim((string) ($in['national_id'] ?? ''));
        if ($nid !== '' && !isset($errors['national_id']) && !preg_match('/^[A-Za-z0-9\- ]{5,30}$/', $nid)) {
            $errors['national_id'] = 'National ID may only contain letters, numbers and dashes.';
        }
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        if ($phone && !isset($errors['phone']) && User::phoneTaken($phone)) {
            $errors['phone'] = 'This phone number is already registered.';
        }
        return $errors;
    }

    private static function recentPublicRegistrations(): int
    {
        $s = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE action IN ('picker.self_registered','recycler.self_registered')
             AND ip_address = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR"
        );
        $s->execute([Request::ip()]);
        return (int) $s->fetchColumn();
    }

    public static function publicRegistrationsThrottled(): bool
    {
        return self::recentPublicRegistrations() >= self::MAX_PUBLIC_REGISTRATIONS_PER_HOUR;
    }
}
