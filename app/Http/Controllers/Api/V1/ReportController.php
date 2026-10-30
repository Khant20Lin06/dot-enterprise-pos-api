<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendDailySalesSummaryJob;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{
    /**
     * Get or compile daily sales & workshop executive summary with Redis/Cache layer.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());
        $cacheKey = "reports:daily-summary:{$date}";
        $isFreshRequested = $request->boolean('fresh');

        $user = $request->user();
        $isPrivileged = $user && ($user->hasRole('ADMIN') || $user->hasPermission('settings', 'manageRoles') || $user->hasPermission('finances', 'view'));

        if ($isFreshRequested && $isPrivileged) {
            Cache::forget($cacheKey);
        }

        $wasCached = Cache::has($cacheKey);

        $summary = Cache::remember($cacheKey, 60, function () use ($date) {
            $job = new SendDailySalesSummaryJob($date);

            return $job->handle();
        });

        return response()->json([
            'success' => true,
            'cached' => $wasCached && ! ($isFreshRequested && $isPrivileged),
            'data' => $summary,
        ])->header('X-Report-Cache', $wasCached ? 'HIT' : 'MISS');
    }
}
