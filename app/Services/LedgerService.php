<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\LedgerEntry;

/**
 * The ONLY writer of the money ledger. Entries are append-only and signed from the account's point of view.
 * The sign of every entry type is enforced here, and one source event can never be posted twice.
 */
final class LedgerService
{
    /** entry type => required sign (+1 / -1) and which account type it belongs to */
    private const RULES = [
        'collection_earning'       => ['picker', 1],
        'collection_reversal'      => ['picker', -1],
        'payout'                   => ['picker', -1],
        'payout_reversal'          => ['picker', 1],
        'invoice_issued'           => ['recycler', 1],
        'invoice_void'             => ['recycler', -1],
        'invoice_payment'          => ['recycler', -1],
        'invoice_payment_reversal' => ['recycler', 1],
    ];

    public const LABELS = [
        'collection_earning' => 'Collection approved', 'collection_reversal' => 'Collection reversed',
        'payout' => 'Payout', 'payout_reversal' => 'Payout reversed',
        'invoice_issued' => 'Invoice issued', 'invoice_void' => 'Invoice voided',
        'invoice_payment' => 'Payment received', 'invoice_payment_reversal' => 'Payment reversed',
    ];

    /**
     * Post one entry (amount in whole UGX, signed). Must run inside a transaction.
     * @throws FinanceException on a wrong sign, wrong account type or duplicate source
     */
    public static function post(string $accountType, int $accountId, string $type, int $amountUgx, string $sourceType, int $sourceId, ?string $reference, ?string $note, ?int $userId): int
    {
        if (!Database::connection()->inTransaction()) {
            throw new \LogicException('LedgerService::post must run inside a transaction.');
        }
        if (!isset(self::RULES[$type])) {
            throw new \InvalidArgumentException("Unknown ledger entry type $type");
        }
        [$expectedAccount, $sign] = self::RULES[$type];
        if ($accountType !== $expectedAccount || $amountUgx === 0 || ($amountUgx <=> 0) !== $sign) {
            throw new \InvalidArgumentException("Ledger entry $type has the wrong account type or sign.");
        }
        try {
            return LedgerEntry::insert($accountType, $accountId, $type, $amountUgx, $sourceType, $sourceId, $reference, $note !== null ? mb_substr($note, 0, 255) : null, $userId);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new FinanceException('This money movement has already been recorded.');
            }
            throw $e;
        }
    }

    public static function balance(string $accountType, int $accountId): int
    {
        return LedgerEntry::balance($accountType, $accountId);
    }
}
