<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Update\UpdateService;

final class UpdateServiceTest extends TestCase
{
    private const URL = 'https://cdn.example.com/grafida.json';

    public function testCanonicalUpdateUrlIsTheFlexidaReleaseList(): void
    {
        $this->assertStringStartsWith('https://api.github.com/repos/lungkao/flexida/releases', UpdateService::UPDATE_URL);
    }

    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/grafida-update-test-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    private function service(FakeTransport $http, string $current = '0.1'): UpdateService
    {
        return new UpdateService($http, $current, $this->cacheFile, self::URL, 'macos-arm64');
    }

    private function release(string $tag, bool $draft = false, string $base = 'https://github.com/lungkao/flexida'): array
    {
        $v = ltrim($tag, 'v');

        return [
            'tag_name' => $tag,
            'draft'    => $draft,
            'html_url' => $base . '/releases/tag/' . $tag,
            'assets'   => [
                ['name' => "Flexida-$v-macos-amd64.zip", 'browser_download_url' => "$base/releases/download/$tag/Flexida-$v-macos-amd64.zip"],
                ['name' => "Flexida-$v-macos-arm64.zip", 'browser_download_url' => "$base/releases/download/$tag/Flexida-$v-macos-arm64.zip"],
            ],
        ];
    }

    private function payload(string $version): HttpResponse
    {
        return new HttpResponse(200, json_encode([$this->release('v' . $version)]));
    }

    /** @param array<string, mixed> $doc */
    private function cache(array $doc): void
    {
        file_put_contents($this->cacheFile, json_encode($doc + ['source' => self::URL]));
    }

    public function testReportsANewerVersionAndCachesTheDocument(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.2'));
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.2', $status['version']);
        $this->assertSame('https://github.com/lungkao/flexida/releases/tag/v0.2', $status['infoURL']);
        $this->assertSame('https://github.com/lungkao/flexida/releases/download/v0.2/Flexida-0.2-macos-arm64.zip', $status['download']);
        $this->assertFileExists($this->cacheFile);
        $this->assertCount(1, $http->requests);
    }

    public function testSameVersionIsNotAnUpdate(): void
    {
        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.1'));
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertNull($status['version']);
        $this->assertNull($status['download']);
    }

    public function testFreshCacheIsNotRefetched(): void
    {
        $this->cache(['version' => '0.2', 'infoURL' => 'x']);

        $http   = new FakeTransport();
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame([], $http->requests, 'A fresh cache must not trigger a network fetch.');
    }

    public function testStaleCacheIsRefetched(): void
    {
        $this->cache(['version' => '0.2']);
        // Backdate the cache well beyond the 12-hour window.
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http = (new FakeTransport())->on(self::URL, $this->payload('0.3'));

        $status = $this->service($http)->status();

        $this->assertSame('0.3', $status['version']);
        $this->assertCount(1, $http->requests);
    }

    public function testFailedFetchWithoutCacheWritesEmptyDocument(): void
    {
        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertFileExists($this->cacheFile);
        $this->assertSame(['source' => self::URL], json_decode((string) file_get_contents($this->cacheFile), true));
    }

    public function testFailedFetchFallsBackToExistingCache(): void
    {
        $this->cache(['version' => '0.9']);
        touch($this->cacheFile, time() - 13 * 60 * 60);

        $http   = (new FakeTransport())->throwFor(self::URL);
        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.9', $status['version']);
    }

    public function testCacheFromAnotherSourceIsIgnored(): void
    {
        // What the upstream Grafida CDN would have left behind.
        file_put_contents($this->cacheFile, json_encode(['version' => '9.9', 'infoURL' => 'https://grafida.app/']));

        $http   = (new FakeTransport())->on(self::URL, $this->payload('0.1'));
        $status = $this->service($http)->status();

        $this->assertFalse($status['available']);
        $this->assertCount(1, $http->requests);
    }

    public function testPicksTheHighestPublishedReleaseAndSkipsDrafts(): void
    {
        $body = json_encode([$this->release('v0.3', true), $this->release('v0.2.2'), $this->release('v0.2.10'), $this->release('nightly')]);
        $http = (new FakeTransport())->on(self::URL, new HttpResponse(200, $body));

        $status = $this->service($http, '0.2.2')->status();

        $this->assertTrue($status['available']);
        $this->assertSame('0.2.10', $status['version']);
    }

    public function testLinksOutsideTheProjectAreNeverOffered(): void
    {
        $body = json_encode([$this->release('v0.5', false, 'https://evil.example/x')]);
        $http = (new FakeTransport())->on(self::URL, new HttpResponse(200, $body));

        $status = $this->service($http)->status();

        $this->assertTrue($status['available']);
        $this->assertNull($status['infoURL']);
        $this->assertNull($status['download']);
    }

    public function testFallsBackToReleasePageWithoutAMatchingFile(): void
    {
        $r = $this->release('v0.4');
        $r['assets'] = [];
        $http = (new FakeTransport())->on(self::URL, new HttpResponse(200, json_encode([$r])));

        $status = $this->service($http)->status();

        $this->assertSame('https://github.com/lungkao/flexida/releases/tag/v0.4', $status['download']);
    }

    public function testDetectPlatformLooksLikeAReleaseSuffix(): void
    {
        $this->assertMatchesRegularExpression('/^(macos|windows|linux)-(arm64|amd64)$/', UpdateService::detectPlatform());
    }
}
