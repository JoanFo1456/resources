<?php

namespace JoanFo\Resources\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ModrinthService
{
    protected const USER_AGENT = 'ResourcesPlugin';

    protected string $baseUrl;

    protected bool $cacheEnabled;

    protected int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = config('resources.modrinth_api_url');
        $this->cacheEnabled = (bool) config('resources.cache_enabled', true);
        $this->cacheTtl = (int) config('resources.cache_ttl');
    }

    /**
     * Search for projects (mods, plugins, modpacks) on Modrinth.
     *
     * @param  array<string, string|array<int, string>>  $facets
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    public function search(string $query = '', array $facets = [], int $limit = 20, int $offset = 0): array
    {
        $cacheKey = 'modrinth_search_'.md5($query.json_encode($facets).$limit.$offset);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'index' => 'relevance',
        ];

        if (!empty($facets)) {
            $params['facets'] = $this->buildFacets($facets);
        }

        $response = $this->client()->get('/search', $params);

        if ($response->failed()) {
            return ['hits' => [], 'total_hits' => 0];
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
     * @param  array<string, string|array<int, string>>  $facets
     */
    public function getTotalCount(string $query = '', array $facets = []): int
    {
        return (int) ($this->search($query, $facets, 1, 0)['total_hits'] ?? 0);
    }

    /**
     * Get all versions of a project, optionally filtered by game version and/or mod loader.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProjectVersions(string $idOrSlug, ?string $gameVersion = null, ?string $loader = null): array
    {
        $cacheKey = 'modrinth_versions_'.$idOrSlug.($gameVersion ? '_'.$gameVersion : '').($loader ? '_'.$loader : '');

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [];
        if ($gameVersion) {
            $params['game_versions'] = json_encode([$gameVersion]);
        }
        if ($loader) {
            $params['loaders'] = json_encode([$loader]);
        }

        $response = $this->client()->get("/project/{$idOrSlug}/version", $params);

        if ($response->failed()) {
            return [];
        }

        $versions = $response->json();

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $versions, $this->cacheTtl);
        }

        return $versions;
    }

    /**
     * Get the versions of a project formatted as id/name pairs for a select field.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getVersionOptions(string $idOrSlug, ?string $gameVersion = null, ?string $loader = null): array
    {
        return collect($this->getProjectVersions($idOrSlug, $gameVersion, $loader))->map(fn ($version) => [
            'id' => $version['id'],
            'name' => $version['name'].' ('.implode(', ', array_slice($version['game_versions'] ?? [], 0, 3)).')',
        ])->all();
    }

    /**
     * Get a specific version by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getVersion(string $versionId): ?array
    {
        $cacheKey = 'modrinth_version_'.$versionId;

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->client()->get("/version/{$versionId}");

        if ($response->failed()) {
            return null;
        }

        $version = $response->json();

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $version, $this->cacheTtl);
        }

        return $version;
    }

    public function getProjectIcon(string $projectId): ?string
    {
        $cacheKey = 'modrinth_project_icon_'.$projectId;

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?: null;
        }

        $response = $this->client()->get("/project/{$projectId}");
        $icon = $response->successful() ? ($response->json('icon_url') ?: '') : '';

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $icon, $this->cacheTtl);
        }

        return $icon ?: null;
    }

    /**
     * Identify installed files by their hash.
     *
     * Uses the batch endpoint POST /version_files with body
     * {"hashes": [...], "algorithm": "sha1"}. The response maps each hash to the
     * version object that owns a file with that hash; the version object contains
     * `project_id` and `id` (the version id).
     *
     * @param  array<int, string>  $hashes
     * @return array<string, array<string, mixed>>  Map of hash => version object
     */
    public function lookupByHashes(array $hashes, string $algorithm = 'sha1'): array
    {
        $hashes = array_values(array_unique(array_filter($hashes)));

        if (empty($hashes)) {
            return [];
        }

        $response = $this->client()->post('/version_files', [
            'hashes' => $hashes,
            'algorithm' => $algorithm,
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * Get the download URL of a version's primary file.
     */
    public function getDownloadUrl(string $versionId): ?string
    {
        $files = $this->getVersion($versionId)['files'] ?? [];

        foreach ($files as $file) {
            if ($file['primary'] ?? false) {
                return $file['url'];
            }
        }

        return $files[0]['url'] ?? null;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withUserAgent(self::USER_AGENT)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * Build the facets query parameter.
     *
     * Each top-level entry becomes an OR group, groups are combined with AND,
     * e.g. ['project_type' => ['mod', 'plugin']] => [["project_type:mod","project_type:plugin"]].
     *
     * @param  array<string, string|array<int, string>>  $facets
     */
    protected function buildFacets(array $facets): string
    {
        $groups = collect($facets)
            ->map(fn ($values, $key) => collect((array) $values)
                ->map(fn ($value) => "{$key}:{$value}")
                ->all())
            ->values()
            ->all();

        return json_encode($groups);
    }
}
