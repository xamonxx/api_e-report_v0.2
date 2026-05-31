<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Wilayah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WilayahController extends Controller
{
    /**
     * GET /api/v1/wilayah/provinces
     * Returns all provinces from config. Cache-friendly (24h TTL via header).
     */
    public function provinces(): JsonResponse
    {
        return response()->json([
            'data' => Wilayah::provinces(),
        ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * GET /api/v1/wilayah/cities?province=...
     * Returns cities filtered by province.
     *
     * The filtering result is memoized server-side (keyed by dataset version +
     * filter params) so the O(n) scan over the city dataset runs once, not on
     * every request.
     */
    public function cities(Request $request): JsonResponse
    {
        $province = $request->query('province');
        $includeDetails = (bool) $request->query('include_details');

        $cacheKey = 'wilayah:cities:' . Wilayah::version()
            . ':' . md5(strtolower((string) $province))
            . ':' . (int) $includeDetails;

        $data = Cache::rememberForever($cacheKey, function () use ($province, $includeDetails) {
            $mapping = Wilayah::cityMapping();

            if ($province) {
                $provinceClean = trim(strtolower($province));
                $mapping = array_filter(
                    $mapping,
                    fn ($provName) => trim(strtolower($provName)) === $provinceClean
                );
            }

            if ($includeDetails) {
                $rows = [];
                foreach ($mapping as $city => $prov) {
                    $rows[] = ['city' => $city, 'province' => $prov];
                }

                return $rows;
            }

            return array_values(array_keys($mapping));
        });

        return response()->json([
            'data' => $data,
        ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * GET /api/v1/wilayah/districts?city=...
     * Returns districts filtered by city.
     *
     * Result is memoized server-side; the fuzzy city matching (regex per row
     * over a ~7k-entry dataset) only runs on a cache miss.
     */
    public function districts(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $includeDetails = (bool) $request->query('include_details');

        $cacheKey = 'wilayah:districts:' . Wilayah::version()
            . ':' . md5(strtolower((string) $city))
            . ':' . (int) $includeDetails;

        $data = Cache::rememberForever($cacheKey, function () use ($city, $includeDetails) {
            $mapping = Wilayah::districtMapping();

            if ($city) {
                $mapping = array_filter(
                    $mapping,
                    fn ($info) => $this->matchCity($info['city'] ?? '', $city)
                );
            }

            if ($includeDetails) {
                $rows = [];
                foreach ($mapping as $item) {
                    $rows[] = [
                        'district' => $item['district'] ?? '',
                        'city' => $item['city'] ?? '',
                        'province' => $item['province'] ?? '',
                    ];
                }

                return $rows;
            }

            $districts = array_map(fn ($item) => $item['district'] ?? '', $mapping);

            return array_values(array_unique($districts));
        });

        return response()->json([
            'data' => $data,
        ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * Normalize and compare two city names fuzzily.
     */
    private function matchCity(string $a, string $b): bool
    {
        $normalize = function ($name) {
            $name = strtolower($name);
            $name = str_replace(
                ['kota administrasi ', 'kota ', 'kabupaten ', 'kab ', 'administrasi '],
                '',
                $name
            );
            return trim(preg_replace('/\s+/', ' ', $name));
        };

        $normA = $normalize($a);
        $normB = $normalize($b);

        if ($normA === $normB) {
            return true;
        }

        return str_contains($normA, $normB) || str_contains($normB, $normA);
    }
}
