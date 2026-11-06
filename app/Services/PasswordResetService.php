<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Helpers\Identifier;
use App\Helpers\PasswordPolicy;
use App\Models\AuditLog;
use App\Models\PasswordReset;
use App\Models\User;

final class PasswordResetService
{
    public function __construct(private ?Mailer $mailer = null)
    {
        $this->mailer ??= LogMailer::make();
    }

    /**
     * Always behaves the same to the caller so account existence is never revealed.
     * Only a SHA-256 hash of the token is stored; the raw token exists only in the message.
     */
    public function request(string $identifier): void
    {
        $parsed = Identifier::parse($identifier);
        $user = $parsed ? User::findByIdentifier($parsed) : null;
        if (!$user || $user['status'] !== 'active') {
            return;
        }
        if (PasswordReset::countLastHour((int) $user['id']) >= (int) Config::get('app.auth.reset_max_per_hour', 3)) {
            AuditLog::write('auth.reset_throttled', (int) $user['id'], 'user', (int) $user['id']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        PasswordReset::invalidateOpen((int) $user['id']);
        PasswordReset::create(
            (int) $user['id'],
            hash('sha256', $token),
            (int) Config::get('app.auth.reset_token_minutes', 60),
            Request::ip()
        );

        $link = Request::baseUrl() . '/reset-password?token=' . $token;
        $minutes = (int) Config::get('app.auth.reset_token_minutes', 60);
        $body = "Hello {$user['full_name']},\nUse this link to set a new PlastiLink password (valid for $minutes minutes, one use):\n$link\nIf you did not ask for this, ignore this message.";

        if ($parsed['type'] === 'email') {
            $this->mailer->send('email', $user['email'], 'Reset your PlastiLink password', $body);
        } else {
            $this->mailer->send('sms', $user['phone_e164'], 'PlastiLink password reset', $body);
        }
        AuditLog::write('auth.reset_requested', (int) $user['id'], 'user', (int) $user['id']);
    }

    public function isTokenValid(string $token): bool
    {
        return $token !== '' && PasswordReset::findValid(hash('sha256', $token)) !== null;
    }

    /** @return string[] error messages; empty on success */
    public function reset(string $token, string $password, string $confirmation): array
    {
        $row = $token !== '' ? PasswordReset::findValid(hash('sha256', $token)) : null;
        if (!$row) {
            return ['This reset link is invalid or has expired. Please request a new one.'];
        }
        $user = User::findById((int) $row['user_id']);
        if (!$user || $user['status'] !== 'active') {
            return ['This reset link is invalid or has expired. Please request a new one.'];
        }
        if ($password !== $confirmation) {
            return ['The two passwords do not match.'];
        }
        $errors = PasswordPolicy::validate($password, $user['role_slug']);
        if ($errors) {
            return $errors;
        }

        Database::transaction(function () use ($row, $user, $password): void {
            User::updatePassword((int) $user['id'], $password);
            PasswordReset::markUsed((int) $row['id']);
            PasswordReset::invalidateOpen((int) $user['id']);
            AuditLog::write('auth.password_reset', (int) $user['id'], 'user', (int) $user['id']);
        });
        return [];
    }
}
