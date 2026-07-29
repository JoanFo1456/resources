<?php

namespace JoanFo\Resources\Observers;

use App\Models\Server;
use JoanFo\Resources\Models\InstalledModpack;

/**
 * Removes tracked installed-modpack records for a server when its egg is changed
 * to one that no longer carries the 'minecraft' tag.
 */
class ServerEggObserver
{
    public function updated(Server $server): void
    {
        if (!$server->wasChanged('egg_id')) {
            return;
        }

        $server->loadMissing('egg');

        if (in_array('minecraft', $server->egg->tags ?? [], true)) {
            return;
        }

        InstalledModpack::where('server_id', $server->id)->delete();
    }
}
