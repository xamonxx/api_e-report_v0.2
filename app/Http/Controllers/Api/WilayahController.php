<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Wilayah;
use App\Support\WilayahNormalizer;
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
     * Bandingkan dua nama kota.
     *
     * Dulu memakai str_contains sebagai cadangan, sehingga kota "Bandung" ikut
     * menarik kecamatan milik "Bandung Barat" - satu nama menjadi awalan nama
     * lain. Kini perbandingannya memakai kunci ternormalisasi dari
     * WilayahNormalizer, yang menyeragamkan awalan Kab./Kota tanpa membuangnya
     * sehingga "Kab. Bandung" dan "Kota Bandung" tetap terpisah.
     */
    private function matchCity(string $a, string $b): bool
    {
        if (WilayahNormalizer::cityKey($a) === WilayahNormalizer::cityKey($b)) {
            return true;
        }

        // Nama polos tanpa Kab./Kota masih boleh cocok dengan salah satunya,
        // mis. filter "Bandung" untuk "Kota Bandung" - tapi hanya bila nama
        // inti persis sama, bukan sekadar berawalan sama.
        $plainA = WilayahNormalizer::cityPlainKey($a);
        $plainB = WilayahNormalizer::cityPlainKey($b);
        $bareA = WilayahNormalizer::cityKey($a) === $plainA;
        $bareB = WilayahNormalizer::cityKey($b) === $plainB;

        return ($bareA || $bareB) && $plainA === $plainB;
    }
}
