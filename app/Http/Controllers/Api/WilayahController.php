<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WilayahController extends Controller
{
    /**
     * GET /api/v1/wilayah/provinces
     * Returns all provinces from config. Cache-friendly (24h TTL via header).
     */
    public function provinces(): JsonResponse
    {
        $provinces = config('wilayah.provinces', []);

        return response()->json([
            'data' => $provinces,
        ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * GET /api/v1/wilayah/cities?province=...
     * Returns cities filtered by province.
     */
    public function cities(Request $request): JsonResponse
    {
        $province = $request->query('province');
        $mapping = config('wilayah_kota.mapping', []);

        if ($province) {
            $provinceClean = trim(strtolower($province));
            $mapping = array_filter($mapping, function ($provName) use ($provinceClean) {
                return trim(strtolower($provName)) === $provinceClean;
            });
        }

        if ($request->query('include_details')) {
            $data = [];
            foreach ($mapping as $city => $prov) {
                $data[] = [
                    'city' => $city,
                    'province' => $prov,
                ];
            }
            return response()->json([
                'data' => $data,
            ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
        }

        return response()->json([
            'data' => array_values(array_keys($mapping)),
        ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
    }

    /**
     * GET /api/v1/wilayah/districts?city=...
     * Returns districts filtered by city.
     */
    public function districts(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $mapping = config('wilayah_kecamatan.mapping', []);

        if ($city) {
            $mapping = array_filter($mapping, function ($info) use ($city) {
                return $this->matchCity($info['city'] ?? '', $city);
            });
        }

        if ($request->query('include_details')) {
            $data = [];
            foreach ($mapping as $item) {
                $data[] = [
                    'district' => $item['district'] ?? '',
                    'city' => $item['city'] ?? '',
                    'province' => $item['province'] ?? '',
                ];
            }
            return response()->json([
                'data' => $data,
            ])->header('Cache-Control', 'public, max-age=86400, s-maxage=86400');
        }

        $districts = array_map(function ($item) {
            return $item['district'] ?? '';
        }, $mapping);

        return response()->json([
            'data' => array_values(array_unique($districts)),
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
