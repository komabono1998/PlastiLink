<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Helpers\PasswordPolicy;
use App\Helpers\Phone;
use App\Helpers\Secret;
use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Recycler;
use App\Models\User;

final class RecyclerService
{
    /** @return array{errors:array<string,string>, id?:int} */
    public static function registerPublic(array $in): array
    {
        if (PickerService::publicRegistrationsThrottled()) {
            return ['errors' => ['_' => 'Too many registrations from this connection. Please try again later.']];
        }
        $errors = self::validate($in);
        $pw = (string) ($in['password'] ?? '');
        $rules = Validator::validate($in, ['password' => ['Password', 'required'], 'password_confirmation' => ['Password confirmation', 'required']]);
        $errors += $rules;
        if (!isset($errors['password']) && ($pe = PasswordPolicy::validate($pw, 'recycler'))) {
            $errors['password'] = $pe[0];
        }
        if (!isset($errors['password_confirmation']) && $pw !== (string) ($in['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $id = Database::transaction(function () use ($in, $pw): int {
            $uid = User::create([
                'role' => 'recycler', 'full_name' => trim($in['contact_person']), 'email' => mb_strtolower(trim($in['email'])),
                'phone_e164' => Phone::normalize($in['phone']), 'password' => $pw, 'status' => 'pending',
            ]);
            $rid = Recycler::create(self::row($in, $uid, 'pending'));
            AuditLog::write('recycler.self_registered', $uid, 'recycler', $rid);
            return $rid;
        });
        return ['errors' => [], 'id' => $id];
    }

    /** @return array{errors:array<string,string>, id?:int, temp?:string} */
    public static function registerByStaff(array $in, array $actor): array
    {
        $errors = self::validate($in);
        if ($errors) {
            return ['errors' => $errors];
        }
        $temp = Secret::tempPassword(10);
        $id = Database::transaction(function () use ($in, $temp, $actor): int {
            $uid = User::create([
                'role' => 'recycler', 'full_name' => trim($in['contact_person']), 'email' => mb_strtolower(trim($in['email'])),
                'phone_e164' => Phone::normalize($in['phone']), 'password' => $temp, 'status' => 'active',
            ]);
            User::setMustChange($uid, true);
            User::decide($uid, 'active', (int) $actor['id']);
            $rid = Recycler::create(self::row($in, $uid, 'approved'));
            Recycler::decide($rid, 'approved', (int) $actor['id'], null);
            AuditLog::write('recycler.registered', (int) $actor['id'], 'recycler', $rid, ['by' => 'staff']);
            return $rid;
        });
        return ['errors' => [], 'id' => $id, 'temp' => $temp];
    }

    /**
     * approve | reject | suspend | reactivate
     * @return array{error:?string, message?:string}
     */
    public static function decide(int $id, string $decision, ?string $reason, array $actor): array
    {
        $r = Recycler::find($id);
        if (!$r) {
            return ['error' => 'Recycler not found.'];
        }
        $reason = $reason !== null ? mb_substr(trim($reason), 0, 500) : null;
        $by = (int) $actor['id'];
        $uid = (int) $r['user_id'];

        [$from, $to, $userStatus, $needsReason, $msg] = match ($decision) {
            'approve'    => [['pending', 'rejected'], 'approved', 'active', false, 'Recycler approved. They can now sign in.'],
            'reject'     => [['pending'], 'rejected', 'suspended', true, 'Registration rejected.'],
            'suspend'    => [['approved'], 'suspended', 'suspended', false, 'Recycler suspended.'],
            'reactivate' => [['suspended'], 'approved', 'active', false, 'Recycler reactivated.'],
            default      => [[], '', '', false, ''],
        };
        if ($to === '') {
            return ['error' => 'Unknown action.'];
        }
        if (!in_array($r['status'], $from, true)) {
            return ['error' => "A {$r['status']} recycler cannot be " . $decision . 'd.'];
        }
        if ($needsReason && ($reason === null || mb_strlen($reason) < 3)) {
            return ['error' => 'Give a short reason for rejecting this registration.'];
        }

        Database::transaction(function () use ($id, $to, $by, $reason, $uid, $userStatus, $decision): void {
            Recycler::decide($id, $to, $by, $reason);
            User::decide($uid, $userStatus, $by);
            AuditLog::write('recycler.' . $decision, $by, 'recycler', $id, $reason ? ['reason' => mb_substr($reason, 0, 200)] : []);
            if (in_array($decision, ['approve', 'reject'], true)) {
                SmsService::notifyRegistration($decision === 'approve' ? 'approved' : 'rejected', User::findById($uid)['phone_e164'] ?? null, $uid);
            }
        });
        return ['error' => null, 'message' => $msg];
    }

    private static function row(array $in, int $userId, string $status): array
    {
        $reg = trim((string) ($in['registration_number'] ?? ''));
        $mat = trim((string) ($in['materials'] ?? ''));
        return [
            'user_id' => $userId, 'business_name' => trim($in['business_name']),
            'registration_number' => $reg === '' ? null : $reg, 'contact_person' => trim($in['contact_person']),
            'address' => trim($in['address']), 'materials' => $mat === '' ? 'PET bottles' : $mat, 'status' => $status,
        ];
    }

    /** @return array<string,string> */
    private static function validate(array $in): array
    {
        $errors = Validator::validate($in, [
            'business_name'       => ['Business name', 'required|max:150'],
            'registration_number' => ['Registration number', 'max:60'],
            'contact_person'      => ['Contact person', 'required|max:150'],
            'email'               => ['Email', 'required|email'],
            'phone'               => ['Phone number', 'required|phone'],
            'address'             => ['Address', 'required|max:255'],
            'materials'           => ['Materials', 'max:255'],
            'consent'             => ['data-use agreement', 'accepted'],
        ]);
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && !isset($errors['email']) && User::emailTaken($email)) {
            $errors['email'] = 'This email address is already registered.';
        }
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        if ($phone && !isset($errors['phone']) && User::phoneTaken($phone)) {
            $errors['phone'] = 'This phone number is already registered.';
        }
        return $errors;
    }
}
