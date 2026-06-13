<?php

namespace JoanFo\Resources\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SpigotService
{
    protected string $baseUrl;

    protected bool $cacheEnabled;

    protected int $cacheTtl;

    public function __construct()
    {
        $this->baseUrl = config('resources.spigot_api_url');
        $this->cacheEnabled = (bool) config('resources.cache_enabled', true);
        $this->cacheTtl = (int) config('resources.cache_ttl');
    }

    /**
     * Search for resources on SpigotMC via the Spiget API.
     *
     * @param  int  $page  Page number (1-indexed)
     * @return array<int, array<string, mixed>>
     */
    public function search(string $searchFilter = '', int $size = 20, int $page = 1): array
    {
        $cacheKey = 'spigot_search_'.md5($searchFilter.$size.$page);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->client()->get($this->searchEndpoint($searchFilter), [
            'size' => $size,
            'page' => max(1, $page),
            'sort' => '-downloads',
        ]);

        if ($response->failed()) {
            return [];
        }

        $resources = $this->hydrateAuthorNames($response->json() ?? []);

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $resources, $this->cacheTtl);
        }

        return $resources;
    }

    /**
     * Get total number of search results.
     */
    public function getTotalCount(string $searchFilter = ''): int
    {
        $cacheKey = 'spigot_count_'.md5($searchFilter);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->client()->get($this->searchEndpoint($searchFilter), [
            'size' => 1,
            'page' => 1,
            'sort' => '-downloads',
        ]);

        if ($response->failed()) {
            return 0;
        }

        $count = (int) $response->header('X-Page-Count');

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $count, $this->cacheTtl);
        }

        return $count;
    }

    /**
     * Get the versions of a resource, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getResourceVersions(int $resourceId, int $size = 50): array
    {
        $cacheKey = 'spigot_versions_'.$resourceId.'_'.$size;

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $response = $this->client()->get("/resources/{$resourceId}/versions", [
            'size' => $size,
            'sort' => '-releaseDate',
        ]);

        if ($response->failed()) {
            return [];
        }

        $versions = $response->json() ?? [];

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $versions, $this->cacheTtl);
        }

        return $versions;
    }

    /**
     * Get the versions of a resource formatted as id/name pairs for a select field.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getVersionOptions(int $resourceId): array
    {
        return collect($this->getResourceVersions($resourceId))->map(fn ($version) => [
            'id' => (string) $version['id'],
            'name' => $version['name'],
        ])->all();
    }

    /**
     * Get the download URL for a resource version.
     *
     * Spiget does not serve files itself, so this points at SpigotMC directly.
     */
    public function getDownloadUrl(int $resourceId, string $versionId): string
    {
        return "https://www.spigotmc.org/resources/{$resourceId}/download?version={$versionId}";
    }

    protected function searchEndpoint(string $searchFilter): string
    {
        return $searchFilter === ''
            ? '/resources'
            : '/search/resources/'.rawurlencode($searchFilter);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * Resolve author display names for a page of resources.
     *
     * Spiget list responses only contain author ids, so names are fetched
     * concurrently and cached per author.
     *
     * @param  array<int, array<string, mixed>>  $resources
     * @return array<int, array<string, mixed>>
     */
    protected function hydrateAuthorNames(array $resources): array
    {
        $authorIds = collect($resources)
            ->pluck('author.id')
            ->filter()
            ->unique()
            ->values();

        $names = [];
        $missing = [];

        foreach ($authorIds as $authorId) {
            $name = $this->cacheEnabled ? Cache::get("spigot_author_{$authorId}") : null;

            if ($name !== null) {
                $names[$authorId] = $name;
            } else {
                $missing[] = $authorId;
            }
        }

        if (!empty($missing)) {
            $responses = Http::pool(fn (Pool $pool) => collect($missing)->map(
                fn ($authorId) => $pool->as((string) $authorId)
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->get("{$this->baseUrl}/authors/{$authorId}")
            )->all());

            foreach ($missing as $authorId) {
                $response = $responses[(string) $authorId] ?? null;

                // Pooled requests return the connection exception instead of throwing.
                if (!$response instanceof Response || !$response->successful()) {
                    continue;
                }

                $name = $response->json()['name'] ?? null;

                if ($name !== null) {
                    if ($this->cacheEnabled) {
                        Cache::put("spigot_author_{$authorId}", $name, $this->cacheTtl);
                    }
                    $names[$authorId] = $name;
                }
            }
        }

        foreach ($resources as $index => $resource) {
            $authorId = $resource['author']['id'] ?? null;

            if ($authorId !== null && isset($names[$authorId])) {
                $resources[$index]['author']['name'] = $names[$authorId];
            }
        }

        return $resources;
    }
}
