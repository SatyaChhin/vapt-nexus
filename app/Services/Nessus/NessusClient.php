<?php

namespace App\Services\Nessus;

use App\Models\NessusServer;
use App\Services\Nessus\Exceptions\NessusAuthenticationException;
use App\Services\Nessus\Exceptions\NessusConnectionException;
use App\Services\Nessus\Exceptions\NessusRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Low-level HTTP client for the Nessus REST API.
 *
 * Authenticates with API keys in the documented "X-ApiKeys" header. The keys
 * only live inside this object: they are never logged, never included in
 * exception messages and are masked in var_dump()/debug output.
 */
final class NessusClient
{
    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $accessKey,
        #[SensitiveParameter] private readonly string $secretKey,
        private readonly bool $verifySsl = true,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
    ) {}

    public static function forServer(NessusServer $server): self
    {
        return new self(
            baseUrl: $server->base_url,
            accessKey: $server->access_key,
            secretKey: $server->secret_key,
            verifySsl: $server->verify_ssl,
            timeout: (int) config('nessus.timeout', 30),
            connectTimeout: (int) config('nessus.connect_timeout', 5),
        );
    }

    /**
     * GET /server/status (no authentication): whether Nessus is up and
     * finished loading plugins. Returns e.g. ['status' => 'ready', ...].
     *
     * @return array<string, mixed>
     */
    public function serverStatus(): array
    {
        return $this->send('GET', '/server/status', authenticated: false);
    }

    /**
     * GET /server/properties: version, edition and license information.
     *
     * @return array<string, mixed>
     */
    public function serverProperties(): array
    {
        return $this->send('GET', '/server/properties');
    }

    /**
     * GET /session: the user the API keys belong to. Requires valid credentials,
     * so it is used to verify the keys.
     *
     * @return array<string, mixed>
     */
    public function session(): array
    {
        return $this->send('GET', '/session');
    }

    /**
     * GET /scans: every scan the API user can see, plus its folders.
     *
     * @return array{folders?: list<array<string, mixed>>|null, scans?: list<array<string, mixed>>|null}
     */
    public function scans(): array
    {
        return $this->send('GET', '/scans');
    }

    /**
     * GET /scans/{id}: summary of the latest run (info, hosts, plugins).
     * Readable on every edition, including Essentials.
     *
     * @return array<string, mixed>
     */
    public function scanDetails(int $scanId): array
    {
        return $this->send('GET', "/scans/{$scanId}");
    }

    /**
     * GET /scans/{id}/hosts/{host_id}: host facts and its plugin list.
     *
     * @return array<string, mixed>
     */
    public function hostDetails(int $scanId, int $hostId): array
    {
        return $this->send('GET', "/scans/{$scanId}/hosts/{$hostId}");
    }

    /**
     * GET /scans/{id}/hosts/{host_id}/plugins/{plugin_id}: plugin metadata
     * plus the output of that plugin per port on the host.
     *
     * @return array<string, mixed>
     */
    public function hostPlugin(int $scanId, int $hostId, int $pluginId): array
    {
        return $this->send('GET', "/scans/{$scanId}/hosts/{$hostId}/plugins/{$pluginId}");
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        return $this->send('POST', $path, ['json' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('PUT', $path, ['json' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    public function host(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws NessusConnectionException
     * @throws NessusAuthenticationException
     * @throws NessusRequestException
     */
    private function send(string $method, string $path, array $options = [], bool $authenticated = true): array
    {
        if (($error = NessusUrlGuard::check($this->host())) !== null) {
            throw new NessusConnectionException($error);
        }

        try {
            $response = $this->request($authenticated)->send($method, ltrim($path, '/'), $options);
        } catch (ConnectionException $e) {
            throw NessusConnectionException::fromConnectionError($this->host(), $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new NessusAuthenticationException($response->status());
        }

        if ($response->failed()) {
            throw NessusRequestException::fromResponse($method, $path, $response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = Http::baseUrl($this->host())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['verify' => $this->verifySsl])
            ->withUserAgent('VAPT-Nexus');

        if ($authenticated) {
            $request->withHeaders([
                'X-ApiKeys' => sprintf('accessKey=%s; secretKey=%s', $this->accessKey, $this->secretKey),
            ]);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'accessKey' => '[redacted]',
            'secretKey' => '[redacted]',
            'verifySsl' => $this->verifySsl,
        ];
    }
}
