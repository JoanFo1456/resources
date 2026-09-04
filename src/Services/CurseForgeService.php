<?php

namespace JoanFo\Resources\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CurseForgeService
{
    public const GAME_MINECRAFT = 432;

    public const CLASS_BUKKIT_PLUGINS = 5;

    public const CLASS_MODS = 6;

    public const CLASS_MODPACKS = 4471;

    /** Sort by CurseForge featured/relevance score — used when a search query is present. */
    protected const SORT_BY_FEATURED = 1;

    /** Sort by total downloads — used when browsing without a query. */
    protected const SORT_BY_DOWNLOADS = 6;

    protected string $baseUrl;

    protected string $apiKey;

    protected bool $cacheEnabled;

    protected int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = config('resources.curseforge_api_url');
        $this->apiKey = (string) config('resources.curseforge_api_key');
        $this->cacheEnabled = (bool) config('resources.cache_enabled', true);
        $this->cacheTtl = (int) config('resources.cache_ttl');
    }

    /** Whether an official CurseForge API key is configured. */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /** Whether the keyless public mirror may be used as a fallback. */
    protected function proxyEnabled(): bool
    {
        return (bool) config('resources.curseforge_proxy_enabled', true)
            && (string) config('resources.curseforge_proxy_url', '') !== '';
    }

    /** Cache flag marking the official key as currently rate-limited. */
    protected function rateLimitedCacheKey(): string
    {
        return 'curseforge_rate_limited';
    }

    /**
     * Requests go to the keyless mirror when there is no API key at all, or while the official
     * key is known to be rate-limited.
     */
    public function usingProxy(): bool
    {
        if (!$this->proxyEnabled()) {
            return false;
        }

        return !$this->hasApiKey() || Cache::has($this->rateLimitedCacheKey());
    }

    /**
     * CurseForge is usable when we either hold a key or can fall back to the keyless mirror.
     */
    public function isConfigured(): bool
    {
        return $this->hasApiKey() || $this->proxyEnabled();
    }

    /** Effective API base URL, for callers that build their own pooled requests. */
    public function apiBaseUrl(): string
    {
        return $this->usingProxy()
            ? (string) config('resources.curseforge_proxy_url')
            : $this->baseUrl;
    }

    /**
     * Effective auth headers, for callers that build their own pooled requests.
     *
     * @return array<string, string>
     */
    public function apiHeaders(): array
    {
        return $this->usingProxy() ? [] : ['x-api-key' => $this->apiKey];
    }

    /**
     * Search for projects on CurseForge.
     *
     * @param  int|null  $classId  Project class (see CLASS_* constants), null for all classes
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int>}
     *
     * @throws RuntimeException When the API key is rejected
     */
    public function search(
        string $searchFilter = '',
        ?int $classId = self::CLASS_MODS,
        int $gameId = self::GAME_MINECRAFT,
        int $pageSize = 20,
        int $index = 0,
    ): array {
        $empty = ['data' => [], 'pagination' => ['totalCount' => 0]];

        if (!$this->isConfigured()) {
            return $empty;
        }

        $cacheKey = 'curseforge_search_'.md5($searchFilter.($classId ?? 'null').$gameId.$pageSize.$index);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'gameId' => $gameId,
            'searchFilter' => $searchFilter,
            'pageSize' => $pageSize,
            'index' => $index,
            'sortField' => $searchFilter !== '' ? self::SORT_BY_FEATURED : self::SORT_BY_DOWNLOADS,
            'sortOrder' => 'desc',
        ];

        if ($classId !== null) {
            $params['classId'] = $classId;
        }

        $response = $this->request('get', '/mods/search', $params);

        $this->ensureAuthorized($response);

        if ($response->failed()) {
            // Failures are not cached so the next request retries the API.
            return $empty;
        }

        $result = $response->json();

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Get total number of search results.
     *
     * @throws RuntimeException When the API key is rejected
     */
    public function getTotalCount(
        string $searchFilter = '',
        ?int $classId = self::CLASS_MODS,
        int $gameId = self::GAME_MINECRAFT,
    ): int {
        return (int) ($this->search($searchFilter, $classId, $gameId, 1, 0)['pagination']['totalCount'] ?? 0);
    }

    /**
     * Get the files (versions) of a project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getModFiles(int $modId, int $pageSize = 50, int $index = 0): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $cacheKey = 'curseforge_files_'.$modId.'_'.$pageSize.'_'.$index;

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->request('get', "/mods/{$modId}/files", [
            'pageSize' => $pageSize,
            'index' => $index,
        ]);

        if ($response->failed()) {
            return [];
        }

        $files = $response->json()['data'] ?? [];

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $files, $this->cacheTtl);
        }

        return $files;
    }

    /**
     * Get the files of a project formatted as id/name pairs for a select field.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getVersionOptions(int $modId, ?string $gameVersion = null): array
    {
        return collect($this->getModFiles($modId))
            ->when($gameVersion, fn ($col) => $col->filter(
                fn ($file) => in_array($gameVersion, $file['gameVersions'] ?? [], true)
            ))
            ->map(function ($file) {
                $gameVersions = collect($file['gameVersions'] ?? [])
                    ->filter(fn ($version) => preg_match('/^\d+\.\d+/', $version))
                    ->take(3)
                    ->implode(', ');

                return [
                    'id' => (string) $file['id'],
                    'name' => $file['displayName'].($gameVersions ? ' ('.$gameVersions.')' : ''),
                ];
            })->values()->all();
    }

    /**
     * Get download URLs for multiple files in one API call.
     * Chunks into batches of 50 to stay within API limits.
     *
     * @param  int[]  $fileIds
     * @return array<int, string|null> Map of fileId → downloadUrl (null if unavailable)
     */
    public function getDownloadUrls(array $fileIds): array
    {
        if (!$this->isConfigured() || empty($fileIds)) {
            return [];
        }

        $results = [];

        foreach (array_chunk($fileIds, 50) as $chunk) {
            $response = $this->request('post', '/mods/files', ['fileIds' => $chunk]);

            if ($response->failed()) {
                continue;
            }

            foreach ($response->json()['data'] ?? [] as $file) {
                $url = $file['downloadUrl'] ?? null;
                if ($url !== null) {
                    $url = str_replace('edge.forgecdn.net', 'mediafilez.forgecdn.net', $url);
                } elseif (!empty($file['id'])) {
                    // Author disabled API distribution (downloadUrl is null) — recover the file
                    // via the keyless redirect endpoint so it isn't silently skipped.
                    $url = $this->nullDownloadFallback(
                        (int) ($file['modId'] ?? 0),
                        (int) $file['id'],
                        (string) ($file['fileName'] ?? ''),
                    );
                }
                $results[(int) $file['id']] = $url;
            }
        }

        return $results;
    }

    /**
     * Best available URL for a file whose official downloadUrl came back null (author opted out
     * of third-party API distribution). Prefers the keyless redirect endpoint — it needs only
     * the project + file id, injects the required analytics api-key itself, and works even under
     * the July 2026 rule that direct CDN pulls carry an api-key. Falls back to a hand-built CDN
     * path only when the project id is unknown.
     */
    private function nullDownloadFallback(int $modId, int $fileId, string $fileName): ?string
    {
        if ($modId > 0 && $fileId > 0) {
            return self::keylessDownloadUrl($modId, $fileId);
        }

        return ($fileId > 0 && $fileName !== '')
            ? self::forgeCdnUrl($fileId, $fileName)
            : null;
    }

    /**
     * CurseForge's website API endpoint that 307-redirects to the actual CDN file. Requires no
     * x-api-key (the redirect appends CurseForge's own analytics key), needs only the project and
     * file ids — both present in a modpack manifest — and so consumes no api.curseforge.com quota.
     * Unofficial/undocumented: used only as a fallback. The redirect resolves the real filename,
     * so pull this with use_header enabled.
     */
    public static function keylessDownloadUrl(int $modId, int $fileId): string
    {
        return "https://www.curseforge.com/api/v1/mods/{$modId}/files/{$fileId}/download";
    }

    /**
     * Build the direct CurseForge CDN URL for a file. CurseForge lays files out as
     * /files/<intdiv(id,1000)>/<id % 1000>/<fileName>, with the second segment as a plain integer
     * (no zero-padding). Last-resort fallback when the project id isn't known.
     */
    public static function forgeCdnUrl(int $fileId, string $fileName): string
    {
        return sprintf(
            'https://mediafilez.forgecdn.net/files/%d/%d/%s',
            intdiv($fileId, 1000),
            $fileId % 1000,
            rawurlencode($fileName),
        );
    }

    /**
     * Identify files by their CurseForge fingerprint (MurmurHash2 of the whitespace-stripped bytes).
     *
     * POST /fingerprints with body {"fingerprints": [ ...uint32... ]}.
     * The response's data.exactMatches[] each contain `id` (the project/mod id) and
     * a `file` object with `id` (the file/version id) and `fileFingerprint`.
     *
     * @param  int[]  $fingerprints
     * @return array<int, array<string, mixed>> The exactMatches array (empty when nothing matches)
     *
     * @throws RuntimeException When the API key is rejected
     */
    public function lookupByFingerprints(array $fingerprints): array
    {
        $fingerprints = array_values(array_unique(array_filter($fingerprints, fn ($fp) => $fp !== null)));

        if (!$this->isConfigured() || empty($fingerprints)) {
            return [];
        }

        $response = $this->request('post', '/fingerprints', [
            'fingerprints' => $fingerprints,
        ]);

        $this->ensureAuthorized($response);

        if ($response->failed()) {
            return [];
        }

        return $response->json()['data']['exactMatches'] ?? [];
    }

    /**
     * Compute the CurseForge fingerprint of a file's raw bytes.
     *
     * CurseForge fingerprints are a 32-bit MurmurHash2 (seed = 1) computed over the file
     * bytes with all whitespace bytes removed first: tab (0x09), line-feed (0x0A),
     * carriage-return (0x0D) and space (0x20). Note the length fed to the hash is the
     * length AFTER stripping, which is what MurmurHash2 seeds with (h = seed ^ len).
     */
    public static function fingerprint(string $bytes): int
    {
        $stripped = str_replace(["\x09", "\x0a", "\x0d", "\x20"], '', $bytes);

        return self::murmur2($stripped, 1);
    }

    /**
     * MurmurHash2 (32-bit, original Austin Appleby variant) implemented with explicit
     * 32-bit-unsigned arithmetic so it behaves identically to the C reference regardless
     * of PHP's native (signed, 64-bit) integers.
     *
     * Reference:
     *   uint32_t h = seed ^ len;
     *   while (len >= 4) { k = read_u32_le(); k*=m; k^=k>>r; k*=m; h*=m; h^=k; }
     *   switch (len) { tail bytes ... h*=m; }
     *   h ^= h>>13; h*=m; h ^= h>>15;
     * with m = 0x5bd1e995, r = 24.
     */
    private static function murmur2(string $data, int $seed): int
    {
        $m = 0x5BD1E995;
        $r = 24;

        $len = strlen($data);
        $h = ($seed ^ $len) & 0xFFFFFFFF;

        $i = 0;
        while ($len >= 4) {
            $k = ord($data[$i])
                | (ord($data[$i + 1]) << 8)
                | (ord($data[$i + 2]) << 16)
                | (ord($data[$i + 3]) << 24);
            $k &= 0xFFFFFFFF;

            $k = self::mul32($k, $m);
            $k ^= ($k >> $r) & 0xFFFFFFFF;
            $k &= 0xFFFFFFFF;
            $k = self::mul32($k, $m);

            $h = self::mul32($h, $m);
            $h = ($h ^ $k) & 0xFFFFFFFF;

            $i += 4;
            $len -= 4;
        }

        switch ($len) {
            case 3:
                $h = ($h ^ (ord($data[$i + 2]) << 16)) & 0xFFFFFFFF;
                // no break — fall through
            case 2:
                $h = ($h ^ (ord($data[$i + 1]) << 8)) & 0xFFFFFFFF;
                // no break — fall through
            case 1:
                $h = ($h ^ ord($data[$i])) & 0xFFFFFFFF;
                $h = self::mul32($h, $m);
        }

        $h = ($h ^ ($h >> 13)) & 0xFFFFFFFF;
        $h = self::mul32($h, $m);
        $h = ($h ^ ($h >> 15)) & 0xFFFFFFFF;

        return $h;
    }

    /**
     * Multiply two 32-bit unsigned integers and truncate the product back to 32 bits,
     * split into 16-bit halves so the intermediate product never exceeds PHP_INT_MAX
     * on any platform.
     */
    private static function mul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        $al = $a & 0xFFFF;
        $ah = ($a >> 16) & 0xFFFF;

        $high = ((($ah * $b) & 0xFFFF) << 16) & 0xFFFFFFFF;
        $low = ($al * $b) & 0xFFFFFFFF;

        return ($high + $low) & 0xFFFFFFFF;
    }

    /**
     * Get the full file (version) object for a specific file, or null on failure.
     *
     * Useful fields: `serverPackFileId` (int|null — the id of the prebuilt server pack, when
     * the pack ships one), `gameVersions` (e.g. ["1.20.1", "Forge", "Server"]), `downloadUrl`.
     *
     * @return array<string, mixed>|null
     */
    public function getFile(int $modId, int $fileId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->request('get', "/mods/{$modId}/files/{$fileId}");

        if ($response->failed()) {
            return null;
        }

        return $response->json()['data'] ?? null;
    }

    /**
     * Get the download URL for a specific file.
     */
    public function getDownloadUrl(int $modId, int $fileId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->request('get', "/mods/{$modId}/files/{$fileId}");

        if ($response->failed()) {
            return null;
        }

        $data = $response->json()['data'] ?? [];
        $downloadUrl = $data['downloadUrl'] ?? null;

        if ($downloadUrl === null) {
            // Author disabled API distribution — recover via the keyless redirect endpoint.
            return !empty($data['id'])
                ? $this->nullDownloadFallback($modId, (int) $data['id'], (string) ($data['fileName'] ?? ''))
                : null;
        }

        // edge.forgecdn.net redirects; mediafilez.forgecdn.net serves files directly.
        return str_replace('edge.forgecdn.net', 'mediafilez.forgecdn.net', $downloadUrl);
    }

    protected function client(bool $forceProxy = false): PendingRequest
    {
        $useProxy = $forceProxy || $this->usingProxy();

        $request = Http::baseUrl($useProxy ? (string) config('resources.curseforge_proxy_url') : $this->baseUrl)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            // The mirror sits behind Cloudflare and answers with a 302. Guzzle downgrades a
            // redirected POST to GET by default, which silently drops the body and breaks the
            // /mods/files and /fingerprints batch endpoints; strict mode keeps the method.
            ->withOptions(['allow_redirects' => ['strict' => true]]);

        return $useProxy ? $request : $request->withHeader('x-api-key', $this->apiKey);
    }

    /**
     * Send a request, transparently failing over to the keyless mirror when the official API
     * rate-limits us (429). The throttled state is remembered briefly so following calls go
     * straight to the mirror instead of spending a rejected round-trip on the official API first.
     *
     * @param  array<string, mixed>  $data
     */
    protected function request(string $method, string $path, array $data = []): Response
    {
        $send = fn (PendingRequest $client): Response => $method === 'post'
            ? $client->post($path, $data)
            : $client->get($path, $data);

        $response = $send($this->client());

        if ($response->status() === 429 && !$this->usingProxy() && $this->proxyEnabled()) {
            Cache::put($this->rateLimitedCacheKey(), true, now()->addMinutes(5));

            return $send($this->client(forceProxy: true));
        }

        return $response;
    }

    /**
     * @throws RuntimeException When the API key is rejected
     */
    protected function ensureAuthorized(Response $response): void
    {
        // Only meaningful for the official API — the mirror sends no key, so a 401/403 from it
        // is a proxy/Cloudflare problem, not a bad key, and must not be reported as one.
        if ($this->usingProxy()) {
            return;
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException("CurseForge API key is invalid or unauthorised (HTTP {$response->status()}). Please check your CURSEFORGE_API_KEY.");
        }
    }
}
