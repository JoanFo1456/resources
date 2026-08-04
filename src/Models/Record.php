<?php

namespace JoanFo\Resources\Models;

/**
 * A mod or plugin search result.
 *
 * @property string $id Composite cache key
 * @property string $project_id Project ID on the source platform
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $author
 * @property string|null $icon
 * @property int $downloads
 * @property string $type
 * @property string $source
 * @property string $search_query
 * @property string $project_type
 * @property int $api_page
 * @property int $api_index
 */
class Record extends JsonCachedModel
{
    /** @var array<string, string> */
    protected $schema = [
        'id'             => 'string',
        'project_id'     => 'string',
        'slug'           => 'string',
        'name'           => 'string',
        'description'    => 'text',
        'author'         => 'string',
        'icon'           => 'string',
        'downloads'      => 'integer',
        'type'           => 'string',
        'loader'         => 'string',
        'loader_detected' => 'boolean',
        'type_detected'  => 'boolean',
        'source'         => 'string',
        'search_query'   => 'string',
        'project_type'   => 'string',
        'api_page'       => 'integer',
        'api_index'      => 'integer',
    ];

    protected static function cacheFilename(): string
    {
        return 'resources.json';
    }

    /**
     * Check whether an API page has already been fetched for the given filters.
     */
    public static function isPageCached(string $searchQuery, string $projectType, string $source, int $page): bool
    {
        $sources = explode('-', $source);

        foreach (static::readCache() as $record) {
            if (($record['search_query'] ?? '') === $searchQuery
                && ($record['project_type'] ?? '') === $projectType
                && in_array($record['source'] ?? '', $sources, true)
                && ($record['api_page'] ?? 0) === $page
                && !empty($record['loader_detected'])
                && !empty($record['type_detected'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all cached records matching the given filters.
     */
    public static function clearCache(?string $searchQuery = null, ?string $projectType = null, ?string $source = null): void
    {
        $sources = $source ? explode('-', $source) : null;

        $cache = array_filter(static::readCache(), function ($record) use ($searchQuery, $projectType, $sources) {
            if ($searchQuery !== null && ($record['search_query'] ?? '') !== $searchQuery) {
                return true;
            }

            if ($projectType !== null && ($record['project_type'] ?? '') !== $projectType) {
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
