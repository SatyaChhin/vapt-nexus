<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Enums\ReportStatus;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Report;
use App\Models\Scan;
use App\Models\User;
use App\Services\Reports\ScanReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakesNessus;
use Tests\TestCase;

class ScanReportTest extends TestCase
{
    use FakesNessus;
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private Scan $scan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('nessus');

        $this->admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->connected()->create(['base_url' => 'https://192.168.56.10:8834']);
        $this->project = Project::factory()->create(['code' => 'HC']);
        $this->project->nessusServers()->attach($server);

        $this->nessusScan(9, 'Quarterly scan');

        $this->actingAs($this->admin)
            ->postJson(route('api.projects.scans.import', $this->project), [
                'nessus_server_id' => $server->id,
                'nessus_scan_id' => 9,
            ])
            ->assertStatus(202);

        $this->scan = Scan::query()->sole();
    }

    private function number(int $sequence): string
    {
        return sprintf('VULN-HC-%s-%05d', now()->format('Y'), $sequence);
    }

    public function test_importing_a_finished_scan_generates_its_report(): void
    {
        $report = Report::query()->sole();

        $this->assertSame($this->number(1), $report->report_number);
        $this->assertSame(ReportStatus::Completed, $report->status);
        $this->assertSame($this->admin->id, $report->created_by);
        $this->assertSame("projects/HC/reports/{$report->created_at->format('Y')}/{$this->number(1)}.pdf", $report->file_path);
        $this->assertNotNull($report->generated_at);
        $this->assertStringStartsWith('%PDF', Storage::disk('nessus')->get($report->file_path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generated', 'resource_id' => $report->id, 'project_id' => $this->project->id]);
    }

    public function test_resyncing_the_same_run_does_not_add_a_report(): void
    {
        $this->postJson(route('api.projects.scans.sync', [$this->project, $this->scan]))->assertStatus(202);

        $this->assertSame(1, Report::query()->count());
    }

    public function test_reports_can_be_generated_on_demand(): void
    {
        $this->postJson(route('api.projects.scans.reports.store', [$this->project, $this->scan]))
            ->assertStatus(202)
            ->assertJsonPath('data.report_number', $this->number(2))
            ->assertJsonPath('data.created_by', $this->admin->name);

        $this->assertSame(ReportStatus::Completed, Report::query()->latest('id')->first()->status);
    }

    public function test_numbers_are_per_project(): void
    {
        $other = Project::factory()->create(['code' => 'SS']);
        $otherScan = Scan::factory()->for($other)->create(['imported_at' => now()]);

        $report = app(ScanReportService::class)->queue($otherScan, $this->admin);

        $this->assertSame(sprintf('VULN-SS-%s-00001', now()->format('Y')), $report->report_number);
    }

    public function test_viewers_can_open_reports_but_not_generate_them(): void
    {
        $viewer = User::factory()->create();
        $this->project->members()->attach($viewer, ['role' => ProjectRole::Viewer->value]);
        $report = Report::query()->sole();

        $this->actingAs($viewer)
            ->get(route('projects.reports.download', [$this->project, $report]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->postJson(route('api.projects.scans.reports.store', [$this->project, $this->scan]))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.downloaded', 'user_id' => $viewer->id]);
    }

    public function test_reports_stay_inside_their_project(): void
    {
        $report = Report::query()->sole();
        $outsider = User::factory()->create();
        $theirs = Project::factory()->withMember($outsider)->create();

        $this->actingAs($outsider);
        $this->get(route('projects.reports.download', [$this->project, $report]))->assertForbidden();
        $this->get(route('projects.reports.download', [$theirs, $report]))->assertNotFound();
    }

    public function test_unfinished_reports_cannot_be_downloaded(): void
    {
        $report = Report::query()->sole();
        $report->forceFill(['status' => ReportStatus::Generating])->save();

        $this->get(route('projects.reports.download', [$this->project, $report]))->assertNotFound();
    }

    public function test_a_scan_must_be_imported_before_reporting(): void
    {
        $empty = Scan::factory()->for($this->project)->create();

        $this->postJson(route('api.projects.scans.reports.store', [$this->project, $empty]))
            ->assertJsonValidationErrors('scan');
    }

    public function test_managers_can_delete_reports_without_freeing_the_number(): void
    {
        $manager = User::factory()->create();
        $this->project->members()->attach($manager, ['role' => ProjectRole::Manager->value]);
        $report = Report::query()->sole();
        $path = $report->file_path;

        $this->actingAs($manager)
            ->from(route('projects.show', $this->project))
            ->delete(route('projects.reports.destroy', [$this->project, $report]))
            ->assertRedirect(route('projects.show', $this->project));

        $this->assertSoftDeleted($report);
        Storage::disk('nessus')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.deleted', 'resource_id' => $report->id, 'user_id' => $manager->id]);

        $this->get(route('projects.reports.download', [$this->project, $report]))->assertNotFound();
        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->has('dashboard.recent_reports', 0)->where('can.deleteReports', true));

        // The deleted number stays reserved.
        $this->postJson(route('api.projects.scans.reports.store', [$this->project, $this->scan]))
            ->assertJsonPath('data.report_number', $this->number(2));
    }

    public function test_analysts_and_viewers_cannot_delete_reports(): void
    {
        $report = Report::query()->sole();

        foreach ([ProjectRole::Analyst, ProjectRole::Viewer] as $role) {
            $member = User::factory()->create();
            $this->project->members()->attach($member, ['role' => $role->value]);

            $this->actingAs($member)
                ->delete(route('projects.reports.destroy', [$this->project, $report]))
                ->assertForbidden();

            $this->get(route('projects.show', $this->project))
                ->assertInertia(fn (Assert $page) => $page->where('can.deleteReports', false));
        }

        $this->assertNotSoftDeleted($report);
        Storage::disk('nessus')->assertExists($report->file_path);
    }

    public function test_a_report_cannot_be_deleted_through_another_project(): void
    {
        $report = Report::query()->sole();
        $manager = User::factory()->create();
        $theirs = Project::factory()->withMember($manager, ProjectRole::Manager)->create();

        $this->actingAs($manager)
            ->delete(route('projects.reports.destroy', [$theirs, $report]))
            ->assertNotFound();

        $this->assertNotSoftDeleted($report);
    }

    public function test_a_report_deleted_while_queued_is_not_generated(): void
    {
        Queue::fake();
        $reports = app(ScanReportService::class);
        $report = $reports->queue($this->scan, $this->admin);

        $reports->delete($report, $this->admin);
        $reports->generate(Report::withTrashed()->findOrFail($report->id));

        $report = Report::withTrashed()->findOrFail($report->id);
        $this->assertSame(ReportStatus::Pending, $report->status);
        $this->assertNull($report->file_path);
        $this->assertCount(1, Storage::disk('nessus')->allFiles('projects/HC/reports'));
    }

    public function test_reports_live_on_the_project_page(): void
    {
        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.generateReports', true)
                ->where('dashboard.recent_reports.0.report_number', $this->number(1))
                ->where('dashboard.recent_reports.0.status', 'completed')
                ->where('dashboard.recent_reports.0.scan_name', 'Quarterly scan')
                // The picker offers scans whose results were imported.
                ->where('dashboard.recent_scans.0.id', $this->scan->id)
                ->whereNot('dashboard.recent_scans.0.imported_at', null));

        $this->get(route('projects.scans.show', [$this->project, $this->scan]))
            ->assertInertia(fn (Assert $page) => $page->missing('reports')->missing('can.deleteReports'));
    }

    public function test_viewers_do_not_get_the_generate_button(): void
    {
        $viewer = User::factory()->create();
        $this->project->members()->attach($viewer, ['role' => ProjectRole::Viewer->value]);

        $this->actingAs($viewer)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('can.generateReports', false));
    }
}
