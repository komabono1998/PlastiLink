<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class User
{
    private const SELECT = 'SELECT u.*, r.slug AS role_slug, r.name AS role_name
                            FROM users u JOIN roles r ON r.id = u.role_id';

    public static function findById(int $id): ?array
    {
        $s = Database::connection()->prepare(self::SELECT . ' WHERE u.id = ? LIMIT 1');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @param array{type:string,value:string} $identifier from Identifier::parse() */
    public static function findByIdentifier(array $identifier): ?array
    {
        $col = $identifier['type'] === 'email' ? 'u.email' : 'u.phone_e164';
        $s = Database::connection()->prepare(self::SELECT . " WHERE $col = ? LIMIT 1");
        $s->execute([$identifier['value']]);
        return $s->fetch() ?: null;
    }

    /**
     * @param array{role:string, full_name:string, email?:?string, phone_e164?:?string, password:string, status?:string} $d
     */
    public static function create(array $d): int
    {
        $pdo = Database::connection();
        $s = $pdo->prepare(
            'INSERT INTO users (role_id, full_name, email, phone_e164, password_hash, status, password_changed_at)
             VALUES ((SELECT id FROM roles WHERE slug = ?), ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $s->execute([
            $d['role'], $d['full_name'], $d['email'] ?? null, $d['phone_e164'] ?? null,
            self::hash($d['password']), $d['status'] ?? 'pending',
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array{q?:string,role?:string,status?:string} $f */
    public static function paginate(array $f, int $offset, int $limit): array
    {
        [$where, $args] = self::filters($f);
        $s = Database::connection()->prepare(
            self::SELECT . " $where ORDER BY u.created_at DESC, u.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $s->execute($args);
        return $s->fetchAll();
    }

    public static function count(array $f): int
    {
        [$where, $args] = self::filters($f);
        $s = Database::connection()->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id $where");
        $s->execute($args);
        return (int) $s->fetchColumn();
    }

    private static function filters(array $f): array
    {
        $w = [];
        $a = [];
        if (!empty($f['q'])) {
            $w[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone_e164 LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            array_push($a, $like, $like, $like);
        }
        if (!empty($f['role'])) {
            $w[] = 'r.slug = ?';
            $a[] = $f['role'];
        }
        if (!empty($f['status'])) {
            $w[] = 'u.status = ?';
            $a[] = $f['status'];
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
    }

    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $s->execute([$email, $exceptId ?? 0]);
        return (int) $s->fetchColumn() > 0;
    }

    public static function phoneTaken(string $phone, ?int $exceptId = null): bool
    {
        $s = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE phone_e164 = ? AND id <> ?');
        $s->execute([$phone, $exceptId ?? 0]);
        return (int) $s->fetchColumn() > 0;
    }

    public static function updateProfile(int $id, string $name, ?string $email, ?string $phone, string $roleSlug): void
    {
        Database::connection()->prepare(
            'UPDATE users SET full_name = ?, email = ?, phone_e164 = ?, role_id = (SELECT id FROM roles WHERE slug = ?) WHERE id = ?'
        )->execute([$name, $email, $phone, $roleSlug, $id]);
    }

    /** Set status; when activating through approval, stamp who approved. */
    public static function decide(int $id, string $status, ?int $byUserId): void
    {
        Database::connection()->prepare(
            'UPDATE users SET status = ?, approved_by = IF(? = \'active\', ?, approved_by), approved_at = IF(? = \'active\', UTC_TIMESTAMP(), approved_at) WHERE id = ?'
        )->execute([$status, $status, $byUserId, $status, $id]);
    }

    public static function setMustChange(int $id, bool $flag): void
    {
        Database::connection()->prepare('UPDATE users SET must_change_password = ? WHERE id = ?')->execute([$flag ? 1 : 0, $id]);
    }

    public static function activeSuperAdmins(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'super_admin' AND u.status = 'active'"
        )->fetchColumn();
    }

    /** @return array<int,array> active staff users of a role (for hub assignment pickers). */
    public static function activeByRole(string $roleSlug): array
    {
        $s = Database::connection()->prepare(self::SELECT . " WHERE r.slug = ? AND u.status = 'active' ORDER BY u.full_name");
        $s->execute([$roleSlug]);
        return $s->fetchAll();
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /** Sets a new password and clears the "must change" flag (use setMustChange afterwards for temp passwords). */
    public static function updatePassword(int $id, string $password): void
    {
        Database::connection()
            ->prepare('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), must_change_password = 0 WHERE id = ?')
            ->execute([self::hash($password), $id]);
    }

    public static function rehashIfNeeded(int $id, string $password, string $currentHash): void
    {
        if (password_needs_rehash($currentHash, PASSWORD_DEFAULT, ['cost' => 12])) {
            self::updatePassword($id, $password);
        }
    }

    public static function touchLogin(int $id, string $ip): void
    {
        Database::connection()
            ->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = ? WHERE id = ?')
            ->execute([$ip, $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::connection()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    /** @return array<string,int> status => count */
    public static function countByStatus(): array
    {
        return Database::connection()
            ->query('SELECT status, COUNT(*) FROM users GROUP BY status')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<int, array{name:string, total:int}> */
    public static function countByRole(): array
    {
        return Database::connection()->query(
            'SELECT r.name, COUNT(u.id) AS total FROM roles r LEFT JOIN users u ON u.role_id = r.id
             GROUP BY r.id, r.name ORDER BY r.id'
        )->fetchAll();
    }
}
