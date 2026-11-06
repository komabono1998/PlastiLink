<?php
declare(strict_types=1);

/**
 * PlastiLink scheduled jobs (CLI only). Run from a cron task / Windows Task Scheduler.
 *
 *   php jobs.php expire-orders       Expire purchase-order negotiations that were not answered in time
 *   php jobs.php poll-payments       Ask the payment provider about payments left "processing"
 *   php jobs.php send-sms            Send queued text messages (run every minute or two)
 *   php jobs.php generate-invoices   Invoice completed orders that have none yet (safety net)
 *
 * (Expiry is also applied lazily whenever an order is opened, so this job is a safety net.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

switch ($argv[1] ?? '') {
    case 'expire-orders':
        $n = App\Models\PurchaseOrder::expireStale();
        echo "Expired $n negotiation(s).\n";
        break;
    case 'poll-payments':
        $n = App\Services\PaymentService::pollProcessing();
        echo "Updated $n processing payment(s).\n";
        break;
    case 'send-sms':
        $r = App\Services\SmsService::dispatch();
        echo "SMS: {$r['sent']} sent, {$r['retry']} to retry, {$r['failed']} failed.\n";
        break;
    case 'generate-invoices':
        $n = App\Services\InvoiceService::generateMissing();
        echo "Issued $n invoice(s).\n";
        break;
    default:
        fwrite(STDERR, "Usage: php jobs.php expire-orders | poll-payments | generate-invoices | send-sms\n");
        exit(1);
}
