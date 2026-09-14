<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanHost;
use App\Models\Vulnerability;
use App\Models\VulnerabilityInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Scan result details. Scoped bindings keep the scan inside its project.
 */
class ScanController extends Controller
{
    /**
     * Plugin details and every host/port it was found on in this scan.
     */
    public function plugin(Project $project, Scan $scan, int $plugin): JsonResponse
    {
        Gate::authorize('view', $project);

        $vulnerability = Vulnerability::query()->where('plugin_id', $plugin)->firstOrFail();

        $instances = $scan->vulnerabilityInstances()
            ->where('vulnerability_id', $vulnerability->id)
            ->with('scanHost:id,ip_address,hostname')
            ->orderBy(
                ScanHost::query()->select('ip_address')->whereColumn('scan_hosts.id', 'vulnerability_instances.scan_host_id'),
            )
            ->orderBy('port')
            ->get();

        abort_if($instances->isEmpty(), 404);

        return response()->json(['data' => [
            'plugin_id' => $vulnerability->plugin_id,
            'name' => $vulnerability->name,
            'family' => $vulnerability->family,
            'severity' => $vulnerability->severity->key(),
            'synopsis' => $vulnerability->synopsis,
            'description' => $vulnerability->description,
            'solution' => $vulnerability->solution,
            'see_also' => $this->split($vulnerability->see_also, "\n"),
            'cve' => $this->split($vulnerability->cve, ','),
            'cvss_score' => $vulnerability->cvss_score !== null ? (float) $vulnerability->cvss_score : null,
            'cvss_vector' => $vulnerability->cvss_vector,
            'cvss_version' => $vulnerability->cvss_version,
            'instances' => $instances->map(fn (VulnerabilityInstance $instance) => [
                'id' => $instance->id,
                'ip_address' => $instance->scanHost?->ip_address,
                'hostname' => $instance->scanHost?->hostname,
                'port' => $instance->port,
                'protocol' => $instance->protocol,
                'service' => $instance->service,
                'severity' => $instance->severity->key(),
                'state' => $instance->state->value,
                'plugin_output' => $instance->plugin_output,
            ]),
        ]]);
    }

    /**
     * @return list<string>
     */
    private function split(?string $value, string $separator): array
    {
        return array_values(array_filter(array_map('trim', explode($separator, (string) $value))));
    }
}
