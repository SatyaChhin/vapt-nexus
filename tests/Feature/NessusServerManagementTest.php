<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NessusServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NessusServerManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS = 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0a1b2';

    private const SECRET = 'f0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Local Nessus',
            'base_url' => 'https://192.168.56.10:8834/',
            'access_key' => self::ACCESS,
            'secret_key' => self::SECRET,
            'verify_ssl' => false,
            ...$overrides,
        ];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('nessus-servers.index'))->assertRedirect(route('login'));
    }

    public function test_members_cannot_manage_nessus_servers(): void
    {
        $member = User::factory()->create();
        $server = NessusServer::factory()->create();

        $this->actingAs($member);
        $this->get(route('nessus-servers.index'))->assertForbidden();
        $this->post(route('nessus-servers.store'), $this->payload())->assertForbidden();
        $this->getJson(route('api.nessus.servers.show', $server))->assertForbidden();
        $this->deleteJson(route('api.nessus.servers.destroy', $server))->assertForbidden();

        $this->assertDatabaseCount('nessus_servers', 1);
    }

    public function test_admin_adds_a_server_with_encrypted_credentials(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('nessus-servers.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $server = NessusServer::query()->sole();
        $this->assertSame('https://192.168.56.10:8834', $server->base_url);
        $this->assertSame(self::ACCESS, $server->access_key);
        $this->assertSame(self::SECRET, $server->secret_key);

        $raw = DB::table('nessus_servers')->first();
        $this->assertNotSame(self::ACCESS, $raw->access_key);
        $this->assertNotSame(self::SECRET, $raw->secret_key);
        $this->assertStringNotContainsString(self::ACCESS, $raw->access_key);

        $log = AuditLog::query()->where('action', 'nessus_server.created')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString(self::ACCESS, json_encode($log->metadata));
    }

    public function test_api_keys_are_never_sent_to_the_browser(): void
    {
        $admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->create(['access_key' => self::ACCESS, 'secret_key' => self::SECRET]);

        $this->actingAs($admin)
            ->get(route('nessus-servers.index'))
            ->assertOk()
            ->assertDontSee(self::ACCESS)
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page
                ->component('NessusServers/Index')
                ->has('servers', 1)
                ->where('servers.0.credentials_configured', true)
                ->missing('servers.0.access_key')
                ->missing('servers.0.secret_key'));

        $this->get(route('nessus-servers.edit', $server))
            ->assertOk()
            ->assertDontSee(self::ACCESS)
            ->assertDontSee(self::SECRET);

        $this->getJson(route('api.nessus.servers.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.access_key')
            ->assertDontSee(self::SECRET);
    }

    public function test_updating_without_keys_keeps_the_stored_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->connected()->create(['access_key' => self::ACCESS, 'secret_key' => self::SECRET]);

        $this->actingAs($admin)
            ->put(route('nessus-servers.update', $server), $this->payload([
                'name' => 'Kali Nessus',
                'access_key' => '',
                'secret_key' => '',
            ]))
            ->assertRedirect(route('nessus-servers.index'))
            ->assertSessionHasNoErrors();

        $server->refresh();
        $this->assertSame('Kali Nessus', $server->name);
        $this->assertSame(self::ACCESS, $server->access_key);
        $this->assertSame(self::SECRET, $server->secret_key);
    }

    public function test_rotating_keys_resets_the_connection_status(): void
    {
        $admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->connected()->create();

        $this->actingAs($admin)->putJson(route('api.nessus.servers.update', $server), $this->payload())->assertOk();

        $this->assertSame('unknown', $server->refresh()->status->value);
        $this->assertSame(self::ACCESS, $server->access_key);
        $this->assertTrue(AuditLog::query()->where('action', 'nessus_server.updated')->sole()->metadata['credentials_rotated']);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        config(['nessus.allowed_networks' => ['192.168.56.0/24']]);

        $this->actingAs($admin)
            ->postJson(route('api.nessus.servers.store'), $this->payload([
                'base_url' => 'https://10.1.1.1:8834',
                'access_key' => self::ACCESS."\r\nX-Injected: 1",
                'secret_key' => 'short',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_url', 'access_key', 'secret_key']);

        $this->assertDatabaseCount('nessus_servers', 0);
    }

    public function test_admin_can_delete_a_server(): void
    {
        $admin = User::factory()->admin()->create();
        $server = NessusServer::factory()->create();

        $this->actingAs($admin)
            ->delete(route('nessus-servers.destroy', $server))
            ->assertRedirect(route('nessus-servers.index'));

        $this->assertModelMissing($server);
        $this->assertDatabaseHas('audit_logs', ['action' => 'nessus_server.deleted', 'resource_id' => $server->id]);
    }
}
