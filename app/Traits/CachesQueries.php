<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait CachesQueries
{
    public static function cacheKeyForUser(?int $userId, string $prefix, array $params = []): string
    {
        $paramString = empty($params) ? '' : ':' . md5(serialize($params));
        return "query:{$prefix}:user:{$userId}{$paramString}";
    }

    public static function rememberForUser(
        ?int $userId,
        string $prefix,
        array $params,
        \Closure $callback,
        int $ttlMinutes = 15
    ): mixed {
        $key = self::cacheKeyForUser($userId, $prefix, $params);

        return Cache::remember($key, now()->addMinutes($ttlMinutes), $callback);
    }

    public static function invalidateForUser(?int $userId, string $prefix): void
    {
        $pattern = "query:{$prefix}:user:{$userId}*";
        
        Cache::forget("query:{$prefix}:user:{$userId}");
        
        foreach (Cache::getStore() instanceof \Illuminate\Cache\RedisStore ? [] as $key) {
            Cache::forget($key);
        }
    }

    public static function invalidateAllForUser(?int $userId): void
    {
        Cache::forget("dashboard:super_admin");
        Cache::forget("dashboard:admin:{$userId}");
        Cache::forget("query:analytics:user:{$userId}");
        Cache::forget("query:leads:user:{$userId}");
    }
}