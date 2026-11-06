<?php
declare(strict_types=1);

namespace App\Services;

/** A money rule was broken (balance too low, duplicate posting...). The message is safe to show users. */
final class FinanceException extends \RuntimeException
{
}
