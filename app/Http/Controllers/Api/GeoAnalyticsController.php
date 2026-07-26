<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeoAnalyticsRequest;
use App\Services\Geo\GeoAnalyticsService;
use Illuminate\Http\JsonResponse;

class GeoAnalyticsController extends Controller
{
    /**
     * GET /api/v1/geo-analytics
     */
    public function index(GeoAnalyticsRequest $request, GeoAnalyticsService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->build($request->user(), $request->validated()),
        ]);
    }
}
