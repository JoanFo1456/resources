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
     * Get all versions of a project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProjectVersions(string $idOrSlug): array
    {
        $cacheKey = 'modrinth_versions_'.$idOrSlug;

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->client()->get("/project/{$idOrSlug}/version");

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
    public function getVersionOptions(string $idOrSlug): array
    {
        return collect($this->getProjectVersions($idOrSlug))->map(fn ($version) => [
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
