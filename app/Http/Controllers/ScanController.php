<?php

namespace App\Http\Controllers;

use App\Enums\Severity;
use App\Http\Resources\ScanResource;
use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scan results. The route uses scoped bindings, so a scan is only found
 * through the project it belongs to.
 */
class ScanController extends Controller
{
    public function show(Request $request, Project $project, Scan $scan): Response
    {
        Gate::authorize('view', $project);

        $hosts = $scan->hosts()
            ->orderByDesc('critical_count')
            ->orderByDesc('high_count')
            ->orderByDesc('total_findings')
            ->orderBy('ip_address')
            ->get()
            ->map(fn (ScanHost $host) => [
                'id' => $host->id,
                'ip_address' => $host->ip_address,
                'hostname' => $host->hostname,
                'fqdn' => $host->fqdn,
                'operating_system' => $host->operating_system,
                'total_findings' => $host->total_findings,
                'severity' => collect(Severity::keys())->mapWithKeys(fn ($key) => [$key => $host->{"{$key}_count"}]),
            ]);

        // One row per plugin, like the Vulnerabilities tab in Nessus.
        $findings = $scan->vulnerabilityInstances()
            ->toBase()
            ->join('vulnerabilities', 'vulnerabilities.id', '=', 'vulnerability_instances.vulnerability_id')
            ->groupBy('vulnerabilities.id', 'vulnerabilities.plugin_id', 'vulnerabilities.name', 'vulnerabilities.family', 'vulnerabilities.cvss_score')
            ->select('vulnerabilities.plugin_id', 'vulnerabilities.name', 'vulnerabilities.family', 'vulnerabilities.cvss_score')
            ->selectRaw('max(vulnerability_instances.severity) as severity')
            ->selectRaw('count(*) as instances')
            ->selectRaw('count(distinct vulnerability_instances.scan_host_id) as hosts')
            ->orderByDesc('severity')
            ->orderByDesc('vulnerabilities.cvss_score')
            ->orderBy('vulnerabilities.name')
            ->get()
            ->map(fn (object $row) => [
                'plugin_id' => (int) $row->plugin_id,
                'name' => $row->name,
                'family' => $row->family,
                'cvss_score' => $row->cvss_score !== null ? (float) $row->cvss_score : null,
                'severity' => Severity::from((int) $row->severity)->key(),
                'instances' => (int) $row->instances,
                'hosts' => (int) $row->hosts,
            ]);

        return Inertia::render('Scans/Show', [
            'project' => $project->only('id', 'code', 'name'),
            'scan' => (new ScanResource($scan->load('nessusServer')))->resolve(),
            'hosts' => $hosts,
            'findings' => $findings,
            'can' => ['sync' => $request->user()->can('runScans', $project)],
        ]);
    }
}
