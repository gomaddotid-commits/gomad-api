<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Warung\Application\GetWarungReport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarungReportController extends Controller
{
    public function show(Request $request, GetWarungReport $report): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
        ]);

        return response()->json([
            'data' => $report->execute(
                $request->user(),
                Business::query()->findOrFail($data['business_id']),
            ),
        ]);
    }
}
