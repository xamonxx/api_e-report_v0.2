<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardResource;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function index(): JsonResponse
    {
        $user = auth()->user();

        $data = $this->dashboardService->getForUser($user);

        return response()->json(new DashboardResource($data));
    }
}