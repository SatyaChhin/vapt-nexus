<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the read-only Nessus API for any number of scans, each with one
 * host (192.168.56.30, host_id 2). Change $nessusScans during a test to
 * simulate a scan finishing or being run again.
 */
trait FakesNessus
{
    /** @var array<int, array{name: string, status: string, folder_id: int, uuid: string, plugins: array<int, array{name: string, severity: int, ports: list<string>}>}> */
    protected array $nessusScans = [];

    protected bool $nessusDown = false;

    private bool $nessusFaked = false;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function nessusScan(int $id, string $name, string $status = 'completed', array $overrides = []): void
    {
        $this->nessusScans[$id] = array_merge([
            'name' => $name,
            'status' => $status,
            'folder_id' => 3,
            'uuid' => "run-{$id}-1",
            'plugins' => [
                20007 => ['name' => 'SSL Version 2 and 3 Protocol Detection', 'severity' => 4, 'ports' => ['443 / tcp / www']],
                57582 => ['name' => 'SSL Self-Signed Certificate', 'severity' => 2, 'ports' => ['443 / tcp / www']],
                19506 => ['name' => 'Nessus Scan Information', 'severity' => 0, 'ports' => ['0 / tcp / ']],
            ],
        ], $overrides);

        $this->fakeNessusApi();
    }

    protected function fakeNessusApi(): void
    {
        if ($this->nessusFaked) {
            return;
        }

        $this->nessusFaked = true;

        Http::fake(function (Request $request) {
            if ($this->nessusDown) {
                return Http::response(['error' => 'Invalid credentials'], 401);
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/scans') {
                return Http::response([
                    'folders' => [
                        ['id' => 2, 'type' => 'trash', 'name' => 'Trash'],
                        ['id' => 3, 'type' => 'main', 'name' => 'My Scans'],
                    ],
                    'scans' => collect($this->nessusScans)->map(fn (array $scan, int $id) => [
                        'id' => $id,
                        'uuid' => $scan['uuid'],
                        'name' => $scan['name'],
                        'status' => $scan['status'],
                        'folder_id' => $scan['folder_id'],
                        'last_modification_date' => 1788671241,
                    ])->values()->all(),
                ]);
            }

            if (! preg_match('#^/scans/(\d+)(?:/hosts/2(?:/plugins/(\d+))?)?$#', $path, $m) || ! isset($this->nessusScans[(int) $m[1]])) {
                return Http::response(['error' => 'The requested file was not found.'], 404);
            }

            $scan = $this->nessusScans[(int) $m[1]];

            return match (true) {
                isset($m[2]) => $this->nessusPlugin($scan['plugins'][(int) $m[2]], (int) $m[2]),
                str_ends_with($path, '/hosts/2') => Http::response([
                    'info' => ['host-ip' => '192.168.56.30', 'operating-system' => 'Linux Kernel 6.x on Debian'],
                    'vulnerabilities' => collect($scan['plugins'])->map(fn (array $plugin, int $id) => [
                        'plugin_id' => $id,
                        'plugin_name' => $plugin['name'],
                        'severity' => $plugin['severity'],
                        'count' => count($plugin['ports']),
                    ])->values()->all(),
                ]),
                default => Http::response([
                    'info' => [
                        'name' => $scan['name'],
                        'uuid' => $scan['uuid'],
                        'targets' => '192.168.56.30',
                        'status' => $scan['status'],
                        'hostcount' => 1,
                        'scan_start' => 1788668318,
                        'scan_end' => $scan['status'] === 'completed' ? 1788671241 : null,
                    ],
                    'hosts' => [['host_id' => 2, 'hostname' => '192.168.56.30', 'scanprogresscurrent' => 100, 'scanprogresstotal' => 100]],
                ]),
            };
        });
    }

    /**
     * @param  array{name: string, severity: int, ports: list<string>}  $plugin
     */
    private function nessusPlugin(array $plugin, int $id): mixed
    {
        return Http::response([
            'outputs' => [[
                'plugin_output' => "Output of plugin {$id}",
                'ports' => array_fill_keys($plugin['ports'], [['hostname' => '192.168.56.30']]),
                'severity' => $plugin['severity'],
            ]],
            'info' => ['plugindescription' => [
                'severity' => $plugin['severity'],
                'pluginname' => $plugin['name'],
                'pluginfamily' => 'General',
                'pluginattributes' => [
                    'synopsis' => "Synopsis of {$plugin['name']}.",
                    'description' => "Description of {$plugin['name']}.",
                    'solution' => 'Apply the vendor fix.',
                    'risk_information' => ['cvss3_base_score' => '6.5', 'cvss3_vector' => 'CVSS:3.0/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N'],
                ],
            ]],
        ]);
    }
}
