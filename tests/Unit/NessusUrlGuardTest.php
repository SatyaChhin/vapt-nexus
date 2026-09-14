<?php

namespace Tests\Unit;

use App\Services\Nessus\NessusUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NessusUrlGuardTest extends TestCase
{
    private const LAB = ['192.168.56.0/24', '192.168.168.0/24'];

    #[DataProvider('acceptedUrls')]
    public function test_it_accepts_lab_urls(string $url): void
    {
        $this->assertNull(NessusUrlGuard::check($url, self::LAB));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedUrls(): array
    {
        return [
            'host-only' => ['https://192.168.56.10:8834'],
            'trailing slash' => ['https://192.168.56.20:8834/'],
            'nat' => ['https://192.168.168.129:8834'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_it_rejects_unsafe_urls(string $url): void
    {
        $this->assertNotNull(NessusUrlGuard::check($url, self::LAB));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'outside lab' => ['https://8.8.8.8:8834'],
            'other private range' => ['https://10.0.0.5:8834'],
            'ftp scheme' => ['ftp://192.168.56.10'],
            'credentials' => ['https://user:pass@192.168.56.10:8834'],
            'path' => ['https://192.168.56.10:8834/scans'],
            'query' => ['https://192.168.56.10:8834?x=1'],
            'not a url' => ['192.168.56.10'],
        ];
    }

    public function test_an_empty_allow_list_permits_any_host(): void
    {
        $this->assertNull(NessusUrlGuard::check('https://10.0.0.5:8834', []));
    }
}
