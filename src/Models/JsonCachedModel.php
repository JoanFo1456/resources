<?php

namespace JoanFo\Resources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Sushi\Sushi;

/**
 * Base model for API results that are persisted to a JSON file and exposed
 * to Filament tables through Sushi's in-memory SQLite driver.
 *
 * When caching is disabled (resources.cache_enabled = false) the JSON file
 * is never written; data is kept in a static array for the current request only.
 */
abstract class JsonCachedModel extends Model
{
    use Sushi;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $memoryStore = [];

    /**
     * The JSON file inside the plugin cache directory backing this model.
     */
    abstract protected static function cacheFilename(): string;

    protected function sushiShouldCache(): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        return array_values(static::readCache());
    }

    public static function getCachePath(): string
    {
        $directory = config('resources.cache_path');

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        return $directory.'/'.static::cacheFilename();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function readCache(): array
    {
        if (!config('resources.cache_enabled', true)) {
            return self::$memoryStore[static::cacheFilename()] ?? [];
        }

        $path = static::getCachePath();

        if (!File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?: [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $data
     */
    public static function writeCache(array $data): void
    {
        if (!config('resources.cache_enabled', true)) {
            self::$memoryStore[static::cacheFilename()] = $data;

            return;
        }

        $path = static::getCachePath();

        File::put($path, json_encode($data), lock: true);
        @chmod($path, 0664);
    }
}
