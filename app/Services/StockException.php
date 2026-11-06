<?php
declare(strict_types=1);

namespace App\Services;

/** A stock rule was broken (not enough stock, reserved stock, duplicate posting). The message is safe to show users. */
final class StockException extends \RuntimeException
{
}
