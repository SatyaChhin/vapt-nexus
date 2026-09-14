<?php

namespace Tests\Unit;

use App\Services\Nessus\Exceptions\NessusAuthenticationException;
use App\Services\Nessus\Exceptions\NessusConnectionException;
use App\Services\Nessus\Exceptions\NessusRequestException;
use App\Services\Nessus\NessusClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NessusClientTest extends TestCase
{
    private const ACCESS = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa11111111111111111111111111111111';

    private const SECRET = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb22222222222222222222222222222222';

    private function client(string $url = 'https://192.168.56.10:8834'): NessusClient
    {
        return new NessusClient($url, self::ACCESS, self::SECRET, verifySsl: false);
    }

    public function test_it_authenticates_with_the_x_api_keys_header(): void
    {
        Http::fake(['*/session' => Http::response(['username' => 'admin'])]);

        $this->assertSame(['username' => 'admin'], $this->client()->session());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://192.168.56.10:8834/session'
            && $request->method() === 'GET'
            && $request->header('X-ApiKeys')[0] === 'accessKey='.self::ACCESS.'; secretKey='.self::SECRET);
    }

    public function test_server_status_is_requested_without_credentials(): void
    {
        Http::fake(['*/server/status' => Http::response(['status' => 'ready'])]);

        $this->assertSame('ready', $this->client('https://192.168.56.10:8834/')->serverStatus()['status']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://192.168.56.10:8834/server/status'
            && ! $request->hasHeader('X-ApiKeys'));
    }

    public function test_rejected_credentials_raise_an_authentication_exception_without_leaking_keys(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Invalid Credentials'], 401)]);

        try {
            $this->client()->session();
            $this->fail('Expected NessusAuthenticationException');
        } catch (NessusAuthenticationException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringNotContainsString(self::ACCESS, $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function test_connection_failures_are_translated_to_a_readable_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 192.168.56.10 port 8834: Connection refused'));

        $this->expectException(NessusConnectionException::class);
        $this->expectExceptionMessage('The connection was refused');

        $this->client()->serverStatus();
    }

    public function test_certificate_errors_suggest_disabling_verification_for_lab_certificates(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: self-signed certificate'));

        $this->expectException(NessusConnectionException::class);
        $this->expectExceptionMessage('TLS certificate verification failed');

        $this->client()->serverStatus();
    }

    public function test_other_http_errors_raise_a_request_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Internal error'], 500)]);

        $this->expectException(NessusRequestException::class);
        $this->expectExceptionMessage('Nessus returned HTTP 500 for GET /server/properties: Internal error');

        $this->client()->serverProperties();
    }

    public function test_urls_outside_the_allowed_networks_are_never_contacted(): void
    {
        config(['nessus.allowed_networks' => ['192.168.56.0/24']]);
        Http::fake();

        try {
            $this->client('https://10.20.30.40:8834')->serverStatus();
            $this->fail('Expected NessusConnectionException');
        } catch (NessusConnectionException $e) {
            $this->assertStringContainsString('allowed network', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_debug_output_redacts_the_keys(): void
    {
        ob_start();
        var_dump($this->client());
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString(self::ACCESS, $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }
}
