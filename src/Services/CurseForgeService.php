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

    /**
     * The CurseForge API requires an API key for every request.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
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

        $response = $this->client()->get('/mods/search', $params);

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

        $response = $this->client()->get("/mods/{$modId}/files", [
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
    public function getVersionOptions(int $modId): array
    {
        return collect($this->getModFiles($modId))->map(function ($file) {
            $gameVersions = collect($file['gameVersions'] ?? [])
                ->filter(fn ($version) => preg_match('/^\d+\.\d+/', $version))
                ->take(3)
                ->implode(', ');

            return [
                'id' => (string) $file['id'],
                'name' => $file['displayName'].($gameVersions ? ' ('.$gameVersions.')' : ''),
            ];
        })->all();
    }

    /**
     * Get the download URL for a specific file.
     */
    public function getDownloadUrl(int $modId, int $fileId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->client()->get("/mods/{$modId}/files/{$fileId}");

        if ($response->failed()) {
            return null;
        }

        $downloadUrl = $response->json()['data']['downloadUrl'] ?? null;

        // edge.forgecdn.net redirects; mediafilez.forgecdn.net serves files directly.
        return $downloadUrl === null
            ? null
            : str_replace('edge.forgecdn.net', 'mediafilez.forgecdn.net', $downloadUrl);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeader('x-api-key', $this->apiKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * @throws RuntimeException When the API key is rejected
     */
    protected function ensureAuthorized(Response $response): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException("CurseForge API key is invalid or unauthorised (HTTP {$response->status()}). Please check your CURSEFORGE_API_KEY.");
        }
    }
}
