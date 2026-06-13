<?php

namespace JoanFo\Resources\Services\ModpackInstallers;

use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Support\Facades\Http;
use JoanFo\Resources\Services\ModrinthService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ModrinthModpackInstaller
{
    public function __construct(
        private ModrinthService $modrinthService
    ) {}

    /**
     * Get the download URL of the .mrpack file (Modrinth modpack format) of a version.
     */
    public function getDownloadUrl(string $versionId): string
    {
        $version = $this->modrinthService->getVersion($versionId);

        foreach ($version['files'] ?? [] as $file) {
            $filename = $file['filename'] ?? '';

            if (str_ends_with(strtolower($filename), '.mrpack') && isset($file['url'])) {
                return $file['url'];
            }
        }

        throw new Exception('No .mrpack file found in modpack version');
    }

    public function installByVersionId(DaemonFileRepository $fileRepo, string $versionId, bool $deleteFiles): void
    {
        $this->install($fileRepo, $this->getDownloadUrl($versionId), $deleteFiles);
    }

    public function install(DaemonFileRepository $fileRepo, string $url, bool $deleteFiles): void
    {
        if ($deleteFiles) {
            $this->deleteAllServerFiles($fileRepo);
        }

        // Stream the .mrpack to a local temp file to avoid loading it into PHP memory
        $tempFile = tempnam(sys_get_temp_dir(), 'modpack_');
        $fp = $tempFile === false ? false : fopen($tempFile, 'wb');

        if ($fp === false) {
            throw new Exception('Failed to create temporary file for modpack download');
        }

        $response = Http::withOptions(['sink' => $fp, 'timeout' => 300])->get($url);
        fclose($fp);

        if (!$response->successful()) {
            @unlink($tempFile);
            throw new Exception('Failed to download modpack: HTTP ' . $response->status());
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            unlink($tempFile);
            throw new Exception('Failed to open modpack file');
        }

        $tempDir = sys_get_temp_dir() . '/' . uniqid('modpack_extract_');
        mkdir($tempDir);
        $zip->extractTo($tempDir);
        $zip->close();
        unlink($tempFile);

        $manifestPath = $tempDir . '/modrinth.index.json';
        if (!file_exists($manifestPath)) {
            $this->deleteLocalDirectory($tempDir);
            throw new Exception('Invalid modpack: missing modrinth.index.json');
        }

        $manifestContent = file_get_contents($manifestPath);
        if ($manifestContent === false) {
            $this->deleteLocalDirectory($tempDir);
            throw new Exception('Failed to read modpack manifest');
        }

        $manifest = json_decode($manifestContent, true);

        $deps = $manifest['dependencies'] ?? [];
        $minecraftVersion = $deps['minecraft'] ?? null;

        if (isset($deps['fabric-loader']) && $minecraftVersion) {
            $this->downloadFabricServer($fileRepo, $minecraftVersion, $deps['fabric-loader']);
        } elseif (isset($deps['quilt-loader']) && $minecraftVersion) {
            $this->downloadQuiltServer($fileRepo, $minecraftVersion, $deps['quilt-loader']);
        } elseif (isset($deps['neoforge']) && $minecraftVersion) {
            $this->downloadNeoForgeInstaller($fileRepo, $deps['neoforge']);
        } elseif (isset($deps['forge']) && $minecraftVersion) {
            $this->downloadForgeInstaller($fileRepo, $minecraftVersion, $deps['forge']);
        } elseif ($minecraftVersion) {
            $this->downloadVanillaServer($fileRepo, $minecraftVersion);
        }

        foreach ($manifest['files'] ?? [] as $file) {
            $downloadUrl = $file['downloads'][0] ?? null;
            $filePath = $file['path'] ?? null;

            if ($downloadUrl && $filePath) {
                try {
                    $fileRepo->pull($downloadUrl, '/' . dirname($filePath), [
                        'filename' => basename($filePath),
                        'foreground' => false,
                    ]);
                } catch (Exception $e) {
                    // A single failed file should not abort the whole installation.
                    report($e);
                }
            }
        }

        $this->deleteLocalDirectory($tempDir);
    }

    private function deleteAllServerFiles(DaemonFileRepository $fileRepo): void
    {
        $contents = $fileRepo->getDirectory('/');
        $filesToDelete = collect($contents)->pluck('name')->toArray();

        if (!empty($filesToDelete)) {
            $fileRepo->deleteFiles('/', $filesToDelete);
        }
    }

    private function downloadFabricServer(DaemonFileRepository $fileRepo, string $mcVersion, string $loaderVersion): void
    {
        try {
            $url = "https://meta.fabricmc.net/v2/versions/loader/{$mcVersion}/{$loaderVersion}/1.0.0/server/jar";
            $fileRepo->pull($url, '/', ['filename' => 'server.jar', 'foreground' => false]);
        } catch (Exception $e) {
            report($e);
        }
    }

    private function downloadQuiltServer(DaemonFileRepository $fileRepo, string $mcVersion, string $loaderVersion): void
    {
        try {
            $url = "https://meta.quiltmc.org/v3/versions/loader/{$mcVersion}/{$loaderVersion}/server/jar";
            $fileRepo->pull($url, '/', ['filename' => 'server.jar', 'foreground' => false]);
        } catch (Exception $e) {
            report($e);
        }
    }

    private function downloadForgeInstaller(DaemonFileRepository $fileRepo, string $mcVersion, string $forgeVersion): void
    {
        try {
            $url = "https://maven.minecraftforge.net/net/minecraftforge/forge/{$mcVersion}-{$forgeVersion}/forge-{$mcVersion}-{$forgeVersion}-installer.jar";
            $fileRepo->pull($url, '/', ['filename' => 'forge-installer.jar', 'foreground' => false]);
        } catch (Exception $e) {
            report($e);
        }
    }

    private function downloadNeoForgeInstaller(DaemonFileRepository $fileRepo, string $neoforgeVersion): void
    {
        try {
            $url = "https://maven.neoforged.net/releases/net/neoforged/neoforge/{$neoforgeVersion}/neoforge-{$neoforgeVersion}-installer.jar";
            $fileRepo->pull($url, '/', ['filename' => 'neoforge-installer.jar', 'foreground' => false]);
        } catch (Exception $e) {
            report($e);
        }
    }

    private function downloadVanillaServer(DaemonFileRepository $fileRepo, string $version): void
    {
        try {
            $manifestResponse = Http::get('https://launchermeta.mojang.com/mc/game/version_manifest.json');
            if (!$manifestResponse->successful()) {
                return;
            }

            $versionInfo = collect($manifestResponse->json()['versions'] ?? [])->firstWhere('id', $version);
            if (!$versionInfo) {
                return;
            }

            $serverDownloadUrl = Http::get($versionInfo['url'])->json()['downloads']['server']['url'] ?? null;
            if (!$serverDownloadUrl) {
                return;
            }

            $fileRepo->pull($serverDownloadUrl, '/', ['filename' => 'server.jar', 'foreground' => false]);
        } catch (Exception $e) {
            report($e);
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
