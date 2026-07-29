<?php

namespace JoanFo\Resources\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $server_id
 * @property string $filename
 * @property string $directory
 * @property string $source      modrinth|curseforge|bukkit|spigot
 * @property string $project_id
 * @property string $name
 */
class InstalledResource extends Model
{
    protected $table = 'plugin_resources_installed_resources';

    protected $fillable = [
        'server_id',
        'filename',
        'directory',
        'source',
        'project_id',
        'version_id',
        'name',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
