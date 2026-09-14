<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Project;
use App\Models\Scan;
use App\Services\Reports\ScanReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(private readonly ScanReportService $reports) {}

    /**
     * Generates a new report of the scan's current results on demand.
     */
    public function store(Request $request, Project $project, Scan $scan): JsonResponse
    {
        Gate::authorize('runScans', $project);

        if ($scan->imported_at === null || in_array($scan->status, [ScanStatus::Queued, ScanStatus::Importing], true)) {
            throw ValidationException::withMessages([
                'scan' => 'Wait until the scan results have been imported.',
            ]);
        }

        $report = $this->reports->queue($scan, $request->user());

        return (new ReportResource($report->load(['scan', 'creator'])))->response()->setStatusCode(202);
    }
}
