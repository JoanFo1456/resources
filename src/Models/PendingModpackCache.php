<?php

namespace JoanFo\Resources\Models;

use Illuminate\Support\Facades\File;
use JoanFo\Resources\Models\Concerns\AtomicJsonFile;

class PendingModpackCache
{
    use AtomicJsonFile;

    private static function path(int $serverId): string
    {
        $dir = (string) config('resources.cache_path');

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        return $dir . '/pending_modpack_' . $serverId . '.json';
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
