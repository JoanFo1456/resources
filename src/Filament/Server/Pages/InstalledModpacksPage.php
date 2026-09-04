<?php

namespace JoanFo\Resources\Filament\Server\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use JoanFo\Resources\Models\InstalledModpack;

class InstalledModpacksPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'resources::filament.pages.installed-modpacks';

    protected static ?string $slug = 'installed-modpacks';

    protected static ?int $navigationSort = 12;

    public bool $viewAsGrid = false;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        if (!$server) {
            return false;
        }

        $server->loadMissing('egg');

        return parent::canAccess() && in_array('minecraft', $server->egg->tags ?? []);
    }

    public function getTitle(): string
    {
        return trans('resources::resources.installed_modpacks.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('resources::resources.installed_modpacks.navigation');
    }

    public function getHeading(): string
    {
        return trans('resources::resources.installed_modpacks.heading');
    }

    public static function getNavigationIcon(): string
    {
        return 'tabler-package';
    }

    public function updatedViewAsGrid(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $serverId = Filament::getTenant()->id;

        $table = $table
            ->records(fn () => InstalledModpack::where('server_id', $serverId)
                ->orderByDesc('installed_at')
                ->get())
            ->columns([
                TextColumn::make('name')
                    ->label(trans('resources::resources.table.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('modloader')
                    ->label(trans('resources::resources.installed_modpacks.modloader'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fabric' => 'success',
                        'forge' => 'warning',
                        'neoforge' => 'info',
                        'quilt' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '—'),
                TextColumn::make('source')
                    ->label(trans('resources::resources.table.source'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'curseforge' => 'warning',
                        'modrinth' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('version_id')
                    ->label(trans('resources::resources.installed_modpacks.version'))
                    ->copyable(),
                TextColumn::make('installed_at')
                    ->label(trans('resources::resources.installed_modpacks.installed_at'))
                    ->dateTime()
                    ->since(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(trans('resources::resources.installed_modpacks.remove'))
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (InstalledModpack $record) {
                        $record->delete();
                        $this->resetTable();

                        Notification::make()
                            ->success()
                            ->title(trans('resources::resources.installed_modpacks.removed'))
                            ->send();
                    }),
            ])
            ->headerActions([
                Action::make('browse_modpacks')
                    ->label(trans('resources::resources.installed_modpacks.browse'))
                    ->icon('tabler-world')
                    ->color('gray')
                    ->url(fn () => ModpacksPage::getUrl(tenant: Filament::getTenant())),
                Action::make('toggle_grid')
                    ->label(fn () => $this->viewAsGrid
                        ? trans('resources::resources.view.list')
                        : trans('resources::resources.view.grid'))
                    ->icon(fn () => $this->viewAsGrid ? 'tabler-list' : 'tabler-layout-grid')
                    ->color('gray')
                    ->action(function () {
                        $this->viewAsGrid = !$this->viewAsGrid;
                        $this->resetTable();
                    }),
            ])
            ->searchable()
            ->paginated([10, 25, 50])
            ->emptyStateIcon('tabler-package-off')
            ->emptyStateHeading(trans('resources::resources.installed_modpacks.empty_heading'))
            ->emptyStateDescription(trans('resources::resources.installed_modpacks.empty_description'));

        if ($this->viewAsGrid) {
            $table = $table->contentGrid(['md' => 2, 'xl' => 3]);
        }

        return $table;
    }
}
