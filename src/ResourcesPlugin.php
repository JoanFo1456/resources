<?php

namespace JoanFo\Resources;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use JoanFo\Resources\Filament\Server\Pages\ModpacksPage;
use JoanFo\Resources\Filament\Server\Pages\ResourcesPage;

class ResourcesPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'resources';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            $panel->pages([
                ResourcesPage::class,
                ModpacksPage::class,
            ]);
        }
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'curseforge_api_key' => config('resources.curseforge_api_key'),
            'enable_modrinth' => config('resources.platforms.modrinth'),
            'enable_curseforge' => config('resources.platforms.curseforge'),
            'enable_bukkit' => config('resources.platforms.bukkit'),
            'enable_spigot' => config('resources.platforms.spigot'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('resources::resources.settings.api_config'))
                ->description(trans('resources::resources.settings.api_config_description'))
                ->columns(2)
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label(trans('resources::resources.settings.curseforge_api_key'))
                        ->helperText(trans('resources::resources.settings.curseforge_api_key_help'))
                        ->default(config('resources.curseforge_api_key'))
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Section::make(trans('resources::resources.settings.platform_settings'))
                ->description(trans('resources::resources.settings.platform_settings_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('enable_modrinth')
                        ->label(trans('resources::resources.settings.enable_modrinth'))
                        ->helperText(trans('resources::resources.settings.enable_modrinth_help'))
                        ->default(config('resources.platforms.modrinth'))
                        ->inline(false)
                        ->columnSpan(1),

                    Toggle::make('enable_curseforge')
                        ->label(trans('resources::resources.settings.enable_curseforge'))
                        ->helperText(trans('resources::resources.settings.enable_curseforge_help'))
                        ->default(config('resources.platforms.curseforge'))
                        ->inline(false)
                        ->columnSpan(1),

                    Toggle::make('enable_bukkit')
                        ->label(trans('resources::resources.settings.enable_bukkit'))
                        ->helperText(trans('resources::resources.settings.enable_bukkit_help'))
                        ->default(config('resources.platforms.bukkit'))
                        ->inline(false)
                        ->columnSpan(1),

                    Toggle::make('enable_spigot')
                        ->label(trans('resources::resources.settings.enable_spigot'))
                        ->helperText(trans('resources::resources.settings.enable_spigot_help'))
                        ->default(config('resources.platforms.spigot'))
                        ->inline(false)
                        ->columnSpan(1),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'CURSEFORGE_API_KEY' => $data['curseforge_api_key'] ?? '',
            'RESOURCES_ENABLE_MODRINTH' => (bool) ($data['enable_modrinth'] ?? true),
            'RESOURCES_ENABLE_CURSEFORGE' => (bool) ($data['enable_curseforge'] ?? true),
            'RESOURCES_ENABLE_BUKKIT' => (bool) ($data['enable_bukkit'] ?? true),
            'RESOURCES_ENABLE_SPIGOT' => (bool) ($data['enable_spigot'] ?? true),
        ]);

        Notification::make()
            ->title(trans('resources::resources.settings.saved'))
            ->success()
            ->body(trans('resources::resources.settings.saved_body'))
            ->send();
    }
}
