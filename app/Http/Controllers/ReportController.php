<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\Project;
use App\Models\Report;
use App\Services\AuditLogger;
use App\Services\Reports\ScanReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ScanReportService $reports,
    ) {}

    public function destroy(Request $request, Project $project, Report $report): RedirectResponse
    {
        Gate::authorize('deleteReports', $project);

        $this->reports->delete($report, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report :number deleted.', ['number' => $report->report_number])]);

        return back();
    }

    /**
     * Opens the PDF in the browser. Files live on a private disk, so this
     * authorized route is the only way to reach them.
     */
    public function download(Project $project, Report $report): StreamedResponse
    {
        Gate::authorize('view', $project);

        $disk = Storage::disk(config('nessus.disk'));

        abort_unless($report->status === ReportStatus::Completed && $report->file_path && $disk->exists($report->file_path), 404);

        $this->audit->log('report.downloaded', $report, metadata: ['report_number' => $report->report_number]);

        return $disk->response($report->file_path, $report->report_number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
