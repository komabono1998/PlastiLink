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

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    public static function updatePassword(int $id, string $password): void
    {
        Database::connection()
            ->prepare('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?')
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
