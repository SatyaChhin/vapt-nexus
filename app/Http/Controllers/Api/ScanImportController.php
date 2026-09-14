<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScanResource;
use App\Models\Project;
use App\Models\Scan;
use App\Services\Nessus\NessusScanImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Imports scans that were run in the Nessus UI into a project.
 */
class ScanImportController extends Controller
{
    public function __construct(private readonly NessusScanImporter $importer) {}

    /**
     * Scans available on the project's Nessus servers.
     */
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('runScans', $project);

        return response()->json(['data' => $this->importer->available($project)]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('runScans', $project);

        $validated = $request->validate([
            'nessus_server_id' => ['required', 'integer'],
            'nessus_scan_id' => ['required', 'integer', 'min:1'],
        ]);

        $server = $project->nessusServers()->find($validated['nessus_server_id'])
            ?? throw ValidationException::withMessages([
                'nessus_server_id' => 'This Nessus server is not assigned to the project.',
            ]);

        $scan = $this->importer->queue($project, $server, (int) $validated['nessus_scan_id'], $request->user());

        return (new ScanResource($scan->load('nessusServer')))->response()->setStatusCode(202);
    }

    public function sync(Request $request, Project $project, Scan $scan): JsonResponse
    {
        Gate::authorize('runScans', $project);

        $scan = $this->importer->resync($scan, $request->user());

        return (new ScanResource($scan->load('nessusServer')))->response()->setStatusCode(202);
    }
}
