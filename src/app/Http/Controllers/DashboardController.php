<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    /**
     * Display the real-time operations dashboard.
     */
    public function __invoke(Request $request, DashboardAnalyticsService $analyticsService): View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $metrics = $analyticsService->getMetrics($user);

        if ($request->wantsJson()) {
            return response()->json($metrics);
        }

        return view('dashboard', $metrics);
    }
}
