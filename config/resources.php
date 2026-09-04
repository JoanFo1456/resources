<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CurseForge API Key
    |--------------------------------------------------------------------------
    |
    | Recommended for the CurseForge and Bukkit platforms. Get one from
    | https://console.curseforge.com/
    |
    | Without a key those platforms fall back to the public proxy configured
    | below, so browsing still works out of the box. Setting your own key is
    | still preferable: it is the officially supported path, runs on your own
    | quota, and does not depend on a third party staying online.
    |
    */
    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | CurseForge Keyless Fallback Proxy
    |--------------------------------------------------------------------------
    |
    | Public mirror of the CurseForge API, used only when no API key is set.
    | It exposes the same routes and response shapes, so it is a drop-in.
    |
    | This is a third-party service running on somebody else's API key: it has
    | no SLA and can rate-limit or disappear. Set 'curseforge_proxy_enabled' to
    | false to disable the fallback entirely and require your own key instead.
    |
    */
    'curseforge_proxy_enabled' => env('CURSEFORGE_PROXY_ENABLED', true),
    'curseforge_proxy_url' => env('CURSEFORGE_PROXY_URL', 'https://api.curse.tools/v1/cf'),

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    |
    | Modrinth and Spiget (SpigotMC) are free to use without an API key.
    |
    */
    'modrinth_api_url' => env('MODRINTH_API_URL', 'https://api.modrinth.com/v2'),
    'curseforge_api_url' => env('CURSEFORGE_API_URL', 'https://api.curseforge.com/v1'),
    'spigot_api_url' => env('SPIGOT_API_URL', 'https://api.spiget.org/v2'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Set cache_enabled to false to disable all caching. API responses will
    | always be fetched fresh and no JSON files will be written to disk.
    | Results are still held in PHP memory for the duration of each request.
    |
    | cache_ttl controls how long API responses are kept (in seconds).
    | cache_path is the directory where JSON search results are stored.
    |
    */
    'cache_enabled' => env('RESOURCES_CACHE_ENABLED', true),
    'cache_ttl' => env('RESOURCES_CACHE_TTL', 3600),
    'cache_path' => env('RESOURCES_CACHE_PATH', base_path('plugins/resources/cache')),

    /*
    |--------------------------------------------------------------------------
    | Platforms
    |--------------------------------------------------------------------------
    |
    | Enable or disable individual resource platforms. Disabled platforms do
    | not appear in the server panel. Bukkit plugins are hosted on CurseForge,
    | so the Bukkit platform also requires the CurseForge API key.
    |
    */
    'platforms' => [
        'modrinth' => env('RESOURCES_ENABLE_MODRINTH', true),
        'curseforge' => env('RESOURCES_ENABLE_CURSEFORGE', true),
        'bukkit' => env('RESOURCES_ENABLE_BUKKIT', true),
        'spigot' => env('RESOURCES_ENABLE_SPIGOT', true),
    ],
];
