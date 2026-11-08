<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Update;

use Grafida\Http\HttpException;
use Grafida\Http\Transport;

/**
 * Checks whether a newer version of Grafida is available.
 *
 * Flexida reads the list of GitHub releases of lungkao/flexida and picks the newest published
 * one (prereleases included). Its {@code infoURL} is the release page and {@code download} is the
 * release file for this OS and CPU (falling back to the release page). Only links under
 * https://github.com/lungkao/flexida/ are ever returned.
 *
 * To avoid hammering the CDN, the fetched document is cached in a per-user file
 * and only refreshed when that file is older than 12 hours (the "last fetched"
 * timestamp is simply the file's modification time). A failed fetch with no prior
 * cache still writes an empty {@code {}} document, so a broken network does not
 * trigger a fetch on every startup.
 */
final class UpdateService
{
    /** Canonical URL the release pipeline publishes the update information to. */
    public const UPDATE_URL = 'https://api.github.com/repos/lungkao/flexida/releases?per_page=10';

    /** Only links under this prefix are ever offered to the user as a download. */
    private const TRUSTED_PREFIX = 'https://github.com/lungkao/flexida/';

    /** Refresh the cached information at most once every 12 hours. */
    private const MAX_AGE = 12 * 60 * 60;

    public function __construct(
        private readonly Transport $http,
        private readonly string $currentVersion,
        private readonly string $cacheFile,
        private readonly string $updateUrl = self::UPDATE_URL,
        private readonly ?string $platform = null,
    ) {}

    /** Platform token used in release file names, e.g. {@code macos-arm64}, {@code windows-amd64}. */
    public static function detectPlatform(): string
    {
        $arch = strtolower(php_uname('m'));
        $arch = in_array($arch, ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64';
        $os   = match (PHP_OS_FAMILY) {
            'Darwin'  => 'macos',
            'Windows' => 'windows',
            default   => 'linux',
        };

        return $os . '-' . $arch;
    }

    /**
     * Returns the update status, refreshing the cache from the CDN when stale.
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    public function status(): array
    {
        return $this->evaluate($this->cachedOrFresh());
    }

    /**
     * Reads the cached update information, fetching a fresh copy when the cache
     * is missing or older than {@see MAX_AGE}.
     *
     * @return array<array-key, mixed>
     */
    private function cachedOrFresh(): array
    {
        if (is_file($this->cacheFile)) {
            $age = time() - (int) @filemtime($this->cacheFile);

            if ($age >= 0 && $age < self::MAX_AGE) {
                $cached = $this->readCache();

                // A cache written for another update source (e.g. the upstream project's) is not ours.
                if (($cached['source'] ?? null) === $this->updateUrl) {
                    return $cached;
                }
            }
        }

        return $this->fetch();
    }

    /**
     * Fetches the update information from the CDN (best-effort).
     *
     * On any failure it falls back to a previously-cached copy; if there is none,
     * it writes an empty {@code {}} document so the 12-hour back-off applies to
     * failed attempts too.
     *
     * @return array<array-key, mixed>
     */
    private function fetch(): array
    {
        try {
            $response = $this->http->request('GET', $this->updateUrl, [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Grafida/' . $this->currentVersion,
            ]);

            if ($response->isSuccess()) {
                $data = $this->normalise(json_decode($response->body, true));

                if ($data !== null) {
                    $this->writeCache((string) json_encode($data));

                    return $data;
                }
            }
        } catch (HttpException) {
            // Network/transport failure — handled below.
        }

        if (is_file($this->cacheFile)) {
            $cached = $this->readCache();

            if (($cached['source'] ?? null) === $this->updateUrl) {
                return $cached;
            }
        }

        // No cache and the fetch failed: record the attempt with an empty document.
        $this->writeCache((string) json_encode(['source' => $this->updateUrl]));

        return [];
    }

    /**
     * Reduces the GitHub "list releases" answer to {version, infoURL, download} for the newest
     * published release (prereleases included: Flexida's releases are all experimental).
     *
     * @return array<string, mixed>|null null when the document is not a release list at all
     */
    private function normalise(mixed $data): ?array
    {
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }

        $best = null;

        foreach ($data as $release) {
            if (!is_array($release) || ($release['draft'] ?? false) === true || !is_string($release['tag_name'] ?? null)) {
                continue;
            }

            $version = ltrim($release['tag_name'], 'vV');

            if ($version === '' || preg_match('/^\d+(\.\d+)*$/', $version) !== 1) {
                continue;
            }

            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = ['version' => $version, 'release' => $release];
            }
        }

        $doc = ['source' => $this->updateUrl];

        if ($best === null) {
            return $doc;
        }

        $release = $best['release'];
        $info    = is_string($release['html_url'] ?? null) ? $release['html_url'] : '';
        $info    = str_starts_with($info, self::TRUSTED_PREFIX) ? $info : '';
        $token   = '-' . ($this->platform ?? self::detectPlatform());
        $asset   = '';

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $a) {
            $name = is_array($a) && is_string($a['name'] ?? null) ? $a['name'] : '';
            $url  = is_array($a) && is_string($a['browser_download_url'] ?? null) ? $a['browser_download_url'] : '';

            if ($name !== '' && str_contains($name, $token) && !str_ends_with($name, '.sha256')
                && str_starts_with($url, self::TRUSTED_PREFIX)) {
                $asset = $url;

                break;
            }
        }

        return $doc + [
            'version'  => $best['version'],
            'infoURL'  => $info,
            'download' => $asset !== '' ? $asset : $info,
        ];
    }

    /** @return array<array-key, mixed> */
    private function readCache(): array
    {
        $raw = @file_get_contents($this->cacheFile);

        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function writeCache(string $json): void
    {
        $dir = \dirname($this->cacheFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($this->cacheFile, $json);
    }

    /**
     * Turns the cached update document into the status returned to the SPA.
     *
     * @param array<array-key, mixed> $info
     *
     * @return array{available: bool, version: string|null, infoURL: string|null, download: string|null}
     */
    private function evaluate(array $info): array
    {
        $version  = isset($info['version']) && is_string($info['version']) ? trim($info['version']) : '';
        $infoURL  = isset($info['infoURL']) && is_string($info['infoURL']) ? $info['infoURL'] : '';
        $download = isset($info['download']) && is_string($info['download']) ? $info['download'] : '';

        $available = $version !== '' && version_compare($version, $this->currentVersion, '>');

        return [
            'available' => $available,
            'version'   => $available ? $version : null,
            'infoURL'   => $available && $infoURL !== '' ? $infoURL : null,
            'download'  => $available && $download !== '' ? $download : null,
        ];
    }
}
