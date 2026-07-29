<?php

namespace JoanFo\Resources\Services\ModpackInstallers;

use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Support\Facades\Http;
use JoanFo\Resources\Models\InstalledResourceCache;
use JoanFo\Resources\Services\CurseForgeService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class CurseForgeModpackInstaller
{
    public function __construct(
        private CurseForgeService $curseForgeService
    ) {}

    /** Returns the detected modloader name (fabric|forge|neoforge|quilt|vanilla). */
    public function install(DaemonFileRepository $fileRepo, int $projectId, int $fileId, bool $deleteFiles, int $serverId = 0): string
    {
        if ($deleteFiles) {
            $this->deleteAllServerFiles($fileRepo);
        }

        // Fast path: most popular packs publish a prebuilt "server pack" — a single archive that
        // already bundles every mod + config. When one exists, let the daemon pull that one file
        // straight from the CurseForge CDN and decompress it in place, instead of resolving and
        // pulling every mod individually and streaming overrides file-by-file through the panel.
        $file = $this->curseForgeService->getFile($projectId, $fileId);
        $serverPackFileId = $file['serverPackFileId'] ?? null;

        if (!empty($serverPackFileId)) {
            try {
                return $this->installFromServerPack(
                    $fileRepo,
                    $projectId,
                    (int) $serverPackFileId,
                    $file['gameVersions'] ?? [],
                );
            } catch (Exception $e) {
                // Anything wrong with the server pack (no CDN url, bad archive, daemon error) —
                // fall back to the reliable manifest walk below rather than failing the install.
                report($e);
            }
        }

        return $this->installFromManifest($fileRepo, $projectId, $fileId, $serverId);
    }

    /**
     * Fast install: the daemon downloads the prebuilt server pack directly from the CurseForge
     * CDN and extracts it server-side. One pull + one decompress instead of hundreds of transfers.
     *
     * Individual mods aren't tracked here (the pack bundles them without per-file ids); the
     * background identification job fingerprint-matches them afterwards so they still get
     * update checks.
     *
     * @param  array<int, string>  $gameVersions
     */
    private function installFromServerPack(DaemonFileRepository $fileRepo, int $projectId, int $serverPackFileId, array $gameVersions): string
    {
        $url = $this->curseForgeService->getDownloadUrl($projectId, $serverPackFileId);
        if (!$url) {
            throw new Exception('Server pack has no available download URL');
        }

        $archive = 'cf_serverpack_' . $serverPackFileId . '.zip';

        // Background pull: the daemon downloads the pack straight from the CDN and returns
        // immediately. We must NOT use foreground:true — the panel→daemon HTTP client times out
        // after config('panel.guzzle.timeout') (15s by default), which a large pack blows past,
        // and the resulting error would drop us onto the slow manifest fallback.
        $fileRepo->pull($url, '/', ['filename' => $archive, 'foreground' => false]);

        // Wait (on the queue, off the request cycle) for the daemon to finish writing the archive,
        // then extract it server-side.
        $this->waitForDownload($fileRepo, '/', $archive);

        $fileRepo->decompressFile('/', $archive);
        $fileRepo->deleteFiles('/', [$archive]);

        $this->normalizeServerPackLayout($fileRepo);

        return $this->detectLoaderFromGameVersions($gameVersions) ?? 'vanilla';
    }

    /**
     * Poll the daemon until a background-pulled file has finished downloading: wait for it to
     * appear, then for its size to stop growing across consecutive polls. Runs inside the queue
     * job, so blocking here is fine.
     */
    private function waitForDownload(DaemonFileRepository $fileRepo, string $root, string $filename, int $maxSeconds = 540): void
    {
        $deadline    = time() + $maxSeconds;
        $lastSize    = -1;
        $stablePolls = 0;
        $appeared    = false;

        while (time() < $deadline) {
            sleep(3);

            try {
                $entry = collect($fileRepo->getDirectory($root))->firstWhere('name', $filename);
            } catch (Exception) {
                continue; // transient daemon hiccup — keep waiting
            }

            if (!$entry) {
                // Still not on disk. Before it ever appears that's fine; if it appeared and then
                // vanished, something extracted/removed it — treat as done.
                if ($appeared) {
                    return;
                }
                continue;
            }

            $appeared = true;
            $size = (int) ($entry['size'] ?? 0);

            if ($size > 0 && $size === $lastSize) {
                if (++$stablePolls >= 2) {
                    return; // size held steady across ~6s — download complete
                }
            } else {
                $stablePolls = 0;
            }

            $lastSize = $size;
        }

        throw new Exception('Timed out waiting for the server pack to finish downloading');
    }

    /**
     * Some server packs extract everything under a single wrapper folder (e.g. "ServerFiles/mods")
     * instead of at the root. Detect that and move the wrapper's contents up so the egg's startup
     * finds mods/ and config/ where it expects them.
     */
    private function normalizeServerPackLayout(DaemonFileRepository $fileRepo): void
    {
        $root = collect($fileRepo->getDirectory('/'));

        $isDir = fn ($e) => !($e['file'] ?? true);

        // Already laid out correctly — mods/ or config/ sits at the root.
        $hasPackDirAtRoot = $root->contains(
            fn ($e) => $isDir($e) && in_array(strtolower($e['name'] ?? ''), ['mods', 'config'], true)
        );
        if ($hasPackDirAtRoot) {
            return;
        }

        // Otherwise, if the pack extracted into exactly one wrapper directory, hoist its contents.
        $dirs = $root->filter($isDir)->values();
        if ($dirs->count() !== 1) {
            return;
        }

        $wrapper = $dirs->first()['name'] ?? '';
        if ($wrapper === '') {
            return;
        }

        $moves = collect($fileRepo->getDirectory('/' . $wrapper))
            ->map(fn ($e) => ['from' => $wrapper . '/' . $e['name'], 'to' => $e['name']])
            ->values()
            ->all();

        if (!empty($moves)) {
            $fileRepo->renameFiles('/', $moves);
            $fileRepo->deleteFiles('/', [$wrapper]);
        }
    }

    /**
     * Detect the modloader from a CurseForge file's gameVersions list, which contains loader
     * names alongside the Minecraft version, e.g. ["1.20.1", "NeoForge", "Server"].
     *
     * @param  array<int, string>  $gameVersions
     */
    private function detectLoaderFromGameVersions(array $gameVersions): ?string
    {
        $lower = array_map('strtolower', $gameVersions);

        // NeoForge before Forge so a pack tagged both resolves to the more specific one.
        foreach (['neoforge', 'fabric', 'quilt', 'forge'] as $loader) {
            if (in_array($loader, $lower, true)) {
                return $loader;
            }
        }

        return null;
    }

    /** Slow but universal fallback: download the client manifest pack and resolve every mod. */
    private function installFromManifest(DaemonFileRepository $fileRepo, int $projectId, int $fileId, int $serverId): string
    {
        $downloadUrl = $this->curseForgeService->getDownloadUrl($projectId, $fileId);
        if (!$downloadUrl) {
            throw new Exception('Could not get download URL from CurseForge API');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cfpack_');
        $fp = $tempFile === false ? false : fopen($tempFile, 'wb');

        if ($fp === false) {
            throw new Exception('Failed to create temporary file for modpack download');
        }

        $response = Http::withOptions(['sink' => $fp, 'timeout' => 300])->get($downloadUrl);
        fclose($fp);

        if (!$response->successful()) {
            @unlink($tempFile);
            throw new Exception('Failed to download modpack: HTTP ' . $response->status());
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            throw new Exception('Failed to open modpack zip');
        }

        $tempDir = sys_get_temp_dir() . '/' . uniqid('cfpack_extract_');
        mkdir($tempDir);
        $zip->extractTo($tempDir);
        $zip->close();
        @unlink($tempFile);

        $manifestPath = $tempDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            $this->deleteLocalDirectory($tempDir);
            throw new Exception('Invalid modpack: missing manifest.json');
        }

        $manifestContent = file_get_contents($manifestPath);
        if ($manifestContent === false) {
            $this->deleteLocalDirectory($tempDir);
            throw new Exception('Failed to read modpack manifest');
        }

        $manifest = json_decode($manifestContent, true);

        $mcVersion = $manifest['minecraft']['version'] ?? null;
        $primaryLoader = collect($manifest['minecraft']['modLoaders'] ?? [])
            ->firstWhere('primary', true);
        $loaderId = $primaryLoader['id'] ?? '';

        if ($primaryLoader) {
            $this->downloadModLoader($fileRepo, $mcVersion, $loaderId);
        } elseif ($mcVersion) {
            $this->downloadVanillaServer($fileRepo, $mcVersion);
        }

        $modloader = $this->detectModloaderFromId($loaderId) ?? 'vanilla';

        $modEntries = array_values(array_filter(
            $manifest['files'] ?? [],
            fn ($e) => !empty($e['projectID']) && !empty($e['fileID'])
        ));

        $this->downloadManifestMods($fileRepo, $modEntries, $serverId);

        $overridesDir = $tempDir . '/overrides';
        if (is_dir($overridesDir)) {
            $this->uploadOverrides($fileRepo, $overridesDir);
        }

        $this->deleteLocalDirectory($tempDir);

        return $modloader;
    }

    /**
     * Download every mod in the manifest. CDN-first: build the keyless redirect URL straight from
     * each entry's projectID/fileID so the daemon pulls from the CurseForge CDN without spending
     * any api.curseforge.com quota. If a quick probe shows the keyless endpoint isn't usable, fall
     * back to resolving real download URLs through the official API (which also yields filenames
     * for direct tracking).
     *
     * In the CDN-first case the on-disk filenames aren't known here, so mods are tracked afterwards
     * by the background identification job (fingerprint match) rather than inline.
     *
     * @param  array<int, array<string, mixed>>  $modEntries
     */
    private function downloadManifestMods(DaemonFileRepository $fileRepo, array $modEntries, int $serverId): void
    {
        if (empty($modEntries)) {
            return;
        }

        if ($this->keylessEndpointUsable($modEntries[0])) {
            foreach ($modEntries as $modEntry) {
                $url = CurseForgeService::keylessDownloadUrl(
                    (int) $modEntry['projectID'],
                    (int) $modEntry['fileID'],
                );

                try {
                    // use_header so the daemon names the file from the redirect target rather than
                    // the "…/download" URL path.
                    $fileRepo->pull($url, '/mods', ['foreground' => false, 'use_header' => true]);
                } catch (Exception $e) {
                    report($e);
                }
            }

            return;
        }

        // Fallback: official API. Batched URL resolution, with filenames for inline tracking.
        $downloadUrls = $this->curseForgeService->getDownloadUrls(
            array_map(fn ($e) => (int) $e['fileID'], $modEntries)
        );

        foreach ($modEntries as $modEntry) {
            $modProjectId = (int) $modEntry['projectID'];
            $modFileId    = (int) $modEntry['fileID'];
            $modUrl       = $downloadUrls[$modFileId] ?? null;

            if (!$modUrl) {
                continue;
            }

            // getDownloadUrls may itself hand back a keyless redirect for opted-out mods; those
            // can't be tracked by URL either, so name via headers and leave them to the job.
            $isRedirect = str_contains($modUrl, 'curseforge.com/api/v1');

            try {
                $fileRepo->pull($modUrl, '/mods', array_filter([
                    'foreground' => false,
                    'use_header' => $isRedirect ? true : null,
                ], fn ($v) => !is_null($v)));

                if ($serverId && !$isRedirect) {
                    $filename = urldecode(basename(parse_url($modUrl, PHP_URL_PATH) ?? ''));
                    if ($filename) {
                        InstalledResourceCache::put($serverId, '/mods', $filename, [
                            'source'     => 'curseforge',
                            'project_id' => (string) $modProjectId,
                            'version_id' => (string) $modFileId,
                            'name'       => pathinfo($filename, PATHINFO_FILENAME),
                            'directory'  => '/mods',
                            'filename'   => $filename,
                        ]);
                    }
                }
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    /**
     * Cheap check that the keyless website download endpoint still 307-redirects to the CDN for a
     * sample mod, so we can commit the whole pack to the CDN-first path. One request, no key.
     *
     * @param  array<string, mixed>  $sampleEntry
     */
    private function keylessEndpointUsable(array $sampleEntry): bool
    {
        try {
            $url = CurseForgeService::keylessDownloadUrl(
                (int) $sampleEntry['projectID'],
                (int) $sampleEntry['fileID'],
            );

            $response = Http::withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(10)
                ->get($url);

            $status   = $response->status();
            $location = (string) $response->header('Location');

            return $status >= 300 && $status < 400 && str_contains($location, 'forgecdn.net');
        } catch (Exception) {
            return false;
        }
    }

    private function detectModloaderFromId(string $loaderId): ?string
    {
        if (str_starts_with($loaderId, 'fabric-')) {
            return 'fabric';
        }
        if (str_starts_with($loaderId, 'neoforge-')) {
            return 'neoforge';
        }
        if (str_starts_with($loaderId, 'forge-')) {
            return 'forge';
        }
        if (str_starts_with($loaderId, 'quilt-')) {
            return 'quilt';
        }

        return null;
    }

    private function uploadOverrides(DaemonFileRepository $fileRepo, string $overridesDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($overridesDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($overridesDir));
            $serverPath = '/' . ltrim($relativePath, '/\\');
            $serverPath = preg_replace('#/+#', '/', $serverPath);

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            try {
                $fileRepo->putContent($serverPath, $content);
            } catch (Exception $e) {
                report($e);
            }
        }
    }

    private function downloadModLoader(DaemonFileRepository $fileRepo, ?string $mcVersion, string $loaderId): void
    {
        if (str_starts_with($loaderId, 'fabric-')) {
            $loaderVersion = substr($loaderId, strlen('fabric-'));
            if ($mcVersion && $loaderVersion) {
                try {
                    $url = "https://meta.fabricmc.net/v2/versions/loader/{$mcVersion}/{$loaderVersion}/1.0.0/server/jar";
                    $fileRepo->pull($url, '/', ['filename' => 'server.jar', 'foreground' => false]);
                } catch (Exception $e) {
                    report($e);
                }
            }
        } elseif (str_starts_with($loaderId, 'neoforge-')) {
            $neoforgeVersion = substr($loaderId, strlen('neoforge-'));
            if ($neoforgeVersion) {
                try {
                    $url = "https://maven.neoforged.net/releases/net/neoforged/neoforge/{$neoforgeVersion}/neoforge-{$neoforgeVersion}-installer.jar";
                    $fileRepo->pull($url, '/', ['filename' => 'neoforge-installer.jar', 'foreground' => false]);
                } catch (Exception $e) {
                    report($e);
                }
            }
        } elseif (str_starts_with($loaderId, 'forge-')) {
            $forgeVersion = substr($loaderId, strlen('forge-'));
            if ($mcVersion && $forgeVersion) {
                try {
                    $url = "https://maven.minecraftforge.net/net/minecraftforge/forge/{$mcVersion}-{$forgeVersion}/forge-{$mcVersion}-{$forgeVersion}-installer.jar";
                    $fileRepo->pull($url, '/', ['filename' => 'forge-installer.jar', 'foreground' => false]);
                } catch (Exception $e) {
                    report($e);
                }
            }
        } elseif ($mcVersion) {
            $this->downloadVanillaServer($fileRepo, $mcVersion);
        }
    }

    private function downloadVanillaServer(DaemonFileRepository $fileRepo, string $version): void
    {
        try {
            $manifest = Http::get('https://launchermeta.mojang.com/mc/game/version_manifest.json')->json();
            $versionInfo = collect($manifest['versions'] ?? [])->firstWhere('id', $version);
            if (!$versionInfo) {
                return;
            }

            $serverUrl = Http::get($versionInfo['url'])->json()['downloads']['server']['url'] ?? null;
            if ($serverUrl) {
                $fileRepo->pull($serverUrl, '/', ['filename' => 'server.jar', 'foreground' => false]);
            }
        } catch (Exception $e) {
            report($e);
        }
    }

    private function deleteAllServerFiles(DaemonFileRepository $fileRepo): void
    {
        $contents = $fileRepo->getDirectory('/');
        $filesToDelete = collect($contents)->pluck('name')->toArray();

        if (!empty($filesToDelete)) {
            $fileRepo->deleteFiles('/', $filesToDelete);
        }
    }

    private function deleteLocalDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
