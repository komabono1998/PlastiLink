<?php
declare(strict_types=1);

namespace App\Helpers;

final class Paginator
{
    /** @return array{page:int,pages:int,per:int,total:int,offset:int} */
    public static function make(int $total, mixed $page, int $per = 20): array
    {
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, (int) $page), $pages);
        return ['page' => $page, 'pages' => $pages, 'per' => $per, 'total' => $total, 'offset' => ($page - 1) * $per];
    }
}
