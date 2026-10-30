<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendDailySalesSummaryJob;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Get or compile daily sales & workshop executive summary.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $date = $request->query('date', Carbon::today()->toDateString());

        $job = new SendDailySalesSummaryJob($date);
        $summary = $job->handle();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}
