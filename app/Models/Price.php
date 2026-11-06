<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Append-only price history. A price is never edited or deleted; a new row supersedes the old one. */
final class Price
{
    /**
     * Price in force now: the latest hub-specific price if the hub has an override, otherwise the latest national price.
     * @return array{id:int,price_per_kg_ugx:int,hub_id:?int}|null
     */
    public static function current(int $categoryId, ?int $hubId): ?array
    {
        $pdo = Database::connection();
        if ($hubId !== null) {
            $s = $pdo->prepare(
                'SELECT * FROM price_lists WHERE plastic_category_id = ? AND hub_id = ? AND effective_from <= UTC_TIMESTAMP()
                 ORDER BY effective_from DESC, id DESC LIMIT 1'
            );
            $s->execute([$categoryId, $hubId]);
            if ($row = $s->fetch()) {
                return $row;
            }
        }
        $s = $pdo->prepare(
            'SELECT * FROM price_lists WHERE plastic_category_id = ? AND hub_id IS NULL AND effective_from <= UTC_TIMESTAMP()
             ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $s->execute([$categoryId]);
        return $s->fetch() ?: null;
    }

    public static function create(int $categoryId, ?int $hubId, int $priceUgx, ?string $note, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO price_lists (plastic_category_id, hub_id, price_per_kg_ugx, effective_from, note, created_by)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?)'
        )->execute([$categoryId, $hubId, $priceUgx, $note, $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** Current price for every category: national and each hub override. */
    public static function currentTable(): array
    {
        $rows = [];
        $pdo = Database::connection();
        $cats = $pdo->query('SELECT id, name FROM plastic_categories WHERE is_active = 1 ORDER BY name')->fetchAll();
        $hubs = $pdo->query("SELECT id, name FROM collection_hubs WHERE status = 'active' ORDER BY name")->fetchAll();
        foreach ($cats as $c) {
            $national = self::current((int) $c['id'], null);
            $rows[] = ['category' => $c['name'], 'scope' => 'All hubs (national)', 'price' => $national['price_per_kg_ugx'] ?? null, 'since' => $national['effective_from'] ?? null];
            foreach ($hubs as $h) {
                $s = $pdo->prepare(
                    'SELECT * FROM price_lists WHERE plastic_category_id = ? AND hub_id = ? AND effective_from <= UTC_TIMESTAMP() ORDER BY effective_from DESC, id DESC LIMIT 1'
                );
                $s->execute([$c['id'], $h['id']]);
                if ($o = $s->fetch()) {
                    $rows[] = ['category' => $c['name'], 'scope' => $h['name'] . ' (override)', 'price' => $o['price_per_kg_ugx'], 'since' => $o['effective_from']];
                }
            }
        }
        return $rows;
    }

    public static function history(int $offset, int $limit): array
    {
        $s = Database::connection()->prepare(
            'SELECT pl.*, pc.name AS category_name, h.name AS hub_name, u.full_name AS set_by
             FROM price_lists pl JOIN plastic_categories pc ON pc.id = pl.plastic_category_id
             LEFT JOIN collection_hubs h ON h.id = pl.hub_id LEFT JOIN users u ON u.id = pl.created_by
             ORDER BY pl.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $s->execute();
        return $s->fetchAll();
    }

    public static function historyCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM price_lists')->fetchColumn();
    }
}
