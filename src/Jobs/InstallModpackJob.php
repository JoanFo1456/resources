<?php

namespace JoanFo\Resources\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use JoanFo\Resources\Services\ModpackInstallers\CurseForgeModpackInstaller;
use JoanFo\Resources\Services\ModpackInstallers\ModrinthModpackInstaller;

class InstallModpackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public Server $server,
        public string $source,
        public string $versionId,
        public string $projectId,
        public bool $deleteFiles,
        public string $modpackName,
    ) {}

    public function handle(
        DaemonFileRepository $fileRepository,
        ModrinthModpackInstaller $modrinthInstaller,
        CurseForgeModpackInstaller $curseForgeInstaller,
    ): void {
        $fileRepository->setServer($this->server);

        $this->server->loadMissing('user');

        try {
            match ($this->source) {
                'modrinth' => $modrinthInstaller->installByVersionId($fileRepository, $this->versionId, $this->deleteFiles),
                'curseforge' => $curseForgeInstaller->install($fileRepository, (int) $this->projectId, (int) $this->versionId, $this->deleteFiles),
                default => throw new InvalidArgumentException('Unknown modpack source: '.$this->source),
            };

            Notification::make()
                ->success()
                ->title(trans('resources::resources.modpacks.installed'))
                ->body(trans('resources::resources.modpacks.installed_body', ['name' => $this->modpackName]))
                ->sendToDatabase($this->server->user);
        } catch (Exception $e) {
            report($e);

            Notification::make()
                ->danger()
                ->title(trans('resources::resources.modpacks.failed'))
                ->body("{$this->modpackName}: ".$e->getMessage())
                ->sendToDatabase($this->server->user);
        }
    }
}
