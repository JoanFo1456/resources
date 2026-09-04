<?php

namespace JoanFo\Resources\Models;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks modpacks installed on a server, persisted in the DB migration table.
 *
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $source modrinth|curseforge
 * @property string $version_id
 * @property string|null $version_name
 * @property string|null $icon_url
 * @property string $project_id
 * @property string|null $modloader fabric|forge|neoforge|quilt|vanilla
 * @property Carbon $installed_at
 */
class InstalledModpack extends Model
{
    protected $table = 'plugin_resources_installed_modpacks';

    protected $fillable = [
        'server_id',
        'name',
        'source',
        'version_id',
        'version_name',
        'icon_url',
        'project_id',
        'modloader',
        'installed_at',
    ];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
