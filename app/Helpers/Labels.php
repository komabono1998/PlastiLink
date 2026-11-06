<?php
declare(strict_types=1);

namespace App\Helpers;

final class Labels
{
    private const MOVEMENT = [
        'collection_in' => 'Collection approved (in)', 'collection_reversal' => 'Collection reversed (out)',
        'transfer_out' => 'Transfer sent (out)', 'transfer_in' => 'Transfer received (in)', 'transfer_return' => 'Transfer cancelled (back in)',
        'dispatch_out' => 'Dispatched to recycler (out)', 'dispatch_return' => 'Dispatch cancelled (back in)',
        'adjustment_in' => 'Adjustment (in)', 'adjustment_out' => 'Adjustment (out)', 'write_off' => 'Write-off (out)',
        'reserve' => 'Reserved', 'release' => 'Reservation released',
    ];

    public static function movement(string $type): string
    {
        return self::MOVEMENT[$type] ?? $type;
    }

    /** @return array<string,string> */
    public static function movementTypes(): array
    {
        return self::MOVEMENT;
    }
}
