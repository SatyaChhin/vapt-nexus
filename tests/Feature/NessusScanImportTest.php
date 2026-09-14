<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\ScanStatus;
use App\Enums\VulnerabilityState;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Responses mirror what Nessus Essentials 10.x returns for GET /scans,
 * /scans/{id}, /scans/{id}/hosts/{host_id} and .../plugins/{plugin_id}.
 */
class NessusScanImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private NessusServer $server;

    private Project $project;

    /** @var array<int, array{name: string, severity: int, ports: list<string>, output?: string, cve?: list<string>}> */
    private array $plugins = [];

    private string $status = 'completed';

    private ?int $hostStatus = null;

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('nessus');

        $this->admin = User::factory()->admin()->create();
        $this->server = NessusServer::factory()->connected()->create(['base_url' => 'https://192.168.56.10:8834']);
        $this->project = Project::factory()->create(['code' => 'HC']);
        $this->project->nessusServers()->attach($this->server);
    }

    /**
     * Can be called again within a test to change what Nessus returns.
     *
     * @param  array<int, array{name: string, severity: int, ports: list<string>, output?: string, cve?: list<string>}>  $plugins
     */
    private function fakeNessus(array $plugins = [], string $status = 'completed', ?int $hostStatus = null): void
    {
        $this->plugins = $plugins ?: [
            20007 => ['name' => 'SSL Version 2 and 3 Protocol Detection', 'severity' => 4, 'ports' => ['25 / tcp / smtp', '443 / tcp / www'], 'output' => 'SSLv2 is enabled'],
            42873 => ['name' => 'SSL Medium Strength Cipher Suites Supported (SWEET32)', 'severity' => 3, 'ports' => ['25 / tcp / smtp'], 'cve' => ['CVE-2016-2183']],
            19506 => ['name' => 'Nessus Scan Information', 'severity' => 0, 'ports' => ['0 / tcp / ']],
        ];
        $this->status = $status;
        $this->hostStatus = $hostStatus;

        if ($this->faked) {
            return;
        }

        $this->faked = true;

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            [$plugins, $status, $hostStatus] = [$this->plugins, $this->status, $this->hostStatus];

            return match (true) {
                $path === '/scans' => Http::response([
                    'folders' => [
                        ['id' => 2, 'type' => 'trash', 'name' => 'Trash'],
                        ['id' => 3, 'type' => 'main', 'name' => 'My Scans'],
                    ],
                    'scans' => [
                        ['id' => 6, 'folder_id' => 2, 'name' => 'Old lab scan', 'status' => 'completed', 'last_modification_date' => 1788600000],
                        ['id' => 9, 'folder_id' => 3, 'name' => 'VA_IP', 'status' => $status, 'last_modification_date' => 1788671241],
                    ],
                ]),
                $path === '/scans/9' => Http::response([
                    'info' => [
                        'name' => 'VA_IP',
                        'targets' => '192.168.56.30',
                        'status' => $status,
                        'hostcount' => 1,
                        'scan_start' => 1788668318,
                        'scan_end' => 1788671241,
                    ],
                    'hosts' => [[
                        'host_id' => 2,
                        'hostname' => '192.168.56.30',
                        'scanprogresscurrent' => 100,
                        'scanprogresstotal' => 100,
                    ]],
                ]),
                $path === '/scans/9/hosts/2' => $hostStatus
                    ? Http::response(['error' => 'Internal error'], $hostStatus)
                    : Http::response([
                        'info' => [
                            'host-ip' => '192.168.56.30',
                            'host-fqdn' => 'target.lab',
                            'operating-system' => 'Linux Kernel 6.x on Debian',
                        ],
                        'vulnerabilities' => collect($plugins)->map(fn ($plugin, $id) => [
                            'plugin_id' => $id,
                            'plugin_name' => $plugin['name'],
                            'severity' => $plugin['severity'],
                            'count' => count($plugin['ports']),
                        ])->values()->all(),
                    ]),
                str_starts_with($path, '/scans/9/hosts/2/plugins/') => $this->pluginResponse(
                    $plugins[(int) basename($path)],
                    (int) basename($path),
                ),
                default => Http::response(['error' => 'Not found'], 404),
            };
        });
    }

    /**
     * @param  array{name: string, severity: int, ports: list<string>, output?: string, cve?: list<string>}  $plugin
     */
    private function pluginResponse(array $plugin, int $id): mixed
    {
        return Http::response([
            'outputs' => [[
                'plugin_output' => $plugin['output'] ?? null,
                'ports' => array_fill_keys($plugin['ports'], [['hostname' => '192.168.56.30']]),
                // CVSS v2 rating; the importer must use the plugin severity instead.
                'severity' => max(0, $plugin['severity'] - 1),
            ]],
            'info' => ['plugindescription' => [
                'severity' => $plugin['severity'],
                'pluginname' => $plugin['name'],
                'pluginfamily' => 'Service detection',
                'pluginid' => (string) $id,
                'pluginattributes' => [
                    'synopsis' => 'Synopsis of '.$plugin['name'],
                    'description' => 'Description of '.$plugin['name'],
                    'solution' => 'Upgrade.',
                    'see_also' => ['https://example.com/advisory'],
                    'risk_information' => [
                        'risk_factor' => 'High',
                        'cvss3_base_score' => '7.5',
                        'cvss3_vector' => 'CVSS:3.0/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N',
                        'cvss_base_score' => '5.0',
                    ],
                    'ref_information' => isset($plugin['cve'])
                        ? ['ref' => [['name' => 'cve', 'values' => ['value' => $plugin['cve']]]]]
                        : null,
                ],
            ]],
        ]);
    }

    private function import(): Scan
    {
        $this->actingAs($this->admin)
            ->postJson(route('api.projects.scans.import', $this->project), [
                'nessus_server_id' => $this->server->id,
                'nessus_scan_id' => 9,
            ])
            ->assertStatus(202);

        return Scan::query()->sole();
    }

    public function test_lists_nessus_scans_with_their_import_state(): void
    {
        $this->fakeNessus();

        $this->actingAs($this->admin)
            ->getJson(route('api.projects.nessus-scans', $this->project))
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->server->id)
            ->assertJsonPath('data.0.error', null)
            ->assertJsonPath('data.0.scans.0.id', 9)
            ->assertJsonPath('data.0.scans.0.folder', 'My Scans')
            ->assertJsonPath('data.0.scans.0.scan_id', null)
            ->assertJsonPath('data.0.scans.1.id', 6)
            ->assertJsonPath('data.0.scans.1.in_trash', true);
    }

    public function test_unreachable_server_is_reported_in_the_list(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid credentials'], 401)]);

        $this->actingAs($this->admin)
            ->getJson(route('api.projects.nessus-scans', $this->project))
            ->assertOk()
            ->assertJsonPath('data.0.scans', [])
            ->assertJsonPath('data.0.error', fn (string $error) => str_contains($error, 'rejected the API keys'));
    }

    public function test_imports_hosts_assets_and_findings(): void
    {
        $this->fakeNessus();

        $scan = $this->import();

        $this->assertSame(ScanStatus::Imported, $scan->status);
        $this->assertSame('VA_IP', $scan->name);
        $this->assertSame('192.168.56.30', $scan->targets);
        $this->assertSame(9, $scan->nessus_scan_id);
        $this->assertSame($this->admin->id, $scan->created_by);
        $this->assertSame(2923, $scan->duration_seconds);
        $this->assertSame(1, $scan->scanned_hosts);
        // 20007 on two ports, 42873 and 19506 on one each.
        $this->assertSame(4, $scan->total_findings);
        $this->assertSame([2, 1, 0, 0, 1], [$scan->critical_count, $scan->high_count, $scan->medium_count, $scan->low_count, $scan->info_count]);

        $host = $scan->hosts()->sole();
        $this->assertSame('192.168.56.30', $host->ip_address);
        $this->assertSame('target.lab', $host->fqdn);
        $this->assertSame('Linux Kernel 6.x on Debian', $host->operating_system);
        $this->assertSame(2, $host->critical_count);

        $asset = $this->project->assets()->sole();
        $this->assertSame($asset->id, $host->asset_id);

        $sweet32 = Vulnerability::query()->where('plugin_id', 42873)->sole();
        $this->assertSame('CVE-2016-2183', $sweet32->cve);
        $this->assertSame('7.5', $sweet32->cvss_score);
        $this->assertSame('3.0', $sweet32->cvss_version);

        $ssl = VulnerabilityInstance::query()
            ->whereRelation('vulnerability', 'plugin_id', 20007)
            ->orderBy('port')
            ->get();
        $this->assertSame([25, 443], $ssl->pluck('port')->all());
        $this->assertSame('smtp', $ssl[0]->service);
        $this->assertSame(4, $ssl[0]->severity->value, 'Uses the plugin severity, not the CVSS v2 output severity.');
        $this->assertSame('SSLv2 is enabled', $ssl[0]->plugin_output);
        $this->assertSame($this->project->id, $ssl[0]->project_id);

        $raw = $scan->rawResults()->sole();
        Storage::disk('nessus')->assertExists($raw->file_path);
        $this->assertStringStartsWith('projects/HC/scans/', $raw->file_path);

        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.import_queued', 'resource_id' => $scan->id, 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.imported', 'resource_id' => $scan->id, 'project_id' => $this->project->id]);

        // Read-only: nothing that Nessus Essentials would reject.
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_resync_keeps_triage_state_and_drops_findings_that_are_gone(): void
    {
        $this->fakeNessus();
        $scan = $this->import();

        $triaged = VulnerabilityInstance::query()->whereRelation('vulnerability', 'plugin_id', 42873)->sole();
        $triaged->update(['state' => VulnerabilityState::FalsePositive]);
        $firstFound = $triaged->first_found_at;

        $this->fakeNessus([
            42873 => ['name' => 'SSL Medium Strength Cipher Suites Supported (SWEET32)', 'severity' => 3, 'ports' => ['25 / tcp / smtp']],
        ]);

        $this->postJson(route('api.projects.scans.sync', [$this->project, $scan]))->assertStatus(202);

        $scan->refresh();
        $this->assertSame(1, $scan->total_findings);
        $this->assertSame(1, $scan->high_count);
        $this->assertSame(0, $scan->critical_count);

        $triaged->refresh();
        $this->assertSame(VulnerabilityState::FalsePositive, $triaged->state);
        $this->assertTrue($firstFound->equalTo($triaged->first_found_at));
        $this->assertSame(1, VulnerabilityInstance::query()->count());
        $this->assertSame(1, Scan::query()->count());
    }

    public function test_a_nessus_scan_belongs_to_one_project(): void
    {
        $this->fakeNessus();
        $this->import();

        $other = Project::factory()->create();
        $other->nessusServers()->attach($this->server);

        $this->postJson(route('api.projects.scans.import', $other), [
            'nessus_server_id' => $this->server->id,
            'nessus_scan_id' => 9,
        ])->assertJsonValidationErrors(['nessus_scan_id' => 'another project']);

        $this->getJson(route('api.projects.nessus-scans', $other))
            ->assertJsonPath('data.0.scans.0.imported_elsewhere', true)
            ->assertJsonPath('data.0.scans.0.scan_id', null);
    }

    public function test_server_must_be_assigned_to_the_project(): void
    {
        $unassigned = NessusServer::factory()->create();

        $this->actingAs($this->admin)
            ->postJson(route('api.projects.scans.import', $this->project), [
                'nessus_server_id' => $unassigned->id,
                'nessus_scan_id' => 9,
            ])
            ->assertJsonValidationErrors('nessus_server_id');

        $this->assertSame(0, Scan::query()->count());
    }

    public function test_scans_that_never_ran_or_do_not_exist_are_rejected(): void
    {
        $this->fakeNessus(status: 'empty');

        $this->actingAs($this->admin)
            ->postJson(route('api.projects.scans.import', $this->project), ['nessus_server_id' => $this->server->id, 'nessus_scan_id' => 9])
            ->assertJsonValidationErrors(['nessus_scan_id' => 'never been run']);

        $this->postJson(route('api.projects.scans.import', $this->project), ['nessus_server_id' => $this->server->id, 'nessus_scan_id' => 404])
            ->assertJsonValidationErrors(['nessus_scan_id' => 'does not exist']);

        $this->assertSame(0, Scan::query()->count());
    }

    public function test_nessus_errors_during_import_mark_the_scan_failed(): void
    {
        $this->fakeNessus(hostStatus: 500);

        $scan = $this->import();

        $this->assertSame(ScanStatus::Failed, $scan->status);
        $this->assertStringContainsString('HTTP 500', $scan->error_message);
        $this->assertSame(0, $scan->hosts()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.import_failed', 'resource_id' => $scan->id]);
    }

    public function test_running_scans_are_imported_as_partial_results(): void
    {
        $this->fakeNessus(status: 'running');

        $scan = $this->import();

        $this->assertSame(ScanStatus::Running, $scan->status);
        $this->assertStringContainsString('partial', $scan->error_message);
        $this->assertSame(4, $scan->total_findings);
    }

    public function test_viewers_can_see_results_but_not_import(): void
    {
        $this->fakeNessus();
        $scan = $this->import();

        $viewer = User::factory()->create();
        $this->project->members()->attach($viewer, ['role' => ProjectRole::Viewer->value]);

        $this->actingAs($viewer)
            ->get(route('projects.scans.show', [$this->project, $scan]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scans/Show')
                ->where('scan.id', $scan->id)
                ->where('scan.severity.critical', 2)
                ->has('hosts', 1)
                ->has('findings', 3)
                ->where('findings.0.plugin_id', 20007)
                ->where('findings.0.severity', 'critical')
                ->where('findings.0.instances', 2)
                ->where('can.sync', false));

        $this->getJson(route('api.projects.nessus-scans', $this->project))->assertForbidden();
        $this->postJson(route('api.projects.scans.import', $this->project), ['nessus_server_id' => $this->server->id, 'nessus_scan_id' => 9])
            ->assertForbidden();
        $this->postJson(route('api.projects.scans.sync', [$this->project, $scan]))->assertForbidden();
    }

    public function test_scan_results_stay_inside_their_project(): void
    {
        $this->fakeNessus();
        $scan = $this->import();

        $outsider = User::factory()->create();
        $theirs = Project::factory()->withMember($outsider)->create();

        $this->actingAs($outsider);
        $this->get(route('projects.scans.show', [$this->project, $scan]))->assertForbidden();
        // Scoped binding: the scan is not found through a project it does not belong to.
        $this->get(route('projects.scans.show', [$theirs, $scan]))->assertNotFound();
        $this->getJson(route('api.projects.scans.plugin', [$theirs, $scan, 20007]))->assertNotFound();
        $this->getJson(route('api.projects.scans.plugin', [$this->project, $scan, 20007]))->assertForbidden();
    }

    public function test_plugin_details_list_every_affected_port(): void
    {
        $this->fakeNessus();
        $scan = $this->import();

        $this->getJson(route('api.projects.scans.plugin', [$this->project, $scan, 42873]))
            ->assertOk()
            ->assertJsonPath('data.name', 'SSL Medium Strength Cipher Suites Supported (SWEET32)')
            ->assertJsonPath('data.severity', 'high')
            ->assertJsonPath('data.cve', ['CVE-2016-2183'])
            ->assertJsonPath('data.see_also', ['https://example.com/advisory'])
            ->assertJsonPath('data.instances.0.ip_address', '192.168.56.30')
            ->assertJsonPath('data.instances.0.port', 25)
            ->assertJsonPath('data.instances.0.service', 'smtp');

        $this->getJson(route('api.projects.scans.plugin', [$this->project, $scan, 99999]))->assertNotFound();
    }

    public function test_project_page_links_imported_scans(): void
    {
        $this->fakeNessus();
        $scan = $this->import();

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Show')
                ->where('can.importScans', true)
                ->where('dashboard.recent_scans.0.id', $scan->id)
                ->where('dashboard.recent_scans.0.status', 'imported')
                ->where('dashboard.open_vulnerabilities', 4)
                ->where('dashboard.severity.critical', 2));
    }
}
