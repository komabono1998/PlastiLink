<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Validator;
use App\Models\AuditLog;
use App\Models\Hub;
use App\Models\PlasticCategory;
use App\Models\Price;

final class PriceService
{
    public const MAX_PRICE_PER_KG = 1000000;

    /** @return array{errors:array<string,string>} */
    public static function set(array $in, array $actor): array
    {
        $errors = Validator::validate($in, [
            'plastic_category_id' => ['Material', 'required|int'],
            'price_per_kg_ugx'    => ['Price per kg', 'required|int'],
            'note'                => ['Note', 'max:255'],
        ]);
        if (!isset($errors['plastic_category_id']) && !array_key_exists((int) $in['plastic_category_id'], PlasticCategory::options())) {
            $errors['plastic_category_id'] = 'Choose a material from the list.';
        }
        if (!isset($errors['price_per_kg_ugx'])) {
            $p = (int) $in['price_per_kg_ugx'];
            if ($p < 1 || $p > self::MAX_PRICE_PER_KG) {
                $errors['price_per_kg_ugx'] = 'Price per kg must be between 1 and ' . number_format(self::MAX_PRICE_PER_KG) . ' UGX.';
            }
        }
        $hubId = (int) ($in['hub_id'] ?? 0) ?: null;
        if ($hubId !== null && !array_key_exists($hubId, Hub::options())) {
            $errors['hub_id'] = 'Choose an active hub, or leave it as "All hubs".';
        }
        if ($errors) {
            return ['errors' => $errors];
        }

        $note = trim((string) ($in['note'] ?? '')) ?: null;
        $id = Price::create((int) $in['plastic_category_id'], $hubId, (int) $in['price_per_kg_ugx'], $note, (int) $actor['id']);
        AuditLog::write('price.set', (int) $actor['id'], 'price', $id, [
            'category_id' => (int) $in['plastic_category_id'], 'hub_id' => $hubId, 'price_per_kg_ugx' => (int) $in['price_per_kg_ugx'],
        ]);
        return ['errors' => []];
    }
}
