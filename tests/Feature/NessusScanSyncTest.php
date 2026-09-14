<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Enums\ScanStatus;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Report;
use App\Models\Scan;
use App\Services\Nessus\NessusScanSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesNessus;
use Tests\TestCase;

class NessusScanSyncTest extends TestCase
{
    use FakesNessus;
    use RefreshDatabase;

    private NessusServer $server;

    private Project $pos;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('nessus');

        $this->server = NessusServer::factory()->connected()->create(['base_url' => 'https://192.168.56.10:8834']);
        $this->pos = Project::factory()->create(['code' => 'POS', 'name' => 'KHMER POS']);
        $this->pos->nessusServers()->attach($this->server);
    }

    public function test_scans_named_with_a_project_code_are_imported_with_a_report(): void
    {
        $this->nessusScan(27, 'VA_POS');
        $this->nessusScan(28, 'Weekly POSTGRES check');
        $this->nessusScan(13, 'VA_IP_POS', 'canceled', ['folder_id' => 2]);
        $this->nessusScan(30, 'Unrelated scan');

        $this->artisan('nessus:sync')
            ->expectsOutputToContain('VA_POS (#27) → POS')
            ->assertSuccessful();

        $scan = Scan::query()->sole();
        $this->assertSame($this->pos->id, $scan->project_id);
        $this->assertSame(27, $scan->nessus_scan_id);
        $this->assertSame(ScanStatus::Imported, $scan->status);
        $this->assertSame('run-27-1', $scan->nessus_run_uuid);
        $this->assertNull($scan->created_by);
        $this->assertSame(3, $scan->total_findings);

        $report = Report::query()->sole();
        $this->assertSame(ReportStatus::Completed, $report->status);
        $this->assertSame($scan->id, $report->scan_id);
        $this->assertSame('run-27-1', $report->nessus_run_uuid);
        $this->assertNull($report->created_by);
        $this->assertSame('VULN-POS-'.now()->format('Y').'-00001', $report->report_number);
        $this->assertStringStartsWith('%PDF', Storage::disk('nessus')->get($report->file_path));
    }

    public function test_unchanged_scans_are_left_alone(): void
    {
        $this->nessusScan(27, 'VA_POS');
        $this->artisan('nessus:sync');

        $this->artisan('nessus:sync')->expectsOutputToContain('Nothing new in Nessus.')->assertSuccessful();

        $this->assertSame(1, Scan::query()->count());
        $this->assertSame(1, Report::query()->count());
        $this->assertDatabaseCount('raw_scan_results', 1);
    }

    public function test_a_running_scan_gets_its_report_when_it_finishes(): void
    {
        $this->nessusScan(27, 'VA_POS', 'running');
        $this->artisan('nessus:sync');

        $scan = Scan::query()->sole();
        $this->assertSame(ScanStatus::Running, $scan->status);
        $this->assertSame(0, Report::query()->count(), 'Partial results get no automatic report.');

        $this->nessusScans[27]['status'] = 'completed';
        $this->artisan('nessus:sync')->expectsOutputToContain('Re-synced')->assertSuccessful();

        $this->assertSame(ScanStatus::Imported, $scan->refresh()->status);
        $this->assertSame(ReportStatus::Completed, Report::query()->sole()->status);
    }

    public function test_a_new_run_in_nessus_is_followed_and_reported(): void
    {
        $this->nessusScan(27, 'VA_POS');
        $this->artisan('nessus:sync');
        $scan = Scan::query()->sole();

        // Launched again: while it runs, the previous results stay visible.
        $this->nessusScans[27] = [...$this->nessusScans[27], 'status' => 'running', 'uuid' => 'run-27-2'];
        $this->artisan('nessus:sync')->expectsOutputToContain('Running');
        $this->assertSame(ScanStatus::Running, $scan->refresh()->status);
        $this->assertSame(3, $scan->total_findings);

        $this->nessusScans[27]['status'] = 'completed';
        unset($this->nessusScans[27]['plugins'][57582]);
        $this->artisan('nessus:sync');

        $scan->refresh();
        $this->assertSame(ScanStatus::Imported, $scan->status);
        $this->assertSame('run-27-2', $scan->nessus_run_uuid);
        $this->assertSame(2, $scan->total_findings);
        $this->assertSame(
            ['VULN-POS-'.now()->format('Y').'-00001', 'VULN-POS-'.now()->format('Y').'-00002'],
            Report::query()->orderBy('id')->pluck('report_number')->all(),
        );
        $this->assertSame('run-27-2', Report::query()->latest('id')->first()->nessus_run_uuid);
    }

    public function test_ambiguous_names_and_archived_projects_are_skipped(): void
    {
        $ip = Project::factory()->create(['code' => 'IP']);
        $ip->nessusServers()->attach($this->server);
        $old = Project::factory()->create(['code' => 'OLD', 'status' => ProjectStatus::Archived]);
        $old->nessusServers()->attach($this->server);
        $this->nessusScan(9, 'VA_IP_POS');
        $this->nessusScan(10, 'VA_OLD');

        $this->artisan('nessus:sync')
            ->expectsOutputToContain('matches several projects (POS, IP)')
            ->assertSuccessful();

        $this->assertSame(0, Scan::query()->count());
    }

    public function test_projects_only_receive_scans_from_their_own_servers(): void
    {
        $other = NessusServer::factory()->create(['base_url' => 'https://192.168.56.20:8834']);
        $this->pos->nessusServers()->detach($this->server);
        $this->pos->nessusServers()->attach($other);
        $this->nessusScan(27, 'VA_POS');

        $this->artisan('nessus:sync');

        // Both fake servers list VA_POS, but only the assigned one imports it.
        $this->assertSame([$other->id], Scan::query()->pluck('nessus_server_id')->all());
    }

    public function test_an_unreachable_server_is_reported(): void
    {
        $this->nessusScan(27, 'VA_POS');
        $this->nessusDown = true;

        $this->artisan('nessus:sync')
            ->expectsOutputToContain('rejected the API keys')
            ->assertFailed();

        $this->assertSame(0, Scan::query()->count());
    }

    public function test_project_codes_match_whole_words_only(): void
    {
        $sync = app(NessusScanSync::class);
        $projects = collect([$this->pos]);

        foreach (['VA_POS', 'pos-web', 'Scan POS 2026', 'POS'] as $name) {
            $this->assertCount(1, $sync->matchProjects($name, $projects), $name);
        }

        foreach (['POSTGRES', 'VA_POSX', 'EPOS', ''] as $name) {
            $this->assertCount(0, $sync->matchProjects($name, $projects), $name);
        }
    }

    public function test_the_sync_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'nessus:sync'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
