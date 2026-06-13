<?php

namespace JoanFo\Resources\Models;

/**
 * A modpack search result.
 *
 * @property string $id Composite cache key
 * @property string $project_id Project ID on the source platform
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $author
 * @property string $icon
 * @property int $downloads
 * @property string $source
 * @property string $search_query
 * @property int $page_number
 */
class Modpack extends JsonCachedModel
{
    /** @var array<string, string> */
    protected $schema = [
        'id' => 'string',
        'project_id' => 'string',
        'slug' => 'string',
        'name' => 'string',
        'description' => 'string',
        'author' => 'string',
        'icon' => 'string',
        'downloads' => 'integer',
        'source' => 'string',
        'search_query' => 'string',
        'page_number' => 'integer',
    ];

    protected static function cacheFilename(): string
    {
        return 'modpacks.json';
    }

    /**
     * Check whether an API page has already been fetched for the given filters.
     */
    public static function isPageCached(?string $searchQuery, string $source, int $pageNumber): bool
    {
        $sources = explode('-', $source);

        foreach (static::readCache() as $record) {
            if (($record['search_query'] ?? '') === ($searchQuery ?? '')
                && in_array($record['source'] ?? '', $sources, true)
                && ($record['page_number'] ?? 0) === $pageNumber
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all cached modpacks matching the given filters.
     */
    public static function clearCache(?string $searchQuery = null, ?string $source = null): void
    {
        $sources = $source ? explode('-', $source) : null;

        $cache = array_filter(static::readCache(), function ($record) use ($searchQuery, $sources) {
            if ($searchQuery !== null && ($record['search_query'] ?? '') !== $searchQuery) {
                return true;
            }

            if ($sources !== null && !in_array($record['source'] ?? '', $sources, true)) {
                return true;
            }

            return false;
        });

        static::writeCache($cache);
    }
}
