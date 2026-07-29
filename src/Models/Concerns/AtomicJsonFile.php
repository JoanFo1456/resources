<?php

namespace JoanFo\Resources\Models\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Atomic, decode-tolerant JSON file helpers.
 *
 * Writes go to a per-process temp file and are swapped in with rename(), which is
 * atomic on the same filesystem. Concurrent readers therefore only ever observe the
 * complete previous file or the complete new file — never a truncated, half-written
 * one. This matters because our JSON caches are read on every Livewire render (the
 * installed-modpack card auto-refreshes every few seconds) while the queue worker and
 * lazy icon fetch write the same files. A plain file_put_contents(LOCK_EX) truncates
 * before it locks, so an unlocked reader can catch an empty file and decode it to null,
 * making the card vanish for that render.
 */
trait AtomicJsonFile
{
    /**
     * Read and decode a JSON file. Returns null when the file is missing, empty, or
     * cannot be decoded (e.g. a transient partial read), so callers can treat a bad
     * read the same as "not there yet" instead of crashing.
     *
     * @return array<mixed>|null
     */
    protected static function readJsonFile(string $path): ?array
    {
        if (!File::exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Atomically write $data as JSON to $path.
     *
     * @param  array<mixed>  $data
     */
    protected static function writeJsonFile(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tmp = $path . '.' . getmypid() . '.tmp';

        File::put($tmp, $json);
        @chmod($tmp, 0664);

        // Atomic replace — readers see either the old or the new file, never a partial one.
        if (!@rename($tmp, $path)) {
            // Fall back to an in-place write if rename is unavailable (e.g. cross-device).
            File::put($path, $json, lock: true);
            @chmod($path, 0664);
            @unlink($tmp);
        }
    }
}
