<?php

namespace JoanFo\Resources\Services\ModpackInstallers;

use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Support\Facades\Http;
use JoanFo\Resources\Services\CurseForgeService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class CurseForgeModpackInstaller
{
    public function __construct(
        private CurseForgeService $curseForgeService
    ) {}

    public function install(DaemonFileRepository $fileRepo, int $projectId, int $fileId, bool $deleteFiles): void
    {
        if ($deleteFiles) {
            $this->deleteAllServerFiles($fileRepo);
        }

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

        if ($primaryLoader) {
            $this->downloadModLoader($fileRepo, $mcVersion, $primaryLoader['id'] ?? '');
        } elseif ($mcVersion) {
            $this->downloadVanillaServer($fileRepo, $mcVersion);
        }

        foreach ($manifest['files'] ?? [] as $modEntry) {
            $modProjectId = $modEntry['projectID'] ?? null;
            $modFileId = $modEntry['fileID'] ?? null;

            if (!$modProjectId || !$modFileId) {
                continue;
            }

            try {
                $modUrl = $this->curseForgeService->getDownloadUrl((int) $modProjectId, (int) $modFileId);
                if ($modUrl) {
                    $fileRepo->pull($modUrl, '/mods', ['foreground' => false]);
                }
            } catch (Exception $e) {
                report($e);
            }
        }

        $overridesDir = $tempDir . '/overrides';
        if (is_dir($overridesDir)) {
            $this->uploadOverrides($fileRepo, $overridesDir);
        }

        $this->deleteLocalDirectory($tempDir);
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
