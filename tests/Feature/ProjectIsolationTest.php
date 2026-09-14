<?php

namespace Tests\Feature;

use App\Enums\ProjectRole;
use App\Models\NessusServer;
use App\Models\Project;
use App\Models\Scan;
use App\Models\ScanHost;
use App\Models\User;
use App\Models\VulnerabilityInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_project_and_becomes_its_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->create();

        $this->actingAs($admin)
            ->post(route('projects.store'), [
                'code' => 'hc',
                'name' => 'Healthcare',
                'environment' => 'lab',
                'nessus_server_ids' => [$server->id],
            ])
            ->assertSessionHasNoErrors();

        $project = Project::query()->sole();
        $this->assertSame('HC', $project->code);
        $this->assertSame(ProjectRole::Manager, $admin->roleIn($project));
        $this->assertTrue($project->nessusServers->contains($server));
        $this->assertSame('projects/HC/scans/2026', $project->storagePath('scans', '2026'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'project_id' => $project->id]);
    }

    public function test_project_codes_are_validated_and_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Project::factory()->create(['code' => 'HC']);

        $this->actingAs($admin)
            ->postJson(route('api.projects.store'), ['code' => 'hc', 'name' => 'Dup', 'environment' => 'lab'])
            ->assertJsonValidationErrors('code');

        $this->postJson(route('api.projects.store'), ['code' => '../x', 'name' => 'Bad', 'environment' => 'lab'])
            ->assertJsonValidationErrors('code');
    }

    public function test_members_cannot_create_projects(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('api.projects.store'), ['code' => 'SS', 'name' => 'SecretShare', 'environment' => 'lab'])
            ->assertForbidden();
    }

    public function test_members_only_see_their_own_projects(): void
    {
        $member = User::factory()->create();
        $mine = Project::factory()->withMember($member)->create(['name' => 'Healthcare']);
        Project::factory()->create(['name' => 'SecretShare']);

        $this->actingAs($member)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Projects/Index')
                ->has('projects', 1)
                ->where('projects.0.id', $mine->id));

        $this->getJson(route('api.projects.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_guessing_another_projects_id_is_forbidden(): void
    {
        $member = User::factory()->create();
        Project::factory()->withMember($member)->create();
        $other = Project::factory()->create();

        $this->actingAs($member);
        $this->get(route('projects.show', $other))->assertForbidden();
        $this->getJson(route('api.projects.show', $other))->assertForbidden();

        foreach (['dashboard', 'scans', 'assets', 'vulnerabilities', 'reports'] as $endpoint) {
            $this->getJson(route("api.projects.{$endpoint}", $other))->assertForbidden();
        }
    }

    public function test_findings_are_scoped_to_their_project(): void
    {
        $member = User::factory()->create();
        $healthcare = Project::factory()->withMember($member, ProjectRole::Viewer)->create();
        $secretShare = Project::factory()->withMember($member, ProjectRole::Viewer)->create();

        $hcFinding = VulnerabilityInstance::factory()->for(
            ScanHost::factory()->for(Scan::factory()->for($healthcare)),
        )->create();
        VulnerabilityInstance::factory()->for(
            ScanHost::factory()->for(Scan::factory()->for($secretShare)),
        )->create();

        $this->assertSame($healthcare->id, $hcFinding->project_id);

        $this->actingAs($member)
            ->getJson(route('api.projects.vulnerabilities', $healthcare))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hcFinding->id);

        $this->getJson(route('api.projects.dashboard', $healthcare))
            ->assertOk()
            ->assertJsonPath('data.open_vulnerabilities', 1)
            ->assertJsonPath('data.severity.medium', 1);
    }

    public function test_managers_can_edit_but_not_change_the_code_or_servers(): void
    {
        $manager = User::factory()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::Manager)->create(['code' => 'POS']);
        $server = NessusServer::factory()->create();

        $this->actingAs($manager)
            ->putJson(route('api.projects.update', $project), [
                'name' => 'KHMER POS', 'environment' => 'staging', 'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'KHMER POS');

        $this->putJson(route('api.projects.update', $project), [
            'code' => 'XYZ', 'name' => 'KHMER POS', 'environment' => 'staging', 'status' => 'active',
            'nessus_server_ids' => [$server->id],
        ])->assertJsonValidationErrors(['code', 'nessus_server_ids']);

        $this->assertSame('POS', $project->refresh()->code);
    }

    public function test_viewers_and_analysts_cannot_edit(): void
    {
        $viewer = User::factory()->create();
        $project = Project::factory()->withMember($viewer, ProjectRole::Viewer)->create();

        $this->actingAs($viewer)
            ->putJson(route('api.projects.update', $project), ['name' => 'X', 'environment' => 'lab', 'status' => 'active'])
            ->assertForbidden();
    }

    public function test_projects_with_scan_history_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $withScans = Project::factory()->create();
        Scan::factory()->for($withScans)->create();
        $empty = Project::factory()->create();

        $this->actingAs($admin)
            ->deleteJson(route('api.projects.destroy', $withScans))
            ->assertJsonValidationErrors('project');
        $this->assertModelExists($withScans);

        $this->deleteJson(route('api.projects.destroy', $empty))->assertNoContent();
        $this->assertModelMissing($empty);
    }

    public function test_dashboard_only_counts_visible_projects(): void
    {
        $member = User::factory()->create();
        Project::factory()->withMember($member)->create();
        Project::factory()->count(2)->create();

        $this->actingAs($member)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.projects', 1)
                ->where('servers', null));
    }
}
