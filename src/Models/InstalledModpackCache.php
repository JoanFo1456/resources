<?php

namespace JoanFo\Resources\Models;

use Illuminate\Support\Facades\File;
use JoanFo\Resources\Models\Concerns\AtomicJsonFile;

/**
 * JSON-file-backed store for the single modpack installed on a server.
 *
 * One file per server at {cache_path}/modpack_{serverId}.json.
 * Easy to inspect or delete manually.
 */
class InstalledModpackCache
{
    use AtomicJsonFile;

    private static function path(int $serverId): string
    {
        $dir = (string) config('resources.cache_path');

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        return $dir . '/modpack_' . $serverId . '.json';
    }

    public static function get(int $serverId): ?array
    {
        return static::readJsonFile(static::path($serverId)) ?: null;
    }

    public static function put(int $serverId, array $data): void
    {
        static::writeJsonFile(static::path($serverId), $data);
    }

    public static function remove(int $serverId): void
    {
        $path = static::path($serverId);

        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
