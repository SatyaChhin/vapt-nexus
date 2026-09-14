<?php

namespace Tests\Feature;

use App\Models\NessusServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NessusConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private NessusServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->server = NessusServer::factory()->create(['base_url' => 'https://192.168.56.10:8834']);
    }

    private function fakeNessus(string $status = 'ready', int $sessionStatus = 200): void
    {
        Http::fake([
            '192.168.56.10:8834/server/status' => Http::response(['code' => 200, 'status' => $status]),
            '192.168.56.10:8834/session' => Http::response(['username' => 'admin'], $sessionStatus),
            '192.168.56.10:8834/server/properties' => Http::response([
                'nessus_type' => 'Nessus Essentials',
                'server_version' => '10.8.3',
            ]),
        ]);
    }

    public function test_successful_connection(): void
    {
        $this->fakeNessus();

        $this->actingAs($this->admin)
            ->postJson(route('api.nessus.servers.test', $this->server))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Nessus connection successful',
                'status' => 'connected',
                'version' => '10.8.3',
                'edition' => 'Nessus Essentials',
                'server' => ['id' => $this->server->id, 'status' => 'connected'],
            ])
            ->assertJsonMissingPath('server.access_key')
            ->assertDontSee($this->server->access_key)
            ->assertDontSee($this->server->secret_key);

        $this->server->refresh();
        $this->assertSame('connected', $this->server->status->value);
        $this->assertNotNull($this->server->last_connected_at);
        $this->assertSame('Nessus Essentials 10.8.3', $this->server->server_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'nessus_server.connection_tested', 'resource_id' => $this->server->id]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://192.168.56.10:8834/session'
            && $request->hasHeader('X-ApiKeys'));
    }

    public function test_invalid_credentials(): void
    {
        $this->fakeNessus(sessionStatus: 401);

        $this->actingAs($this->admin)
            ->postJson(route('api.nessus.servers.test', $this->server))
            ->assertOk()
            ->assertJson(['success' => false, 'status' => 'unauthorized']);

        $this->server->refresh();
        $this->assertSame('unauthorized', $this->server->status->value);
        $this->assertNull($this->server->last_connected_at);
        $this->assertStringContainsString('rejected the API keys', $this->server->last_error);
    }

    public function test_unreachable_server(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out after 5001 milliseconds'));

        $this->actingAs($this->admin)
            ->postJson(route('api.nessus.servers.test', $this->server))
            ->assertOk()
            ->assertJson(['success' => false, 'status' => 'failed'])
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Unable to connect to Nessus'));

        $this->assertSame('failed', $this->server->refresh()->status->value);
    }

    public function test_nessus_still_loading_plugins_is_reported_without_checking_keys(): void
    {
        $this->fakeNessus(status: 'loading');

        $this->actingAs($this->admin)
            ->postJson(route('api.nessus.servers.test', $this->server))
            ->assertOk()
            ->assertJson(['success' => false, 'status' => 'not_ready', 'nessus_status' => 'loading']);

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/session'));
    }

    public function test_only_admins_can_test_connections(): void
    {
        Http::fake();

        $this->postJson(route('api.nessus.servers.test', $this->server))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson(route('api.nessus.servers.test', $this->server))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_connection_tests_are_rate_limited(): void
    {
        $this->fakeNessus();
        $this->actingAs($this->admin);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('api.nessus.servers.test', $this->server))->assertOk();
        }

        $this->postJson(route('api.nessus.servers.test', $this->server))->assertTooManyRequests();
    }

    public function test_artisan_command_reports_the_result(): void
    {
        $this->fakeNessus();

        $this->artisan('nessus:test', ['server' => $this->server->id])
            ->expectsOutputToContain('Nessus connection successful')
            ->assertSuccessful();
    }
}
