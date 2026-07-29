<?php

namespace JoanFo\Resources\Jobs;

use App\Models\Egg;
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
use JoanFo\Resources\Models\InstalledModpackCache;
use JoanFo\Resources\Models\PendingModpackCache;
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
        public ?string $versionName = null,
        public ?string $iconUrl = null,
    ) {}

    public function handle(
        DaemonFileRepository $fileRepository,
        ModrinthModpackInstaller $modrinthInstaller,
        CurseForgeModpackInstaller $curseForgeInstaller,
    ): void {
        $fileRepository->setServer($this->server);

        $this->server->loadMissing('user');

        try {
            $serverId = $this->server->id;
            $modloader = match ($this->source) {
                'modrinth' => $modrinthInstaller->installByVersionId($fileRepository, $this->versionId, $this->deleteFiles, $serverId),
                'curseforge' => $curseForgeInstaller->install($fileRepository, (int) $this->projectId, (int) $this->versionId, $this->deleteFiles, $serverId),
                default => throw new InvalidArgumentException('Unknown modpack source: '.$this->source),
            };

            $this->switchEggForModloader($modloader);

            InstalledModpackCache::put($this->server->id, [
                'name'         => $this->modpackName,
                'source'       => $this->source,
                'version_id'   => $this->versionId,
                'project_id'   => $this->projectId,
                'modloader'    => $modloader,
                'version_name' => $this->versionName,
                'icon_url'     => $this->iconUrl,
                'installed_at' => now()->toISOString(),
            ]);

            PendingModpackCache::remove($this->server->id);

            Notification::make()
                ->success()
                ->title(trans('resources::resources.modpacks.installed'))
                ->body(trans('resources::resources.modpacks.installed_body', ['name' => $this->modpackName]))
                ->sendToDatabase($this->server->user);
        } catch (Exception $e) {
            report($e);

            PendingModpackCache::remove($this->server->id);

            Notification::make()
                ->danger()
                ->title(trans('resources::resources.modpacks.failed'))
                ->body("{$this->modpackName}: ".$e->getMessage())
                ->sendToDatabase($this->server->user);
        }
    }

    /**
     * Find the best matching egg for the detected modloader and assign it to the server.
     * Looks for an egg whose tags include the modloader name. Falls back silently if none found.
     */
    private function switchEggForModloader(string $modloader): void
    {
        if ($modloader === 'vanilla') {
            return;
        }

        $egg = Egg::all()->first(fn (Egg $e) => in_array($modloader, $e->tags ?? [], true));

        if (!$egg) {
            return;
        }

        if ($egg->id === $this->server->egg_id) {
            return;
        }

        $this->server->egg_id = $egg->id;
        $this->server->save();
    }
}
