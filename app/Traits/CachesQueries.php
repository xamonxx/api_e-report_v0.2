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
        // Clear the base key
        Cache::forget("query:{$prefix}:user:{$userId}");

        // If using Redis, scan and remove all keys matching the pattern
        $store = Cache::getStore();
        if ($store instanceof \Illuminate\Cache\RedisStore) {
            $cachePrefix = config('cache.prefix', 'laravel_cache');
            $pattern = "{$cachePrefix}:query:{$prefix}:user:{$userId}*";
            $redis = $store->connection();
            $cursor = null;
            do {
                [$cursor, $keys] = $redis->scan($cursor ?: 0, ['MATCH' => $pattern, 'COUNT' => 100]);
                if (!empty($keys)) {
                    $redis->del(...$keys);
                }
            } while ($cursor);
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