<?php

namespace JoanFo\Resources\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a mod or plugin file already present on the server filesystem.
 * Instances are hydrated manually from the Wings file-listing API; no database table backs this model.
 *
 * @property string $id          md5 of directory + filename
 * @property string $name        filename (e.g. "EssentialsX.jar")
 * @property int    $size        file size in bytes
 * @property string $type        "mod" or "plugin"
 * @property string $directory   "/mods" or "/plugins"
 * @property string $modified_at ISO-8601 last-modified timestamp
 * @property string $version     extracted version string (e.g. "1.12.0"), defaults to "1.0.0"
 * @property string $source      "modrinth" | "curseforge" | "spigot" | "bukkit" | "manual"
 */
class InstalledFile extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
