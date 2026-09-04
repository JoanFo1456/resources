<?php

namespace JoanFo\Resources\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use JoanFo\Resources\Models\InstalledResourceCache;
use JoanFo\Resources\Services\CurseForgeService;
use JoanFo\Resources\Services\ModrinthService;

/**
 * Identify manually-installed (or otherwise untracked) jars against Modrinth and CurseForge
 * by file hash / fingerprint, and back-fill the resolved source/project_id/version_id into
 * InstalledResourceCache — so they get recognized and update-checked like plugin-installed ones.
 *
 * This runs on the queue rather than in the Livewire request cycle because it reads whole jar
 * files off the daemon to hash them, which is far too slow to block a page load. Opening the
 * Resources page dispatches this job; the identified files simply appear on a later render.
 */
class IdentifyInstalledResourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * Skip hashing jars larger than this (bytes). getContent() throws FileSizeTooLargeException
     * above the cap rather than truncating, and a truncated read could never produce a valid hash.
     */
    private const MAX_BYTES = 75 * 1024 * 1024;

    /** How many files to fold into a single Modrinth/CurseForge lookup request. */
    private const LOOKUP_CHUNK = 100;

    /**
     * How long a file that matched neither platform stays "known manual" before we bother
     * hashing/looking it up again. Prevents re-fetching the same unmatched jars on every run;
     * the occasional re-check still catches mods that get published to a platform later.
     */
    private const RECHECK_AFTER_DAYS = 7;

    public function __construct(public Server $server) {}

    /**
     * Whether a cached resource entry still needs identification: it's a manual jar with no
     * resolved project, and we've either never checked it or last checked it long enough ago.
     * Shared with the page so it only dispatches this job when there is real work to do.
     *
     * @param  array<string, mixed>  $resource
     */
    public static function needsIdentification(array $resource): bool
    {
        if (($resource['source'] ?? null) !== 'manual' || !empty($resource['project_id'])) {
            return false;
        }

        $checkedAt = $resource['checked_at'] ?? null;
        if (!$checkedAt) {
            return true;
        }

        try {
            return Carbon::parse($checkedAt)->lt(now()->subDays(self::RECHECK_AFTER_DAYS));
        } catch (Exception) {
            return true;
        }
    }

    public function handle(
        DaemonFileRepository $fileRepository,
        ModrinthService $modrinthService,
        CurseForgeService $curseForgeService,
    ): void {
        $serverId = $this->server->id;
        $fileRepository->setServer($this->server);

        $unidentified = collect(InstalledResourceCache::all($serverId))
            ->filter(fn ($r) => self::needsIdentification($r))
            ->values();

        if ($unidentified->isEmpty()) {
            return;
        }

        // Compute both hashes for each candidate. Bytes are read one file at a time and
        // discarded immediately so memory stays bounded no matter how many jars there are.
        $entries = [];
        foreach ($unidentified as $resource) {
            $directory = $resource['directory'] ?? '';
            $filename = $resource['filename'] ?? '';
            if ($directory === '' || $filename === '') {
                continue;
            }

            try {
                $bytes = $fileRepository->getContent($directory . '/' . $filename, self::MAX_BYTES);
            } catch (Exception) {
                // Too large, missing, or daemon unreachable — leave as manual, retry on a later run.
                continue;
            }

            if ($bytes === '') {
                continue;
            }

            $entries[] = [
                'directory' => $directory,
                'filename' => $filename,
                'name' => $resource['name'] ?? pathinfo($filename, PATHINFO_FILENAME),
                'sha1' => sha1($bytes),
                'fingerprint' => CurseForgeService::fingerprint($bytes),
            ];

            unset($bytes);
        }

        foreach (array_chunk($entries, self::LOOKUP_CHUNK) as $chunk) {
            $this->identifyChunk($serverId, $chunk, $modrinthService, $curseForgeService);
        }
    }

    /**
     * @param  array<int, array{directory: string, filename: string, name: string, sha1: string, fingerprint: int}>  $entries
     */
    private function identifyChunk(
        int $serverId,
        array $entries,
        ModrinthService $modrinthService,
        CurseForgeService $curseForgeService,
    ): void {
        // Batch both APIs (one request each per chunk) rather than N per-file requests.
        $modrinthMatches = [];
        try {
            $modrinthMatches = $modrinthService->lookupByHashes(array_column($entries, 'sha1'));
        } catch (Exception) {
        }

        $curseForgeByFingerprint = [];
        if ($curseForgeService->isConfigured()) {
            try {
                foreach ($curseForgeService->lookupByFingerprints(array_column($entries, 'fingerprint')) as $match) {
                    $fp = $match['file']['fileFingerprint'] ?? null;
                    if ($fp !== null) {
                        $curseForgeByFingerprint[(int) $fp] = $match;
                    }
                }
            } catch (Exception) {
            }
        }

        $now = now()->toISOString();

        foreach ($entries as $entry) {
            // Prefer Modrinth (free, no key required); fall back to CurseForge.
            if (isset($modrinthMatches[$entry['sha1']])) {
                $version = $modrinthMatches[$entry['sha1']];
                if (!empty($version['project_id']) && !empty($version['id'])) {
                    InstalledResourceCache::put($serverId, $entry['directory'], $entry['filename'], [
                        'source' => 'modrinth',
                        'project_id' => (string) $version['project_id'],
                        'version_id' => (string) $version['id'],
                        'name' => $entry['name'],
                        'checked_at' => $now,
                    ]);

                    continue;
                }
            }

            $match = $curseForgeByFingerprint[$entry['fingerprint']] ?? null;
            if ($match && !empty($match['id']) && !empty($match['file']['id'])) {
                InstalledResourceCache::put($serverId, $entry['directory'], $entry['filename'], [
                    'source' => 'curseforge',
                    'project_id' => (string) $match['id'],
                    'version_id' => (string) $match['file']['id'],
                    'name' => $entry['name'],
                    'checked_at' => $now,
                ]);

                continue;
            }

            // No match on either platform: keep it manual but stamp checked_at so we don't
            // re-hash and re-query it every run — it's retried only after RECHECK_AFTER_DAYS.
            InstalledResourceCache::put($serverId, $entry['directory'], $entry['filename'], [
                'source' => 'manual',
                'project_id' => null,
                'version_id' => null,
                'name' => $entry['name'],
                'checked_at' => $now,
            ]);
        }
    }
}
