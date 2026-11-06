<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Receipt;

final class ReceiptService
{
    /**
     * Issue the receipt for an approved collection. The receipt keeps its OWN snapshot, so renaming a picker or hub
     * later never changes an issued receipt. It deliberately holds no phone number and no national ID.
     */
    public static function issue(array $collection, array $approver): int
    {
        return Receipt::create((int) $collection['id'], (int) $collection['picker_id'], (int) $approver['id'], [
            'reference'      => $collection['reference'],
            'picker_name'    => $collection['picker_name'],
            'picker_code'    => $collection['picker_code'],
            'hub_name'       => $collection['hub_name'],
            'neighbourhood'  => $collection['neighbourhood'],
            'material'       => $collection['category_name'],
            'gross_kg'       => $collection['gross_weight_kg'],
            'tare_kg'        => $collection['tare_weight_kg'],
            'net_kg'         => $collection['net_weight_kg'],
            'price_per_kg'   => (int) $collection['price_per_kg_ugx'],
            'total_ugx'      => (int) $collection['total_value_ugx'],
            'recorded_by'    => $collection['recorded_by_name'],
            'recorded_at'    => $collection['recorded_at'],
            'approved_by'    => $approver['full_name'],
            'currency'       => (string) config('app.currency', 'UGX'),
        ]);
    }
}
