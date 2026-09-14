<?php

namespace App\Services\Nessus;

use App\Enums\NessusServerStatus;
use App\Models\NessusServer;
use App\Services\AuditLogger;
use App\Services\Nessus\Exceptions\NessusAuthenticationException;
use App\Services\Nessus\Exceptions\NessusConnectionException;
use App\Services\Nessus\Exceptions\NessusRequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NessusServerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, base_url: string, access_key: string, secret_key: string, verify_ssl: bool}  $data
     */
    public function create(array $data): NessusServer
    {
        $server = NessusServer::create($data);

        $this->audit->log('nessus_server.created', $server, metadata: [
            'name' => $server->name,
            'base_url' => $server->base_url,
            'verify_ssl' => $server->verify_ssl,
        ]);

        return $server;
    }

    /**
     * Blank access/secret keys keep the stored ones, so the edit form never
     * has to send existing credentials back to the browser.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(NessusServer $server, array $data): NessusServer
    {
        $data = array_filter(
            $data,
            fn ($value, $key) => ! in_array($key, ['access_key', 'secret_key'], true) || filled($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $server->fill($data);
        $changed = array_keys($server->getDirty());

        // A different endpoint or new keys invalidate the last test result.
        if (array_intersect($changed, ['base_url', 'access_key', 'secret_key', 'verify_ssl']) !== []) {
            $server->forceFill([
                'status' => NessusServerStatus::Unknown,
                'server_version' => null,
                'last_error' => null,
            ]);
        }

        $server->save();

        $this->audit->log('nessus_server.updated', $server, metadata: [
            'changed' => array_values(array_diff($changed, ['access_key', 'secret_key'])),
            'credentials_rotated' => array_intersect($changed, ['access_key', 'secret_key']) !== [],
        ]);

        return $server;
    }

    public function delete(NessusServer $server): void
    {
        $this->audit->log('nessus_server.deleted', $server, metadata: [
            'name' => $server->name,
            'base_url' => $server->base_url,
        ]);

        $server->delete();
    }

    /**
     * Checks reachability (GET /server/status), then the API keys (GET /session),
     * and records the outcome on the server.
     */
    public function testConnection(NessusServer $server): ConnectionResult
    {
        $client = NessusClient::forServer($server);
        $started = hrtime(true);

        try {
            $status = $client->serverStatus();
            $nessusStatus = is_string($status['status'] ?? null) ? $status['status'] : null;

            if ($nessusStatus !== 'ready') {
                $result = new ConnectionResult(
                    success: false,
                    status: NessusServerStatus::NotReady,
                    message: sprintf(
                        'Nessus is reachable but not ready (status: %s). It may still be loading plugins or need activation. The API keys are checked once it is ready.',
                        $nessusStatus ?? 'unknown',
                    ),
                    nessusStatus: $nessusStatus,
                );
            } else {
                $client->session();
                $properties = rescue(fn () => $client->serverProperties(), [], report: false);

                $result = new ConnectionResult(
                    success: true,
                    status: NessusServerStatus::Connected,
                    message: 'Nessus connection successful',
                    nessusStatus: $nessusStatus,
                    version: $this->stringOrNull(Arr::get($properties, 'server_version') ?? Arr::get($properties, 'nessus_ui_version')),
                    edition: $this->stringOrNull(Arr::get($properties, 'nessus_type')),
                );
            }
        } catch (NessusAuthenticationException $e) {
            $result = new ConnectionResult(false, NessusServerStatus::Unauthorized, $e->getMessage(), nessusStatus: 'ready');
        } catch (NessusConnectionException $e) {
            $result = new ConnectionResult(false, NessusServerStatus::Failed, $e->getMessage());
        } catch (NessusRequestException $e) {
            $result = new ConnectionResult(false, NessusServerStatus::Failed, 'Unexpected response from Nessus. '.$e->getMessage());
        }

        $result = $result->withLatency((int) round((hrtime(true) - $started) / 1_000_000));

        $this->record($server, $result);

        return $result;
    }

    private function record(NessusServer $server, ConnectionResult $result): void
    {
        $server->forceFill([
            'status' => $result->status,
            'last_checked_at' => now(),
            'last_connected_at' => $result->success ? now() : $server->last_connected_at,
            'server_version' => $result->version !== null
                ? Str::limit(trim(($result->edition ?? '').' '.$result->version), 50, '')
                : $server->server_version,
            'last_error' => $result->success ? null : Str::limit($result->message, 500, ''),
        ])->save();

        Log::log($result->success ? 'info' : 'warning', 'Nessus connection test', [
            'nessus_server_id' => $server->id,
            'base_url' => $server->base_url,
            'status' => $result->status->value,
            'latency_ms' => $result->latencyMs,
        ]);

        $this->audit->log('nessus_server.connection_tested', $server, metadata: $result->toArray());
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
