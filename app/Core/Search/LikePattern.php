<?php

declare(strict_types=1);

namespace NovaNuke\Core\Search;

final class LikePattern
{
    public static function contains(string $literal): string
    {
        return '%' . strtr($literal, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    }
}
