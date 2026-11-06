<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

interface Middleware
{
    /** Return null to continue, or a response body string to stop the request. */
    public function handle(Request $request): ?string;
}
