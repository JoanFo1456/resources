<?php

namespace JoanFo\Resources\Models;

use Illuminate\Support\Facades\File;
use JoanFo\Resources\Models\Concerns\AtomicJsonFile;

/**
 * JSON-file-backed store for tracking which mods/plugins are installed on a server.
 *
 * One file per server at {cache_path}/installed_{serverId}.json.
 * Keyed by "directory/filename" → {source, project_id, version_id, name, directory, filename}.
 * Easy to inspect and delete when stale.
 */
class InstalledResourceCache
{
    use AtomicJsonFile;

    private static function path(int $serverId): string
    {
        $dir = (string) config('resources.cache_path');

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        return $dir . '/installed_' . $serverId . '.json';
    }

    /** @return array<string, array<string, mixed>> Keyed by "directory/filename" */
    public static function all(int $serverId): array
    {
        return static::readJsonFile(static::path($serverId)) ?? [];
    }

    public static function get(int $serverId, string $directory, string $filename): ?array
    {
        return static::all($serverId)[$directory . '/' . $filename] ?? null;
    }

    public static function put(int $serverId, string $directory, string $filename, array $data): void
    {
        $all = static::all($serverId);
        $all[$directory . '/' . $filename] = array_merge($data, [
            'directory' => $directory,
            'filename'  => $filename,
        ]);
        static::writeJsonFile(static::path($serverId), $all);
    }

    public static function remove(int $serverId, string $directory, string $filename): void
    {
        $all = static::all($serverId);
        unset($all[$directory . '/' . $filename]);
        static::writeJsonFile(static::path($serverId), $all);
    }
}
