<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsReportRequest;
use App\Services\Reports\AnalyticsReportService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    /**
     * GET /api/v1/analytics
     */
    public function index(
        AnalyticsReportRequest $request,
        AnalyticsReportService $reportService
    ): JsonResponse {
        $user = $request->user();
        $report = $reportService->buildForUser($user, $request->validated());

        return response()->json([
            'data' => $report,
        ]);
    }
}
