<?php

return [
    'title' => 'Minecraft Mods & Plugins',
    'navigation' => 'Mods & Plugins',
    'heading' => 'Resources',

    'search_placeholder' => 'Search mods and plugins...',
    'empty_heading' => 'No mods or plugins found',
    'empty_description' => 'Try adjusting your search filters or search for something else.',

    'table' => [
        'name' => 'Name',
        'author' => 'Author',
        'downloads' => 'Downloads',
        'type' => 'Type',
        'source' => 'Source',
    ],

    'filters' => [
        'label' => 'Filters',
        'platform' => 'Platform',
        'type' => 'Type',
        'type_all' => 'All',
        'type_mod' => 'Mod',
        'type_plugin' => 'Plugin',
    ],

    'download' => [
        'action' => 'Download',
        'heading' => 'Download :name',
        'version' => 'Select Version',
        'version_placeholder' => 'Choose a version...',
        'version_help' => 'Select the version you want to download',
        'started' => 'Download started',
        'started_body' => 'Downloading :name to the :directory folder.',
        'failed' => 'Download failed',
        'no_url' => 'Could not get a download URL.',
    ],

    'modpacks' => [
        'title' => 'Minecraft Modpacks',
        'navigation' => 'Modpacks',
        'heading' => 'Modpacks',
        'search_placeholder' => 'Search modpacks...',
        'empty_heading' => 'No modpacks found',
        'install' => 'Install',
        'install_heading' => 'Install :name',
        'delete_files' => 'Delete existing server files before installation',
        'delete_files_help' => 'Warning: this will permanently delete all current files in the server directory.',
        'queued' => 'Installation queued',
        'queued_body' => 'Installing :name… you will be notified when it finishes.',
        'installed' => 'Modpack installed',
        'installed_body' => ':name has been installed successfully.',
        'failed' => 'Modpack installation failed',
    ],

    'curseforge_error' => 'CurseForge error',
    'curseforge_not_configured' => 'CurseForge API key not configured',
    'curseforge_not_configured_body' => 'Set the CurseForge API key in the Resources plugin settings.',

    'settings' => [
        'saved' => 'Settings saved',
        'saved_body' => 'Resources plugin settings have been saved. Refresh the page to apply changes.',
        'api_config' => 'API Configuration',
        'api_config_description' => 'Configure API keys for the resource platforms',
        'curseforge_api_key' => 'CurseForge API Key',
        'curseforge_api_key_help' => 'Required for CurseForge and Bukkit plugins. Get your key from https://console.curseforge.com/',
        'platform_settings' => 'Platform Settings',
        'platform_settings_description' => 'Enable or disable resource platforms. Disabled platforms will not appear in the server panel.',
        'enable_modrinth' => 'Enable Modrinth',
        'enable_modrinth_help' => 'Free platform, no API key required',
        'enable_curseforge' => 'Enable CurseForge',
        'enable_curseforge_help' => 'Requires a CurseForge API key',
        'enable_bukkit' => 'Enable Bukkit',
        'enable_bukkit_help' => 'Hosted on CurseForge, requires the API key',
        'enable_spigot' => 'Enable Spigot',
        'enable_spigot_help' => 'Uses the Spiget API, no key required',
    ],
];
